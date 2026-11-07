<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Borradores iniciales de páginas del sitio (SCR-WEB-03, 04 y 08): 7 sectores, 7 diferenciadores y consultoras.
 * Contenido tomado de docs/00 §5 (diferenciadores) y docs/11 §1 (normas sectoriales). Quedan en BORRADOR: el dueño
 * los revisa y publica desde Sitio web › Páginas. Las normas se citan solo si figuran en docs/11 y la web las rotula
 * «a validar» (docs/11, veracidad). Sin promesas de tiempos, cifras ni condiciones comerciales.
 * Solo crea las que no existen (por slug).
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('platform');
        foreach ($this->pages() as $i => $p) {
            if ($db->selectOne('select 1 from site_pages where slug = ?', [$p['slug']])) {
                continue;
            }
            $db->insert(
                'insert into site_pages (slug, page_type, title, summary, blocks, meta_title, meta_description, sector_code, sort_order)
                 values (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)',
                [$p['slug'], $p['type'], $p['title'], $p['summary'], json_encode($p['blocks'], JSON_UNESCAPED_UNICODE),
                    mb_substr($p['meta_title'] ?? $p['title'].' · EscudoSST', 0, 70), mb_substr($p['summary'], 0, 170), $p['sector'] ?? null, $i]
            );
        }
    }

    public function down(): void
    {
        $slugs = array_column($this->pages(), 'slug');
        DB::connection('platform')->table('site_pages')->whereIn('slug', $slugs)->where('status', 'draft')->update(['deleted_at' => now()]);
    }

    private function cta(string $button = 'trial'): array
    {
        return ['type' => 'cta', 'title' => 'Ordena tu SST en un solo lugar', 'text' => 'Crea tu cuenta de prueba con el RUC de tu empresa o agenda una demo con un asesor.', 'button' => $button];
    }

    private function sector(string $slug, string $code, string $name, string $intro, array $norms, array $risks, array $modules): array
    {
        return [
            'slug' => "sectores/{$slug}", 'type' => 'sector', 'sector' => $code,
            'title' => "SST para {$name}",
            'summary' => $intro,
            'meta_title' => "Gestión SST para {$name} · EscudoSST",
            'blocks' => [
                ['type' => 'hero', 'eyebrow' => 'Sectores', 'title' => "Seguridad y salud en el trabajo para {$name}", 'text' => $intro],
                ['type' => 'norms', 'title' => 'Normativa de referencia', 'items' => $norms],
                ['type' => 'bullets', 'title' => 'Riesgos típicos que gestionas', 'items' => $risks],
                ['type' => 'modules', 'title' => 'Módulos que más te sirven', 'items' => $modules],
                $this->cta(),
            ],
        ];
    }

    private function differentiator(string $slug, string $name, string $intro, array $bullets, array $modules, array $faq = []): array
    {
        return [
            'slug' => "soluciones/{$slug}", 'type' => 'product', 'title' => $name, 'summary' => $intro,
            'blocks' => array_values(array_filter([
                ['type' => 'hero', 'eyebrow' => 'Diferenciador', 'title' => $name, 'text' => $intro],
                ['type' => 'bullets', 'title' => 'Qué resuelve', 'items' => $bullets],
                ['type' => 'modules', 'title' => 'Módulos relacionados', 'items' => $modules],
                $faq ? ['type' => 'faq', 'title' => 'Preguntas frecuentes', 'items' => $faq] : null,
                $this->cta('demo'),
            ])),
        ];
    }

    private function pages(): array
    {
        $base = 'Ley 29783 y su reglamento (D.S. 005-2012-TR)';

        return [
            $this->sector('construccion', 'construction', 'construcción', 'Obras con muchos frentes, contratistas y trabajos de alto riesgo: IPERC de obra, PETAR, ATS y charlas en un solo lugar.',
                ['D.S. 011-2019-TR, Reglamento de SST para el sector construcción', 'Norma G.050, Seguridad durante la construcción', $base],
                ['Trabajos en altura', 'Excavaciones y zanjas', 'Izaje de cargas', 'Trabajos en caliente', 'Contratistas y subcontratistas en obra'],
                ['IPC', 'PTR', 'ATS', 'CH5', 'CTR', 'SCT', 'INS', 'EPP']),
            $this->sector('mineria', 'mining', 'minería', 'Operaciones mineras con exigencias propias: PETAR, índices de seguridad, capacitación y notificaciones a la autoridad.',
                ['D.S. 024-2016-EM, Reglamento de SSO en minería, y su modificatoria D.S. 023-2017-EM', $base],
                ['Caída de rocas', 'Espacios confinados', 'Equipos pesados y vehículos', 'Exposición a polvo y ruido', 'Explosivos'],
                ['IPC', 'PTR', 'MAG', 'EST', 'CAP', 'ACC', 'EMO']),
            $this->sector('agroindustria', 'agroindustry', 'agroindustria', 'Campañas, cuadrillas y personal rotativo: lleva capacitaciones, EPP y exposición a agroquímicos al día.',
                [$base, 'Normas sobre uso de agroquímicos'],
                ['Exposición a plaguicidas', 'Personal de campaña y cuadrillas', 'Trabajo bajo el sol', 'Carga manual', 'Herramientas de corte'],
                ['TRB', 'CAP', 'EPP', 'MAG', 'EMO', 'INS']),
            $this->sector('manufactura', 'manufacturing', 'manufactura', 'Plantas con máquinas, ruido y procesos continuos: inspecciones, guardas y bloqueo y etiquetado bajo control.',
                [$base],
                ['Atrapamiento en máquinas', 'Ruido', 'Bloqueo y etiquetado', 'Montacargas', 'Sustancias químicas'],
                ['IPC', 'INS', 'MAG', 'PET', 'EPP', 'OBS']),
            $this->sector('retail', 'retail', 'retail y comercio', 'Tiendas y almacenes con atención al público: ergonomía, carga manual y emergencias en cada local.',
                [$base, 'R.M. 375-2008-TR, Norma básica de ergonomía'],
                ['Carga manual de mercadería', 'Atención al público', 'Caídas al mismo nivel', 'Emergencias y evacuación', 'Trabajo en almacenes'],
                ['IPC', 'PSI', 'SIM', 'EQE', 'CAP', 'INS']),
            $this->sector('oficinas', 'office', 'oficinas', 'Aunque el riesgo parezca bajo, la ley se cumple igual: ergonomía, riesgos psicosociales, emergencias y registros.',
                [$base, 'R.M. 375-2008-TR, Norma básica de ergonomía'],
                ['Trabajo frente a pantallas', 'Posturas prolongadas', 'Riesgos psicosociales', 'Emergencias y evacuación'],
                ['LBA', 'IPC', 'PSI', 'SIM', 'COM', 'REG']),
            $this->sector('clinicas', 'health', 'clínicas', 'Personal expuesto a riesgo biológico: punzocortantes, vacunación y vigilancia médica con los datos de salud protegidos.',
                [$base, 'Normas de bioseguridad'],
                ['Riesgo biológico', 'Accidentes con punzocortantes', 'Vacunación del personal', 'Turnos y carga laboral'],
                ['IPC', 'EMO', 'VMD', 'ACC', 'CAP', 'PSI']),

            $this->differentiator('modo-inspeccion-sunafil', 'Modo Inspección SUNAFIL', 'Elige al trabajador y el periodo: el expediente se arma solo con lo que ya registraste, te dice qué falta y lo compartes con un enlace que vence.',
                ['Evidencias por trabajador y periodo', 'Lo que falta, con enlace para corregirlo', 'Enlace temporal para el inspector'], ['SUN', 'REG', 'TRB', 'CAP', 'EPP'],
                [['q' => '¿Qué incluye el expediente?', 'a' => 'Lo que ya registraste en el sistema para ese trabajador y periodo: inducción, capacitaciones, EPP, exámenes médicos e IPERC, entre otros.']]),
            $this->differentiator('fotocheck-qr', 'Fotocheck SST con QR', 'El fotocheck se habilita solo si el trabajador tiene sus requisitos al día. En campo, el supervisor escanea el QR y ve si puede ingresar.',
                ['Requisitos configurables por tipo de fotocheck', 'Verificación por QR', 'Vencimientos que avisan antes'], ['FOT', 'CAP', 'EMO', 'EPP']),
            $this->differentiator('reporte-sin-app', 'Reporte sin app por QR o WhatsApp', 'Cualquier trabajador reporta un acto o condición subestándar escaneando un QR o por WhatsApp, sin instalar nada.',
                ['Sin descargas ni contraseñas', 'Foto y ubicación del reporte', 'Seguimiento hasta cerrarlo'], ['ACT', 'ACR', 'INS']),
            $this->differentiator('modo-consultora', 'Modo consultora y marca blanca', 'Las consultoras SST llevan a todas sus empresas cliente desde un solo panel, y cada empresa ve solo lo suyo.',
                ['Varias empresas desde un panel', 'Cada empresa con sus propios datos', 'Tu marca en el sistema'], ['CON', 'TEN', 'USR']),
            $this->differentiator('app-offline', 'App móvil offline', 'Inspecciones y registros en campo aunque no haya señal; se sincronizan al volver a tener conexión.',
                ['Funciona sin internet', 'Sincroniza al reconectar', 'Pensada para obra, mina y campo'], ['OFF', 'INS', 'ATS', 'PTR']),
            $this->differentiator('asistente-ia', 'Asistente IA', 'Sugiere peligros para tu IPERC, guía investigaciones y redacta actas. Siempre lo revisa y aprueba una persona.',
                ['Sugerencias para el IPERC', 'Guía para investigar accidentes', 'Borradores de actas y documentos', 'Nada se guarda sin tu aprobación'], ['IAA', 'IPC', 'INV']),
            $this->differentiator('paquetes-sectoriales', 'Paquetes sectoriales', 'Formatos, peligros y controles típicos de tu sector listos para empezar, en lugar de armarlos desde cero.',
                ['Plantillas por sector', 'Peligros y controles típicos', 'Se adaptan a tu empresa'], ['PSE', 'IPC', 'PET']),

            [
                'slug' => 'consultoras', 'type' => 'landing', 'title' => 'EscudoSST para consultoras SST',
                'summary' => 'Gestiona la SST de todas tus empresas cliente desde un solo panel, con tu marca.',
                'blocks' => [
                    ['type' => 'hero', 'eyebrow' => 'Plan Consultora', 'title' => 'Todas tus empresas cliente en un solo lugar', 'text' => 'Deja de llevar un Excel por cliente. Entra a cada empresa desde tu panel; cada una ve solo lo suyo.'],
                    ['type' => 'bullets', 'title' => 'Lo que ganas', 'items' => ['Un panel con todas tus empresas', 'Cada cliente con sus propios datos y su plan', 'Tu marca en el sistema', 'Los mismos formatos para todos tus clientes']],
                    ['type' => 'modules', 'title' => 'Incluye', 'items' => ['CON', 'TEN', 'USR', 'SUN']],
                    ['type' => 'cta', 'title' => '¿Eres consultora?', 'text' => 'Agenda una demo y te mostramos cómo llevarías a tus clientes.', 'button' => 'consultancy'],
                ],
            ],
        ];
    }
};
