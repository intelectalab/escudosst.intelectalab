<?php

use App\Modules\HOM\VendorScoring;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-HOM: un solo cálculo de puntaje (VendorScoring) para «Calificar», la edición de la evaluación y la autoevaluación del
 * portal, con puntajes de 0 a 5 por criterio y calificación A/B/C.
 *  - Parámetros «a confirmar»: vendor_rating_a_min_score (total mínimo para la calificación A; entre el mínimo de la
 *    plantilla y este valor es B «aprobado con condiciones») y vendor_passing_score (mínimo cuando la plantilla no tiene uno).
 *  - Una sola forma de «scores»: {criterio: {score, comment?, evidence_file_id?, by?}} (antes «Calificar» guardaba solo el número).
 *  - Las evaluaciones con todos sus puntajes de 0 a 5 recalculan total y calificación (la edición de la evaluación los
 *    promediaba sin escalar: totales de 0 a 5 contra mínimos de 0 a 100). El estado (decisión de gerencia) no cambia.
 *    Las que tienen puntajes fuera de 0 a 5 (autoevaluaciones antiguas sin tope) conservan su total para que SST las
 *    vuelva a calificar.
 * Con RLS por empresa, se recorre empresa por empresa con la conexión de la aplicación.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const PARAMS = [
        ['vendor_rating_a_min_score', '85', 'decimal', 'puntos', 'Puntaje total mínimo (de 100) para la calificación A de un proveedor; desde el mínimo de la plantilla hasta este valor es B «aprobado con condiciones»', 'M-HOM §5 rating A/B/C', 'M-HOM'],
        ['vendor_passing_score', '70', 'decimal', 'puntos', 'Puntaje total mínimo (de 100) para aprobar la homologación cuando la plantilla no indica uno', 'M-HOM RN-HOM-02', 'M-HOM'],
    ];

    public function up(): void
    {
        foreach (self::PARAMS as [$key, $value, $type, $unit, $desc, $ref, $src]) {
            DB::connection('platform')->insert(
                "insert into legal_parameters (company_id, key, value, value_type, unit, description, legal_reference, source, valid_from, to_confirm)
                 select null, ?, ?, ?, ?, ?, ?, ?, date '2012-04-25', true where not exists (select 1 from legal_parameters where company_id is null and key = ?)",
                [$key, $value, $type, $unit, $desc, $ref, $src, $key]);
        }
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            $app = DB::connection('pgsql');
            $app->transaction(function () use ($app, $c) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                foreach ($app->select("select id, template_id, scores, status from vendor_evaluations where scores <> '{}'::jsonb") as $e) {
                    $scores = json_decode((string) $e->scores, true) ?: [];
                    $shaped = [];
                    $inRange = true;
                    foreach ($scores as $name => $v) {
                        $entry = is_array($v) ? $v : ['score' => $v];
                        $sc = $entry['score'] ?? null;
                        if (is_numeric($sc)) {
                            $entry['score'] = (float) $sc;
                            $inRange = $inRange && $sc >= 0 && $sc <= VendorScoring::MAX;
                        }
                        $shaped[$name] = $entry;
                    }
                    $sets = ['scores = ?::jsonb'];
                    $bind = [json_encode($shaped, JSON_UNESCAPED_UNICODE)];
                    if ($inRange) {
                        $t = VendorScoring::template($e->template_id, $app);
                        if ($t['criteria'] !== [] && array_diff(array_column($t['criteria'], 'name'), array_keys($shaped)) === []) {
                            $total = VendorScoring::total($shaped, $t['criteria']);
                            array_push($sets, 'total_score = ?', 'rating = ?');
                            array_push($bind, $total, VendorScoring::rating($total, $t['passing']));
                        }
                    }
                    $bind[] = $e->id;
                    $app->update('update vendor_evaluations set '.implode(', ', $sets).' where id = ?', $bind);
                }
            });
        }
    }

    public function down(): void {}
};
