<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PSI RN-PSI-04: el cuestionario psicosocial y su baremo son configurables y versionados por empresa (psychosocial_instruments:
 * borrador → vigente → obsoleto; la encuesta copia las preguntas y el baremo de la versión vigente al crearse).
 * SCR-PSI-02: la evaluación ergonómica guarda la lista de verificación respondida (checklist). La lista de la R.M. 375-2008-TR es un
 * parámetro con vigencia (psi_rm375_checklist, a confirmar por el especialista): los factores de su Título II.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const RM375 = [
        'Manipulación manual de cargas',
        'Carga límite recomendada',
        'Posicionamiento postural en los puestos de trabajo',
        'Equipos y herramientas usados en los puestos de trabajo',
        'Condiciones ambientales de trabajo',
        'Organización del trabajo',
    ];

    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table if not exists psychosocial_instruments (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references companies(id),
                version_number integer not null,
                title text not null,
                status text not null default 'draft' check (status in ('draft', 'current', 'obsolete')),
                questions jsonb not null default '{}'::jsonb,
                cutoffs jsonb not null default '{"medium": 33.3, "high": 66.7}'::jsonb,
                change_summary text,
                published_at timestamptz,
                published_by uuid,
                created_at timestamptz not null default now(),
                created_by uuid,
                updated_at timestamptz not null default now(),
                updated_by uuid,
                deleted_at timestamptz
            )
        SQL);
        DB::statement('create unique index if not exists psychosocial_instruments_version on psychosocial_instruments (company_id, version_number) where deleted_at is null');
        DB::statement('alter table psychosocial_instruments enable row level security');
        DB::statement('alter table psychosocial_instruments force row level security');
        DB::statement('drop policy if exists rls_psychosocial_instruments_company on psychosocial_instruments');
        DB::statement("create policy rls_psychosocial_instruments_company on psychosocial_instruments using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                       with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
        foreach (['escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on psychosocial_instruments to {$role}");
            }
        }
        DB::statement('alter table psychosocial_surveys add column if not exists instrument_id uuid references psychosocial_instruments(id)');
        DB::statement("alter table ergonomic_assessments add column if not exists checklist jsonb not null default '[]'::jsonb");

        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'psi_rm375_checklist', ?, 'json', null, 'Factores de riesgo disergonómico de la lista de verificación (se marca si el factor está presente en el puesto)',
                    'R.M. 375-2008-TR, Título II · M-PSI SCR-PSI-02', 'M-PSI', date '2008-11-30', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'psi_rm375_checklist')",
            [json_encode(self::RM375, JSON_UNESCAPED_UNICODE)]);
    }

    public function down(): void
    {
        DB::statement('alter table psychosocial_surveys drop column if exists instrument_id');
        DB::statement('alter table ergonomic_assessments drop column if exists checklist');
        DB::statement('drop table if exists psychosocial_instruments');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'psi_rm375_checklist'");
    }
};
