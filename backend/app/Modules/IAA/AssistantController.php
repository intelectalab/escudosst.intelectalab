<?php

namespace App\Modules\IAA;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Asistente IA (complemento IAA), entrenado por el equipo de EscudoSST (sin IA de pago):
 *  POST /iaa/suggest {feature: controls|causes|talk, text}
 * Busca en la biblioteca del asistente (Panel › Asistente IA) el tema cuyas palabras clave más coinciden con lo que
 * escribió la persona. Si no encuentra, responde con la guía general y anota la pregunta para que el equipo le enseñe.
 * Nunca acepta datos de salud. Las sugerencias son propuestas: la persona decide qué usar.
 */
class AssistantController extends Controller
{
    private const FEATURES = ['controls' => 'iperc_suggestion', 'causes' => 'investigation_guide', 'talk' => 'other'];

    public function suggest(Request $request): JsonResponse
    {
        $d = $request->validate([
            'feature' => ['required', 'in:controls,causes,talk'],
            'text' => ['required', 'string', 'min:5', 'max:1500'],
        ], ['text.required' => 'Escribe el peligro, el evento o el tema.', 'text.min' => 'Escribe un poco más (al menos 5 letras).']);
        if (preg_match('/\b(diagn[oó]stico|hipertensi[oó]n|diabetes|embarazo|gestante|VIH|c[aá]ncer|depresi[oó]n)\b/iu', $d['text'])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el texto', 'No escribas datos de salud de personas: el asistente no los necesita.', ['errors' => ['text' => ['No escribas datos de salud de personas: el asistente no los necesita.']]]);
        }
        Usage::guard();
        $started = microtime(true);
        [$items, $note, $matched] = $this->answer($d['feature'], $d['text']);

        $ctx = app(CompanyContext::class);
        $req = DB::selectOne(
            "insert into ai_requests (company_id, user_id, feature, module_code, provider, model, prompt_template_code, prompt_version, input_redacted, contains_medical_data,
                input_summary, latency_ms, status, completed_at, created_by, updated_by)
             values (?, ?, ?, 'IAA', 'escudosst_library', 'biblioteca', ?, '2', true, false, ?, ?, 'completed', now(), ?, ?) returning id",
            [$ctx->companyId, (string) auth()->id(), self::FEATURES[$d['feature']], $d['feature'], mb_substr($d['text'], 0, 200),
                (int) ((microtime(true) - $started) * 1000), (string) auth()->id(), (string) auth()->id()]
        );
        DB::insert(
            "insert into ai_suggestions (company_id, ai_request_id, suggestion_type, target_type, payload, rationale, status, created_by, updated_by)
             values (?, ?, ?, 'free_text', ?::jsonb, ?, 'pending', ?, ?)",
            [$ctx->companyId, $req->id, $d['feature'], json_encode(['items' => $items], JSON_UNESCAPED_UNICODE), $note, (string) auth()->id(), (string) auth()->id()]
        );

        return response()->json(['data' => ['items' => $items, 'note' => $note, 'topic' => $matched]]);
    }

    /** @return array{0: list<array>, 1: string, 2: ?string} sugerencias, nota y tema encontrado */
    public function answer(string $feature, string $text): array
    {
        $t = ' '.mb_strtolower($text).' ';
        $best = null;
        $bestScore = 0;
        foreach (DB::select("select id, title, keywords, items from assistant_knowledge where feature = ? and status = 'active'", [$feature]) as $k) {
            $score = 0;
            foreach (array_filter(explode(',', trim((string) $k->keywords, '{}'))) as $kw) {
                $kw = mb_strtolower(trim($kw, ' "'));
                if ($kw !== '' && str_contains($t, $kw)) {
                    $score += mb_strlen($kw) >= 6 ? 2 : 1;   // las palabras más específicas pesan más
                }
            }
            if (str_contains($t, mb_strtolower($k->title))) {
                $score += 3;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$k, $score];
            }
        }
        if ($best) {
            DB::update('update assistant_knowledge set uses = uses + 1 where id = ?', [$best->id]);

            return [json_decode($best->items, true) ?: [], "Sugerencias del tema «{$best->title}» de la biblioteca de EscudoSST. Revísalas y adáptalas a tu realidad.", $best->title];
        }
        // Sin respuesta: se anota para que el equipo le enseñe al asistente.
        $q = mb_substr(trim(preg_replace('/\s+/', ' ', mb_strtolower($text))), 0, 300);
        DB::statement('insert into assistant_misses (feature, question) values (?, ?) on conflict (feature, question) do update set times = assistant_misses.times + 1, last_at = now()', [$feature, $q]);

        return [$this->general($feature, $text), 'Aún no tengo un tema para esto: te doy la guía general. El equipo de EscudoSST revisará tu pregunta para enseñarle al asistente.', null];
    }

    private function general(string $feature, string $text): array
    {
        return match ($feature) {
            'talk' => [
                ['group' => 'Inicio (1 min)', 'title' => 'Por qué hablamos hoy de esto', 'detail' => "Cuenta un caso real o cercano relacionado con «{$text}» y qué pudo pasar."],
                ['group' => 'Riesgos (1 min)', 'title' => 'Qué nos puede pasar', 'detail' => 'Nombra los 2 o 3 peligros principales de la tarea y sus consecuencias.'],
                ['group' => 'Controles (2 min)', 'title' => 'Cómo nos cuidamos', 'detail' => 'Procedimiento, EPP correcto, señalización y a quién avisar.'],
                ['group' => 'Compromiso (1 min)', 'title' => 'Pregunta final', 'detail' => '¿Qué harías si ves una condición insegura? Recuerda que puedes detener el trabajo.'],
            ],
            'causes' => [
                ['group' => 'Causa inmediata', 'title' => 'Actos y condiciones', 'detail' => '¿Qué hizo la persona y cómo estaba el lugar y el equipo en ese momento?'],
                ['group' => 'Causa básica', 'title' => 'Factores personales y del trabajo', 'detail' => 'Capacitación, experiencia, supervisión, mantenimiento, procedimientos, carga de trabajo.'],
                ['group' => 'Falta de control', 'title' => '¿Qué falló en el sistema?', 'detail' => 'IPERC, inspecciones y acciones previas.'],
            ],
            default => array_merge(array_map(fn ($h) => ['group' => 'Peligro del catálogo', 'title' => $h->hazard, 'detail' => "Riesgo: {$h->risk}. Consecuencia posible: {$h->possible_consequence}."],
                DB::select('select hazard, risk, possible_consequence from hazard_catalog where deleted_at is null and is_active and (hazard ilike ? or risk ilike ?) limit 3', ["%{$text}%", "%{$text}%"])), [
                ['group' => 'Eliminación', 'title' => '¿Se puede evitar la tarea o el peligro?', 'detail' => 'Cambia el método para que el peligro no exista.'],
                ['group' => 'Sustitución', 'title' => 'Usa algo menos peligroso', 'detail' => 'Equipo, producto o método con menor riesgo.'],
                ['group' => 'Ingeniería', 'title' => 'Aísla a las personas del peligro', 'detail' => 'Guardas, barreras, ventilación, sistemas de detección.'],
                ['group' => 'Administrativo', 'title' => 'Procedimiento, capacitación y señalización', 'detail' => 'PETS, ATS, permisos, inspecciones y supervisión.'],
                ['group' => 'EPP', 'title' => 'Equipo de protección personal', 'detail' => 'Como última barrera, adecuado al riesgo y con entrega registrada.'],
            ]),
        };
    }
}
