<?php

namespace App\Modules\PSI;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * Encuesta psicosocial anónima (M-PSI):
 *  - Cuestionario base propio de EscudoSST (instrumento «custom») mientras no se licencie un instrumento validado
 *    (ISTAS21/CoPsoQ, pregunta abierta de M-PSI §14). Escala 0–4; más puntaje = más riesgo.
 *  - Enlace público firmado por encuesta (sin sesión). El trabajador escribe su documento solo para comprobar que
 *    pertenece a la empresa y no responder dos veces: se guarda un hash (HMAC) por encuesta, nunca el documento ni
 *    el worker_id (RN-PSI-01).
 *  - Resultados solo al cerrar (RN-PSI-05) y sin desagregar grupos con menos del mínimo de respuestas (RN-PSI-02).
 */
final class PsychosocialSurvey
{
    public const SCALE = ['Nunca', 'Casi nunca', 'A veces', 'Casi siempre', 'Siempre'];

    /** Cuestionario base: 6 dimensiones, 3 preguntas cada una (redacción propia). */
    public static function defaultQuestions(): array
    {
        $dims = [
            ['demands', 'Carga y ritmo de trabajo', [
                '¿Tienes más trabajo del que puedes hacer en tu horario?',
                '¿Debes trabajar muy rápido o con plazos muy ajustados?',
                '¿Te llevas trabajo pendiente o preocupaciones del trabajo a casa?']],
            ['control', 'Poco margen para decidir', [
                '¿Sientes que no puedes opinar sobre cómo se hace tu trabajo?',
                '¿Te resulta difícil tomar un descanso corto cuando lo necesitas?',
                '¿Tu trabajo es repetitivo y sin posibilidad de aprender cosas nuevas?']],
            ['support', 'Falta de apoyo', [
                '¿Te falta ayuda de tu jefe cuando tienes un problema en el trabajo?',
                '¿Te falta ayuda de tus compañeros cuando la necesitas?',
                '¿Recibes poca información clara sobre lo que se espera de ti?']],
            ['emotional', 'Desgaste emocional', [
                '¿Tu trabajo te deja agotado emocionalmente?',
                '¿Debes esconder lo que sientes frente a clientes, jefes o compañeros?',
                '¿Atiendes situaciones tensas o conflictivas con frecuencia?']],
            ['insecurity', 'Inseguridad laboral', [
                '¿Te preocupa perder tu trabajo o que no te renueven?',
                '¿Te preocupa que te cambien de horario, puesto o funciones sin consultarte?',
                '¿Sientes que tu esfuerzo no se reconoce de forma justa?']],
            ['conflict', 'Trato y convivencia', [
                '¿Has vivido o visto burlas, gritos o maltrato en tu trabajo?',
                '¿Hay conflictos entre compañeros que no se resuelven?',
                '¿Sientes que se trata a algunas personas de forma injusta?']],
        ];

        return ['scale' => self::SCALE, 'dimensions' => array_map(fn ($d) => [
            'code' => $d[0], 'name' => $d[1],
            'items' => array_map(fn ($t, $i) => ['id' => "{$d[0]}_".($i + 1), 'text' => $t], $d[2], array_keys($d[2])),
        ], $dims)];
    }

    public static function signature(string $companyId, string $surveyId): string
    {
        return substr(hash_hmac('sha256', "psi|{$companyId}|{$surveyId}", (string) config('app.key')), 0, 20);
    }

    public static function publicUrl(string $companyId, string $surveyId): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/')."/encuesta/{$companyId}/{$surveyId}/".self::signature($companyId, $surveyId);
    }

    public static function respondentHash(string $surveyId, string $workerId): string
    {
        return hash_hmac('sha256', "psi-respondent|{$surveyId}|{$workerId}", (string) config('app.key'));
    }

    /** Nivel según el puntaje 0–100 de una dimensión. */
    public static function level(float $score): string
    {
        return $score >= 66.7 ? 'high' : ($score >= 33.3 ? 'medium' : 'low');
    }

    /** Resultados agregados (se llama al cerrar la encuesta, con el contexto de la empresa). */
    public static function results(object $survey): array
    {
        $q = json_decode((string) $survey->questions, true) ?: self::defaultQuestions();
        $min = (int) LegalParams::number('psychosocial_min_responses_report', 5);
        $rows = DB::select('select r.answers, r.area_id, a.name as area from psychosocial_responses r left join areas a on a.id = r.area_id
                             where r.survey_id = ? and r.deleted_at is null', [$survey->id]);

        $score = function (array $subset) use ($q): array {
            $out = [];
            foreach ($q['dimensions'] as $d) {
                $sum = 0;
                $n = 0;
                foreach ($subset as $r) {
                    $a = json_decode((string) $r->answers, true) ?: [];
                    foreach ($d['items'] as $it) {
                        if (isset($a[$it['id']]) && is_numeric($a[$it['id']])) {
                            $sum += (int) $a[$it['id']];
                            $n++;
                        }
                    }
                }
                $s = $n ? round($sum / ($n * 4) * 100, 1) : null;
                $out[] = ['code' => $d['code'], 'name' => $d['name'], 'score' => $s, 'level' => $s === null ? null : self::level($s)];
            }

            return $out;
        };

        $byArea = [];
        foreach ($rows as $r) {
            $byArea[$r->area_id ?? 'none'][] = $r;
        }
        $areas = [];
        $hidden = 0;
        foreach ($byArea as $key => $subset) {
            if (count($subset) < $min) {
                $hidden += count($subset);

                continue;
            }
            $areas[] = ['area_id' => $key === 'none' ? null : $key, 'area' => $subset[0]->area ?? 'Sin área', 'responses' => count($subset), 'dimensions' => $score($subset)];
        }

        return [
            'responses' => count($rows), 'min_group' => $min, 'shown' => count($rows) >= $min,
            'dimensions' => count($rows) >= $min ? $score($rows) : [],
            'areas' => $areas, 'hidden_responses' => $hidden,
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
