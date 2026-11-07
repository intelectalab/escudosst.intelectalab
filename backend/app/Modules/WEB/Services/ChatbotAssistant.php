<?php

namespace App\Modules\WEB\Services;

use App\Modules\WEB\Http\Controllers\SiteMenuController;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Asistente del sitio que aprende (PROPUESTA-ASISTENTE, aprobada). Sin IA externa: reglas propias, funciona sin internet
 * y responde SIEMPRE con datos reales y publicados (planes, horarios libres, ayuda, recursos, casos, preguntas frecuentes,
 * estado del servicio). Si no hay datos, lo dice. Solo escribe la memoria de sinónimos (alias) y la conversación.
 *
 * Orden: 1) alias aprendido activo, 2) respuestas guiadas del panel, 3) intenciones por reglas, 4) búsqueda con puntaje.
 */
class ChatbotAssistant
{
    public const INTENTS = [
        'ayuda' => 'Saludo y ayuda', 'reclamo' => 'Libro de Reclamaciones', 'estado' => 'Estado del servicio', 'multa' => 'Calculadora de multas',
        'precios' => 'Precios y planes', 'demo' => 'Agendar demo', 'prueba' => 'Prueba gratis', 'autodiagnostico' => 'Autodiagnóstico',
        'como' => 'Cómo hacer algo (centro de ayuda)', 'recursos' => 'Recursos descargables', 'casos' => 'Casos de éxito', 'modulos' => 'Módulos y funciones',
        'partner' => 'Programa de partners', 'boletin' => 'Boletín normativo', 'seguridad' => 'Seguridad y datos', 'contacto' => 'Contacto y WhatsApp',
        'faq' => 'Pregunta frecuente', 'buscar' => 'Búsqueda',
    ];

    /** Reglas en orden: de la más específica a la más general (texto ya normalizado, sin tildes). */
    private const RULES = [
        'reclamo' => '/\b(reclam\w*|queja\w*|libro de reclamaciones)\b/',
        'estado' => '/\b(caid[oa]s?|se cayo|no (carga|funciona|abre|entra)|falla\w*|esta abajo|estado del (servicio|sistema)|mantenimiento)\b/',
        'multa' => '/\b(multa\w*|sancion\w*|multar\w*|sancionar\w*|infraccion\w*)\b/',
        'autodiagnostico' => '/\b(autodiagnostico|diagnostico|estoy cumpliendo|cumplo (con )?la ley|nivel de cumplimiento|que tan preparad[oa])\b/',
        'demo' => '/\b(demo|demostracion|reunion|agendar|agenda|cita|horarios? (libres?|disponibles?)|hay horario|presentacion)\b/',
        'prueba' => '/\b(prueba gratis|probar|trial|gratis|periodo de prueba|crear (una )?cuenta|registrarme)\b/',
        'precios' => '/\b(precios?|cuanto (cuesta|vale|sale|cobran)|costos?|tarifas?|planes?|cotiza\w*|mensualidad|pagar|cuanto es)\b/',
        'recursos' => '/\b(plantillas?|formatos?|descarga\w*|guias?|checklist|lista de verificacion|matriz|recursos?|modelo de)\b/',
        'casos' => '/\b(casos?( de exito)?|quien(es)? (lo )?usan?|empresas que (lo )?usan|clientes|testimonios?|experiencias?)\b/',
        'partner' => '/\b(partners?|referid\w*|consultor[ae]?s?|comision\w*|alia(do|nza)s?|revender|distribuidor\w*)\b/',
        'boletin' => '/\b(boletin|newsletter|novedades normativas?|suscribirme|suscripcion|noticias)\b/',
        'seguridad' => '/\b(seguridad de (los |mis )?datos|mis datos|privacidad|proteccion de datos|ley 29733|donde (guardan|estan|se guardan)|confidencial\w*|subencargad\w*|confianza)\b/',
        'contacto' => '/\b(whatsapp|telefono|numero de celular|su celular|llamar\w*|contacto|contactar\w*|hablar con|horario de atencion|asesor humano|persona real|escribirles)\b/',
        'modulos' => '/\b(modulos?|funcion\w*|que incluye|que hace|caracteristicas|iperc|capacitacion\w*|epp|inspeccion\w*|accidentes?|comite|registros obligatorios|app|app movil|aplicacion|celular)\b/',
    ];

    private const STOP = ['el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'al', 'a', 'en', 'y', 'o', 'u', 'que', 'para', 'por', 'con', 'sin',
        'mi', 'mis', 'tu', 'tus', 'su', 'sus', 'me', 'te', 'se', 'lo', 'le', 'les', 'es', 'son', 'hay', 'tiene', 'tienen', 'tengo', 'puedo', 'pueden', 'como',
        'cual', 'cuales', 'donde', 'cuando', 'quiero', 'necesito', 'saber', 'ver', 'sobre', 'favor', 'esta', 'este', 'esto', 'eso', 'ese', 'muy', 'mas', 'ya', 'si', 'no'];

    private const FILLERS = '/^(hola|buenas( tardes| noches)?|buenos dias|oye|disculpa|por favor|porfa|dime|quiero saber|me gustaria saber|me puedes decir|muestrame|quisiera saber|una consulta|consulta)\b\s*/';

    public function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    public static function normalize(string $t): string
    {
        $t = mb_strtolower($t);
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $t = preg_replace('/[¿?¡!.,;:"\'()«»]+/u', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /** Quita correos, teléfonos, DNI y RUC antes de interpretar (RN-WEB-76). */
    public static function redact(string $t): string
    {
        return preg_replace(['/[^\s@]+@[^\s@]+\.[^\s@]+/u', '/\b\d{11}\b/', '/\b\d{8}\b/', '/\+?\d[\d\s-]{7,}\d/'], ' ', $t);
    }

    /** Texto para guardar en la conversación: correos, teléfonos, DNI y RUC quedan como [dato oculto]. */
    public static function redactForStorage(string $t): string
    {
        return preg_replace(['/[^\s@]+@[^\s@]+\.[^\s@]+/u', '/\b\d{11}\b/', '/\b\d{8}\b/', '/\+?\d[\d\s-]{7,}\d/'], '[dato oculto]', $t);
    }

    /** Término de búsqueda: sin muletillas ni palabras vacías. */
    public static function term(string $text): string
    {
        $n = self::normalize(self::redact($text));
        for ($i = 0; $i < 3; $i++) {
            $n = trim(preg_replace(self::FILLERS, '', $n));
        }
        $words = array_values(array_filter(explode(' ', $n), fn ($w) => $w !== '' && ! in_array($w, self::STOP, true)));

        return mb_substr(implode(' ', $words), 0, 120);
    }

    /** @return array{intent: string, term: string} */
    public function interpret(string $text): array
    {
        $n = self::normalize(self::redact($text));
        $clean = $n;
        for ($i = 0; $i < 3; $i++) {
            $clean = trim(preg_replace(self::FILLERS, '', $clean));
        }
        $term = self::term($text);
        if ($clean === '' || preg_match('/^(ayuda|menu|opciones|que puedes hacer|en que me (puedes )?ayudar|que haces|gracias)$/', $clean)) {
            return ['intent' => 'ayuda', 'term' => $term];
        }
        if (preg_match('/^(como|que pasos|pasos para|que debo hacer para)\b/', $clean) && ! preg_match(self::RULES['precios'], $clean) && ! preg_match(self::RULES['multa'], $clean)) {
            return ['intent' => 'como', 'term' => $term];
        }
        foreach (self::RULES as $intent => $re) {
            if (preg_match($re, $clean)) {
                return ['intent' => $intent, 'term' => $term];
            }
        }

        return ['intent' => 'buscar', 'term' => $term];
    }

    // ---------------------------------------------------------------- Responder

    /** @return array{reply: string, card: ?array, kind: string, intent: string, term: string, intent_id?: ?string, cta_type?: string, cta_url?: ?string} */
    public function answer(string $text): array
    {
        $term = self::term($text);
        if ($term !== '' && ($alias = $this->db()->selectOne("select target_type, target_key from chatbot_aliases where term = ? and status = 'active' order by uses desc limit 1", [$term]))) {
            $hit = $this->byTarget($alias->target_type, $alias->target_key);
            if ($hit) {
                $this->db()->update("update chatbot_aliases set uses = uses + 1, updated_at = now() where term = ? and target_type = ? and target_key = ?", [$term, $alias->target_type, $alias->target_key]);

                return $hit + ['term' => $term];
            }
        }
        $guided = $this->guided($text);
        if ($guided) {
            return $guided + ['term' => $term];
        }
        ['intent' => $intent] = $this->interpret($text);
        if (! in_array($intent, ['ayuda', 'reclamo', 'estado', 'demo'], true) && ($faq = $this->faqCovered($term))) {
            return $faq + ['intent' => 'faq', 'term' => $term];
        }
        $r = match ($intent) {
            'ayuda' => $this->help(),
            'como' => $this->how($term),
            'buscar' => $this->search($term),
            default => $this->intentAnswer($intent, $term),
        };

        return $r + ['intent' => $intent, 'term' => $term];
    }

    /** Respuestas guiadas que escribe el Dueño en el panel: tienen prioridad cuando coinciden. */
    private function guided(string $text): ?array
    {
        $low = mb_strtolower($text);
        foreach ($this->db()->select("select id, name, answer, cta_type, cta_url, trigger_phrases_json from chatbot_intents where status = 'published' and deleted_at is null order by sort_order, name") as $i) {
            foreach (json_decode($i->trigger_phrases_json, true) ?: [] as $ph) {
                $ph = trim(mb_strtolower((string) $ph));
                if ($ph !== '' && str_contains($low, $ph)) {
                    return ['reply' => $i->answer, 'card' => null, 'kind' => 'guided', 'intent' => 'guided', 'intent_id' => $i->id, 'cta_type' => $i->cta_type, 'cta_url' => $i->cta_url];
                }
            }
        }

        return null;
    }

    /** Una pregunta frecuente publicada cuyas palabras clave están todas (o casi todas) en la pregunta del visitante. */
    private function faqCovered(string $term): ?array
    {
        $q = ' '.$term.' ';
        $best = null;
        foreach ($this->index() as $it) {
            if ($it['type'] !== 'faq') {
                continue;
            }
            $words = array_values(array_filter(explode(' ', self::term($it['title'])), fn ($w) => mb_strlen($w) >= 3));
            if (count($words) < 1) {
                continue;
            }
            $hit = count(array_filter($words, fn ($w) => str_contains($q, ' '.(mb_strlen($w) > 5 ? mb_substr($w, 0, -2) : $w))));
            $cover = $hit / count($words);
            if ($cover >= 0.8 && $hit >= 1 && (! $best || $cover > $best[0] || ($cover === $best[0] && count($words) > $best[1]))) {
                $best = [$cover, count($words), $it];
            }
        }

        return $best ? ['reply' => $best[2]['answer'], 'card' => ['type' => 'source', 'label' => 'Fuente: Preguntas frecuentes', 'href' => '/preguntas-frecuentes'], 'kind' => 'assistant:faq'] : null;
    }

    private function help(): array
    {
        return ['reply' => 'Puedo contarte sobre precios y planes, agendar una demo, calcular una multa referencial, guiarte paso a paso con el sistema, mostrarte plantillas y casos, o ver si el servicio está funcionando. ¿Qué necesitas?',
            'card' => ['type' => 'buttons', 'items' => [['label' => '¿Cuánto cuesta?', 'ask' => '¿Cuánto cuesta?'], ['label' => 'Quiero una demo', 'ask' => 'Quiero una demo'], ['label' => 'Calcular una multa', 'ask' => '¿Cuánto es una multa?'], ['label' => 'Plantillas gratis', 'ask' => 'Plantillas']]],
            'kind' => 'assistant:ayuda'];
    }

    private function button(string $label, string $href): array
    {
        return ['type' => 'buttons', 'items' => [['label' => $label, 'href' => $href]]];
    }

    private function intentAnswer(string $intent, string $term): array
    {
        $k = 'assistant:'.$intent;
        switch ($intent) {
            case 'reclamo':
                return ['reply' => 'Para presentar un reclamo o una queja usa nuestro Libro de Reclamaciones virtual: queda registrado con su código y plazo de respuesta. Por este chat no se registran reclamos.', 'card' => $this->button('Ir al Libro de Reclamaciones', '/libro-de-reclamaciones'), 'kind' => $k];

            case 'estado':
                $s = json_decode($this->db()->selectOne('select public_service_status() as r')->r, true);
                $labels = ['operational' => 'Operativo', 'degraded' => 'Degradado', 'partial_outage' => 'Caída parcial', 'major_outage' => 'Caída total', 'maintenance' => 'Mantenimiento'];
                $ok = ($s['overall_status'] ?? 'operational') === 'operational';

                return ['reply' => $ok ? 'Ahora todos los servicios funcionan con normalidad.' : 'Ahora hay un problema reportado. Mira el detalle:',
                    'card' => ['type' => 'status', 'overall' => $s['overall_status'] ?? 'operational', 'items' => array_map(fn ($c) => ['name' => $c['name'], 'status' => $c['current_status'], 'label' => $labels[$c['current_status']] ?? $c['current_status']], $s['components'] ?? []), 'href' => '/estado'],
                    'kind' => $k];

            case 'multa':
                $p = json_decode($this->db()->selectOne('select public_fine_calculator_parameters() as r')->r, true);
                $ready = ($p['result'] ?? '') === 'ok' && ! empty($p['available']);   // sin tablas legales cargadas no se ofrece

                return ['reply' => $ready ? 'Con la calculadora puedes estimar el rango referencial de una multa de SUNAFIL según el tamaño de tu empresa y la gravedad. No es asesoría legal.' : 'La calculadora de multas está en preparación: todavía estamos validando los valores legales. Mientras tanto, podemos revisarlo contigo en una demo.',
                    'card' => $ready ? $this->button('Calcular mi multa', '/herramientas/calculadora-multas') : $this->button('Agendar una demo', '/demo/agendar'), 'kind' => $k];

            case 'autodiagnostico':
                return ['reply' => 'El autodiagnóstico te dice en pocos minutos qué tan preparada está tu empresa para la Ley 29783 y te da un informe en PDF con lo que falta.', 'card' => $this->button('Hacer el autodiagnóstico', '/herramientas/autodiagnostico'), 'kind' => $k];

            case 'prueba':
                return ['reply' => 'Puedes crear tu cuenta de prueba con el RUC de tu empresa y explorar el sistema por tu cuenta.', 'card' => $this->button('Crear mi cuenta de prueba', '/prueba-gratis'), 'kind' => $k];

            case 'precios':
                $plans = json_decode($this->db()->selectOne('select public_pricing() as p')->p, true)['plans'] ?? [];
                $items = array_map(fn ($p) => ['title' => $p['name'], 'price' => $p['price'] ? 'S/ '.number_format((float) $p['price'], 2, '.', ',').' al mes'.($p['code'] === 'K' ? ' por empresa' : '') : ($p['price_label'] ?? 'Solicitar cotización'), 'desc' => $p['description'] ?? null], $plans);

                return $items
                    ? ['reply' => 'Estos son nuestros planes. El precio final depende de tu empresa y de los complementos que elijas.', 'card' => ['type' => 'plans', 'items' => $items, 'href' => '/planes'], 'kind' => $k]
                    : ['reply' => 'Te preparamos una cotización según el tamaño de tu empresa.', 'card' => $this->button('Pedir cotización', '/demo?tipo=cotizacion'), 'kind' => $k];

            case 'demo':
                $s = $this->db()->selectOne('select public_booking_enabled, duration_minutes from demo_booking_settings where singleton');
                if (! $s?->public_booking_enabled) {
                    return ['reply' => 'Déjanos tus datos y te escribimos para coordinar el horario de tu demo.', 'card' => $this->button('Pedir una demo', '/demo'), 'kind' => $k];
                }
                $today = now('America/Lima')->toDateString();
                // Si pide un día («el jueves», «mañana», «hoy»), se muestran los horarios de ese día.
                $days = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];
                $want = null;
                foreach (explode(' ', $term) as $w) {
                    $want ??= $days[$w] ?? ($w === 'manana' ? (int) now('America/Lima')->addDay()->isoWeekday() : ($w === 'hoy' ? (int) now('America/Lima')->isoWeekday() : null));
                }
                $all = app(GoogleCalendarService::class)->filterBusy($this->db()->select('select host_id, starts_at, ends_at from web_demo_free_slots(?::date, (?::date + 14)) order by starts_at', [$today, $today]));
                $pickSlots = fn (?int $dow) => collect($all)->filter(fn ($x) => ! $dow || Carbon::parse($x->starts_at)->timezone('America/Lima')->isoWeekday() === $dow)
                    ->unique(fn ($x) => Carbon::parse($x->starts_at)->timestamp)->take(3)->values()->all();
                $slots = $pickSlots($want);
                $dayName = $want ? array_search($want, $days, true) : null;
                if (! $slots && $want) {
                    $slots = $pickSlots(null);
                    if ($slots) {
                        return ['reply' => 'No hay horarios libres el '.str_replace('miercoles', 'miércoles', str_replace('sabado', 'sábado', $dayName)).'. Estos son los más cercanos (hora de Lima):',
                            'card' => ['type' => 'slots', 'items' => array_map(fn ($x) => ['starts_at' => Carbon::parse($x->starts_at)->toIso8601String()], $slots), 'href' => '/demo/agendar'], 'kind' => $k];
                    }
                }
                if (! $slots) {
                    return ['reply' => 'En este momento no hay horarios libres en los próximos días. Déjanos tus datos y te buscamos uno.', 'card' => $this->button('Pedir una demo', '/demo'), 'kind' => $k];
                }

                return ['reply' => "La demo dura {$s->duration_minutes} minutos. ".($want ? 'Estos son los horarios libres de ese día' : 'Estos son los próximos horarios libres').' (hora de Lima):',
                    'card' => ['type' => 'slots', 'items' => array_map(fn ($x) => ['starts_at' => Carbon::parse($x->starts_at)->toIso8601String()], $slots), 'href' => '/demo/agendar'], 'kind' => $k];

            case 'partner':
                return ['reply' => 'Si eres consultora SST, profesional independiente o instituto, puedes recomendar EscudoSST con tu propio código de referido.', 'card' => $this->button('Ver el programa de partners', '/partners'), 'kind' => $k];

            case 'boletin':
                return ['reply' => 'En el boletín te contamos en simple los cambios de la normativa SST, SUNAFIL y tu sector. Eliges los temas y te das de baja cuando quieras.', 'card' => $this->button('Suscribirme al boletín', '/boletin'), 'kind' => $k];

            case 'seguridad':
                return ['reply' => 'Pedimos un código por correo al ingresar, cada empresa ve solo sus datos, los datos de salud van en una base separada y hacemos copias diarias cifradas. Todo el detalle, con los proveedores que usamos, está en el centro de confianza.', 'card' => $this->button('Ver el centro de confianza', '/confianza'), 'kind' => $k];

            case 'contacto':
                $wa = $this->db()->selectOne('select whatsapp_number from site_settings where singleton')?->whatsapp_number;

                return ['reply' => $wa ? 'Puedes escribirnos por WhatsApp y una persona del equipo te responde.' : 'Déjanos tus datos y te escribimos.', 'card' => $wa ? ['type' => 'buttons', 'items' => [['label' => 'Escribir por WhatsApp', 'whatsapp' => true]]] : $this->button('Contactarnos', '/demo?tipo=contacto'), 'kind' => $k];

            case 'recursos':
                $hits = $this->rank($term, ['resource']);
                if (! $hits) {
                    $all = array_slice(array_values(array_filter($this->index(), fn ($i) => $i['type'] === 'resource')), 0, 3);
                    if (! $all) {
                        return ['reply' => 'Aún no publicamos recursos descargables. Pronto habrá plantillas y guías.', 'card' => null, 'kind' => $k];
                    }
                    $hits = $all;
                }

                return ['reply' => 'Estos recursos te pueden servir:', 'card' => $this->choices($term, $hits), 'kind' => $k];

            case 'casos':
                $hits = $this->rank($term, ['story']) ?: array_slice(array_values(array_filter($this->index(), fn ($i) => $i['type'] === 'story')), 0, 3);

                return $hits
                    ? ['reply' => 'Mira cómo lo usan otras empresas:', 'card' => $this->choices($term, $hits), 'kind' => $k]
                    : ['reply' => 'Aún no publicamos casos de éxito. En una demo te mostramos ejemplos de tu sector.', 'card' => $this->button('Agendar una demo', '/demo/agendar'), 'kind' => $k];

            case 'modulos':
                $words = array_filter(explode(' ', $term), fn ($w) => mb_strlen($w) > 2 && ! in_array($w, ['modulo', 'modulos', 'tienen', 'incluye'], true));
                $mods = $this->db()->select("select trim(code) as code, name, solves from modules where deleted_at is null and plan_code not in ('I') order by group_number, number");
                $hit = array_values(array_filter($mods, function ($m) use ($words) {
                    $hay = self::normalize($m->name.' '.$m->solves);
                    foreach ($words as $w) {
                        if (str_contains($hay, $w)) {
                            return true;
                        }
                    }

                    return false;
                }));
                $show = array_slice($hit ?: $mods, 0, 4);

                return ['reply' => $hit ? 'Sí, esto lo cubre EscudoSST:' : 'EscudoSST tiene módulos para toda la gestión SST de la Ley 29783. Algunos:',
                    'card' => ['type' => 'list', 'items' => array_map(fn ($m) => ['title' => $m->name, 'desc' => $m->solves, 'href' => '/producto'], $show), 'href' => '/producto'], 'kind' => $k];
        }

        return $this->search($term);
    }

    private function how(string $term): array
    {
        $hits = $this->rank($term, ['help_article']);
        if (! $hits) {
            return $this->search($term);
        }
        $top = $hits[0];
        $art = $this->db()->selectOne("select body from help_articles where slug = ? and status = 'published' and deleted_at is null", [$top['key']]);
        $steps = [];
        foreach (preg_split('/\n/', (string) $art?->body) as $l) {
            if (preg_match('/^\s*[-•*]\s+(.*)$/', $l, $m)) {
                $steps[] = trim($m[1]);
            }
        }

        return ['reply' => $top['title'], 'card' => ['type' => 'guide', 'title' => $top['title'], 'steps' => array_slice($steps, 0, 8), 'summary' => $steps ? null : $top['desc'], 'href' => $top['href'],
            'more' => array_map(fn ($h) => ['title' => $h['title'], 'href' => $h['href']], array_slice($hits, 1, 2))], 'kind' => 'assistant:como'];
    }

    /** Búsqueda en todo lo publicado. Una pregunta frecuente muy parecida se responde directo; si no, se ofrecen opciones. */
    private function search(string $term): array
    {
        $hits = $this->rank($term, null);
        if (! $hits) {
            return ['reply' => 'No tengo una respuesta para eso todavía. Puedes dejarnos tus datos o escribirnos y una persona te ayuda.', 'card' => null, 'kind' => 'fallback'];
        }
        $top = $hits[0];
        if ($top['type'] === 'faq' && $top['score'] >= 60 && (! isset($hits[1]) || $top['score'] - $hits[1]['score'] >= 20)) {
            return ['reply' => $top['answer'], 'card' => ['type' => 'source', 'label' => 'Fuente: Preguntas frecuentes', 'href' => '/preguntas-frecuentes'], 'kind' => 'assistant:faq', 'intent' => 'faq'];
        }

        return ['reply' => '¿Te refieres a alguno de estos?', 'card' => $this->choices($term, array_slice($hits, 0, 4)), 'kind' => 'assistant:buscar'];
    }

    private function choices(string $term, array $hits): array
    {
        return ['type' => 'choices', 'term' => $term, 'items' => array_map(fn ($h) => array_filter([
            'title' => $h['title'], 'desc' => $h['desc'] ? mb_strimwidth($h['desc'], 0, 140, '…') : null, 'href' => $h['href'],
            'answer' => $h['answer'] ?? null, 'target_type' => $h['type'], 'target_key' => $h['key'], 'label' => $h['label'],
        ], fn ($v) => $v !== null), $hits)];
    }

    // ---------------------------------------------------------------- Índice y puntaje

    /** Todo lo publicado que el asistente puede mostrar. En caché 5 minutos. */
    public function index(): array
    {
        return Cache::remember('chatbot:index', 300, function () {
            $db = $this->db();
            $items = [];
            foreach ($db->select("select id, question, answer, topic from site_faqs where status = 'published' and deleted_at is null order by sort_order") as $f) {
                $items[] = ['type' => 'faq', 'key' => $f->id, 'title' => $f->question, 'desc' => $f->answer, 'answer' => $f->answer, 'extra' => (string) $f->topic, 'href' => '/preguntas-frecuentes', 'label' => 'Pregunta frecuente'];
            }
            foreach ($db->select("select a.slug, a.title, a.body, c.name as cat from help_articles a join help_categories c on c.id = a.category_id and c.status = 'active' and c.deleted_at is null where a.status = 'published' and a.deleted_at is null") as $a) {
                $plain = trim(preg_replace(['/^#+\s*/m', '/^-\s+/m', '/\s+/'], ['', '', ' '], $a->body));
                $items[] = ['type' => 'help_article', 'key' => $a->slug, 'title' => $a->title, 'desc' => $plain, 'extra' => $a->cat, 'href' => '/ayuda/'.$a->slug, 'label' => 'Centro de ayuda'];
            }
            foreach ($db->select("select slug, title, description, resource_type, sector from resource_items where status = 'published' and deleted_at is null") as $r) {
                $items[] = ['type' => 'resource', 'key' => $r->slug, 'title' => $r->title, 'desc' => (string) $r->description, 'extra' => $r->resource_type.' '.$r->sector.' plantilla formato guia', 'href' => '/recursos#'.$r->slug, 'label' => 'Recurso gratis'];
            }
            foreach ($db->select("select slug, title, client_name, sector, challenge from success_stories where status = 'published' and deleted_at is null and (consent_file_id is not null or is_example)") as $s) {
                $items[] = ['type' => 'story', 'key' => $s->slug, 'title' => $s->title, 'desc' => (string) $s->challenge, 'extra' => $s->client_name.' '.$s->sector, 'href' => '/casos/'.$s->slug, 'label' => 'Caso de éxito'];
            }
            foreach (SiteMenuController::linkablePages() as $path => [$label, $group]) {
                if (str_starts_with($path, '/casos/')) {
                    continue;
                }
                $items[] = ['type' => 'page', 'key' => $path, 'title' => $label, 'desc' => null, 'extra' => $group, 'href' => $path, 'label' => 'Página'];
            }

            return $items;
        });
    }

    /** Puntaje de la propuesta: alias +100, título exacto +90, empieza con +40, todas las palabras +30 (+5 c/u), parciales +8 c/u. */
    public function rank(string $term, ?array $types, int $limit = 6): array
    {
        if (mb_strlen($term) < 2) {
            return [];
        }
        $words = array_values(array_filter(explode(' ', $term), fn ($w) => mb_strlen($w) >= 3));
        if (! $words) {
            $words = [$term];
        }
        $aliases = collect($this->db()->select("select target_type, target_key from chatbot_aliases where term = ? and status = 'active'", [$term]))
            ->map(fn ($a) => $a->target_type.'|'.$a->target_key)->all();
        $out = [];
        foreach ($this->index() as $it) {
            if ($types && ! in_array($it['type'], $types, true)) {
                continue;
            }
            $title = self::normalize($it['title']);
            $hay = $title.' '.self::normalize(($it['desc'] ?? '').' '.$it['extra']);
            $score = in_array($it['type'].'|'.$it['key'], $aliases, true) ? 100 : 0;
            if ($title === $term) {
                $score += 90;
            } elseif (str_starts_with($title, $term)) {
                $score += 40;
            }
            $found = 0;
            foreach ($words as $w) {
                $stem = mb_strlen($w) > 5 ? mb_substr($w, 0, mb_strlen($w) - 2) : $w;   // «capacitaciones» ~ «capacitacion»
                if (preg_match('/\b'.preg_quote($stem, '/').'/u', $hay)) {
                    $found++;
                    $score += str_contains($title, $stem) ? 8 : 4;
                }
            }
            if ($found === count($words)) {
                $score += 30 + 5 * $found;
            }
            if ($score >= 8) {
                $out[] = $it + ['score' => $score];
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $limit);
    }

    /** Respuesta para un destino concreto (alias o elección del visitante). Null si ya no está publicado. */
    public function byTarget(string $type, string $key): ?array
    {
        if ($type === 'intent') {
            if (! isset(self::INTENTS[$key]) || in_array($key, ['buscar', 'faq'], true)) {
                return null;
            }

            return ($key === 'ayuda' ? $this->help() : $this->intentAnswer($key, '')) + ['intent' => $key];
        }
        $it = collect($this->index())->first(fn ($i) => $i['type'] === $type && $i['key'] === $key);
        if (! $it) {
            return null;
        }
        if ($type === 'faq') {
            return ['reply' => $it['answer'], 'card' => ['type' => 'source', 'label' => 'Fuente: Preguntas frecuentes', 'href' => '/preguntas-frecuentes'], 'kind' => 'assistant:faq', 'intent' => 'faq'];
        }
        if ($type === 'help_article') {
            return $this->how(self::term($it['title'])) + ['intent' => 'como'];
        }

        return ['reply' => $it['title'], 'card' => $this->choices('', [$it + ['score' => 100]]), 'kind' => 'assistant:'.$type, 'intent' => 'buscar'];
    }

    // ---------------------------------------------------------------- Aprendizaje

    /**
     * El visitante eligió un resultado para su término. Si el título ya contiene el término no hay nada que aprender.
     * Queda como sugerencia y se activa al llegar a 3 conversaciones distintas (o cuando el Dueño la aprueba).
     */
    public function learn(string $term, string $type, string $key, string $conversationId, ?string $voterHash = null): ?string
    {
        $term = self::term($term);
        $it = $type === 'intent' ? (isset(self::INTENTS[$key]) ? ['title' => self::INTENTS[$key]] : null) : collect($this->index())->first(fn ($i) => $i['type'] === $type && $i['key'] === $key);
        if (mb_strlen($term) < 3 || ! $it || str_contains(self::normalize($it['title']), $term)) {
            return null;
        }
        $db = $this->db();
        $db->insert("insert into chatbot_aliases (term, target_type, target_key, status, source) values (?, ?, ?, 'suggested', 'visitor') on conflict (term, target_type, target_key) do nothing", [$term, $type, $key]);
        $a = $db->selectOne('select id, status from chatbot_aliases where term = ? and target_type = ? and target_key = ?', [$term, $type, $key]);
        $new = $db->affectingStatement('insert into chatbot_alias_votes (alias_id, conversation_id, voter_hash) values (?, ?, ?) on conflict do nothing', [$a->id, $conversationId, $voterHash ?? $conversationId]) > 0;
        if ($new) {
            $db->update('update chatbot_aliases set uses = uses + 1, updated_at = now() where id = ?', [$a->id]);
        }
        // 3 personas distintas (no 3 conversaciones de la misma persona).
        if ($a->status === 'suggested' && (int) $db->selectOne('select count(distinct coalesce(voter_hash, conversation_id::text)) as n from chatbot_alias_votes where alias_id = ?', [$a->id])->n >= 3) {
            $db->update("update chatbot_aliases set status = 'active', updated_at = now() where id = ?", [$a->id]);
        }

        return $a->id;
    }
}
