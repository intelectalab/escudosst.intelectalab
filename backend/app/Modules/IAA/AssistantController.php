<?php

namespace App\Modules\IAA;

use App\Core\Http\ApiProblem;
use App\Core\Support\Providers;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Asistente IA (complemento IAA).
 *  POST /iaa/suggest {feature: controls|causes|talk, text}
 * Con el proveedor de IA configurado (Panel › Proveedores y claves) responde con IA; si no, en modo de prueba sugiere
 * desde la biblioteca de EscudoSST (jerarquía de controles, árbol de causas, guion de charla) y lo marca así.
 * Nunca se envían datos de salud ni documentos completos: solo el texto que escribe la persona.
 * Las sugerencias son propuestas: la persona decide qué usar (queda registro en ai_requests y ai_suggestions).
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
        $ai = Providers::get('ai');
        $started = microtime(true);
        [$items, $note, $mode, $provider, $model] = $ai['live']
            ? $this->live($ai, $d['feature'], $d['text'])
            : [$this->library($d['feature'], $d['text']), 'Sugerencias de prueba desde la biblioteca de EscudoSST (sin IA). Cuando se configure el proveedor de IA, serán más específicas.', 'test', 'escudosst_library', 'reglas-v1'];

        $ctx = app(CompanyContext::class);
        $req = DB::selectOne(
            "insert into ai_requests (company_id, user_id, feature, module_code, provider, model, prompt_template_code, prompt_version, input_redacted, contains_medical_data,
                input_summary, latency_ms, status, completed_at, created_by, updated_by)
             values (?, ?, ?, 'IAA', ?, ?, ?, '1', true, false, ?, ?, 'completed', now(), ?, ?) returning id",
            [$ctx->companyId, (string) auth()->id(), self::FEATURES[$d['feature']], $provider, $model, $d['feature'], mb_substr($d['text'], 0, 200),
                (int) ((microtime(true) - $started) * 1000), (string) auth()->id(), (string) auth()->id()]
        );
        DB::insert(
            "insert into ai_suggestions (company_id, ai_request_id, suggestion_type, target_type, payload, rationale, status, created_by, updated_by)
             values (?, ?, ?, 'free_text', ?::jsonb, ?, 'pending', ?, ?)",
            [$ctx->companyId, $req->id, $d['feature'], json_encode(['items' => $items], JSON_UNESCAPED_UNICODE), $note, (string) auth()->id(), (string) auth()->id()]
        );

        return response()->json(['data' => ['mode' => $mode, 'items' => $items, 'note' => $note]]);
    }

    /** @return array{0: list<array>, 1: string, 2: string, 3: string, 4: string} */
    private function live(array $ai, string $feature, string $text): array
    {
        $ask = [
            'controls' => "Propón controles para este peligro o tarea en una empresa peruana, siguiendo la jerarquía de controles (eliminación, sustitución, ingeniería, administrativos, EPP) y la Ley 29783: «{$text}».",
            'causes' => "Ayuda a investigar este accidente o incidente con el método de árbol de causas: propone causas inmediatas (actos y condiciones) y causas básicas (factores personales y del trabajo) a verificar, y preguntas para entrevistar: «{$text}».",
            'talk' => "Redacta el guion de una charla de 5 minutos de seguridad para trabajadores peruanos sobre: «{$text}». Lenguaje simple, puntos clave y una pregunta final.",
        ][$feature];
        $system = 'Eres un especialista en seguridad y salud en el trabajo en Perú. Responde en español simple. Devuelve SOLO JSON: {"items":[{"title":"...","detail":"...","group":"..."}],"note":"..."} con 4 a 8 items. No inventes normas ni cifras.';
        try {
            if ($ai['provider'] !== 'anthropic') {
                throw new \RuntimeException('Proveedor sin conexión automática.');
            }
            $r = Http::timeout(40)->withHeaders(['x-api-key' => $ai['secrets']['api_key'], 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', [
                'model' => $ai['settings']['model'] ?? 'claude-sonnet-5-5', 'max_tokens' => 1500, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $ask]],
            ]);
            $txt = (string) ($r->json('content.0.text') ?? '');
            $json = json_decode(preg_replace('/^[^{]*|[^}]*$/s', '', $txt), true);
            if (! $r->successful() || ! is_array($json['items'] ?? null)) {
                throw new \RuntimeException('Respuesta no válida');
            }

            return [array_slice($json['items'], 0, 10), (string) ($json['note'] ?? 'Revisa y adapta las sugerencias a tu realidad antes de usarlas.'), 'live', $ai['provider'], $ai['settings']['model'] ?? 'claude-sonnet-5-5'];
        } catch (\Throwable) {
            return [$this->library($feature, $text), 'El proveedor de IA no respondió: se muestran sugerencias de la biblioteca de EscudoSST.', 'test', 'escudosst_library', 'reglas-v1'];
        }
    }

    /** Biblioteca de EscudoSST (modo de prueba): sugerencias generales según palabras clave. */
    private function library(string $feature, string $text): array
    {
        $t = mb_strtolower($text);
        if ($feature === 'talk') {
            return [
                ['group' => 'Inicio (1 min)', 'title' => 'Por qué hablamos hoy de esto', 'detail' => "Cuenta un caso real o cercano relacionado con «{$text}» y qué pudo pasar."],
                ['group' => 'Riesgos (1 min)', 'title' => 'Qué nos puede pasar', 'detail' => 'Nombra los 2 o 3 peligros principales de la tarea y sus consecuencias.'],
                ['group' => 'Controles (2 min)', 'title' => 'Cómo nos cuidamos', 'detail' => 'Repasa los controles: procedimiento, EPP correcto, señalización y a quién avisar.'],
                ['group' => 'Compromiso (1 min)', 'title' => 'Pregunta final', 'detail' => '¿Qué harías si ves a un compañero sin su EPP o una condición insegura? Recuerda que puedes detener el trabajo.'],
            ];
        }
        if ($feature === 'causes') {
            return [
                ['group' => 'Causa inmediata · acto', 'title' => '¿Se siguió el procedimiento?', 'detail' => 'Verifica si había PETS/ATS, si se cumplió y si la persona estaba autorizada y capacitada.'],
                ['group' => 'Causa inmediata · condición', 'title' => '¿Cómo estaba el lugar y el equipo?', 'detail' => 'Orden, iluminación, guardas, estado del equipo, señalización, EPP disponible y en buen estado.'],
                ['group' => 'Causa básica · personal', 'title' => 'Conocimiento, habilidad y estado', 'detail' => 'Capacitación vigente, experiencia en la tarea, fatiga, apuro, comunicación.'],
                ['group' => 'Causa básica · trabajo', 'title' => 'Supervisión y planificación', 'detail' => 'Supervisión, mantenimiento, compras de EPP/equipos, diseño del puesto, carga de trabajo, IPERC actualizado.'],
                ['group' => 'Falta de control', 'title' => '¿Qué falló en el sistema?', 'detail' => 'IPERC sin este riesgo, inspecciones que no lo detectaron, acciones previas sin cerrar.'],
                ['group' => 'Entrevista', 'title' => 'Preguntas para testigos', 'detail' => '¿Qué estaba haciendo? ¿Qué pasó justo antes? ¿Era la forma habitual de hacerlo? ¿Qué cambiarías?'],
            ];
        }
        $lib = [
            'altura|andamio|escalera|techo' => ['Eliminar: hacer el trabajo desde el piso o con prefabricados', 'Ingeniería: barandas, plataformas, líneas de vida y puntos de anclaje certificados',
                'Administrativo: PETAR de trabajo en altura, ATS, personal capacitado y con aptitud médica', 'EPP: arnés de cuerpo entero con doble línea y casco con barbiquejo'],
            'ruido|sonido|decibel' => ['Sustituir: equipos menos ruidosos', 'Ingeniería: encapsular la fuente, silenciadores, aislamiento',
                'Administrativo: rotación, señalización de zonas de ruido, monitoreo de ruido y audiometrías', 'EPP: protectores auditivos según el nivel medido'],
            'químic|solvente|ácido|sustancia|gas' => ['Sustituir por productos menos peligrosos (revisar hoja de seguridad)', 'Ingeniería: ventilación o extracción localizada, contención de derrames',
                'Administrativo: hojas de seguridad a la vista, rotulado, capacitación y kit antiderrames', 'EPP: guantes, lentes y respirador adecuados al producto'],
            'eléctric|tablero|cable|energ' => ['Eliminar: trabajar sin tensión (bloqueo y etiquetado)', 'Ingeniería: interruptores diferenciales, puesta a tierra, tableros cerrados',
                'Administrativo: personal calificado, permiso de trabajo, inspección de herramientas', 'EPP: guantes dieléctricos, calzado dieléctrico y careta'],
            'carga|levant|manual|peso|ergonóm|postura' => ['Eliminar: ayudas mecánicas (carretilla, montacargas, polipasto)', 'Ingeniería: rediseñar el puesto, mesas a la altura correcta',
                'Administrativo: límite de peso, pausas activas, levantamiento entre dos, capacitación', 'EPP: guantes de agarre y calzado de seguridad'],
            'máquina|atrap|corte|herramient' => ['Ingeniería: guardas fijas, paradas de emergencia y bloqueo', 'Administrativo: procedimiento de operación, bloqueo y etiquetado en mantenimiento',
                'Administrativo: solo personal autorizado y capacitado', 'EPP: guantes anticorte (no cerca de partes que giran), lentes'],
            'caída al mismo|piso|tropiez|resbal|orden' => ['Eliminar: retirar obstáculos y derrames', 'Ingeniería: pisos antideslizantes, iluminación, pasamanos',
                'Administrativo: orden y limpieza (5S), señalización de piso mojado', 'EPP: calzado antideslizante'],
            'confinado|tanque|pozo|cisterna' => ['Eliminar: hacer el trabajo desde afuera si es posible', 'Ingeniería: ventilación forzada y medición continua de gases',
                'Administrativo: PETAR de espacio confinado, vigía y plan de rescate', 'EPP: equipo de respiración y arnés con línea de rescate'],
        ];
        foreach ($lib as $keys => $controls) {
            if (preg_match('/'.$keys.'/u', $t)) {
                return array_map(fn ($c) => ['group' => explode(':', $c)[0], 'title' => trim(explode(':', $c, 2)[1] ?? $c), 'detail' => 'Jerarquía de controles (Ley 29783, art. 21).'], $controls);
            }
        }
        $hz = DB::select("select hazard, risk, possible_consequence from hazard_catalog where deleted_at is null and is_active and (hazard ilike ? or risk ilike ?) limit 3", ["%{$text}%", "%{$text}%"]);

        return array_merge(array_map(fn ($h) => ['group' => 'Peligro del catálogo', 'title' => $h->hazard, 'detail' => "Riesgo: {$h->risk}. Consecuencia posible: {$h->possible_consequence}."], $hz), [
            ['group' => 'Eliminación', 'title' => '¿Se puede evitar la tarea o el peligro?', 'detail' => 'Cambia el método para que el peligro no exista.'],
            ['group' => 'Sustitución', 'title' => 'Usa algo menos peligroso', 'detail' => 'Equipo, producto o método con menor riesgo.'],
            ['group' => 'Ingeniería', 'title' => 'Aísla a las personas del peligro', 'detail' => 'Guardas, barreras, ventilación, sistemas de detección.'],
            ['group' => 'Administrativo', 'title' => 'Procedimiento, capacitación y señalización', 'detail' => 'PETS, ATS, permisos, inspecciones y supervisión.'],
            ['group' => 'EPP', 'title' => 'Equipo de protección personal', 'detail' => 'Como última barrera, adecuado al riesgo y con entrega registrada.'],
        ]);
    }
}
