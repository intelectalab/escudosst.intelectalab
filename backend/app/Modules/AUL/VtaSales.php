<?php

namespace App\Modules\AUL;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Reglas compartidas de la venta de cursos (M-VTA), dentro del contexto de la empresa vendedora:
 *  - cupón validado en el servidor (RN-VTA-11): activo, vigente, oferta incluida, monto mínimo, usos totales y por comprador;
 *    el uso se cuenta solo al pagarse el pedido.
 *  - precio del pedido con descuento e IGV (RN-VTA-10) y vencimiento del pedido pendiente (RN-VTA-12, order_payment_ttl_minutes).
 *  - participante externo por documento (se reutiliza si ya existe).
 */
final class VtaSales
{
    /** @return array{coupon_id: ?string, discount: float} */
    public static function coupon(?string $code, object $offer, float $gross, string $email, ?string $document): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return ['coupon_id' => null, 'discount' => 0.0];
        }
        $c = DB::selectOne('select * from coupons where lower(code) = lower(?) and deleted_at is null', [$code]);
        $bad = fn (string $m) => throw new ApiProblem(422, 'validation_failed', 'Revisa el cupón', $m, ['errors' => ['coupon_code' => [$m]]]);
        if (! $c || ! $c->is_active) {
            $bad('Ese cupón no existe o no está activo.');
        }
        if (($c->valid_from && strtotime($c->valid_from) > time()) || ($c->valid_until && strtotime($c->valid_until) <= time())) {
            $bad('Ese cupón no está vigente.');
        }
        if ($c->applies_to === 'offers' && ! in_array($offer->id, self::uuids($c->course_offer_ids), true)) {
            $bad('Ese cupón no aplica a este curso.');
        }
        if ($c->min_order_total !== null && $gross < (float) $c->min_order_total) {
            $bad('Ese cupón es para compras desde S/ '.number_format((float) $c->min_order_total, 2).'.');
        }
        if ($c->max_redemptions !== null && (int) $c->redemptions_count >= (int) $c->max_redemptions) {
            $bad('Ese cupón ya se usó el máximo de veces.');
        }
        if ($c->max_redemptions_per_buyer !== null) {
            $used = (int) DB::selectOne(
                "select count(*) as n from orders where coupon_id = ? and deleted_at is null and status in ('paid', 'partially_refunded')
                    and (lower(buyer_email) = lower(?) or (?::text is not null and buyer_document_number = ?::text))",
                [$c->id, $email, $document, $document]
            )->n;
            if ($used >= (int) $c->max_redemptions_per_buyer) {
                $bad('Ya usaste este cupón el máximo de veces permitido.');
            }
        }
        $discount = $c->discount_type === 'percent' ? round($gross * (float) $c->discount_value / 100, 2) : min((float) $c->discount_value, $gross);

        return ['coupon_id' => $c->id, 'discount' => max(0.0, round($discount, 2))];
    }

    /** @return array{total: float, subtotal: float, igv: float} sobre el monto ya descontado */
    public static function amounts(object $offer, float $net): array
    {
        $total = round($net, 2);
        $sub = $offer->price_includes_igv && $offer->igv_affectation === 'taxed' ? round($total / 1.18, 2) : $total;

        return ['total' => $total, 'subtotal' => $sub, 'igv' => round($total - $sub, 2)];
    }

    public static function ttlMinutes(): int
    {
        return max(15, (int) LegalParams::number('order_payment_ttl_minutes', 4320));
    }

    /** Participante externo por su documento (se reutiliza si ya existe). */
    public static function learner(string $company, array $d, ?string $actor = null, ?string $buyerRuc = null, ?string $buyerName = null): string
    {
        $doc = strtoupper(trim((string) $d['document_number']));
        $hash = hash_hmac('sha256', $doc, (string) config('app.key'));
        $found = DB::selectOne("select id from external_learners where document_number_hash = decode(?, 'hex') and deleted_at is null", [$hash]);
        if ($found) {
            if ($buyerRuc) {
                DB::update('update external_learners set buyer_company_ruc = coalesce(buyer_company_ruc, ?), buyer_company_name = coalesce(buyer_company_name, ?), updated_at = now() where id = ?',
                    [$buyerRuc, $buyerName, $found->id]);
            }

            return $found->id;
        }

        return DB::selectOne(
            "insert into external_learners (company_id, document_type, document_number, document_number_hash, document_last4, first_name, last_name, email,
                buyer_company_ruc, buyer_company_name, data_consent_at, consent_text_version, source, created_by, updated_by)
             values (?, ?, ?, decode(?, 'hex'), ?, ?, ?, ?, ?, ?, now(), 'v1', ?, ?, ?) returning id",
            [$company, in_array($d['document_type'] ?? 'DNI', ['DNI', 'CE', 'PTP', 'PASSPORT'], true) ? ($d['document_type'] ?? 'DNI') : 'OTHER', Crypt::encryptString($doc),
                $hash, substr($doc, -4), $d['first_name'], $d['last_name'], mb_strtolower((string) $d['email']), $buyerRuc, $buyerName,
                $buyerRuc ? 'license' : 'purchase', $actor, $actor]
        )->id;
    }

    /** Pedidos pendientes cuyo plazo venció: pasan a «vencido» y liberan el aforo (RN-VTA-12). */
    public static function expire(\Illuminate\Database\ConnectionInterface $db): int
    {
        return $db->update("update orders set status = 'expired', updated_at = now() where status = 'pending' and expires_at is not null and expires_at <= now() and deleted_at is null");
    }

    /** @return list<string> */
    private static function uuids(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }

        return array_values(array_filter(explode(',', trim((string) $v, '{}'))));
    }

    /**
     * SCR-VTA-18: cuenta del participante externo para su portal (rol external_learner en la empresa vendedora) y sus
     * matrículas ligadas a la cuenta; la primera vez recibe el enlace para crear su contraseña. Devuelve el usuario.
     */
    public static function ensureAccount(string $learnerId): ?string
    {
        $l = DB::selectOne('select id, company_id, user_id, first_name, last_name, email from external_learners where id = ?', [$learnerId]);
        if (! $l || ! filter_var($l->email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $user = $l->user_id ?: self::account($l->company_id, $l->email, trim($l->first_name.' '.$l->last_name));
        if (! $user) {
            return null;
        }
        DB::update('update external_learners set user_id = ?, updated_at = now() where id = ? and user_id is null', [$user, $l->id]);
        DB::update('update course_enrollments set user_id = ? where external_learner_id = ? and user_id is null and deleted_at is null', [$user, $l->id]);

        return $user;
    }

    /** Representante de la empresa compradora: cuenta del portal ligada a sus cupos (RN-VTA-16: solo ve a quienes asignó). */
    public static function ensureManager(string $licenseId): ?string
    {
        $lic = DB::selectOne('select id, company_id, manager_user_id, buyer_contact_email, buyer_legal_name from course_licenses where id = ?', [$licenseId]);
        if (! $lic || $lic->manager_user_id || ! filter_var($lic->buyer_contact_email, FILTER_VALIDATE_EMAIL)) {
            return $lic?->manager_user_id;
        }
        $user = self::account($lic->company_id, $lic->buyer_contact_email, (string) $lic->buyer_legal_name);
        if ($user) {
            DB::update('update course_licenses set manager_user_id = ?, updated_at = now() where id = ?', [$user, $lic->id]);
        }

        return $user;
    }

    private static function account(string $company, string $email, string $name): ?string
    {
        $email = mb_strtolower(trim($email));
        $p = DB::connection('platform');
        $u = $p->selectOne('select id, is_platform_staff from users where lower(email) = ? and deleted_at is null', [$email]);
        if ($u?->is_platform_staff) {
            return null;
        }
        $id = $u?->id ?? $p->selectOne("insert into users (email, full_name, status) values (?, ?, 'invited') returning id", [$email, mb_substr($name ?: $email, 0, 160)])->id;
        if (! DB::selectOne('select 1 as ok from memberships where user_id = ? and company_id = ? and deleted_at is null', [$id, $company])) {
            $role = DB::selectOne("select id from roles where code = 'external_learner' and account_id is null")?->id;
            if (! $role) {
                return null;
            }
            DB::insert("insert into memberships (company_id, user_id, role_id, status, invited_at, accepted_at) values (?, ?, ?, 'active', now(), now())", [$company, $id, $role]);
        }
        $companyName = DB::selectOne('select coalesce(trade_name, legal_name) as n from companies where id = ?', [$company])?->n ?? 'EscudoSST';
        app(\App\Modules\USR\Services\AccountSecurityService::class)->inviteLearner($id, $companyName);

        return $id;
    }
}
