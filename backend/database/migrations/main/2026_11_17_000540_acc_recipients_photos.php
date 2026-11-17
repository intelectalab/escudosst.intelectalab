<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-ACC: notificaciones por destinatario (MTPE, centro asistencial, aseguradora SCTR, empresa principal; §5 event_notifications),
 * fotos del registro (SCR-ACC-01) y paso «clasificado» (§9: el estado intermedio «registered» se muestra como «Clasificado»).
 * Los destinatarios por tipo de evento y sus plazos son parámetros «por confirmar» (RN-ACC-01): la ficha no fija valores.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const PARAMS = [
        ['acc_notification_recipients', '{"minor_accident": ["medical_center", "insurer_sctr"], "disabling_accident": ["medical_center", "insurer_sctr"], "fatal_accident": ["insurer_sctr"], "contractor_event": ["principal_company"]}',
            'json', null, 'Destinatarios de notificación además del MTPE, por tipo de evento (y «contractor_event» para eventos de contratistas)', 'M-ACC §5, §7, RN-ACC-01'],
        ['notify_deadline_medical_center_hours', '0', 'hours', 'horas', 'Plazo para avisar al centro asistencial (0 = sin plazo fijado)', 'M-ACC RN-ACC-01'],
        ['notify_deadline_insurer_sctr_hours', '0', 'hours', 'horas', 'Plazo para avisar a la aseguradora del SCTR (0 = sin plazo fijado)', 'M-ACC RN-ACC-01'],
        ['notify_deadline_principal_company_hours', '0', 'hours', 'horas', 'Plazo del contratista para avisar a la empresa principal (0 = sin plazo fijado)', 'M-ACC RN-ACC-01'],
    ];

    public function up(): void
    {
        $ck = DB::selectOne("select conname from pg_constraint where conrelid = 'event_notifications'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%mtpe_sat%'");
        if ($ck) {
            DB::statement("alter table event_notifications drop constraint {$ck->conname}");
        }
        DB::statement("alter table event_notifications add constraint ck_event_notifications_authority check (authority in ('mtpe_sat', 'sunafil', 'essalud', 'eps', 'insurer_sctr', 'osinergmin', 'medical_center', 'principal_company', 'other'))");
        DB::statement("alter table sst_events add column if not exists photo_file_ids jsonb not null default '[]'::jsonb, add column if not exists classified_at timestamptz, add column if not exists classified_by uuid references users(id)");
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, 'M-ACC', date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $key]);
        }
    }

    public function down(): void
    {
        DB::statement('alter table sst_events drop column if exists photo_file_ids, drop column if exists classified_at, drop column if exists classified_by');
        DB::connection('platform')->delete('delete from legal_parameters where company_id is null and key = any(?::text[])', ['{'.implode(',', array_column(self::PARAMS, 0)).'}']);
    }
};
