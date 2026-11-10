<?php

namespace App\Modules\REC\Rules;

use App\Modules\PSI\PsychosocialSurvey;
use Illuminate\Support\Facades\DB;

/**
 * Encuestas psicosociales (M-PSI): borrador → abierta → cerrada (calcula resultados) → informada.
 * Las respuestas solo llegan por el enlace anónimo; nunca se crean ni editan desde el panel (RN-PSI-01).
 */
class PsiRules extends RecordRules
{
    private const FLOW = ['draft' => ['open'], 'open' => ['closed'], 'closed' => ['reported', 'open'], 'reported' => []];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        if ($res['table'] === 'psychosocial_responses') {
            $this->conflict('Las respuestas son anónimas: solo llegan por el enlace de la encuesta y no se pueden crear ni cambiar desde aquí.');
        }
        if ($op === 'create') {
            $values['status'] = 'draft';
            $values['is_anonymous'] = true;
            if (($values['instrument'] ?? 'custom') !== 'custom') {
                $this->fail('instrument', 'Por ahora se usa el cuestionario base de EscudoSST; ISTAS21 y CoPsoQ requieren una licencia que aún no está contratada.');
            }
            $values['instrument_version'] ??= 'EscudoSST base v1';
            $extra['questions'] = ['?::jsonb', [json_encode(PsychosocialSurvey::defaultQuestions(), JSON_UNESCAPED_UNICODE)]];

            return;
        }
        if (array_key_exists('is_anonymous', $values) && ! $values['is_anonymous']) {
            $this->fail('is_anonymous', 'La encuesta psicosocial siempre es anónima.');
        }
        $to = $values['status'] ?? null;
        if (! $to || $to === $current->status) {
            if ($current->status !== 'draft' && array_intersect(array_keys($values), ['instrument', 'instrument_version'])) {
                $this->fail('instrument', 'El cuestionario no se cambia después de abrir la encuesta.');
            }

            return;
        }
        if (! in_array($to, self::FLOW[$current->status] ?? [], true)) {
            $this->fail('status', 'Ese cambio de estado no está permitido.');
        }
        $ends = $this->value($values, $current, 'ends_on');
        if ($to === 'open') {
            $values['starts_on'] = $this->value($values, $current, 'starts_on') ?: now('America/Lima')->toDateString();
            if ($ends && $ends < now('America/Lima')->toDateString()) {
                $this->fail('ends_on', 'La fecha de cierre ya pasó: cámbiala antes de abrir la encuesta.');
            }
            $extra['results'] = ['null', []];
        }
        if ($to === 'closed') {
            $values['ends_on'] = $ends && $ends < now('America/Lima')->toDateString() ? $ends : now('America/Lima')->toDateString();
            $extra['results'] = ['?::jsonb', [json_encode(PsychosocialSurvey::results($current), JSON_UNESCAPED_UNICODE)]];
        }
        if ($to === 'reported' && ! $current->results) {
            $this->fail('status', 'Primero cierra la encuesta para calcular los resultados.');
        }
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'psychosocial_surveys' && $current->status !== 'draft') {
            $this->conflict('Solo se archiva una encuesta en borrador; las que ya recibieron respuestas se conservan.');
        }
    }
}
