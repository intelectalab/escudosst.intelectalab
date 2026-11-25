<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-WEB §11 y RN-WEB-10, §10:
 *  - site_not_found_paths: enlaces rotos detectados (404 registrados por la aplicación), agregados por ruta: solo la ruta sin
 *    parámetros, el dominio que enlazó y el conteo; sin IP, navegador ni datos de la persona.
 *  - site_web_vitals: mediciones de Core Web Vitals (LCP, INP, CLS) de visitantes reales del sitio público, solo con
 *    consentimiento analítico, por página y dispositivo (sin sesión ni IP); se conservan como los eventos analíticos
 *    (web.analytics_event_retention_months) y el reporte compara el percentil 75 con cwv_lcp_target, cwv_inp_target y
 *    cwv_cls_target.
 *  - site_settings.blog_feed_failures / blog_feed_failed_at: lecturas fallidas seguidas del blog de WordPress; al superar
 *    web.blog_feed_failure_threshold se avisa al Dueño de la plataforma (M-WEB §10).
 * Los umbrales son parámetros de plataforma «a confirmar» (to_confirm), no valores definitivos.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
create table if not exists site_not_found_paths (
    id uuid not null default uuidv7(),
    path text not null,
    hit_count integer not null default 0,
    first_seen_at timestamptz not null default now(),
    last_seen_at timestamptz not null default now(),
    last_referrer_host text,
    internal_referrer boolean not null default false,
    resolved_at timestamptz,
    resolved_by uuid references users (id),
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    primary key (id),
    constraint uq_site_not_found_paths_path unique (path),
    constraint ck_site_not_found_paths_path check (path ~ '^/' and length(path) <= 300),
    constraint ck_site_not_found_paths_ref check (last_referrer_host is null or length(last_referrer_host) <= 200)
)
SQL);
        DB::statement("comment on table site_not_found_paths is '[WEB] Enlaces rotos del sitio público: 404 agregados por ruta (sin IP ni datos personales), M-WEB §11'");
        DB::statement("comment on column site_not_found_paths.internal_referrer is 'el enlace roto está en una página del propio sitio'");
        DB::statement('create index if not exists idx_site_not_found_paths_hits on site_not_found_paths (resolved_at, hit_count desc)');

        DB::statement(<<<'SQL'
create table if not exists site_web_vitals (
    id uuid not null default uuidv7(),
    occurred_at timestamptz not null default now(),
    path text not null,
    device text not null check (device in ('desktop', 'mobile', 'tablet')),
    metric text not null check (metric in ('LCP', 'INP', 'CLS')),
    value numeric(12,4) not null check (value >= 0),
    primary key (id),
    constraint ck_site_web_vitals_path check (path ~ '^/' and length(path) <= 300)
)
SQL);
        DB::statement("comment on table site_web_vitals is '[WEB] Core Web Vitals medidos en el navegador de visitantes con consentimiento analítico (RN-WEB-10); sin sesión, IP ni navegador'");
        DB::statement("comment on column site_web_vitals.value is 'LCP e INP en milisegundos; CLS sin unidad'");
        DB::statement('create index if not exists idx_site_web_vitals_1 on site_web_vitals (occurred_at, metric, device)');

        foreach (['site_not_found_paths', 'site_web_vitals'] as $t) {
            DB::statement("alter table {$t} enable row level security");
            DB::statement("alter table {$t} force row level security");
            DB::statement("drop policy if exists rls_{$t}_platform on {$t}");
            DB::statement("create policy rls_{$t}_platform on {$t} to escudo_platform using (true) with check (true)");
            DB::statement("revoke all on {$t} from public, escudo_app");
        }
        DB::statement('grant select, insert, update on site_not_found_paths to escudo_platform');
        DB::statement('grant select, insert, delete on site_web_vitals to escudo_platform');
        DB::statement('drop trigger if exists trg_site_not_found_paths_updated_at on site_not_found_paths');
        DB::statement('create trigger trg_site_not_found_paths_updated_at before update on site_not_found_paths for each row execute function trg_set_updated_at()');

        DB::statement('alter table site_settings add column if not exists blog_feed_failures integer not null default 0');
        DB::statement('alter table site_settings add column if not exists blog_feed_failed_at timestamptz');
        DB::statement("comment on column site_settings.blog_feed_failures is 'lecturas fallidas seguidas del blog de WordPress (se reinicia al leerlo bien)'");

        $params = [
            ['web.blog_feed_failure_threshold', '3', 'integer', 'lecturas', 'Lecturas fallidas seguidas del blog de WordPress antes de avisar al Dueño de la plataforma (M-WEB §10; valor interno, configurable)'],
            ['web.cwv_lcp_target', '2500', 'integer', 'ms', 'RN-WEB-10 · Umbral de Largest Contentful Paint (percentil 75, móvil) de cada plantilla pública. Referencia: «bueno» según web.dev; a confirmar'],
            ['web.cwv_inp_target', '200', 'integer', 'ms', 'RN-WEB-10 · Umbral de Interaction to Next Paint (percentil 75, móvil). Referencia: «bueno» según web.dev; a confirmar'],
            ['web.cwv_cls_target', '0.1', 'decimal', null, 'RN-WEB-10 · Umbral de Cumulative Layout Shift (percentil 75, móvil). Referencia: «bueno» según web.dev; a confirmar'],
        ];
        foreach ($params as [$key, $value, $type, $unit, $desc]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, 'Parámetro de plataforma', 'M-WEB', '2026-01-01', true
                  where not exists (select 1 from legal_parameters where key = ? and company_id is null and deleted_at is null)",
                [$key, $value, $type, $unit, $desc, $key]
            );
        }
    }

    public function down(): void
    {
        DB::connection('platform')->statement("delete from legal_parameters where company_id is null and key in ('web.blog_feed_failure_threshold', 'web.cwv_lcp_target', 'web.cwv_inp_target', 'web.cwv_cls_target')");
        DB::statement('alter table site_settings drop column if exists blog_feed_failed_at');
        DB::statement('alter table site_settings drop column if exists blog_feed_failures');
        DB::statement('drop table if exists site_web_vitals');
        DB::statement('drop table if exists site_not_found_paths');
    }
};
