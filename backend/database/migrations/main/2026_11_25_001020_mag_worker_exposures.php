<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-MAG RN-MAG-06 y §12 «Produce: exposiciones para EMO y VMD»: marca de exposición por trabajador (solo la marca, sin datos
 * médicos). Al aprobarse una medición sobre el límite se registran los trabajadores expuestos (la medición personal o los
 * activos del puesto, o del área, del punto) con el agente, el nivel, la medición y su periodo de vigencia
 * (monitoring_frequency_months desde la fecha medida). Al anularse o corregirse la medición, su marca queda anulada.
 * EMO la usa en los protocolos por puesto y en la orden del trabajador (y la orden puede ser «por exposición», M-EMO §5); VMD en
 * sus programas por exposición.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
create table if not exists worker_exposures (
    id uuid not null default uuidv7(),
    company_id uuid not null references companies(id),
    worker_id uuid not null,
    measurement_id uuid not null,
    monitoring_point_id uuid not null,
    agent_type_id uuid not null references agent_types(id),
    site_id uuid,
    area_id uuid,
    position_id uuid,
    level text not null default 'exceeds_limit' check (level in ('exceeds_limit', 'action_level')),
    ratio_to_limit numeric(10,4),
    personal boolean not null default false,
    measured_at timestamptz not null,
    valid_from date not null,
    valid_until date not null,
    status text not null default 'active' check (status in ('active', 'void')),
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    primary key (id),
    constraint uq_worker_exposures_measurement_worker unique (company_id, measurement_id, worker_id),
    constraint fk_worker_exposures_worker_id foreign key (company_id, worker_id) references workers (company_id, id),
    constraint fk_worker_exposures_measurement_id foreign key (company_id, measurement_id) references measurements (company_id, id),
    constraint fk_worker_exposures_monitoring_point_id foreign key (company_id, monitoring_point_id) references monitoring_points (company_id, id),
    check (valid_until >= valid_from)
)
SQL);
        DB::statement("comment on table worker_exposures is '[MAG] Marca de exposición del trabajador sobre el límite (RN-MAG-06), para EMO y VMD; sin datos médicos'");
        DB::statement('create index if not exists idx_worker_exposures_worker on worker_exposures (company_id, worker_id, valid_until)');
        DB::statement('create index if not exists idx_worker_exposures_agent on worker_exposures (company_id, agent_type_id, valid_until)');
        DB::statement('alter table worker_exposures enable row level security');
        DB::statement('alter table worker_exposures force row level security');
        $co = "NULLIF(current_setting('app.company_id', true), '')::uuid";
        DB::statement('drop policy if exists rls_worker_exposures_company on worker_exposures');
        DB::statement("create policy rls_worker_exposures_company on worker_exposures to escudo_app using (company_id = {$co}) with check (company_id = {$co})");
        DB::statement('grant select, insert, update on worker_exposures to escudo_app');
        DB::statement('grant select, insert, update on worker_exposures to escudo_definer');

        // M-EMO §5: la orden de examen también puede ser «por exposición».
        DB::statement('alter table medical_exam_orders drop constraint if exists medical_exam_orders_exam_type_check');
        DB::statement("alter table medical_exam_orders add constraint medical_exam_orders_exam_type_check check (exam_type in ('pre_employment', 'periodic', 'exit', 'return_to_work', 'position_change', 'exposure', 'other'))");

        // Marca de las mediciones ya aprobadas sobre el límite (mismos expuestos que mostraba /mag/exposed), empresa por empresa
        // con la conexión de la aplicación (RLS); vigencia: monitoring_frequency_months vigente a la fecha medida (12 sin parámetro).
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            $app = DB::connection('pgsql');
            $app->transaction(function () use ($app, $c) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                $app->insert(<<<'SQL'
insert into worker_exposures (company_id, worker_id, measurement_id, monitoring_point_id, agent_type_id, site_id, area_id, position_id, level,
                              ratio_to_limit, personal, measured_at, valid_from, valid_until)
select m.company_id, w.id, m.id, m.monitoring_point_id, m.agent_type_id, p.site_id, w.current_area_id, w.current_position_id, 'exceeds_limit',
       m.ratio_to_limit, m.worker_id is not null, m.measured_at, d.on_date,
       (d.on_date + make_interval(months => coalesce((select case when lp.value ~ '^[0-9]+$' then lp.value::int end from legal_parameters lp
            where lp.key = 'monitoring_frequency_months' and (lp.company_id is null or lp.company_id = m.company_id) and lp.valid_from <= d.on_date
              and (lp.valid_to is null or lp.valid_to >= d.on_date) and lp.deleted_at is null order by lp.company_id nulls last, lp.valid_from desc limit 1), 12)))::date
  from measurements m join monitoring_points p on p.id = m.monitoring_point_id
  cross join lateral (select (m.measured_at at time zone 'America/Lima')::date as on_date) d
  join workers w on w.company_id = m.company_id and w.deleted_at is null and w.status = 'active'
       and (w.id = m.worker_id or (m.worker_id is null and ((p.position_id is not null and w.current_position_id = p.position_id)
            or (p.position_id is null and p.area_id is not null and w.current_area_id = p.area_id))))
 where m.deleted_at is null and m.status = 'approved' and m.result = 'exceeds_limit'
on conflict do nothing
SQL);
            });
        }
    }

    public function down(): void
    {
        DB::statement('drop table if exists worker_exposures');
    }
};
