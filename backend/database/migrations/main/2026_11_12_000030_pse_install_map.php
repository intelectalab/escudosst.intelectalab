<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paquetes sectoriales (M-PSE §5, §9, RN-PSE-02/03/06/08):
 *  - sector_pack_items: item_key (identidad estable del ítem entre versiones y paquetes) y version del ítem.
 *  - company_sector_packs: installed_version, item_map (item_key → {id local, versión instalada, tipo}), update_available,
 *    uninstalled_at y los estados de la ficha (installed → update_available → updated; installed → uninstalled).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Empresas con instalaciones, leídas antes de cualquier cambio de tabla (después quedarían bloqueadas hasta el final).
        $companies = DB::connection('platform')->select('select id as company_id from companies');
        DB::statement('alter table sector_pack_items add column if not exists item_key text');
        DB::statement('alter table sector_pack_items add column if not exists version integer not null default 1');
        DB::statement("update sector_pack_items set item_key = item_type || ':' || coalesce(nullif(code, ''), nullif(payload->>'inspection_template_code', ''), nullif(payload->>'permit_type_code', ''), nullif(payload->>'topic', ''), name, id::text) where item_key is null");

        DB::statement("alter table company_sector_packs add column if not exists installed_version integer");
        DB::statement("alter table company_sector_packs add column if not exists item_map jsonb not null default '{}'::jsonb");
        DB::statement('alter table company_sector_packs add column if not exists update_available boolean not null default false');
        DB::statement('alter table company_sector_packs add column if not exists uninstalled_at timestamptz');
        DB::statement('alter table company_sector_packs drop constraint if exists company_sector_packs_status_check');
        // Las instalaciones anteriores pasan a los estados de la ficha. Se hace en esta misma conexión (otra conexión
        // esperaría a que termine este cambio de tabla); la RLS se cumple fijando la empresa de cada fila.
        foreach ($companies as $c) {
            DB::select("select set_config('app.company_id', ?, true)", [$c->company_id]);
            DB::update("update company_sector_packs set status = case status when 'active' then 'installed' when 'removed' then 'uninstalled' else status end,
                            installed_version = coalesce(installed_version, pack_version) where company_id = ?", [$c->company_id]);
        }
        DB::statement("alter table company_sector_packs add constraint company_sector_packs_status_check check (status = any (array['installed', 'update_available', 'updated', 'uninstalled', 'active', 'removed']))");
    }

    public function down(): void
    {
        DB::statement('alter table company_sector_packs drop constraint if exists company_sector_packs_status_check');
        DB::statement("alter table company_sector_packs add constraint company_sector_packs_status_check check (status = any (array['active', 'removed', 'installed', 'update_available', 'updated', 'uninstalled']))");
    }
};
