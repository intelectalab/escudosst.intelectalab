<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/10-seguridad-y-privacidad.md:
 *  §1.4 Registro de solicitudes ARCO por empresa (arco_requests ya existe): el administrador de cuenta (responsable de datos
 *       personales de la empresa) registra, responde y cierra; antes solo podía ver.
 *  §1.5 Registro de brechas de seguridad (data_breaches): detección, contención y evaluación (datos, si incluye datos
 *       sensibles, personas afectadas), aviso al responsable, notificación a la ANPD y a los titulares con el plazo
 *       data_breach_notification_hours (a confirmar), causa, medidas y revisión posterior.
 *  §4   Alertas por patrones anómalos de acceso a datos sensibles: muchos trabajadores distintos en poco tiempo
 *       (sensitive_access_anomaly_workers en sensitive_access_anomaly_minutes; valores referenciales a confirmar).
 *  §9   Borrado al vencer la conservación:
 *       - cookie_consents (inalterable): se purga al vencer web.cookie_consent_validity. El trigger de inalterable deja borrar
 *         solo dentro de la tarea de conservación (app.retention_purge) y solo el rol dueño puede hacerlo.
 *       - site_events y chatbot_messages: particiones mensuales; web_partition_month() crea el mes y mueve las filas que
 *         hubieran caído en la partición por defecto, así web_drop_expired_partitions() puede descartarlas al vencer.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
create table if not exists data_breaches (
    id uuid not null default uuidv7(),
    company_id uuid not null references companies (id),
    code text,
    title text not null,
    detected_at timestamptz not null,
    detection_source text not null default 'internal' check (detection_source in ('alert', 'client_report', 'provider_report', 'internal', 'other')),
    description text not null,
    data_categories text,
    includes_sensitive_data boolean not null default false,
    affected_people_count integer check (affected_people_count is null or affected_people_count >= 0),
    affected_scope text,
    responsible_user_id uuid references users (id),
    containment_measures text,
    cause text,
    legal_parameter_key text not null default 'data_breach_notification_hours',
    anpd_due_at timestamptz,
    anpd_notified_at timestamptz,
    anpd_reference text,
    holders_notification_required boolean not null default false,
    holders_notified_at timestamptz,
    corrective_measures text,
    lessons_learned text,
    status text not null default 'detected' check (status in ('detected', 'contained', 'notified', 'closed')),
    closed_at timestamptz,
    created_at timestamptz not null default now(),
    created_by uuid references users (id),
    updated_at timestamptz not null default now(),
    updated_by uuid references users (id),
    deleted_at timestamptz,
    primary key (id),
    constraint uq_data_breaches_company_id_id unique (company_id, id),
    constraint ck_data_breaches_closed check (status <> 'closed' or closed_at is not null),
    constraint ck_data_breaches_notified check (anpd_notified_at is null or anpd_notified_at >= detected_at)
)
SQL);
        DB::statement("comment on table data_breaches is '[TRB] Registro de brechas de seguridad de datos personales (Ley 29733, docs/10 §1.5): causa, medidas y plazo de notificación a la ANPD según parámetro (a confirmar)'");
        DB::statement("comment on column data_breaches.anpd_due_at is 'detected_at + data_breach_notification_hours (parámetro a confirmar)'");
        DB::statement('create unique index if not exists uq_data_breaches_code on data_breaches (company_id, code) where code is not null');
        DB::statement('create index if not exists idx_data_breaches_status on data_breaches (company_id, status, anpd_due_at)');
        DB::statement('alter table data_breaches enable row level security');
        DB::statement('alter table data_breaches force row level security');
        $co = "NULLIF(current_setting('app.company_id', true), '')::uuid";
        DB::statement('drop policy if exists rls_data_breaches_company on data_breaches');
        DB::statement("create policy rls_data_breaches_company on data_breaches to escudo_app using (company_id = {$co}) with check (company_id = {$co})");
        DB::statement('grant select, insert, update on data_breaches to escudo_app');
        DB::statement('grant select, insert, update on data_breaches to escudo_definer');
        DB::statement('drop trigger if exists trg_data_breaches_updated_at on data_breaches');
        DB::statement('create trigger trg_data_breaches_updated_at before update on data_breaches for each row execute function trg_set_updated_at()');

        // Permisos: el administrador de cuenta lleva el registro de brechas y gestiona las solicitudes ARCO.
        $perms = [
            ['trb.data_breaches.read', 'read', 'Ver · data_breaches (Trabajadores y legajo)'],
            ['trb.data_breaches.create', 'create', 'Crear · data_breaches (Trabajadores y legajo)'],
            ['trb.data_breaches.update', 'update', 'Editar · data_breaches (Trabajadores y legajo)'],
        ];
        foreach ($perms as [$code, $action, $desc]) {
            DB::connection('platform')->insert(
                "insert into permissions (code, module_code, resource, action, is_sensitive, is_special, description) values (?, 'TRB', 'data_breaches', ?, true, false, ?) on conflict (code) do nothing",
                [$code, $action, $desc]
            );
        }
        $grants = [
            'account_admin' => ['trb.data_breaches.read', 'trb.data_breaches.create', 'trb.data_breaches.update', 'trb.arco_requests.create', 'trb.arco_requests.update'],
            'management' => ['trb.data_breaches.read'],
        ];
        foreach ($grants as $role => $codes) {
            DB::connection('platform')->insert(
                'insert into role_permissions (account_id, role_id, permission_id)
                 select null, r.id, p.id from roles r join permissions p on p.code = any(?::text[])
                  where r.account_id is null and r.code = ? on conflict do nothing',
                ['{'.implode(',', $codes).'}', $role]
            );
        }

        // Umbrales de la alerta por accesos anómalos (referenciales, a confirmar por la empresa o el abogado).
        $params = [
            ['sensitive_access_anomaly_workers', '20', 'integer', 'trabajadores', 'docs/10 §4 · Trabajadores distintos cuyos datos sensibles o médicos abre un mismo usuario dentro de la ventana para avisar como acceso anómalo (valor referencial a confirmar)'],
            ['sensitive_access_anomaly_minutes', '60', 'integer', 'minutos', 'docs/10 §4 · Ventana de tiempo para contar los trabajadores distintos de la alerta de acceso anómalo (valor referencial a confirmar)'],
        ];
        foreach ($params as [$key, $value, $type, $unit, $desc]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, 'Ley 29733; D.S. 016-2024-JUS (medidas de seguridad; a confirmar)', 'docs/10', '2026-01-01', true
                  where not exists (select 1 from legal_parameters where key = ? and company_id is null and deleted_at is null)",
                [$key, $value, $type, $unit, $desc, $key]
            );
        }

        // Conservación de cookie_consents: solo la tarea de conservación (rol dueño, con app.retention_purge) puede borrar.
        DB::statement('drop trigger if exists trg_cookie_consents_immutable on cookie_consents');
        DB::statement('create trigger trg_cookie_consents_immutable before update on cookie_consents for each statement execute function trg_forbid_mutation()');
        DB::statement('drop trigger if exists trg_cookie_consents_no_delete on cookie_consents');
        DB::statement("create trigger trg_cookie_consents_no_delete before delete on cookie_consents for each statement
                        when (current_setting('app.retention_purge', true) is distinct from 'cookie_consents') execute function trg_forbid_mutation()");
        DB::statement('drop policy if exists rls_cookie_consents_owner_purge_read on cookie_consents');
        DB::statement('create policy rls_cookie_consents_owner_purge_read on cookie_consents for select to escudo_owner using (true)');
        DB::statement('drop policy if exists rls_cookie_consents_owner_purge on cookie_consents');
        DB::statement('create policy rls_cookie_consents_owner_purge on cookie_consents for delete to escudo_owner using (true)');

        // Particiones mensuales con las filas que hubieran caído en la partición por defecto (rol dueño).
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.web_partition_month(p_table text, p_month date) RETURNS text
LANGUAGE plpgsql AS $$
DECLARE
  s date := date_trunc('month', p_month)::date;
  e date := (date_trunc('month', p_month) + interval '1 month')::date;
  n text := format('%s_%s', p_table, to_char(s, 'YYYYMM'));
  d text := p_table || '_default';
BEGIN
  IF p_table NOT IN ('site_events', 'chatbot_messages') THEN
    RAISE EXCEPTION 'tabla inválida' USING ERRCODE = 'invalid_parameter_value';
  END IF;
  IF to_regclass(n) IS NOT NULL THEN
    RETURN n;
  END IF;
  EXECUTE format('CREATE TABLE %I (LIKE %I INCLUDING DEFAULTS INCLUDING CONSTRAINTS)', n, p_table);
  -- Las filas del mes que estaban en la partición por defecto pasan a la nueva (la regla de inalterable es de la tabla padre).
  -- El dueño de la tabla solo salta RLS sin FORCE: se quita un momento en la partición por defecto y se vuelve a poner.
  EXECUTE format('ALTER TABLE %I NO FORCE ROW LEVEL SECURITY', d);
  EXECUTE format('WITH m AS (DELETE FROM %I WHERE occurred_at >= %L AND occurred_at < %L RETURNING *) INSERT INTO %I SELECT * FROM m', d, s, e, n);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', d);
  EXECUTE format('ALTER TABLE %I ATTACH PARTITION %I FOR VALUES FROM (%L) TO (%L)', p_table, n, s, e);
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', n);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', n);
  RETURN n;
END $$
SQL);
        DB::statement('REVOKE ALL ON FUNCTION public.web_partition_month(text, date) FROM PUBLIC');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.web_partition_month(text, date)');
        DB::statement('drop policy if exists rls_cookie_consents_owner_purge on cookie_consents');
        DB::statement('drop policy if exists rls_cookie_consents_owner_purge_read on cookie_consents');
        DB::statement('drop trigger if exists trg_cookie_consents_no_delete on cookie_consents');
        DB::statement('drop trigger if exists trg_cookie_consents_immutable on cookie_consents');
        DB::statement('create trigger trg_cookie_consents_immutable before update or delete on cookie_consents for each statement execute function trg_forbid_mutation()');
        DB::connection('platform')->statement("delete from legal_parameters where company_id is null and key in ('sensitive_access_anomaly_workers', 'sensitive_access_anomaly_minutes')");
        DB::connection('platform')->statement("delete from role_permissions rp using permissions p where rp.permission_id = p.id and p.code like 'trb.data_breaches.%'");
        DB::connection('platform')->statement("delete from permissions where code like 'trb.data_breaches.%'");
        DB::statement('drop table if exists data_breaches');
    }
};
