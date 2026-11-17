<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-CH5 SCR-CH5-03 «Banco de temas» (safety_talks.topic_bank_id, docs/modulos/M-CH5 §5): temas con contenido sugerido y las palabras
 * de los peligros del IPERC a los que se vinculan (RN-CH5-08). RN-CH5-07: parámetro talks_count_as_training (la empresa lo activa;
 * por defecto apagado).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table if not exists talk_topics (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references companies(id),
                title text not null,
                content text,
                hazard_keywords text[] not null default '{}',
                duration_minutes integer,
                is_active boolean not null default true,
                created_at timestamptz not null default now(),
                created_by uuid,
                updated_at timestamptz not null default now(),
                updated_by uuid,
                deleted_at timestamptz
            )
        SQL);
        DB::statement('alter table talk_topics enable row level security');
        DB::statement('alter table talk_topics force row level security');
        DB::statement('drop policy if exists rls_talk_topics_company on talk_topics');
        DB::statement("create policy rls_talk_topics_company on talk_topics using (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)
                       with check (company_id = (nullif(current_setting('app.company_id', true), ''))::uuid)");
        foreach (['escudo_app', 'escudo_definer'] as $role) {
            if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                DB::statement("grant select, insert, update on talk_topics to {$role}");
            }
        }
        DB::statement('alter table safety_talks add column if not exists topic_bank_id uuid references talk_topics(id)');
        DB::connection('platform')->insert(
            "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
             select null, 'talks_count_as_training', 'false', 'boolean', null, 'Las charlas de 5 minutos realizadas se agregan al registro de capacitación como sensibilización con su duración',
                    'M-CH5 RN-CH5-07', 'M-CH5', date '2012-04-25', true
              where not exists (select 1 from legal_parameters where company_id is null and key = 'talks_count_as_training')");
    }

    public function down(): void
    {
        DB::statement('alter table safety_talks drop column if exists topic_bank_id');
        DB::statement('drop table if exists talk_topics');
        DB::connection('platform')->delete("delete from legal_parameters where company_id is null and key = 'talks_count_as_training'");
    }
};
