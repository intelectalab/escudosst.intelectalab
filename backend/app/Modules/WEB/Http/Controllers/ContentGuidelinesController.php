<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\Concerns\SitePanelHelpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lineamientos de contenido (SCR-WEB-68, RN-WEB-58): publicar solo contenido veraz.
 *  GET /internal/site/guidelines   frases bloqueadas y revisión automática de todo lo que hoy se muestra en el sitio
 *  PUT /internal/site/guidelines   cambia las listas (Dueño)
 *
 * Las mismas listas se usan al publicar (páginas, novedades, webinars, ventanas, integraciones, ajustes del sitio):
 * si un texto contiene una frase bloqueada, no se puede publicar. La comparativa además no puede tener precios.
 */
class ContentGuidelinesController extends Controller
{
    use SitePanelHelpers;

    /** Qué se revisa: [tabla, etiqueta, enlace del panel, expresión del título, expresión del texto, condición de «publicado»]. */
    private const SOURCES = [
        ['site_pages', 'Página', '/plataforma/sitio/paginas', 'title', "concat_ws(' ', title, summary, blocks::text, meta_title, meta_description)", "status = 'published'"],
        ['site_faqs', 'Pregunta frecuente', '/plataforma/sitio/contenido', 'question', "concat_ws(' ', question, answer)", "status = 'published'"],
        ['site_testimonials', 'Testimonio', '/plataforma/sitio/contenido', 'author_name', 'quote', "status in ('approved', 'published')"],
        ['success_stories', 'Caso de éxito', '/plataforma/sitio/casos', 'title', "concat_ws(' ', title, challenge, solution, quote)", "status = 'published'"],
        ['help_articles', 'Artículo de ayuda', '/plataforma/sitio/ayuda', 'title', "concat_ws(' ', title, body)", "status = 'published'"],
        ['product_updates', 'Novedad', '/plataforma/sitio/novedades', 'title', "concat_ws(' ', title, body)", "status = 'published'"],
        ['webinars', 'Webinar', '/plataforma/sitio/webinars', 'title', "concat_ws(' ', title, description)", "status in ('published', 'live', 'finished')"],
        ['site_popups', 'Ventana emergente', '/plataforma/sitio/ventanas', 'name', "concat_ws(' ', title, body)", "status = 'active'"],
        ['site_integrations', 'Integración', '/plataforma/sitio/integraciones', 'name', "concat_ws(' ', name, description)", "status = 'published'"],
    ];

    public const RULES = [
        ['Sellos y certificaciones', 'Solo los que EscudoSST tenga de verdad y pueda mostrar (nada de «homologado», «ISO 27001» ni «auditoría aprobada» sin el documento).'],
        ['Tiempos', 'No prometer tiempos que no se puedan cumplir (por ejemplo «implementación en horas»).'],
        ['Condiciones comerciales', 'No publicar condiciones que aún no se decidieron: días de prueba, permanencia, tarjeta, descuentos.'],
        ['Planes y precios', 'Los planes y precios salen del panel (Planes y módulos); no se escriben a mano en los textos.'],
        ['Fechas', 'Usar fechas reales; no inventar fechas de lanzamiento ni de vigencia.'],
        ['Empresas y testimonios', 'Sin empresas reales sin su autorización firmada; los ejemplos se rotulan «Ejemplo».'],
        ['Normas', 'Las normas se citan como referencia «a validar con un especialista» y solo las de la documentación.'],
        ['Comparativa', 'Frente a Excel y papel, sin nombrar a otras empresas y sin precios.'],
    ];

    public function show(): JsonResponse
    {
        $s = $this->db()->selectOne('select content_blocked_terms, comparison_blocked_terms from site_settings where singleton');
        $findings = [];
        foreach (self::SOURCES as [$table, $label, $href, $title, $text, $published]) {
            foreach ($this->db()->select(
                "select id, {$title} as title, ({$published}) as is_published,
                        web_blocked_term({$text}, (select content_blocked_terms from site_settings where singleton)
                          || case when ? = 'site_pages' and page_type_or_null = 'comparison' then (select comparison_blocked_terms from site_settings where singleton) else '[]'::jsonb end) as term
                   from (select *, ".($table === 'site_pages' ? 'page_type' : 'null::text')." as page_type_or_null from {$table} where deleted_at is null) t
                  where web_blocked_term({$text}, (select content_blocked_terms from site_settings where singleton)
                          || case when ? = 'site_pages' and page_type_or_null = 'comparison' then (select comparison_blocked_terms from site_settings where singleton) else '[]'::jsonb end) is not null",
                [$table, $table]
            ) as $r) {
                $findings[] = ['source' => $label, 'title' => $r->title, 'term' => $r->term, 'published' => (bool) $r->is_published, 'href' => $href];
            }
        }
        $settings = $this->db()->selectOne('select concat_ws(\' \', announcement_bar::text, whatsapp_default_message) as t from site_settings where singleton')->t;
        if ($settings && ($term = $this->blockedTerm($settings))) {
            $findings[] = ['source' => 'Ajustes del sitio', 'title' => 'Barra de anuncio o mensaje de WhatsApp', 'term' => $term, 'published' => true, 'href' => '/plataforma/sitio'];
        }
        usort($findings, fn ($a, $b) => $b['published'] <=> $a['published']);

        return response()->json(['data' => [
            'terms' => json_decode($s->content_blocked_terms ?? '[]', true),
            'comparison_terms' => json_decode($s->comparison_blocked_terms ?? '[]', true),
            'rules' => self::RULES,
            'findings' => $findings,
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    public function save(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'terms' => ['present', 'array', 'max:100'],
            'terms.*' => ['string', 'min:2', 'max:80'],
            'comparison_terms' => ['present', 'array', 'max:50'],
            'comparison_terms.*' => ['string', 'min:1', 'max:80'],
        ], self::MESSAGES);
        $norm = fn (array $l) => array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $l), 'strlen')));
        $terms = $norm($d['terms']);
        $comparison = $norm($d['comparison_terms']);
        $this->db()->update(
            'update site_settings set content_blocked_terms = ?::jsonb, comparison_blocked_terms = ?::jsonb, updated_by = ?, updated_at = now() where singleton',
            [json_encode($terms, JSON_UNESCAPED_UNICODE), json_encode($comparison, JSON_UNESCAPED_UNICODE), $request->user()->id]
        );
        $accounts->audit($request->user()->id, 'content_guidelines_changed', 'site_settings', null, ['terms' => count($terms), 'comparison_terms' => count($comparison)]);

        return $this->show();
    }
}
