<?php

namespace App\Core\Support;

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Support\Facades\DB;

/**
 * Borrado al vencer la conservación (docs/10 §9; docs/04 «Eventos analíticos y mensajes del chatbot»; M-WEB RN-WEB-65 y 77).
 * Solo datos sin obligación legal de conservarse y con su parámetro vigente:
 *  - site_events y chatbot_messages: particiones mensuales (se crean por adelantado y se descartan completas al pasar
 *    web.analytics_event_retention_months y web.chatbot_retention_months); queda site_analytics_daily agregado.
 *  - chatbot_conversations: se dan de baja (sin ruta) las que empezaron antes del plazo del chatbot.
 *  - site_web_vitals: como los eventos analíticos.
 *  - cookie_consents: al vencer web.cookie_consent_validity (meses); mientras el parámetro no sea un número («por definir»)
 *    no se borra nada.
 *  - idempotency_keys: al vencer su vigencia (idempotency_key_hours).
 * Los registros SST obligatorios, la historia clínica, la bitácora y el Libro de Reclamaciones NO pasan por aquí: se
 * conservan y su purga necesita aprobación (M-AUD RN-AUD-06, docs/10 §9).
 * Cada corrida deja en la bitácora qué se borró (conteos y particiones, nunca el contenido).
 */
final class RetentionPurge
{
    public function __construct(private AccountsService $accounts) {}

    /** @return array<string, mixed> qué se borró */
    public function run(): array
    {
        $owner = DB::connection('pgsql_owner');
        $platform = DB::connection('platform');
        $out = [];

        $months = [
            'site_events' => (int) LegalParams::number('web.analytics_event_retention_months', 13),
            'chatbot_messages' => (int) LegalParams::number('web.chatbot_retention_months', 12),
        ];
        foreach ($months as $table => $keep) {
            // Meses con filas en la partición por defecto (desde el más antiguo) y los dos siguientes al actual, por adelantado.
            $first = $owner->transaction(function () use ($owner, $table) {
                $owner->statement("alter table {$table}_default no force row level security");
                $min = $owner->selectOne("select min(occurred_at) as m from {$table}_default")->m;
                $owner->statement("alter table {$table}_default force row level security");

                return $min;
            });
            $from = $first ? \Carbon\Carbon::parse($first)->setTimezone('America/Lima')->startOfMonth() : now('America/Lima')->startOfMonth();
            $to = now('America/Lima')->startOfMonth()->addMonths(2);
            $created = [];
            for ($m = $from->copy(); $m <= $to; $m->addMonth()) {
                $created[] = $owner->transaction(fn () => $owner->selectOne('select web_partition_month(?, ?::date) as n', [$table, $m->toDateString()])->n);
            }
            $dropped = $keep >= 1
                ? $owner->transaction(fn () => trim((string) $owner->selectOne('select web_drop_expired_partitions(?, ?)::text as d', [$table, $keep])->d, '{}'))
                : '';
            $out[$table] = ['retention_months' => $keep, 'partitions' => count($created), 'dropped' => $dropped === '' ? [] : explode(',', $dropped)];
        }

        $chat = $months['chatbot_messages'];
        $out['chatbot_conversations'] = $chat >= 1 ? $platform->update(
            "update chatbot_conversations set deleted_at = now(), path = null, updated_at = now()
              where deleted_at is null and started_at < now() - make_interval(months => ?)", [$chat]) : 0;

        $events = $months['site_events'];
        $out['site_web_vitals'] = $events >= 1 ? $platform->delete('delete from site_web_vitals where occurred_at < now() - make_interval(months => ?)', [$events]) : 0;

        $validity = LegalParams::text('web.cookie_consent_validity', '');
        if (preg_match('/^\d+$/', $validity) && (int) $validity >= 1) {
            $out['cookie_consents'] = $owner->transaction(function () use ($owner, $validity) {
                $owner->select("select set_config('app.retention_purge', 'cookie_consents', true)");

                return $owner->delete('delete from cookie_consents where consented_at < now() - make_interval(months => ?)', [(int) $validity]);
            });
        } else {
            $out['cookie_consents'] = 'parámetro web.cookie_consent_validity por definir: no se borra';
        }

        $out['idempotency_keys'] = $platform->delete('delete from idempotency_keys where expires_at < now()');

        $this->accounts->audit(null, 'retention_purged', 'retention', null, $out);

        return $out;
    }
}
