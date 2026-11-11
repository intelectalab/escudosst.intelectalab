<?php

namespace App\Modules\SUS\Services;

use App\Core\Http\ApiProblem;
use App\Modules\INV\ScatCatalog;
use App\Modules\MRL\NormChanges;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contenido que valida un especialista SST/legal y que el dueño de la plataforma revisa y edita en Catálogos globales
 * (SCR-SUS-08), en lugar de estar en el código:
 *  - Valor propio de una empresa para un parámetro legal (legal_parameters con company_id): reemplaza al global solo
 *    para esa empresa (p. ej. familias de peligro prohibidas para menores).
 *  - Requisitos del catálogo por norma (legal_norm_requirements) que usa «Agregar desde el catálogo» de M-MRL: al
 *    agregarlos, cambiarlos o retirarlos, los requisitos de las empresas vinculados a la norma quedan «requiere revisión».
 *  - Catálogo SCAT (scat_catalog_items) del asistente de investigación (M-INV). Los códigos no cambian: las
 *    investigaciones ya hechas conservan lo que guardaron.
 * Todo cambio queda en la bitácora de la plataforma; todo texto nuevo o cambiado vuelve a «por confirmar».
 */
class SpecialistCatalogsService
{
    /** Prefijo de código por nivel SCAT. */
    public const SCAT_PREFIX = ['contact' => 'C', 'immediate_act' => 'A', 'immediate_condition' => 'S', 'basic_personal' => 'FP', 'basic_job' => 'FT', 'nac' => 'NAC'];

    public function __construct(
        private readonly AccountsService $accounts,
        private readonly CatalogsService $catalogs,
    ) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Valor propio de una empresa

    /** Empresas con valor propio vigente para el parámetro (y la lista de empresas para elegir). */
    public function overrides(string $paramId): array
    {
        $p = $this->globalParam($paramId);
        $companies = $this->db()->select("select id, coalesce(nullif(trade_name, ''), legal_name) as name from companies where deleted_at is null order by 2");
        $out = [];
        foreach ($companies as $c) {
            $row = $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->currentOverride($db, $c->id, $p->key));
            if ($row) {
                $out[] = ['company_id' => $c->id, 'company_name' => $c->name, 'id' => $row->id, 'value' => $row->value, 'valid_from' => $row->valid_from];
            }
        }

        return ['key' => $p->key, 'overrides' => $out, 'companies' => array_map(fn ($c) => ['id' => $c->id, 'name' => $c->name], $companies)];
    }

    public function setOverride(string $paramId, string $companyId, string $value, string $reason, string $actor): array
    {
        $p = $this->globalParam($paramId);
        $company = $this->company($companyId);
        $value = $this->catalogs->assertParameterValue($p, $value);

        [$id, $before] = $this->accounts->withCompany($company->id, function (ConnectionInterface $db) use ($p, $company, $value, $actor) {
            $current = $this->currentOverride($db, $company->id, $p->key);
            $today = $db->selectOne(
                'select id from legal_parameters where company_id = ? and key = ? and valid_from = current_date', [$company->id, $p->key]
            );
            if ($today) {
                $db->update('update legal_parameters set value = ?, valid_to = null, deleted_at = null, updated_by = ?, updated_at = now() where id = ?',
                    [$value, $actor, $today->id]);

                return [$today->id, $current?->value];
            }
            if ($current) {
                $db->update('update legal_parameters set valid_to = current_date - 1, updated_by = ?, updated_at = now() where id = ?', [$actor, $current->id]);
            }
            $id = $db->selectOne(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_norm_id, legal_reference, source, sector,
                                               valid_from, to_confirm, created_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, 'panel_company', ?, current_date, false, ?) returning id",
                [$company->id, $p->key, $value, $p->value_type, $p->unit, $p->description, $p->legal_norm_id, $p->legal_reference, $p->sector, $actor]
            )->id;

            return [$id, $current?->value];
        });
        $this->accounts->audit($actor, 'legal_parameter_company_set', 'legal_parameters', $id, [
            'key' => $p->key, 'company_id' => $company->id, 'company' => $company->name, 'before' => ['value' => $before], 'after' => ['value' => $value],
        ], $reason);

        return ['id' => $id, 'value' => $value];
    }

    public function removeOverride(string $paramId, string $companyId, ?string $reason, string $actor): void
    {
        $p = $this->globalParam($paramId);
        $company = $this->company($companyId);
        $row = $this->accounts->withCompany($company->id, function (ConnectionInterface $db) use ($p, $company, $actor) {
            $current = $this->currentOverride($db, $company->id, $p->key);
            if ($current) {
                // Desde hoy vuelve a regir el valor global; el historial de la empresa se conserva.
                $db->update(
                    'update legal_parameters set valid_to = case when valid_from < current_date then current_date - 1 else valid_to end,
                            deleted_at = case when valid_from >= current_date then now() else deleted_at end, updated_by = ?, updated_at = now() where id = ?',
                    [$actor, $current->id]
                );
            }

            return $current;
        });
        if (! $row) {
            throw ApiProblem::notFound('Esa empresa no tiene un valor propio para este parámetro.');
        }
        $this->accounts->audit($actor, 'legal_parameter_company_removed', 'legal_parameters', $row->id, [
            'key' => $p->key, 'company_id' => $company->id, 'company' => $company->name, 'before' => ['value' => $row->value],
        ], $reason);
    }

    private function currentOverride(ConnectionInterface $db, string $companyId, string $key): ?object
    {
        return $db->selectOne(
            'select id, value, valid_from from legal_parameters where company_id = ? and key = ? and deleted_at is null and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) order by valid_from desc limit 1',
            [$companyId, $key]
        );
    }

    private function globalParam(string $id): object
    {
        $p = Str::isUuid($id) ? $this->db()->selectOne('select * from legal_parameters where id = ? and company_id is null and deleted_at is null', [$id]) : null;

        return $p ?? throw ApiProblem::notFound('El parámetro no existe.');
    }

    private function company(string $id): object
    {
        $c = Str::isUuid($id) ? $this->db()->selectOne(
            "select id, coalesce(nullif(trade_name, ''), legal_name) as name from companies where id = ? and deleted_at is null", [$id]
        ) : null;

        return $c ?? throw ApiProblem::notFound('La empresa no existe.');
    }

    // ---------------------------------------------------------------- Requisitos del catálogo por norma

    public function normRequirements(string $normId): array
    {
        $norm = $this->norm($normId);

        return ['norm' => $norm, 'requirements' => array_map(fn ($r) => (array) $r + ['archived' => $r->deleted_at !== null, 'to_confirm' => (bool) $r->to_confirm],
            $this->db()->select(
                'select id, article, requirement, topic, applicability, evidence_expected, frequency, sort_order, to_confirm, confirmed_by, confirmed_at, deleted_at, updated_at
                   from legal_norm_requirements where legal_norm_id = ?
                  order by deleted_at is not null, sort_order, article nulls last', [$norm->id]
            ))];
    }

    public function createRequirement(string $normId, array $data, string $actor): array
    {
        $norm = $this->norm($normId);
        $fields = $this->requirementFields($data);
        $this->assertUniqueRequirement($norm->id, $fields, null);
        $order = (int) $this->db()->selectOne('select coalesce(max(sort_order), 0) as n from legal_norm_requirements where legal_norm_id = ?', [$norm->id])->n + 10;
        $id = $this->db()->table('legal_norm_requirements')->insertGetId($fields + [
            'legal_norm_id' => $norm->id, 'sort_order' => $order, 'to_confirm' => true, 'created_by' => $actor,
        ]);
        $this->accounts->audit($actor, 'legal_norm_requirement_created', 'legal_norm_requirements', $id, ['norm' => $norm->number, 'after' => $fields]);
        $flagged = app(NormChanges::class)->flag($norm->id, 'Se agregó un requisito al catálogo de la norma ('.$this->reqLabel($fields).').');

        return ['id' => $id, 'flagged' => $flagged];
    }

    public function updateRequirement(string $id, array $data, string $actor): array
    {
        $before = $this->requirement($id);
        $fields = $this->requirementFields($data);
        $changed = array_filter(array_keys($fields), fn ($k) => (string) ($before->{$k} ?? '') !== (string) ($fields[$k] ?? ''));
        if ($changed === []) {
            return ['id' => $id];
        }
        $this->assertUniqueRequirement($before->legal_norm_id, $fields, $id);
        $this->db()->table('legal_norm_requirements')->where('id', $id)->update($fields + [
            'to_confirm' => true, 'confirmed_by' => null, 'confirmed_at' => null, 'updated_by' => $actor, 'updated_at' => now(),
        ]);
        $this->accounts->audit($actor, 'legal_norm_requirement_updated', 'legal_norm_requirements', $id, [
            'before' => array_intersect_key((array) $before, $fields), 'after' => $fields,
        ]);
        $flagged = app(NormChanges::class)->flag($before->legal_norm_id, 'Se actualizó el requisito '.$this->reqLabel($fields).' del catálogo de la norma.');

        return ['id' => $id, 'flagged' => $flagged];
    }

    public function confirmRequirement(string $id, string $confirmedBy, string $actor): void
    {
        $r = $this->requirement($id);
        if (! $r->to_confirm) {
            throw $this->conflict('Este requisito ya fue confirmado.');
        }
        $this->db()->update('update legal_norm_requirements set to_confirm = false, confirmed_by = ?, confirmed_at = now(), updated_by = ?, updated_at = now() where id = ?',
            [$confirmedBy, $actor, $id]);
        $this->accounts->audit($actor, 'legal_norm_requirement_confirmed', 'legal_norm_requirements', $id, ['requirement' => $r->requirement, 'confirmed_by' => $confirmedBy]);
    }

    public function archiveRequirement(string $id, bool $archive, ?string $reason, string $actor): array
    {
        $r = $this->requirement($id, true);
        if ($archive === ($r->deleted_at !== null)) {
            throw $this->conflict($archive ? 'El requisito ya está archivado.' : 'El requisito no está archivado.');
        }
        if (! $archive) {
            $this->assertUniqueRequirement($r->legal_norm_id, ['article' => $r->article, 'requirement' => $r->requirement], $id);
        }
        $this->db()->update('update legal_norm_requirements set deleted_at = '.($archive ? 'now()' : 'null').', updated_by = ?, updated_at = now() where id = ?', [$actor, $id]);
        $this->accounts->audit($actor, $archive ? 'legal_norm_requirement_archived' : 'legal_norm_requirement_restored', 'legal_norm_requirements', $id,
            ['requirement' => $r->requirement], $reason);
        $label = $this->reqLabel((array) $r);
        $flagged = app(NormChanges::class)->flag($r->legal_norm_id, $archive
            ? "Se retiró el requisito {$label} del catálogo de la norma."
            : "Se volvió a publicar el requisito {$label} en el catálogo de la norma.");

        return ['id' => $id, 'flagged' => $flagged];
    }

    private function requirementFields(array $d): array
    {
        $t = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;

        return [
            'article' => $t($d['article'] ?? null), 'requirement' => trim($d['requirement']), 'topic' => $t($d['topic'] ?? null),
            'applicability' => $t($d['applicability'] ?? null), 'evidence_expected' => $t($d['evidence_expected'] ?? null), 'frequency' => $t($d['frequency'] ?? null),
        ];
    }

    private function assertUniqueRequirement(string $normId, array $f, ?string $id): void
    {
        if ($this->db()->selectOne(
            "select 1 from legal_norm_requirements where legal_norm_id = ? and coalesce(article, '') = ? and requirement = ? and deleted_at is null and id is distinct from ?::uuid",
            [$normId, (string) ($f['article'] ?? ''), $f['requirement'], $id]
        )) {
            throw $this->validation('requirement', 'Esa norma ya tiene ese requisito.');
        }
    }

    private function reqLabel(array $f): string
    {
        return '«'.($f['article'] ?: Str::limit((string) $f['requirement'], 60)).'»';
    }

    private function norm(string $id): object
    {
        $n = Str::isUuid($id) ? $this->db()->selectOne('select id, norm_type, number, title, status from legal_norms where id = ? and company_id is null and deleted_at is null', [$id]) : null;

        return $n ?? throw ApiProblem::notFound('La norma no existe.');
    }

    private function requirement(string $id, bool $withArchived = false): object
    {
        $r = Str::isUuid($id) ? $this->db()->selectOne(
            'select * from legal_norm_requirements where id = ?'.($withArchived ? '' : ' and deleted_at is null'), [$id]
        ) : null;

        return $r ?? throw ApiProblem::notFound('El requisito no existe.');
    }

    // ---------------------------------------------------------------- Catálogo SCAT

    public function scat(): array
    {
        return array_map(fn ($r) => (array) $r + ['is_active' => (bool) $r->is_active, 'to_confirm' => (bool) $r->to_confirm], $this->db()->select(
            "select id, level, code, label, explanation, category, sort_order, is_active, to_confirm, confirmed_by, confirmed_at, updated_at
               from scat_catalog_items
              order by array_position(array['contact', 'immediate_act', 'immediate_condition', 'basic_personal', 'basic_job', 'nac'], level), sort_order, code"
        ));
    }

    public function createScat(array $d, string $actor): array
    {
        $code = Str::upper(trim($d['code']));
        if (! preg_match('/^'.self::SCAT_PREFIX[$d['level']].'\d{2,3}$/', $code)) {
            throw $this->validation('code', 'El código de este nivel empieza con «'.self::SCAT_PREFIX[$d['level']].'» y sigue con 2 o 3 números (por ejemplo, '.self::SCAT_PREFIX[$d['level']].'21).');
        }
        if ($this->db()->selectOne('select 1 from scat_catalog_items where code = ?', [$code])) {
            throw $this->validation('code', 'Ya existe un elemento con ese código (los códigos no se reutilizan).');
        }
        $fields = $this->scatFields($d);
        $order = (int) $this->db()->selectOne('select coalesce(max(sort_order), 0) as n from scat_catalog_items where level = ?', [$d['level']])->n + 10;
        $id = $this->db()->table('scat_catalog_items')->insertGetId($fields + [
            'level' => $d['level'], 'code' => $code, 'sort_order' => $order, 'is_active' => $d['is_active'] ?? true, 'to_confirm' => true, 'created_by' => $actor,
        ]);
        $this->accounts->audit($actor, 'scat_item_created', 'scat_catalog_items', $id, ['code' => $code, 'level' => $d['level'], 'label' => $fields['label']]);
        ScatCatalog::flush();

        return ['id' => $id, 'code' => $code];
    }

    public function updateScat(string $id, array $d, string $actor): array
    {
        $before = $this->scatItem($id);
        $fields = $this->scatFields($d) + ['is_active' => $d['is_active'] ?? (bool) $before->is_active];
        $textChanged = $fields['label'] !== $before->label || (string) $fields['explanation'] !== (string) $before->explanation || (string) $fields['category'] !== (string) $before->category;
        $this->db()->table('scat_catalog_items')->where('id', $id)->update($fields + ($textChanged ? ['to_confirm' => true, 'confirmed_by' => null, 'confirmed_at' => null] : [])
            + ['updated_by' => $actor, 'updated_at' => now()]);
        $this->accounts->audit($actor, 'scat_item_updated', 'scat_catalog_items', $id, [
            'code' => $before->code,
            'before' => ['label' => $before->label, 'explanation' => $before->explanation, 'category' => $before->category, 'is_active' => (bool) $before->is_active],
            'after' => $fields,
        ]);
        ScatCatalog::flush();

        return ['id' => $id];
    }

    public function confirmScat(string $id, string $confirmedBy, string $actor): void
    {
        $i = $this->scatItem($id);
        if (! $i->to_confirm) {
            throw $this->conflict('Este elemento ya fue confirmado.');
        }
        $this->db()->update('update scat_catalog_items set to_confirm = false, confirmed_by = ?, confirmed_at = now(), updated_by = ?, updated_at = now() where id = ?',
            [$confirmedBy, $actor, $id]);
        $this->accounts->audit($actor, 'scat_item_confirmed', 'scat_catalog_items', $id, ['code' => $i->code, 'confirmed_by' => $confirmedBy]);
        ScatCatalog::flush();
    }

    /** Nuevo orden de los elementos de un nivel (todos sus ids, en el orden deseado). */
    public function reorderScat(string $level, array $ids, string $actor): void
    {
        $current = array_column($this->db()->select('select id from scat_catalog_items where level = ?', [$level]), 'id');
        if (count($ids) !== count($current) || array_diff($ids, $current) !== []) {
            throw $this->validation('ids', 'Envía todos los elementos del nivel, en el orden deseado.');
        }
        $this->db()->transaction(function () use ($ids, $actor) {
            foreach (array_values($ids) as $i => $id) {
                $this->db()->update('update scat_catalog_items set sort_order = ?, updated_by = ?, updated_at = now() where id = ?', [($i + 1) * 10, $actor, $id]);
            }
        });
        $codes = array_column($this->db()->select('select code from scat_catalog_items where level = ? order by sort_order', [$level]), 'code');
        $this->accounts->audit($actor, 'scat_reordered', 'scat_catalog_items', null, ['level' => $level, 'order' => $codes]);
        ScatCatalog::flush();
    }

    private function scatFields(array $d): array
    {
        $t = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;

        return ['label' => trim($d['label']), 'explanation' => $t($d['explanation'] ?? null), 'category' => $t($d['category'] ?? null)];
    }

    private function scatItem(string $id): object
    {
        $i = Str::isUuid($id) ? $this->db()->selectOne('select * from scat_catalog_items where id = ?', [$id]) : null;

        return $i ?? throw ApiProblem::notFound('El elemento del catálogo SCAT no existe.');
    }

    // ---------------------------------------------------------------- Ayudantes

    private function conflict(string $detail): ApiProblem
    {
        return new ApiProblem(409, 'conflict', 'No se puede realizar la acción', $detail);
    }

    private function validation(string $field, string $message): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $message, ['errors' => [$field => [$message]]]);
    }
}
