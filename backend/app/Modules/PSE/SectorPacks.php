<?php

namespace App\Modules\PSE;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Paquetes sectoriales (docs/modulos/M-PSE.md). El paquete es catálogo global (RN-PSE-01); al instalarlo cada ítem se copia
 * a la empresa como contenido propio y editable, y el mapa item_key → registro local queda en company_sector_packs.item_map.
 *
 *  - RN-PSE-02/08: idempotente por item_key: reinstalar o instalar otro paquete con el mismo ítem no duplica nada; si la
 *    empresa ya tenía un registro con el mismo código, se vincula.
 *  - RN-PSE-03: una actualización solo toca los ítems que la persona elige; los que la empresa editó piden confirmación.
 *  - RN-PSE-06: desinstalar no borra contenido: solo lo deja «no vinculado» al paquete.
 *
 * Tipos que se instalan: capacitaciones (training_topic), plantillas de inspección con sus ítems, tipos de permiso,
 * peligros del catálogo por familia y EPP. Los demás tipos de la ficha se muestran como «sin contenido instalable» hasta
 * que el paquete traiga sus datos.
 */
final class SectorPacks
{
    public const TYPE_LABEL = [
        'training_topic' => 'Capacitación', 'inspection_template' => 'Plantilla de inspección', 'permit_type' => 'Tipo de permiso',
        'hazard' => 'Peligros del catálogo', 'ppe_item' => 'EPP', 'legal_requirement' => 'Requisito legal', 'kpi' => 'Indicador',
        'document_template' => 'Formato de documento', 'credential_requirement' => 'Requisito de fotocheck',
    ];

    private const SUPPORTED = ['training_topic', 'inspection_template', 'permit_type', 'hazard', 'ppe_item'];

    private function company(): string
    {
        return app(CompanyContext::class)->companyId;
    }

    private function user(): string
    {
        return (string) auth()->id();
    }

    public function pack(string $id): object
    {
        $p = preg_match('/^[0-9a-f-]{36}$/i', $id) ? DB::selectOne("select * from sector_packs where id = ? and deleted_at is null and status = 'published'", [$id]) : null;

        return $p ?? throw ApiProblem::notFound('El paquete no existe o no está publicado.');
    }

    /** @return list<object> */
    public function items(string $packId): array
    {
        return DB::select('select * from sector_pack_items where sector_pack_id = ? and deleted_at is null order by sort_order, name', [$packId]);
    }

    /** Instalación vigente de un paquete en la empresa. */
    public function install(string $packId): ?object
    {
        return DB::selectOne("select * from company_sector_packs where sector_pack_id = ? and status <> 'uninstalled' and status <> 'removed' and deleted_at is null limit 1", [$packId]);
    }

    /** item_key → registro local, de todas las instalaciones vigentes (RN-PSE-08: un ítem compartido se instala una vez). */
    private function installedMap(): array
    {
        $out = [];
        foreach (DB::select("select item_map from company_sector_packs where status not in ('uninstalled', 'removed') and deleted_at is null") as $r) {
            $out += json_decode($r->item_map ?? '{}', true) ?: [];
        }

        return $out;
    }

    /** SCR-PSE-02: vista previa por ítem: qué pasará al instalarlo. */
    public function preview(string $packId): array
    {
        $map = $this->installedMap();
        $out = [];
        foreach ($this->items($packId) as $it) {
            $pl = json_decode($it->payload ?? '{}', true) ?: [];
            [$action, $detail] = match (true) {
                ! in_array($it->item_type, self::SUPPORTED, true) => ['unsupported', 'Este tipo de ítem aún no trae contenido para instalar.'],
                isset($map[$it->item_key]) => ['installed', 'Ya está instalado en tu empresa (no se duplica).'],
                default => $this->existing($it->item_type, $pl, $it) ? ['link', 'Tu empresa ya tiene uno con el mismo código: se vincula sin duplicar.'] : ['install', 'Se copia a tu empresa como contenido editable.'],
            };
            $out[] = ['id' => $it->id, 'key' => $it->item_key, 'type' => $it->item_type, 'type_label' => self::TYPE_LABEL[$it->item_type] ?? $it->item_type,
                'name' => $this->label($it, $pl), 'version' => (int) $it->version, 'action' => $action, 'detail' => $detail];
        }

        return $out;
    }

    /**
     * Instala el paquete con los ítems elegidos (todos si no se indica). Reinstalar un paquete ya instalado agrega los ítems
     * que falten. @return array{installed: list<array>, linked: list<array>, skipped: list<array>}
     */
    public function installPack(string $packId, ?array $itemIds = null): array
    {
        $p = $this->pack($packId);
        $current = $this->install($packId);
        $shared = $this->installedMap();
        $map = $current ? (json_decode($current->item_map ?? '{}', true) ?: []) : [];
        $res = ['installed' => [], 'linked' => [], 'skipped' => []];
        foreach ($this->items($packId) as $it) {
            if ($itemIds !== null && ! in_array($it->id, $itemIds, true)) {
                continue;
            }
            $pl = json_decode($it->payload ?? '{}', true) ?: [];
            $row = ['type' => self::TYPE_LABEL[$it->item_type] ?? $it->item_type, 'name' => $this->label($it, $pl)];
            if (! in_array($it->item_type, self::SUPPORTED, true)) {
                $res['skipped'][] = $row;

                continue;
            }
            if (isset($shared[$it->item_key])) {
                $map[$it->item_key] = $shared[$it->item_key];
                $res['linked'][] = $row;

                continue;
            }
            [$ids, $linked] = $this->materialize($it->item_type, $pl, $it, $packId);
            if ($ids === []) {
                $res['skipped'][] = $row + ['reason' => 'No se encontró el contenido base en el catálogo.'];

                continue;
            }
            $map[$it->item_key] = ['type' => $it->item_type, 'ids' => $ids, 'version' => (int) $it->version, 'at' => now()->toIso8601String(), 'sig' => $this->signature($it->item_type, $ids)];
            $res[$linked ? 'linked' : 'installed'][] = $row;
        }
        if ($current) {
            DB::update("update company_sector_packs set item_map = ?::jsonb, items_applied = ?::jsonb, updated_by = ?, updated_at = now() where id = ?",
                [json_encode($map, JSON_UNESCAPED_UNICODE), json_encode($res, JSON_UNESCAPED_UNICODE), $this->user(), $current->id]);
        } else {
            DB::insert("insert into company_sector_packs (company_id, sector_pack_id, pack_version, installed_version, applied_at, applied_by, items_applied, item_map, status, created_by, updated_by)
                        values (?, ?, ?, ?, now(), ?, ?::jsonb, ?::jsonb, 'installed', ?, ?)",
                [$this->company(), $packId, (int) $p->version_number, (int) $p->version_number, $this->user(), json_encode($res, JSON_UNESCAPED_UNICODE),
                    json_encode($map, JSON_UNESCAPED_UNICODE), $this->user(), $this->user()]);
        }

        return $res + ['pack' => $p->name];
    }

    /** SCR-PSE-03: ítems nuevos o cambiados desde la versión instalada; marca los que la empresa editó. */
    public function updates(string $packId): array
    {
        $inst = $this->install($packId) ?? throw ApiProblem::notFound('Tu empresa no tiene este paquete instalado.');
        $map = json_decode($inst->item_map ?? '{}', true) ?: [];
        $out = [];
        foreach ($this->items($packId) as $it) {
            if (! in_array($it->item_type, self::SUPPORTED, true)) {
                continue;
            }
            $pl = json_decode($it->payload ?? '{}', true) ?: [];
            $mine = $map[$it->item_key] ?? null;
            if ($mine && (int) $it->version <= (int) ($mine['version'] ?? 1)) {
                continue;
            }
            $out[] = ['id' => $it->id, 'key' => $it->item_key, 'type_label' => self::TYPE_LABEL[$it->item_type] ?? $it->item_type, 'name' => $this->label($it, $pl),
                'change' => $mine ? 'changed' : 'new', 'from_version' => $mine['version'] ?? null, 'to_version' => (int) $it->version,
                'edited' => $mine ? $this->edited($mine) : false];
        }

        return ['installed_version' => (int) ($inst->installed_version ?? $inst->pack_version), 'items' => $out];
    }

    /** Aplica la actualización a los ítems elegidos; un ítem editado por la empresa solo se toca si viene en $confirm. */
    public function applyUpdates(string $packId, array $itemIds, array $confirm = []): array
    {
        $p = $this->pack($packId);
        $inst = $this->install($packId) ?? throw ApiProblem::notFound('Tu empresa no tiene este paquete instalado.');
        $map = json_decode($inst->item_map ?? '{}', true) ?: [];
        $done = ['updated' => [], 'added' => [], 'kept' => []];
        foreach ($this->items($packId) as $it) {
            if (! in_array($it->id, $itemIds, true) || ! in_array($it->item_type, self::SUPPORTED, true)) {
                continue;
            }
            $pl = json_decode($it->payload ?? '{}', true) ?: [];
            $row = ['type' => self::TYPE_LABEL[$it->item_type], 'name' => $this->label($it, $pl)];
            $mine = $map[$it->item_key] ?? null;
            if (! $mine) {
                [$ids] = $this->materialize($it->item_type, $pl, $it, $packId);
                if ($ids) {
                    $map[$it->item_key] = ['type' => $it->item_type, 'ids' => $ids, 'version' => (int) $it->version, 'at' => now()->toIso8601String(), 'sig' => $this->signature($it->item_type, $ids)];
                    $done['added'][] = $row;
                }

                continue;
            }
            if ($this->edited($mine) && ! in_array($it->id, $confirm, true)) {
                $done['kept'][] = $row + ['reason' => 'La editó tu empresa: confirma para reemplazarla.'];

                continue;
            }
            $this->refresh($it->item_type, $pl, $it, $mine['ids']);
            $map[$it->item_key] = ['version' => (int) $it->version, 'at' => now()->toIso8601String(), 'sig' => $this->signature($it->item_type, $mine['ids'])] + $mine;
            $done['updated'][] = $row;
        }
        $pending = array_filter($this->updates($packId)['items'], fn ($u) => ! in_array($u['id'], $itemIds, true) || $u['edited']);
        DB::update("update company_sector_packs set item_map = ?::jsonb, installed_version = ?, update_available = ?, status = ?, updated_by = ?, updated_at = now() where id = ?",
            [json_encode($map, JSON_UNESCAPED_UNICODE), $pending ? (int) ($inst->installed_version ?? $inst->pack_version) : (int) $p->version_number,
                $pending ? 'true' : 'false', $pending ? 'update_available' : 'updated', $this->user(), $inst->id]);

        return $done;
    }

    /** RN-PSE-06: desinstalar no borra el contenido; las plantillas dejan de estar ligadas al paquete. */
    public function uninstall(string $packId): void
    {
        $inst = $this->install($packId) ?? throw ApiProblem::notFound('Tu empresa no tiene este paquete instalado.');
        $map = json_decode($inst->item_map ?? '{}', true) ?: [];
        $templates = [];
        foreach ($map as $m) {
            if (($m['type'] ?? '') === 'inspection_template') {
                $templates = [...$templates, ...($m['ids'] ?? [])];
            }
        }
        if ($templates) {
            DB::update('update inspection_templates set sector_pack_id = null, updated_by = ?, updated_at = now() where id = any(?::uuid[]) and company_id = ?',
                [$this->user(), '{'.implode(',', $templates).'}', $this->company()]);
        }
        DB::update("update company_sector_packs set status = 'uninstalled', uninstalled_at = now(), update_available = false, updated_by = ?, updated_at = now() where id = ?", [$this->user(), $inst->id]);
    }

    /** §11: inventario del contenido sectorial instalado y su versión. */
    public function inventory(): array
    {
        $rows = [];
        foreach (DB::select("select c.*, p.name as pack_name, p.version_number as pack_latest from company_sector_packs c join sector_packs p on p.id = c.sector_pack_id
                              where c.deleted_at is null order by c.applied_at") as $c) {
            $items = DB::select('select item_key, name, item_type, payload, version from sector_pack_items where sector_pack_id = ? and deleted_at is null', [$c->sector_pack_id]);
            $byKey = [];
            foreach ($items as $i) {
                $byKey[$i->item_key] = $i;
            }
            foreach (json_decode($c->item_map ?? '{}', true) ?: [] as $key => $m) {
                $i = $byKey[$key] ?? null;
                $rows[] = ['pack' => $c->pack_name, 'status' => $c->status, 'type' => self::TYPE_LABEL[$m['type'] ?? ''] ?? ($m['type'] ?? ''),
                    'name' => $i ? $this->label($i, json_decode($i->payload ?? '{}', true) ?: []) : $key, 'installed_version' => $m['version'] ?? null,
                    'latest_version' => $i ? (int) $i->version : null, 'records' => count($m['ids'] ?? []), 'edited' => $this->edited($m)];
            }
        }

        return $rows;
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    private function label(object $it, array $pl): string
    {
        if ($it->item_type === 'permit_type' && empty($it->name) && ! empty($pl['permit_type_code'])) {
            $n = DB::selectOne('select name from permit_types where company_id is null and code = ? and deleted_at is null limit 1', [$pl['permit_type_code']])?->name;
            if ($n) {
                return $n;
            }
        }
        return (string) ($pl['topic'] ?? null ?: $it->name ?: ($pl['inspection_template_code'] ?? $pl['permit_type_code'] ?? implode(', ', (array) ($pl['hazard_families'] ?? [])) ?: $it->item_key));
    }

    /** ¿La empresa ya tiene un registro equivalente (mismo código o título)? */
    private function existing(string $type, array $pl, object $it): ?string
    {
        return match ($type) {
            'training_topic' => DB::selectOne('select id from trainings where company_id = ? and title = ? and deleted_at is null limit 1', [$this->company(), (string) ($pl['topic'] ?? $it->name)])?->id,
            'inspection_template' => DB::selectOne('select id from inspection_templates where company_id = ? and code = ? and deleted_at is null order by version_number desc limit 1', [$this->company(), (string) ($pl['inspection_template_code'] ?? $it->code)])?->id,
            'permit_type' => DB::selectOne('select id from permit_types where company_id = ? and code = ? and deleted_at is null limit 1', [$this->company(), (string) ($pl['permit_type_code'] ?? $it->code)])?->id,
            'ppe_item' => DB::selectOne('select id from ppe_items where company_id = ? and code = ? and deleted_at is null limit 1', [$this->company(), (string) ($pl['code'] ?? $it->code)])?->id,
            default => null,
        };
    }

    /** Crea (o vincula) el contenido local de un ítem. @return array{0: list<string>, 1: bool} ids locales y si solo se vinculó */
    private function materialize(string $type, array $pl, object $it, string $packId): array
    {
        if ($id = $this->existing($type, $pl, $it)) {
            return [[$id], true];
        }
        $c = $this->company();
        $u = $this->user();
        switch ($type) {
            case 'training_topic':
                $kind = in_array($pl['training_type'] ?? '', ['induction', 'specific', 'general', 'refresher'], true) ? $pl['training_type'] : 'specific';
                $id = DB::selectOne("insert into trainings (company_id, title, training_type, topic, modality, duration_minutes, status, created_by, updated_by)
                                     values (?, ?, ?, ?, 'in_person', 60, 'draft', ?, ?) returning id", [$c, (string) ($pl['topic'] ?? $it->name), $kind, (string) ($pl['topic'] ?? $it->name), $u, $u])->id;

                return [[$id], false];
            case 'inspection_template':
                $g = DB::selectOne('select * from inspection_templates where company_id is null and code = ? and deleted_at is null order by version_number desc limit 1', [(string) ($pl['inspection_template_code'] ?? $it->code)]);
                if (! $g) {
                    return [[], false];
                }
                $id = DB::selectOne("insert into inspection_templates (company_id, code, name, inspection_type, sector, sector_pack_id, version_number, description, scoring_method, requires_signature, status, created_by, updated_by)
                                     values (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'active', ?, ?) returning id",
                    [$c, $g->code, $g->name, $g->inspection_type, $g->sector, $packId, $g->description, $g->scoring_method, $g->requires_signature ? 'true' : 'false', $u, $u])->id;
                $this->copyTemplateItems($g->id, $id);

                return [[$id], false];
            case 'permit_type':
                $g = DB::selectOne('select * from permit_types where company_id is null and code = ? and deleted_at is null limit 1', [(string) ($pl['permit_type_code'] ?? $it->code)]);
                if (! $g) {
                    return [[], false];
                }
                $id = DB::selectOne("insert into permit_types (company_id, code, name, category, validity_hours, requires_ats, requires_gas_test, checklist, approval_chain, required_credentials, legal_reference, is_active, created_by, updated_by)
                                     values (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?, true, ?, ?) returning id",
                    [$c, $g->code, $g->name, $g->category, $g->validity_hours, $g->requires_ats ? 'true' : 'false', $g->requires_gas_test ? 'true' : 'false', $g->checklist, $g->approval_chain,
                        $g->required_credentials, $g->legal_reference, $u, $u])->id;

                return [[$id], false];
            case 'hazard':
                $ids = [];
                foreach (DB::select('select * from hazard_catalog where company_id is null and is_active and deleted_at is null and hazard_family = any(?::text[])', ['{'.implode(',', (array) ($pl['hazard_families'] ?? [])).'}']) as $g) {
                    $ids[] = DB::selectOne('select id from hazard_catalog where company_id = ? and code = ? and deleted_at is null', [$c, $g->code])?->id
                        ?? DB::selectOne("insert into hazard_catalog (company_id, code, hazard_family, hazard, risk, possible_consequence, typical_controls, sector, source, is_active, created_by, updated_by)
                                           values (?, ?, ?, ?, ?, ?, ?, ?, 'sector_pack', true, ?, ?) returning id",
                            [$c, $g->code, $g->hazard_family, $g->hazard, $g->risk, $g->possible_consequence, $g->typical_controls, $g->sector, $u, $u])->id;
                }

                return [$ids, false];
            case 'ppe_item':
                if (empty($pl['name'])) {
                    return [[], false];
                }
                $id = DB::selectOne("insert into ppe_items (company_id, code, name, category, created_by, updated_by) values (?, ?, ?, ?, ?, ?) returning id",
                    [$c, (string) ($pl['code'] ?? $it->code ?? $it->item_key), (string) $pl['name'], (string) ($pl['category'] ?? 'other'), $u, $u])->id;

                return [[$id], false];
        }

        return [[], false];
    }

    /** Reemplaza el contenido local con el del paquete (solo cuando la persona lo eligió). */
    private function refresh(string $type, array $pl, object $it, array $ids): void
    {
        if ($type === 'inspection_template' && $ids) {
            $g = DB::selectOne('select * from inspection_templates where company_id is null and code = ? and deleted_at is null order by version_number desc limit 1', [(string) ($pl['inspection_template_code'] ?? $it->code)]);
            if ($g) {
                DB::update('update inspection_templates set name = ?, description = ?, updated_by = ?, updated_at = now() where id = ? and company_id = ?', [$g->name, $g->description, $this->user(), $ids[0], $this->company()]);
                // Los ítems se actualizan por su código (las respuestas de inspecciones pasadas siguen apuntando a ellos);
                // los que el paquete ya no trae quedan archivados.
                DB::insert("insert into inspection_template_items (company_id, template_id, section, item_code, question, answer_type, weight, is_critical, requires_photo_on_fail, legal_reference, sort_order, created_by, updated_by)
                            select ?, ?, section, item_code, question, answer_type, weight, is_critical, requires_photo_on_fail, legal_reference, sort_order, ?, ?
                              from inspection_template_items where template_id = ? and deleted_at is null
                            on conflict (template_id, item_code) do update set section = excluded.section, question = excluded.question, answer_type = excluded.answer_type,
                               weight = excluded.weight, is_critical = excluded.is_critical, requires_photo_on_fail = excluded.requires_photo_on_fail,
                               legal_reference = excluded.legal_reference, sort_order = excluded.sort_order, deleted_at = null, updated_by = excluded.updated_by, updated_at = now()",
                    [$this->company(), $ids[0], $this->user(), $this->user(), $g->id]);
                DB::update('update inspection_template_items set deleted_at = now() where template_id = ? and company_id = ? and deleted_at is null
                              and item_code not in (select item_code from inspection_template_items where template_id = ? and deleted_at is null)', [$ids[0], $this->company(), $g->id]);
            }
        } elseif ($type === 'training_topic' && $ids) {
            DB::update('update trainings set title = ?, topic = ?, updated_by = ?, updated_at = now() where id = ? and company_id = ?', [(string) ($pl['topic'] ?? $it->name), (string) ($pl['topic'] ?? $it->name), $this->user(), $ids[0], $this->company()]);
        }
    }

    private function copyTemplateItems(string $from, string $to): void
    {
        DB::insert("insert into inspection_template_items (company_id, template_id, section, item_code, question, answer_type, weight, is_critical, requires_photo_on_fail, legal_reference, sort_order, created_by, updated_by)
                    select ?, ?, section, item_code, question, answer_type, weight, is_critical, requires_photo_on_fail, legal_reference, sort_order, ?, ?
                      from inspection_template_items where template_id = ? and deleted_at is null", [$this->company(), $to, $this->user(), $this->user(), $from]);
    }

    /** ¿La empresa editó el contenido después de instalarlo? Se compara la huella del contenido de entonces con la de ahora. */
    private function edited(array $m): bool
    {
        return isset($m['sig'], $m['ids']) && $m['sig'] !== $this->signature($m['type'] ?? '', $m['ids']);
    }

    /** Huella del contenido local de un ítem (lo que la persona puede editar). */
    private function signature(string $type, array $ids): ?string
    {
        if (! $ids) {
            return null;
        }
        $in = '{'.implode(',', $ids).'}';
        $row = match ($type) {
            'inspection_template' => DB::selectOne("select string_agg(coalesce(t.name, '') || '|' || coalesce(t.description, '') || '|' || coalesce((select string_agg(i.question, '~' order by i.sort_order, i.item_code)
                                        from inspection_template_items i where i.template_id = t.id and i.deleted_at is null), ''), '#') as v from inspection_templates t where t.id = any(?::uuid[])", [$in]),
            'training_topic' => DB::selectOne("select string_agg(coalesce(title, '') || '|' || coalesce(description, ''), '#') as v from trainings where id = any(?::uuid[])", [$in]),
            'permit_type' => DB::selectOne("select string_agg(coalesce(name, '') || '|' || coalesce(checklist::text, ''), '#') as v from permit_types where id = any(?::uuid[])", [$in]),
            'ppe_item' => DB::selectOne("select string_agg(coalesce(name, ''), '#') as v from ppe_items where id = any(?::uuid[])", [$in]),
            default => null,
        };

        return $row ? md5((string) $row->v) : null;
    }
}
