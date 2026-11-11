<?php

namespace App\Modules\AUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogo público de cursos de una empresa (VTA · SCR-VTA-12, 15 y 16), sin sesión y con límite de uso por IP:
 *  GET  /public/courses/{catalog}                    empresa y ofertas publicadas, visibles y vigentes
 *  GET  /public/courses/{catalog}/{offer}            ficha del curso (temario, horas, certificado, precio) y otras ofertas
 *  POST /public/courses/{catalog}/{offer}/order      solicitud de compra: pedido pendiente de pago (persona o cupos para empresa)
 * Con sesión, para la empresa vendedora:
 *  GET  /vta/catalog                                 dirección de su catálogo público
 *  PUT  /vta/catalog {slug}                          cambia el identificador de la dirección
 *
 * {catalog} es el catalog_slug de la empresa (o su id mientras no tenga uno). Si la empresa no tiene VTA activo,
 * responde «no disponible» (RN-VTA-21). El pago es manual: el pedido queda pendiente y la empresa lo confirma con
 * «Registrar pago» (POST /vta/orders/{id}/pay). No hay datos de pago configurables por empresa todavía: el comprador
 * ve que la empresa se comunicará con él para indicarle cómo pagar.
 */
class VtaCatalogController extends Controller
{
    public function index(string $catalog): JsonResponse
    {
        return $this->inCatalog($catalog, function (object $c) {
            $offers = DB::select(
                "select o.slug, o.title, o.short_description, o.list_price, o.sale_price, o.price_includes_igv, o.access_days, o.allows_company_purchase,
                        case when o.seats_total is null then null else greatest(o.seats_total - o.seats_sold - {$this->reservedSql()}, 0) end as seats_left,
                        c.category, c.hours, c.delivery_mode, c.certificate_enabled
                   from course_offers o join courses c on c.id = o.course_id and c.status = 'published' and c.deleted_at is null
                  where o.status = 'published' and o.is_public and o.deleted_at is null {$this->onSaleSql()}
                  order by o.title"
            );

            return response()->json(['data' => ['company' => $this->company($c), 'offers' => array_map(fn ($o) => $this->offer($o), $offers)]]);
        });
    }

    public function show(string $catalog, string $offer): JsonResponse
    {
        return $this->inCatalog($catalog, function (object $c) use ($offer) {
            $o = $this->findOffer($offer);
            $sections = DB::select('select title, description from course_sections where course_id = ? and is_visible and deleted_at is null order by sort_order, section_number', [$o->course_id]);
            $others = DB::select(
                "select o.slug, o.title, o.short_description, o.list_price, o.sale_price, o.price_includes_igv, o.access_days, o.allows_company_purchase,
                        case when o.seats_total is null then null else greatest(o.seats_total - o.seats_sold - {$this->reservedSql()}, 0) end as seats_left,
                        c.category, c.hours, c.delivery_mode, c.certificate_enabled
                   from course_offers o join courses c on c.id = o.course_id and c.status = 'published' and c.deleted_at is null
                  where o.status = 'published' and o.is_public and o.deleted_at is null and o.id <> ? {$this->onSaleSql()}
                  order by (c.category is not distinct from ?) desc, o.title limit 3",
                [$o->id, $o->category]
            );

            return response()->json(['data' => [
                'company' => $this->company($c),
                'offer' => $this->offer($o) + [
                    'min_company_seats' => $o->min_company_seats ? (int) $o->min_company_seats : null,
                    'summary' => $o->summary, 'description' => $o->description, 'objectives' => $o->objectives, 'target_audience' => $o->target_audience,
                    'sections' => array_map(fn ($s) => ['title' => $s->title, 'description' => $s->description], $sections),
                ],
                'others' => array_map(fn ($x) => $this->offer($x), $others),
                'payment_instructions' => null,
            ]]);
        });
    }

    public function order(Request $request, string $catalog, string $offer): JsonResponse
    {
        $d = $request->validate([
            'buyer_type' => ['required', 'in:person,company'],
            'document_type' => ['required_if:buyer_type,person', 'nullable', 'in:DNI,CE,PTP,PASSPORT'],
            'document_number' => ['required_if:buyer_type,person', 'nullable', 'string', 'min:6', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'first_name' => ['required_if:buyer_type,person', 'nullable', 'string', 'max:80'],
            'last_name' => ['required_if:buyer_type,person', 'nullable', 'string', 'max:80'],
            'legal_name' => ['required_if:buyer_type,company', 'nullable', 'string', 'max:160'],
            'ruc' => ['required_if:buyer_type,company', 'nullable', 'regex:/^(10|15|17|20)\d{9}$/'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +()-]+$/'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:500'],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
            'idempotency_key' => ['nullable', 'uuid'],
            'website' => ['prohibited'],   // trampa para robots
        ], [
            '*.required_if' => 'Completa este dato.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Revisa el correo.',
            'ruc.regex' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.',
            'document_number.regex' => 'Escribe el número de documento sin espacios ni guiones.',
            'privacy_consent.accepted' => 'Para comprar, acepta el tratamiento de tus datos.',
        ]);

        return $this->inCatalog($catalog, function (object $c) use ($d, $offer, $request) {
            $o = $this->findOffer($offer);
            $key = $d['idempotency_key'] ?? (string) Str::uuid();
            // Doble clic o reintento: devuelve el mismo pedido, sin crear otro.
            if ($prev = DB::selectOne('select order_number, total, expires_at from orders where idempotency_key = ?', [$key])) {
                return $this->orderResponse($c, $prev, 200);
            }
            $company = $d['buyer_type'] === 'company';
            $qty = $company ? (int) ($d['seats'] ?? max(1, (int) $o->min_company_seats)) : 1;
            if ($company && ! $o->allows_company_purchase) {
                $this->fail('buyer_type', 'Este curso no se vende como cupos para empresas.');
            }
            if ($company && $o->min_company_seats && $qty < (int) $o->min_company_seats) {
                $this->fail('seats', "La compra para empresas es desde {$o->min_company_seats} cupos.");
            }
            // El aforo cuenta lo vendido y lo reservado por pedidos pendientes que aún no vencen (RN-VTA-12).
            if ($o->seats_total && (int) $o->seats_left < $qty) {
                $this->fail('seats', (int) $o->seats_left > 0 ? "Solo quedan {$o->seats_left} cupos." : 'Ya no quedan cupos en este curso.');
            }

            $unit = (float) ($o->sale_price ?? $o->list_price);
            $total = round($unit * $qty, 2);
            $sub = $o->price_includes_igv && $o->igv_affectation === 'taxed' ? round($total / 1.18, 2) : $total;
            $igv = round($total - $sub, 2);
            $n = (int) DB::selectOne('select count(*) + 1 as n from orders')->n;
            do {
                $number = sprintf('PED-%s-%05d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from orders where order_number = ? and deleted_at is null', [$number]));
            $name = $company ? $d['legal_name'] : trim($d['first_name'].' '.$d['last_name']);
            $learner = $company ? null : $this->learner($c->company_id, $d);

            $id = DB::selectOne(
                "insert into orders (company_id, order_number, external_learner_id, buyer_type, buyer_document_type, buyer_document_number, buyer_name, buyer_email, buyer_phone,
                    buyer_ruc, buyer_legal_name, billing_document_type, subtotal, igv_rate, igv_total, total, idempotency_key, source, status, expires_at, customer_ip_hash)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.18, ?, ?, ?, 'public_catalog', 'pending', now() + interval '3 days', decode(?, 'hex')) returning id",
                [$c->company_id, $number, $learner, $d['buyer_type'], $company ? 'RUC' : $d['document_type'], $company ? $d['ruc'] : $d['document_number'], $name,
                    mb_strtolower($d['email']), $d['phone'] ?? null, $company ? $d['ruc'] : null, $company ? $d['legal_name'] : null, $company ? 'invoice' : 'receipt',
                    $sub, $igv, $total, $key, hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))]
            )->id;
            DB::insert(
                'insert into order_items (company_id, order_id, course_offer_id, course_id, description, item_type, quantity, unit_price, subtotal, igv_amount, total)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$c->company_id, $id, $o->id, $o->course_id, mb_substr($o->title, 0, 300), $company ? 'license' : 'individual', $qty, $unit, $sub, $igv, $total]
            );

            return $this->orderResponse($c, DB::selectOne('select order_number, total, expires_at from orders where id = ?', [$id]), 201);
        });
    }

    /** Dirección del catálogo público de la empresa activa. */
    public function link(): JsonResponse
    {
        $this->can('vta.course_offers.read');

        return response()->json(['data' => $this->linkData()]);
    }

    public function setSlug(Request $request): JsonResponse
    {
        $this->can('vta.course_offers.update');
        $d = $request->validate(
            ['slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/']],
            ['slug.regex' => 'Usa solo letras minúsculas sin tildes, números y guiones (por ejemplo: instituto-qhaliy).']
        );
        $ctx = app(CompanyContext::class)->companyId;
        $taken = DB::connection('platform')->selectOne('select 1 as ok from companies where catalog_slug = ? and id <> ? and deleted_at is null', [$d['slug'], $ctx]);
        if ($taken) {
            $this->fail('slug', 'Esa dirección ya la usa otra empresa. Prueba con otra.');
        }
        DB::update('update companies set catalog_slug = ?, updated_at = now(), updated_by = ? where id = ?', [$d['slug'], (string) auth()->id(), $ctx]);

        return response()->json(['data' => $this->linkData()]);
    }

    private function linkData(): array
    {
        $ctx = app(CompanyContext::class)->companyId;
        $slug = DB::selectOne('select catalog_slug from companies where id = ?', [$ctx])?->catalog_slug;

        return ['slug' => $slug, 'url' => rtrim((string) config('escudo.frontend_url'), '/').'/cursos/'.($slug ?: $ctx)];
    }

    /**
     * Ubica la empresa del catálogo (la tabla companies no se lee sin sesión: se consulta con el rol de plataforma solo
     * su nombre y si VTA está activo) y ejecuta con el contexto RLS de esa empresa.
     */
    private function inCatalog(string $catalog, callable $fn): JsonResponse
    {
        $ref = strtolower($catalog);
        $isId = Str::isUuid($ref);
        if (! $isId && ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $ref)) {
            throw ApiProblem::notFound('Este catálogo de cursos no existe.');
        }
        $c = DB::connection('platform')->selectOne(
            "select c.id as company_id, coalesce(c.trade_name, c.legal_name) as name, c.catalog_slug,
                    exists (select 1 from feature_flags f where f.company_id = c.id and f.module_code = 'VTA' and f.mode = 'enabled' and f.deleted_at is null) as vta
               from companies c
              where c.deleted_at is null and c.status = 'active' and ".($isId ? 'c.id = ?::uuid' : 'c.catalog_slug = ?'),
            [$ref]
        );
        if (! $c) {
            throw ApiProblem::notFound('Este catálogo de cursos no existe.');
        }
        if (! $c->vta) {
            throw new ApiProblem(404, 'catalog_unavailable', 'Catálogo no disponible', 'Este catálogo de cursos no está disponible por ahora.');
        }

        return DB::transaction(function () use ($c, $fn) {
            DbContext::setCompany($c->company_id);

            return $fn($c);
        });
    }

    private function findOffer(string $slug): object
    {
        $o = preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) ? DB::selectOne(
            "select o.*, case when o.seats_total is null then null else greatest(o.seats_total - o.seats_sold - {$this->reservedSql()}, 0) end as seats_left,
                    c.summary, c.description, c.objectives, c.target_audience, c.category, c.hours, c.delivery_mode, c.certificate_enabled
               from course_offers o join courses c on c.id = o.course_id and c.status = 'published' and c.deleted_at is null
              where o.slug = ? and o.status = 'published' and o.deleted_at is null {$this->onSaleSql()}
              limit 1",
            [$slug]
        ) : null;
        if (! $o) {
            throw ApiProblem::notFound('Este curso no está a la venta.');
        }

        return $o;
    }

    /** Cupos reservados por pedidos pendientes que todavía no vencen (para la oferta «o» de la consulta). */
    private function reservedSql(): string
    {
        return "coalesce((select sum(i.quantity) from order_items i join orders p on p.id = i.order_id
                           where i.course_offer_id = o.id and i.deleted_at is null and p.status = 'pending' and p.deleted_at is null
                             and (p.expires_at is null or p.expires_at > now())), 0)";
    }

    private function onSaleSql(): string
    {
        return 'and (o.sale_starts_at is null or o.sale_starts_at <= now()) and (o.sale_ends_at is null or o.sale_ends_at > now())';
    }

    private function company(object $c): array
    {
        return ['name' => $c->name, 'catalog' => $c->catalog_slug ?: $c->company_id];
    }

    private function offer(object $o): array
    {
        return [
            'slug' => $o->slug, 'title' => $o->title, 'short_description' => $o->short_description,
            'list_price' => (float) $o->list_price, 'sale_price' => $o->sale_price !== null ? (float) $o->sale_price : null,
            'price' => (float) ($o->sale_price ?? $o->list_price), 'price_includes_igv' => (bool) $o->price_includes_igv, 'currency' => 'PEN',
            'access_days' => $o->access_days ? (int) $o->access_days : null, 'seats_left' => $o->seats_left !== null ? (int) $o->seats_left : null,
            'allows_company_purchase' => (bool) $o->allows_company_purchase, 'category' => $o->category,
            'hours' => $o->hours !== null ? (float) $o->hours : null, 'delivery_mode' => $o->delivery_mode, 'certificate' => (bool) $o->certificate_enabled,
        ];
    }

    private function orderResponse(object $c, object $order, int $status): JsonResponse
    {
        return response()->json(['data' => [
            'order_number' => $order->order_number, 'total' => (float) $order->total, 'status' => 'pending', 'expires_at' => $order->expires_at,
            'company' => $c->name, 'payment_instructions' => null,
        ]], $status);
    }

    /** Participante externo por su documento o correo (se reutiliza si ya existe); guarda los consentimientos. */
    private function learner(string $companyId, array $d): string
    {
        $hash = hash_hmac('sha256', strtoupper($d['document_number']), (string) config('app.key'));
        $marketing = (bool) ($d['marketing_consent'] ?? false);
        $found = DB::selectOne(
            "select id from external_learners where (document_number_hash = decode(?, 'hex') or lower(email) = lower(?)) and deleted_at is null
              order by (document_number_hash = decode(?, 'hex')) desc limit 1",
            [$hash, $d['email'], $hash]
        );
        if ($found) {
            if ($marketing) {
                DB::update('update external_learners set marketing_consent = true, marketing_consent_at = coalesce(marketing_consent_at, now()), updated_at = now() where id = ?', [$found->id]);
            }

            return $found->id;
        }

        return DB::selectOne(
            "insert into external_learners (company_id, document_type, document_number, document_number_hash, document_last4, first_name, last_name, email,
                data_consent_at, consent_text_version, marketing_consent, marketing_consent_at, source)
             values (?, ?, ?, decode(?, 'hex'), ?, ?, ?, ?, now(), 'v1', ?, case when ? then now() end, 'purchase') returning id",
            [$companyId, $d['document_type'], Crypt::encryptString(strtoupper($d['document_number'])), $hash, substr($d['document_number'], -4),
                $d['first_name'], $d['last_name'], mb_strtolower($d['email']), $marketing ? 'true' : 'false', $marketing ? 'true' : 'false']
        )->id;
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona ventas.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}
