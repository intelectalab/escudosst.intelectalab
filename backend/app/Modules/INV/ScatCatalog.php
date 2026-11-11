<?php

namespace App\Modules\INV;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo SCAT de EscudoSST (M-INV SCR-INV-04): taxonomía propia que sigue la estructura del modelo de causalidad
 * (tipo de contacto → causas inmediatas → causas básicas → necesidades de acción de control). Redacción propia,
 * «a confirmar por especialista SST» (pregunta abierta del módulo: taxonomía con licencia o propia).
 *
 * Cada código se guarda en investigation_causes.scat_code con su nivel y categoría:
 *  - actos subestándar          → immediate / unsafe_act        (A01…)
 *  - condiciones subestándar    → immediate / unsafe_condition  (S01…)
 *  - factores personales        → basic / personal_factor       (FP01…)
 *  - factores del trabajo       → basic / job_factor            (FT01…)
 *  - necesidades de control     → lack_of_control / management_system (NAC01-P|E|C)
 * El tipo de contacto (C01…) se guarda en investigations.scat_contact.
 *
 * El catálogo vigente está en la tabla global scat_catalog_items (lo edita el dueño de la plataforma en Catálogos globales);
 * las constantes de abajo son la carga inicial y el respaldo si la tabla está vacía.
 */
final class ScatCatalog
{
    public const CONTACT = [
        'C01' => 'Golpeado contra (choque contra algo inmóvil o en movimiento)',
        'C02' => 'Golpeado por (objeto en movimiento, caída de objetos)',
        'C03' => 'Caída a distinto nivel',
        'C04' => 'Caída al mismo nivel (resbalón o tropiezo)',
        'C05' => 'Atrapado en (puntos de pellizco, aberturas)',
        'C06' => 'Atrapado entre o debajo (aplastamiento, enganche)',
        'C07' => 'Contacto con energía eléctrica',
        'C08' => 'Contacto con calor, llama o superficies calientes',
        'C09' => 'Contacto con frío',
        'C10' => 'Contacto con sustancias químicas, cáusticas o tóxicas',
        'C11' => 'Exposición a ruido, vibración o radiación',
        'C12' => 'Sobreesfuerzo, sobrecarga o movimiento repetitivo',
        'C13' => 'Falla de equipo o pérdida de contención (sin contacto con personas)',
    ];

    public const ACTS = [
        'A01' => 'Operar equipos sin autorización',
        'A02' => 'No advertir ni señalizar el peligro',
        'A03' => 'No asegurar ni bloquear (energías, cargas, equipos)',
        'A04' => 'Operar a velocidad inadecuada',
        'A05' => 'Anular o retirar dispositivos de seguridad',
        'A06' => 'Usar equipos o herramientas defectuosos',
        'A07' => 'Usar equipos o herramientas de forma incorrecta',
        'A08' => 'No usar o usar mal el equipo de protección personal',
        'A09' => 'Cargar, apilar o almacenar de forma incorrecta',
        'A10' => 'Levantar objetos de forma incorrecta',
        'A11' => 'Adoptar una posición o postura inadecuada para la tarea',
        'A12' => 'Dar mantenimiento a equipos en movimiento o energizados',
        'A13' => 'Hacer bromas o distraer a otros',
        'A14' => 'Trabajar bajo la influencia de alcohol u otras sustancias',
        'A15' => 'No seguir el procedimiento o el permiso de trabajo',
        'A16' => 'Ubicarse en la línea de fuego o zona de peligro',
    ];

    public const CONDITIONS = [
        'S01' => 'Guardas o barreras inadecuadas o ausentes',
        'S02' => 'Equipo de protección personal inadecuado o insuficiente',
        'S03' => 'Herramientas, equipos o materiales defectuosos',
        'S04' => 'Espacio limitado o congestionado para trabajar',
        'S05' => 'Sistemas de advertencia o señalización insuficientes',
        'S06' => 'Peligro de incendio o explosión',
        'S07' => 'Orden y limpieza deficientes',
        'S08' => 'Atmósfera peligrosa (gases, polvos, humos, vapores)',
        'S09' => 'Exposición a ruido o vibración',
        'S10' => 'Exposición a radiación',
        'S11' => 'Temperaturas extremas',
        'S12' => 'Iluminación deficiente o excesiva',
        'S13' => 'Ventilación insuficiente',
        'S14' => 'Superficies de tránsito o de trabajo en mal estado',
        'S15' => 'Condiciones del clima o del entorno no controladas',
    ];

    public const PERSONAL = [
        'FP01' => 'Capacidad física inadecuada para el puesto',
        'FP02' => 'Capacidad mental o de percepción inadecuada para la tarea',
        'FP03' => 'Tensión física (fatiga, carga de trabajo, turnos)',
        'FP04' => 'Tensión mental (presión de tiempo, preocupación, monotonía)',
        'FP05' => 'Falta de conocimiento (no recibió la capacitación o inducción)',
        'FP06' => 'Falta de habilidad (práctica insuficiente)',
        'FP07' => 'Motivación incorrecta (ahorrar tiempo, evitar incomodidad, presión de grupo)',
    ];

    public const JOB = [
        'FT01' => 'Liderazgo o supervisión deficientes',
        'FT02' => 'Ingeniería o diseño inadecuados',
        'FT03' => 'Compras o adquisiciones deficientes',
        'FT04' => 'Mantenimiento deficiente',
        'FT05' => 'Herramientas y equipos inadecuados',
        'FT06' => 'Estándares o procedimientos de trabajo deficientes',
        'FT07' => 'Desgaste normal no controlado',
        'FT08' => 'Uso indebido o abuso de equipos',
        'FT09' => 'Comunicación deficiente (órdenes, cambios de turno)',
    ];

    /** Elementos del sistema de gestión donde se ubica la necesidad de acción de control. */
    public const NAC = [
        'NAC01' => 'Liderazgo y administración',
        'NAC02' => 'Capacitación de supervisores y jefaturas',
        'NAC03' => 'Inspecciones planeadas',
        'NAC04' => 'Análisis y procedimientos de tareas (PETS, ATS)',
        'NAC05' => 'Investigación de accidentes e incidentes',
        'NAC06' => 'Observación de tareas',
        'NAC07' => 'Preparación para emergencias',
        'NAC08' => 'Reglas y permisos de trabajo',
        'NAC09' => 'Análisis estadístico de accidentes e incidentes',
        'NAC10' => 'Capacitación e inducción del personal',
        'NAC11' => 'Equipo de protección personal',
        'NAC12' => 'Vigilancia de la salud en el trabajo',
        'NAC13' => 'Evaluación del sistema de gestión (auditorías)',
        'NAC14' => 'Controles de ingeniería y gestión del cambio',
        'NAC15' => 'Comunicación personal',
        'NAC16' => 'Reuniones de seguridad y charlas',
        'NAC17' => 'Promoción de la seguridad',
        'NAC18' => 'Selección y asignación de personal',
        'NAC19' => 'Control de compras y contratistas',
        'NAC20' => 'Mantenimiento preventivo',
    ];

    /** Qué falló del elemento de control. */
    public const PEC = [
        'P' => 'Programa inexistente o inadecuado',
        'E' => 'Estándares inadecuados',
        'C' => 'Cumplimiento inadecuado de los estándares',
    ];

    /** Nivel del catálogo → [nivel de la causa, categoría, grupo de la API]. */
    public const LEVELS = [
        'contact' => [null, null, 'contact'],
        'immediate_act' => ['immediate', 'unsafe_act', 'acts'],
        'immediate_condition' => ['immediate', 'unsafe_condition', 'conditions'],
        'basic_personal' => ['basic', 'personal_factor', 'personal'],
        'basic_job' => ['basic', 'job_factor', 'job'],
        'nac' => ['lack_of_control', 'management_system', 'nac'],
    ];

    private const CACHE = 'inv.scat_catalog_items';

    /**
     * Elementos del catálogo por código (activos e inactivos), leídos una vez por solicitud. Se leen de scat_catalog_items (lo edita el dueño de la
     * plataforma); si la tabla está vacía o no existe, se usan las constantes de esta clase.
     */
    public static function items(): array
    {
        if (app()->bound(self::CACHE)) {
            return app(self::CACHE);
        }
        try {
            $rows = DB::select('select level, code, label, explanation, category, is_active, to_confirm from scat_catalog_items order by level, sort_order, code');
        } catch (QueryException) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r->code] = ['level' => $r->level, 'code' => $r->code, 'label' => $r->label, 'explanation' => $r->explanation, 'category' => $r->category,
                'is_active' => (bool) $r->is_active, 'to_confirm' => (bool) $r->to_confirm];
        }
        if ($out === []) {
            foreach (['contact' => self::CONTACT, 'immediate_act' => self::ACTS, 'immediate_condition' => self::CONDITIONS,
                'basic_personal' => self::PERSONAL, 'basic_job' => self::JOB, 'nac' => self::NAC] as $level => $list) {
                foreach ($list as $code => $label) {
                    $out[$code] = ['level' => $level, 'code' => $code, 'label' => $label, 'explanation' => null, 'category' => null, 'is_active' => true, 'to_confirm' => true];
                }
            }
        }

        app()->instance(self::CACHE, $out);

        return $out;
    }

    /** Olvida lo leído (tras editar el catálogo en el panel). */
    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    /** Texto del catálogo de un código (o el mismo código si ya no existe). */
    public static function label(string $code): string
    {
        return self::items()[$code]['label'] ?? $code;
    }

    /** Códigos de tipo de contacto activos. */
    public static function contactCodes(bool $activeOnly = true): array
    {
        return array_keys(array_filter(self::items(), fn ($i) => $i['level'] === 'contact' && (! $activeOnly || $i['is_active'])));
    }

    /**
     * Nivel y categoría de una causa por su código (null si el código no es del catálogo).
     *
     * @return array{0: string, 1: string, 2: string, 3: bool}|null [nivel, categoría, descripción, activo]
     */
    public static function classify(string $code): ?array
    {
        $items = self::items();
        if (preg_match('/^(NAC\d{2,3})-([PEC])$/', $code, $m)) {
            $i = $items[$m[1]] ?? null;

            return $i && $i['level'] === 'nac' ? ['lack_of_control', 'management_system', $i['label'].' · '.self::PEC[$m[2]], $i['is_active']] : null;
        }
        $i = $items[$code] ?? null;
        if (! $i || $i['level'] === 'contact' || $i['level'] === 'nac') {
            return null;
        }
        [$level, $category] = self::LEVELS[$i['level']];

        return [$level, $category, $i['label'], $i['is_active']];
    }

    public static function toArray(): array
    {
        $out = ['contact' => [], 'acts' => [], 'conditions' => [], 'personal' => [], 'job' => [], 'nac' => []];
        $pending = false;
        foreach (self::items() as $i) {
            if (! $i['is_active']) {
                continue;
            }
            $pending = $pending || $i['to_confirm'];
            $out[self::LEVELS[$i['level']][2]][] = ['code' => $i['code'], 'label' => $i['label'], 'explanation' => $i['explanation'], 'category' => $i['category']];
        }
        $out['pec'] = array_map(fn ($k, $v) => ['code' => $k, 'label' => $v], array_keys(self::PEC), self::PEC);
        $out['note'] = $pending
            ? 'Catálogo propio de EscudoSST basado en la estructura del modelo SCAT; a confirmar por especialista SST.'
            : 'Catálogo propio de EscudoSST basado en la estructura del modelo SCAT; revisado por especialista SST.';

        return $out;
    }
}
