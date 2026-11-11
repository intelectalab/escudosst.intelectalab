<?php

namespace App\Modules\SUS\Services;

use App\Core\Http\ApiProblem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogos globales del panel del dueño (SCR-SUS-08, docs/03 §11): parámetros legales con vigencia, normas,
 * peligros base, paquetes sectoriales y feriados. Son datos de la plataforma (company_id NULL) que usan todas las empresas.
 *
 * Reglas:
 *  - Un parámetro legal nunca se sobrescribe: cambiar su valor crea una VERSIÓN nueva con fecha de inicio y cierra la
 *    anterior el día previo (las empresas usan el valor vigente a cada fecha). Toda versión nueva queda «a confirmar».
 *  - «Confirmar» lo hace un especialista SST/legal (docs/03 §3: todo valor legal queda a confirmar hasta revisión).
 *  - Nada se borra: normas derogadas, peligros y paquetes se desactivan o retiran. Los feriados sí se pueden quitar.
 *  - Todo cambio queda en la bitácora de la plataforma.
 */
class CatalogsService
{
    public function __construct(private readonly AccountsService $accounts) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    public function summary(): array
    {
        $db = $this->db();

        return [
            'legal_parameters' => [
                'total' => (int) $db->selectOne('select count(distinct key) as n from legal_parameters where company_id is null and deleted_at is null')->n,
                'to_confirm' => (int) $db->selectOne(
                    'select count(*) as n from legal_parameters where company_id is null and deleted_at is null and to_confirm
                        and valid_from <= current_date and (valid_to is null or valid_to >= current_date)'
                )->n,
            ],
            'legal_norms' => ['total' => (int) $db->selectOne('select count(*) as n from legal_norms where deleted_at is null')->n],
            'hazards' => ['total' => (int) $db->selectOne('select count(*) as n from hazard_catalog where company_id is null and deleted_at is null and is_active')->n],
            'sector_packs' => ['total' => (int) $db->selectOne("select count(*) as n from sector_packs where deleted_at is null and status = 'published'")->n],
            'holidays' => [
                'total' => (int) $db->selectOne("select count(*) as n from holidays where deleted_at is null and holiday_on >= date_trunc('year', current_date)")->n,
                'to_confirm' => (int) $db->selectOne('select count(*) as n from holidays where deleted_at is null and to_confirm and holiday_on >= current_date')->n,
            ],
        ];
    }

    // ---------------------------------------------------------------- Parámetros legales

    public function legalParameters(): array
    {
        return array_map(fn ($r) => (array) $r + ['is_current' => (bool) $r->is_current] + self::classify($r), $this->db()->select(
            'select p.id, p.key, p.value, p.value_type, p.unit, p.description, p.legal_reference, p.source, p.sector,
                    p.valid_from, p.valid_to, p.to_confirm, p.confirmed_by, p.confirmed_at,
                    n.id as norm_id, n.number as norm_number, n.title as norm_title,
                    (p.valid_from <= current_date and (p.valid_to is null or p.valid_to >= current_date)) as is_current
               from legal_parameters p left join legal_norms n on n.id = p.legal_norm_id
              where p.company_id is null and p.deleted_at is null
              order by p.key, p.valid_from desc'
        ));
    }

    /**
     * Clasifica un parámetro para mostrarlo y tratarlo bien:
     *  - area: sst | privacy (datos personales) | sales (venta de cursos) | web (sitio web) | billing (cobro de suscripciones)
     *  - kind: legal (lo valida un especialista SST/legal) | internal (valor de la plataforma, no legal)
     *          | pending (aún sin valor: «por definir» o tabla vacía) | reference (apunta a otro catálogo)
     *
     * @return array{area: string, kind: string}
     */
    public static function classify(object $p): array
    {
        $key = (string) $p->key;
        $area = match (true) {
            str_starts_with($key, 'web.') => 'web',
            str_starts_with($key, 'sus.') => 'billing',
            str_starts_with($key, 'arco_') || str_starts_with($key, 'data_breach') => 'privacy',
            $key === 'igv_rate' => 'sales',
            default => 'sst',
        };
        $value = trim((string) $p->value);
        $desc = mb_strtolower((string) $p->description);
        $kind = match (true) {
            $key === 'web.business_holidays' => 'reference',
            $value === 'por_definir' || str_contains($value, '"rows": []') || str_contains($value, '"rows":[]') => 'pending',
            str_contains($desc, 'no legal') || str_contains($desc, 'parámetro de plataforma') || str_contains($desc, 'valor interno')
                || str_contains($desc, 'términos vigentes del proveedor') => 'internal',
            default => 'legal',
        };

        return ['area' => $area, 'kind' => $kind];
    }

    public function newParameterVersion(string $id, array $data, string $actor): array
    {
        $p = $this->find('legal_parameters', $id, 'El parámetro no existe.');
        $this->assertValue($p->value_type, $data['value']);
        if ($data['valid_from'] <= $p->valid_from) {
            throw $this->validation('valid_from', 'La nueva vigencia debe empezar después del '.date('d/m/Y', strtotime($p->valid_from)).'.');
        }

        return $this->db()->transaction(function () use ($p, $data, $actor) {
            // Cierra la versión anterior el día previo al inicio de la nueva.
            $this->db()->update(
                'update legal_parameters set valid_to = (?::date - 1), updated_by = ? where id = ? and (valid_to is null or valid_to >= ?::date)',
                [$data['valid_from'], $actor, $p->id, $data['valid_from']]
            );
            $newId = $this->db()->selectOne(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_norm_id, legal_reference, source, sector,
                                               valid_from, to_confirm, created_by)
                 values (null, ?, ?, ?, ?, ?, ?, ?, 'panel', ?, ?, true, ?) returning id",
                [$p->key, trim($data['value']), $p->value_type, $p->unit, $p->description, $p->legal_norm_id,
                    $data['legal_reference'] ?? $p->legal_reference, $p->sector, $data['valid_from'], $actor]
            )->id;
            $this->accounts->audit($actor, 'legal_parameter_versioned', 'legal_parameters', $newId, [
                'key' => $p->key,
                'before' => ['value' => $p->value, 'valid_from' => $p->valid_from],
                'after' => ['value' => trim($data['value']), 'valid_from' => $data['valid_from']],
            ], $data['reason']);

            return ['id' => $newId];
        });
    }

    public function confirmParameter(string $id, string $confirmedBy, string $actor): void
    {
        $p = $this->find('legal_parameters', $id, 'El parámetro no existe.');
        if (! $p->to_confirm) {
            throw $this->conflict('Este valor ya fue confirmado.');
        }
        $kind = self::classify($p)['kind'];
        if ($kind === 'pending') {
            throw $this->conflict('Este parámetro aún no tiene valor: primero registra el valor con «Nueva versión».');
        }
        if ($kind === 'reference') {
            throw $this->conflict('Este parámetro solo apunta a otro catálogo; no tiene un valor que confirmar.');
        }
        $this->db()->update(
            'update legal_parameters set to_confirm = false, confirmed_by = ?, confirmed_at = now(), updated_by = ? where id = ?',
            [$confirmedBy, $actor, $p->id]
        );
        $this->accounts->audit($actor, 'legal_parameter_confirmed', 'legal_parameters', $p->id, [
            'key' => $p->key, 'value' => $p->value, 'confirmed_by' => $confirmedBy,
        ]);
    }

    // ---------------------------------------------------------------- Normas legales

    public function legalNorms(): array
    {
        return $this->db()->select(
            "select n.*, (select count(*) from legal_parameters p where p.legal_norm_id = n.id and p.deleted_at is null and p.company_id is null) as parameters_count
               from legal_norms n where n.deleted_at is null
              order by case n.norm_type when 'law' then 0 when 'supreme_decree' then 1 when 'ministerial_resolution' then 2 else 3 end, n.published_on desc nulls last, n.number"
        );
    }

    public function saveNorm(?string $id, array $data, string $actor): array
    {
        $fields = array_intersect_key($data, array_flip(['norm_type', 'number', 'title', 'issuing_entity', 'published_on', 'effective_on', 'repealed_on', 'scope', 'sector', 'summary', 'official_url', 'status']));
        $dup = $this->db()->selectOne('select id from legal_norms where norm_type = ? and number = ? and deleted_at is null and id is distinct from ?::uuid',
            [$fields['norm_type'], $fields['number'], $id]);
        if ($dup) {
            throw $this->validation('number', 'Ya existe una norma de ese tipo con ese número.');
        }

        if ($id === null) {
            $id = $this->db()->table('legal_norms')->insertGetId($fields + ['created_by' => $actor]);
            $this->accounts->audit($actor, 'legal_norm_created', 'legal_norms', $id, ['number' => $fields['number'], 'title' => $fields['title']]);
        } else {
            $before = $this->find('legal_norms', $id, 'La norma no existe.');
            $this->db()->table('legal_norms')->where('id', $id)->update($fields + ['updated_by' => $actor]);
            $this->accounts->audit($actor, 'legal_norm_updated', 'legal_norms', $id, [
                'number' => $fields['number'],
                'before' => array_intersect_key((array) $before, $fields),
                'after' => $fields,
            ]);
            // Novedades normativas (M-MRL RN-MRL-05): los requisitos vinculados de cada empresa quedan «requiere revisión».
            if ($reason = \App\Modules\MRL\NormChanges::reason($before, $fields)) {
                $flagged = app(\App\Modules\MRL\NormChanges::class)->flag($id, $reason);

                return ['id' => $id, 'flagged' => $flagged];
            }
        }

        return ['id' => $id];
    }

    // ---------------------------------------------------------------- Peligros base

    public function hazards(): array
    {
        return array_map(fn ($r) => array_merge((array) $r, ['typical_controls' => json_decode($r->typical_controls ?? '[]', true) ?: []]), $this->db()->select(
            'select id, code, hazard_family, hazard, risk, possible_consequence, typical_controls, sector, source, is_active
               from hazard_catalog where company_id is null and deleted_at is null order by hazard_family, code'
        ));
    }

    public function saveHazard(?string $id, array $data, string $actor): array
    {
        $fields = [
            'hazard_family' => $data['hazard_family'],
            'hazard' => trim($data['hazard']),
            'risk' => trim($data['risk']),
            'possible_consequence' => $data['possible_consequence'] ?? null,
            'typical_controls' => json_encode(array_values(array_filter(array_map(fn ($c) => trim((string) $c), $data['typical_controls'] ?? []))), JSON_UNESCAPED_UNICODE),
            'sector' => $data['sector'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];

        if ($id === null) {
            $code = Str::upper(trim($data['code']));
            if ($this->db()->selectOne('select 1 from hazard_catalog where company_id is null and code = ? and deleted_at is null', [$code])) {
                throw $this->validation('code', 'Ya existe un peligro con ese código.');
            }
            $id = $this->db()->table('hazard_catalog')->insertGetId($fields + ['code' => $code, 'source' => 'panel', 'created_by' => $actor]);
            $this->accounts->audit($actor, 'hazard_created', 'hazard_catalog', $id, ['code' => $code, 'hazard' => $fields['hazard']]);
        } else {
            $before = $this->find('hazard_catalog', $id, 'El peligro no existe.');
            $this->db()->table('hazard_catalog')->where('id', $id)->update($fields + ['updated_by' => $actor]);
            $this->accounts->audit($actor, 'hazard_updated', 'hazard_catalog', $id, [
                'code' => $before->code,
                'before' => ['hazard' => $before->hazard, 'risk' => $before->risk, 'is_active' => $before->is_active],
                'after' => ['hazard' => $fields['hazard'], 'risk' => $fields['risk'], 'is_active' => $fields['is_active']],
            ]);
        }

        return ['id' => $id];
    }

    // ---------------------------------------------------------------- Paquetes sectoriales

    public function sectorPacks(): array
    {
        $items = collect($this->db()->select(
            'select sector_pack_id, item_type, code, name from sector_pack_items where deleted_at is null order by sort_order, name'
        ))->groupBy('sector_pack_id');

        return array_map(fn ($p) => (array) $p + ['items' => $items->get($p->id, collect())->values()->all()], $this->db()->select(
            'select p.id, p.code, p.name, p.sector, p.description, p.legal_references, p.version_number, p.status
               from sector_packs p where p.deleted_at is null order by p.name'
        ));
    }

    public function setPackStatus(string $id, string $status, string $actor): void
    {
        $p = $this->find('sector_packs', $id, 'El paquete no existe.');
        $this->db()->update('update sector_packs set status = ?, updated_by = ? where id = ?', [$status, $actor, $id]);
        $this->accounts->audit($actor, 'sector_pack_status', 'sector_packs', $id, ['name' => $p->name, 'before' => ['status' => $p->status], 'after' => ['status' => $status]]);
    }

    // ---------------------------------------------------------------- Feriados

    public function holidays(): array
    {
        return $this->db()->select(
            "select id, holiday_on, name, scope, region_ubigeo, is_non_working, to_confirm
               from holidays where deleted_at is null and holiday_on >= date_trunc('year', current_date) - interval '1 year'
              order by holiday_on"
        );
    }

    public function createHoliday(array $data, string $actor): array
    {
        if ($this->db()->selectOne("select 1 from holidays where holiday_on = ? and scope = 'national' and deleted_at is null", [$data['holiday_on']])) {
            throw $this->validation('holiday_on', 'Ya hay un feriado nacional en esa fecha.');
        }
        $id = $this->db()->table('holidays')->insertGetId([
            'holiday_on' => $data['holiday_on'], 'name' => trim($data['name']), 'scope' => 'national',
            'is_non_working' => $data['is_non_working'] ?? true, 'to_confirm' => false, 'created_by' => $actor,
        ]);
        $this->accounts->audit($actor, 'holiday_created', 'holidays', $id, ['date' => $data['holiday_on'], 'name' => trim($data['name'])]);

        return ['id' => $id];
    }

    public function updateHoliday(string $id, array $data, string $actor): void
    {
        $h = $this->find('holidays', $id, 'El feriado no existe.');
        $this->db()->update('update holidays set to_confirm = ?, is_non_working = ?, updated_by = ? where id = ?',
            [$data['to_confirm'] ?? $h->to_confirm, $data['is_non_working'] ?? $h->is_non_working, $actor, $id]);
        $this->accounts->audit($actor, 'holiday_updated', 'holidays', $id, ['date' => $h->holiday_on, 'name' => $h->name]);
    }

    public function deleteHoliday(string $id, string $actor): void
    {
        $h = $this->find('holidays', $id, 'El feriado no existe.');
        $this->db()->update('update holidays set deleted_at = now(), updated_by = ? where id = ?', [$actor, $id]);
        $this->accounts->audit($actor, 'holiday_deleted', 'holidays', $id, ['date' => $h->holiday_on, 'name' => $h->name]);
    }

    // ---------------------------------------------------------------- Ayudantes

    private function find(string $table, string $id, string $notFound): object
    {
        $row = Str::isUuid($id) ? $this->db()->selectOne("select * from {$table} where id = ? and deleted_at is null", [$id]) : null;

        return $row ?? throw ApiProblem::notFound($notFound);
    }

    private function assertValue(string $type, string $value): void
    {
        $value = trim($value);
        $ok = match ($type) {
            'integer', 'hours', 'days', 'business_days', 'months', 'years' => preg_match('/^-?\d+$/', $value) === 1,
            'decimal' => is_numeric($value),
            'boolean' => in_array(strtolower($value), ['true', 'false'], true),
            'json' => json_validate($value),
            default => $value !== '',
        };
        if (! $ok) {
            $hint = match ($type) {
                'decimal' => 'un número (usa punto para decimales)',
                'boolean' => '«true» o «false»',
                'json' => 'un JSON válido',
                'text' => 'un texto',
                default => 'un número entero',
            };
            throw $this->validation('value', "El valor debe ser {$hint}.");
        }
    }

    private function conflict(string $detail): ApiProblem
    {
        return new ApiProblem(409, 'conflict', 'No se puede realizar la acción', $detail);
    }

    private function validation(string $field, string $message): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $message, ['errors' => [$field => [$message]]]);
    }
}
