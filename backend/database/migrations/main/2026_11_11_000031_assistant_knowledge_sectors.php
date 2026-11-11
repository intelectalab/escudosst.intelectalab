<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Biblioteca del Asistente IA (complemento IAA) ampliada a los sectores del producto (construcción, minería,
 * agroindustria, manufactura, retail, oficinas y clínicas), alineada al catálogo de peligros y a los paquetes sectoriales:
 *  - controls: controles por jerarquía (Ley 29783 art. 21 y DS 005-2012-TR) para cada peligro frecuente.
 *  - causes:   guías de investigación por tipo de evento (causas inmediatas, básicas y falta de control).
 *  - talk:     guiones de charla de 5 minutos.
 * Contenido genérico y referencial: el equipo de EscudoSST lo revisa y edita en Panel › Asistente IA. La tabla no tiene
 * estado «por revisar»: los temas quedan activos y su nombre de origen queda en esta migración (se identifican por título).
 * Solo inserta los temas que no existen (por función y título): no pisa lo que el equipo ya editó.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('platform');
        foreach ($this->topics() as [$feature, $title, $keywords, $items]) {
            if ($db->selectOne('select 1 as ok from assistant_knowledge where feature = ? and lower(title) = lower(?)', [$feature, $title])) {
                continue;
            }
            $db->insert('insert into assistant_knowledge (feature, title, keywords, items) values (?, ?, ?, ?::jsonb)', [
                $feature, $title,
                '{'.implode(',', array_map(fn ($k) => '"'.str_replace(['"', '\\'], '', mb_strtolower($k)).'"', $keywords)).'}',
                json_encode($items, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        $db = DB::connection('platform');
        foreach ($this->topics() as [$feature, $title]) {
            $db->delete('delete from assistant_knowledge where feature = ? and title = ? and uses = 0 and updated_by is null', [$feature, $title]);
        }
    }

    /** @return list<array{0: string, 1: string, 2: list<string>, 3: list<array>}> */
    private function topics(): array
    {
        $c = fn (string $g, string $t, string $d = '') => ['group' => $g, 'title' => $t, 'detail' => $d];
        $k = fn (string $g, string $t, string $d) => ['group' => $g, 'title' => $t, 'detail' => $d];
        $talk = fn (string $why, string $risks, string $controls, string $ask) => [
            ['group' => 'Inicio (1 min)', 'title' => 'Por qué importa', 'detail' => $why],
            ['group' => 'Riesgos (1 min)', 'title' => 'Qué nos puede pasar', 'detail' => $risks],
            ['group' => 'Controles (2 min)', 'title' => 'Cómo nos cuidamos', 'detail' => $controls],
            ['group' => 'Compromiso (1 min)', 'title' => 'Pregunta final', 'detail' => $ask],
        ];

        return [
            // ── Controles por jerarquía ────────────────────────────────────────────────────────────────
            ['controls', 'Aberturas, zanjas y excavaciones', ['excavac', 'zanja', 'talud', 'derrumbe', 'entibado', 'apertura en piso', 'abertura', 'hueco', 'buzón abierto'], [
                $c('Eliminación', 'Usar métodos sin zanja o prefabricados cuando sea posible', 'Reduce el tiempo de personas dentro de la excavación.'),
                $c('Ingeniería', 'Entibado o talud según el tipo de suelo, y barandas o tapas en aberturas', 'Material excavado a una distancia segura del borde.'),
                $c('Señalización', 'Cerco perimétrico, cinta y señales en todo el borde', 'Iluminación de noche y desvío del tránsito de vehículos.'),
                $c('Administrativo', 'Ubicar redes enterradas antes de excavar, PETAR de excavación e inspección diaria del talud', 'Escalera de acceso y salida; nadie solo dentro de la zanja.'),
                $c('EPP', 'Casco, botas de seguridad, chaleco reflectivo y arnés si hay riesgo de caída'),
            ]],
            ['controls', 'Equipos de izaje y cargas suspendidas', ['izaje', 'grúa', 'grua', 'eslinga', 'carga suspendida', 'rigger', 'puente grúa', 'tecle', 'winche', 'montaje'], [
                $c('Ingeniería', 'Equipos de izaje con certificado vigente y limitador de carga', 'Accesorios (eslingas, grilletes, ganchos) con capacidad marcada y en buen estado.'),
                $c('Administrativo', 'Plan de izaje para izajes críticos, operador y rigger calificados', 'Inspección preuso del equipo y de los accesorios.'),
                $c('Señalización', 'Área de izaje delimitada: nadie bajo la carga suspendida', 'Uso de vientos (cuerdas guía) para controlar la carga.'),
                $c('Administrativo', 'Suspender el izaje con viento fuerte o sin visibilidad', 'Comunicación por radio o señales acordadas con el operador.'),
                $c('EPP', 'Casco con barbiquejo, guantes, lentes y chaleco reflectivo'),
            ]],
            ['controls', 'Trabajos en caliente', ['soldad', 'caliente', 'oxicorte', 'esmeril', 'chispa', 'amolado', 'corte con disco', 'humos metálicos', 'humos metalicos'], [
                $c('Eliminación', 'Unir o cortar en taller o con métodos en frío cuando sea posible'),
                $c('Ingeniería', 'Extracción localizada de humos y biombos o pantallas contra la radiación del arco', 'Retirar o cubrir con mantas ignífugas los materiales combustibles cercanos.'),
                $c('Administrativo', 'Permiso de trabajo en caliente con vigía contra incendio y extintor al alcance', 'Vigilar el área después de terminar por posibles focos de fuego.'),
                $c('Administrativo', 'Inspección de equipos, mangueras, válvulas antirretroceso y cilindros asegurados'),
                $c('EPP', 'Careta de soldar con filtro adecuado, guantes y mandil de cuero, respirador para humos metálicos'),
            ]],
            ['controls', 'Polvo inhalable y sílice', ['polvo', 'sílice', 'silice', 'perforac', 'chancado', 'molienda', 'cemento', 'arenado', 'cantera'], [
                $c('Sustitución', 'Materiales o métodos que generen menos polvo'),
                $c('Ingeniería', 'Humedecer la superficie, perforación húmeda y extracción con filtro', 'Cabinas cerradas en equipos y encapsulado de fajas y chancadoras.'),
                $c('Administrativo', 'Monitoreo de polvo respirable, limpieza por aspiración (no barrer en seco) y rotación', 'Vigilancia médica de los expuestos según el protocolo del médico ocupacional.'),
                $c('EPP', 'Respirador con filtro para partículas adecuado al nivel medido, con prueba de ajuste'),
            ]],
            ['controls', 'Operación de equipo pesado', ['equipo pesado', 'excavadora', 'cargador', 'volquete', 'retroexcavadora', 'tractor', 'volcadura', 'maquinaria pesada', 'camión minero', 'camion minero'], [
                $c('Ingeniería', 'Equipos con cabina antivuelco (ROPS), alarma de retroceso, cámaras y cinturón de seguridad'),
                $c('Administrativo', 'Operadores autorizados, inspección preuso y mantenimiento al día', 'Vías con ancho, bermas y pendientes adecuadas; velocidad máxima definida.'),
                $c('Señalización', 'Separar zonas de peatones y equipos; respetar el radio de giro y los puntos ciegos', 'Contacto visual o por radio antes de acercarse al equipo.'),
                $c('Administrativo', 'Control de fatiga y descanso del operador en turnos largos'),
                $c('EPP', 'Casco, chaleco reflectivo, botas de seguridad y protección auditiva según el ruido'),
            ]],
            ['controls', 'Tránsito de vehículos y montacargas en planta', ['montacarga', 'atropell', 'tránsito interno', 'transito interno', 'patio de maniobra', 'estocador', 'transpaleta', 'carretilla elevadora', 'almacén'], [
                $c('Ingeniería', 'Vías peatonales separadas con barreras, espejos convexos en cruces y alarma de retroceso'),
                $c('Señalización', 'Demarcación de pasillos, límite de velocidad y señales de pare en cruces'),
                $c('Administrativo', 'Solo operadores capacitados y autorizados; inspección preuso del montacargas', 'No transportar personas ni levantar cargas por encima de la capacidad.'),
                $c('EPP', 'Chaleco reflectivo y calzado de seguridad para quienes circulan en la zona'),
            ]],
            ['controls', 'Conducción de vehículos en vía pública', ['conduc', 'manejo', 'chofer', 'vehículo', 'vehiculo', 'carretera', 'tránsito', 'transito', 'reparto', 'motocicleta'], [
                $c('Ingeniería', 'Vehículos con mantenimiento al día, cinturones, frenos y luces en buen estado; monitoreo de velocidad (GPS) si es posible'),
                $c('Administrativo', 'Conductores con licencia vigente de la categoría, plan de viaje y control de horas de manejo', 'Prohibido usar el celular al conducir; cero alcohol.'),
                $c('Administrativo', 'Inspección preuso y kit de emergencia (botiquín, extintor, triángulos)'),
                $c('EPP', 'Casco y ropa reflectiva para motociclistas; chaleco reflectivo al bajar en la vía'),
            ]],
            ['controls', 'Bloqueo y etiquetado de energías', ['bloqueo', 'etiquetado', 'loto', 'energía peligrosa', 'energia peligrosa', 'mantenimiento', 'energía residual', 'arranque inesperado'], [
                $c('Eliminación', 'Aislar todas las fuentes de energía (eléctrica, neumática, hidráulica, mecánica, térmica) antes de intervenir'),
                $c('Ingeniería', 'Puntos de bloqueo instalados en cada fuente y liberación de energía residual'),
                $c('Administrativo', 'Procedimiento de bloqueo por equipo: candado y tarjeta personal, prueba de arranque en vacío', 'Cada trabajador coloca su propio candado; solo lo retira quien lo puso.'),
                $c('Administrativo', 'Capacitación y verificación del procedimiento por el supervisor'),
                $c('EPP', 'EPP según la energía: guantes dieléctricos, lentes y careta cuando aplique'),
            ]],
            ['controls', 'Gases comprimidos y equipos a presión', ['cilindro', 'balón', 'balon', 'compresor', 'caldera', 'presión', 'presion', 'autoclave', 'gas comprimido', 'oxígeno', 'acetileno'], [
                $c('Ingeniería', 'Válvulas de alivio, manómetros calibrados y cilindros asegurados con cadena en posición vertical'),
                $c('Administrativo', 'Mantenimiento e inspección periódica de calderas, compresores y recipientes', 'Almacenar los cilindros separados por tipo (oxidantes lejos de combustibles), con capuchón.'),
                $c('Señalización', 'Rotulado del contenido y señal de peligro en el área de almacenamiento'),
                $c('EPP', 'Lentes o careta y guantes al manipular cilindros y conexiones'),
            ]],
            ['controls', 'Materiales inflamables o combustibles', ['incendio', 'inflamable', 'combustible', 'fuego', 'gasolina', 'petróleo', 'petroleo', 'thinner', 'explosión', 'explosion'], [
                $c('Sustitución', 'Productos menos inflamables cuando sea posible'),
                $c('Ingeniería', 'Almacén ventilado, bandejas antiderrame, instalaciones eléctricas adecuadas y detectores de humo', 'Extintores del tipo correcto y en cantidad suficiente, con carga vigente.'),
                $c('Administrativo', 'Cantidades mínimas en el área de trabajo, orden y retiro de desechos combustibles', 'Prohibido fumar y hacer trabajos en caliente sin permiso; plan de respuesta a emergencias.'),
                $c('Señalización', 'Señales de inflamable, rutas de evacuación y ubicación de extintores'),
                $c('EPP', 'Guantes y lentes al trasvasar; ropa que no genere estática cuando aplique'),
            ]],
            ['controls', 'Temperatura extrema alta (estrés térmico)', ['calor', 'estrés térmico', 'estres termico', 'temperatura alta', 'deshidrat', 'golpe de calor', 'horno', 'fundición', 'fundicion'], [
                $c('Ingeniería', 'Ventilación, sombra, aislamiento de fuentes de calor y zonas de descanso frescas'),
                $c('Administrativo', 'Agua potable disponible, pausas de recuperación y programar tareas pesadas en horas frescas', 'Aclimatación progresiva del personal nuevo y vigilancia de síntomas.'),
                $c('Administrativo', 'Monitoreo de estrés térmico en los puestos expuestos'),
                $c('EPP', 'Ropa ligera de colores claros; ropa aluminizada para fuentes radiantes'),
            ]],
            ['controls', 'Radiación solar UV', ['solar', 'radiación uv', 'radiacion uv', 'intemperie', 'campo abierto', 'exposición al sol', 'exposicion al sol'], [
                $c('Ingeniería', 'Toldos y zonas con sombra para descansos y tareas fijas'),
                $c('Administrativo', 'Reprogramar tareas al aire libre fuera de las horas de mayor radiación cuando sea posible', 'Agua disponible y capacitación sobre los efectos del sol.'),
                $c('EPP', 'Bloqueador solar, sombrero o casco con cubrenuca, lentes con filtro UV y ropa de manga larga'),
            ]],
            ['controls', 'Temperatura extrema baja y altitud', ['frío', 'frio', 'cámara de frío', 'camara de frio', 'congelad', 'altitud', 'hipoxia', 'soroche', 'helada'], [
                $c('Ingeniería', 'Cámaras de frío con apertura desde adentro y alarma; refugios temperados en altitud'),
                $c('Administrativo', 'Límite de tiempo de permanencia, pausas en zona temperada y bebidas calientes', 'En altitud: evaluación médica previa, aclimatación y no trabajar solo.'),
                $c('EPP', 'Ropa térmica por capas, guantes y calzado aislante; protección del rostro'),
            ]],
            ['controls', 'Vibraciones', ['vibra', 'martillo neumático', 'martillo neumatico', 'rompepavimento', 'compactador', 'plancha compactadora'], [
                $c('Sustitución', 'Herramientas y equipos con menor vibración'),
                $c('Ingeniería', 'Mangos y asientos antivibratorios; mantenimiento de equipos y vías'),
                $c('Administrativo', 'Limitar el tiempo de exposición diario y rotar tareas', 'Monitoreo de vibraciones en los puestos expuestos.'),
                $c('EPP', 'Guantes antivibración certificados'),
            ]],
            ['controls', 'Iluminación deficiente', ['iluminación', 'iluminacion', 'oscur', 'poca luz', 'visibilidad', 'luminaria'], [
                $c('Ingeniería', 'Luminarias suficientes para la tarea, iluminación de emergencia y mantenimiento de luminarias'),
                $c('Administrativo', 'Medición de los niveles de iluminación por puesto y corrección de los puntos deficientes'),
                $c('EPP', 'Linterna frontal para zonas sin iluminación fija'),
            ]],
            ['controls', 'Uso de pantallas de visualización de datos', ['pantalla', 'computadora', 'oficina', 'monitor', 'teclado', 'digitador', 'laptop', 'escritorio', 'teletrabajo'], [
                $c('Ingeniería', 'Silla regulable con apoyo lumbar, monitor a la altura de los ojos y a un brazo de distancia', 'Teclado y ratón al nivel de los codos; evitar reflejos en la pantalla.'),
                $c('Administrativo', 'Pausas activas y descanso visual (mirar a lo lejos cada cierto tiempo)', 'Evaluación ergonómica del puesto (R.M. 375-2008-TR).'),
                $c('Administrativo', 'Capacitación en postura y organización del puesto, también en teletrabajo'),
            ]],
            ['controls', 'Movimientos repetitivos', ['repetitiv', 'empaque', 'embalaje', 'selección de fruta', 'seleccion de fruta', 'cosecha', 'línea de producción', 'linea de produccion', 'cajero', 'caja registradora'], [
                $c('Ingeniería', 'Herramientas y mesas adaptadas a la tarea, altura regulable y apoyo para antebrazos'),
                $c('Administrativo', 'Rotación entre tareas con distintos movimientos, pausas activas y ritmo de trabajo adecuado', 'Evaluación ergonómica del puesto (R.M. 375-2008-TR).'),
                $c('Administrativo', 'Capacitación en técnica de trabajo y reporte temprano de molestias'),
            ]],
            ['controls', 'Postura forzada o prolongada', ['de pie', 'sentado', 'postura forzada', 'postura prolongada', 'agachado', 'inclinado', 'arrodillado'], [
                $c('Ingeniería', 'Superficies a la altura correcta, asientos o apoyos para alternar posturas y alfombras antifatiga'),
                $c('Administrativo', 'Alternar posturas, pausas activas y rotación de tareas'),
                $c('EPP', 'Rodilleras para trabajos de rodillas y calzado cómodo y de seguridad'),
            ]],
            ['controls', 'Agentes biológicos', ['biológ', 'biolog', 'virus', 'bacteria', 'fluido', 'sangre', 'paciente', 'contagio', 'hongo', 'laboratorio clínico', 'laboratorio clinico'], [
                $c('Ingeniería', 'Lavamanos y dispensadores de alcohol cerca de la atención, ventilación y cabinas de seguridad biológica en laboratorio'),
                $c('Administrativo', 'Precauciones estándar, lavado de manos, limpieza y desinfección de superficies', 'Vacunación del personal según su exposición y protocolo ante exposición accidental.'),
                $c('Administrativo', 'Manejo de residuos según su clase y capacitación del personal'),
                $c('EPP', 'Guantes, mascarilla o respirador, protección ocular y bata según la tarea'),
            ]],
            ['controls', 'Residuos biocontaminados y punzocortantes', ['punzocortante', 'aguja', 'jeringa', 'pinchazo', 'bisturí', 'bisturi', 'biocontaminado', 'objeto cortopunzante'], [
                $c('Eliminación', 'Dispositivos con mecanismo de seguridad cuando estén disponibles'),
                $c('Ingeniería', 'Contenedores rígidos para punzocortantes al alcance del punto de uso, sin llenarlos más de lo indicado'),
                $c('Administrativo', 'No reencapuchar agujas, segregar residuos en el punto de generación y protocolo de atención ante pinchazos'),
                $c('EPP', 'Guantes y protección ocular; guantes resistentes para el personal de limpieza'),
            ]],
            ['controls', 'Plaguicidas', ['plaguicida', 'pesticida', 'fumiga', 'agroquímic', 'agroquimic', 'fitosanitario', 'herbicida', 'insecticida', 'aplicación en campo'], [
                $c('Sustitución', 'Productos de menor toxicidad y manejo integrado de plagas'),
                $c('Ingeniería', 'Equipos de aplicación en buen estado, cabina cerrada en tractores y almacén ventilado y con llave'),
                $c('Administrativo', 'Respetar el plazo de reingreso al campo, hoja de seguridad y etiqueta, personal capacitado', 'Duchas y lavado de ropa de trabajo; nunca comer, beber ni fumar durante la aplicación.'),
                $c('Señalización', 'Señalizar las zonas recién aplicadas'),
                $c('EPP', 'Respirador con filtro adecuado al producto, guantes de nitrilo, lentes, traje impermeable y botas'),
            ]],
            ['controls', 'Animales, insectos y vectores', ['animal', 'insecto', 'picadura', 'mordedura', 'serpiente', 'abeja', 'zancudo', 'mosquito', 'ganado', 'perro'], [
                $c('Ingeniería', 'Mallas, control de vectores y orden para evitar refugios de animales'),
                $c('Administrativo', 'Inspección previa de la zona de trabajo, capacitación y botiquín con indicaciones de primeros auxilios', 'Protocolo de atención y traslado rápido en caso de mordedura o picadura.'),
                $c('EPP', 'Botas altas, polainas, guantes, ropa de manga larga y repelente'),
            ]],
            ['controls', 'Herramientas manuales cortantes', ['cuchillo', 'cuchilla', 'tijera', 'machete', 'cúter', 'cutter', 'podar', 'deshuesado', 'fileteo'], [
                $c('Sustitución', 'Cuchillas retráctiles o herramientas con protección'),
                $c('Ingeniería', 'Mesas estables y soportes para fijar la pieza; afilado y mantenimiento de las herramientas'),
                $c('Administrativo', 'Técnica de corte alejando la hoja del cuerpo, guardado en fundas y capacitación'),
                $c('EPP', 'Guantes anticorte (o de malla en cárnicos) y mandil cuando aplique'),
            ]],
            ['controls', 'Proyección de partículas', ['partícula', 'particula', 'esquirla', 'viruta', 'proyección', 'proyeccion', 'cincel', 'torno'], [
                $c('Ingeniería', 'Guardas y pantallas en equipos que proyectan partículas'),
                $c('Administrativo', 'Delimitar el área para que terceros no queden en la línea de proyección', 'Inspección de discos y herramientas antes de usar.'),
                $c('EPP', 'Lentes de seguridad con protección lateral y careta facial en esmerilado'),
            ]],
            ['controls', 'Objetos almacenados en altura', ['estante', 'anaquel', 'rack', 'apilad', 'almacenamiento', 'caída de objetos', 'caida de objetos', 'góndola', 'gondola'], [
                $c('Ingeniería', 'Estanterías ancladas, con capacidad marcada y protecciones contra impactos'),
                $c('Administrativo', 'Lo pesado abajo y lo liviano arriba, altura máxima de apilamiento y escaleras para alcanzar', 'Inspección periódica de estanterías dañadas.'),
                $c('Señalización', 'Rotular la capacidad de carga por nivel'),
                $c('EPP', 'Casco en zonas de almacenamiento en altura y calzado de seguridad'),
            ]],
            ['controls', 'Atención al público con conflicto', ['público', 'publico', 'cliente agresivo', 'agresión', 'agresion', 'atención al cliente', 'atencion al cliente', 'reclamo'], [
                $c('Ingeniería', 'Mostradores con barrera, rutas de salida y botón o medio de alerta'),
                $c('Administrativo', 'Protocolo de atención de situaciones difíciles, capacitación en manejo de conflictos', 'Apoyo del supervisor y registro de los incidentes de agresión.'),
                $c('Administrativo', 'Pausas y apoyo psicológico después de un evento violento'),
            ]],
            ['controls', 'Asalto o robo', ['asalto', 'robo', 'delincuen', 'atraco', 'manejo de efectivo', 'transporte de valores'], [
                $c('Ingeniería', 'Cámaras, cajas fuertes con retardo, iluminación y control de accesos'),
                $c('Administrativo', 'Mínimo efectivo en caja, horarios variables para traslado de valores', 'Capacitación: no resistirse, proteger la vida y avisar después.'),
                $c('Administrativo', 'Atención y acompañamiento a los trabajadores después del evento'),
            ]],
            ['controls', 'Carga mental y estrés laboral', ['estrés', 'estres', 'carga mental', 'psicosocial', 'sobrecarga', 'presión de tiempo', 'presion de tiempo', 'hostigamiento', 'acoso'], [
                $c('Administrativo', 'Evaluar los factores de riesgo psicosocial con una encuesta anónima y actuar sobre los resultados'),
                $c('Administrativo', 'Distribución razonable de la carga, pausas, claridad de funciones y apoyo del supervisor'),
                $c('Administrativo', 'Canal de denuncia y procedimiento contra el hostigamiento, con confidencialidad'),
            ]],
            ['controls', 'Trabajo nocturno o por turnos', ['nocturno', 'turno de noche', 'turnos rotativos', 'guardia', 'jornada larga', 'fatiga'], [
                $c('Administrativo', 'Rotación de turnos en sentido horario y descanso suficiente entre turnos'),
                $c('Administrativo', 'Evitar tareas críticas al final del turno nocturno; pausas y alimentación disponible'),
                $c('Ingeniería', 'Buena iluminación y zonas de descanso'),
            ]],
            ['controls', 'Sismo y emergencias naturales', ['sismo', 'terremoto', 'temblor', 'huaico', 'inundación', 'inundacion', 'tsunami', 'lluvia intensa', 'evacuación', 'evacuacion'], [
                $c('Ingeniería', 'Estructuras evaluadas, estanterías y equipos anclados, salidas libres'),
                $c('Señalización', 'Rutas de evacuación, zonas seguras y punto de reunión señalizados'),
                $c('Administrativo', 'Plan de respuesta a emergencias, brigadas capacitadas y simulacros periódicos', 'Mochila o kit de emergencia y lista de contactos.'),
            ]],
            ['controls', 'Trabajo aislado o en solitario', ['aislado', 'solitario', 'trabaja solo', 'trabajo solo', 'vigilante', 'turno solo'], [
                $c('Eliminación', 'Evitar que tareas de alto riesgo se hagan sin compañía'),
                $c('Administrativo', 'Reporte periódico por radio o teléfono y procedimiento si no responde'),
                $c('Ingeniería', 'Dispositivo de alerta o pulsador de emergencia'),
            ]],
            ['controls', 'Radiación ionizante', ['rayos x', 'radiación ionizante', 'radiacion ionizante', 'radiolog', 'tomógraf', 'tomograf', 'densímetro nuclear', 'densimetro nuclear'], [
                $c('Ingeniería', 'Blindaje de salas y equipos según la autorización de la autoridad competente'),
                $c('Administrativo', 'Personal autorizado, dosimetría personal y límites de dosis; tiempo, distancia y blindaje'),
                $c('Señalización', 'Señal de radiación y acceso restringido a la zona controlada'),
                $c('EPP', 'Mandil plomado, protector de tiroides y lentes plomados según la tarea'),
            ]],
            ['controls', 'Movilización de pacientes', ['movilizar paciente', 'traslado de paciente', 'movilización de paciente', 'movilizacion de paciente', 'camilla', 'cama clínica', 'cama clinica'], [
                $c('Ingeniería', 'Grúas de traslado, sábanas deslizantes y camas regulables'),
                $c('Administrativo', 'Movilizar entre dos o más personas según el paciente y capacitación en técnicas de movilización'),
                $c('EPP', 'Calzado cerrado y antideslizante'),
            ]],
            ['controls', 'Desprendimiento de rocas en minería subterránea', ['desprendimiento', 'roca', 'sostenimiento', 'desatado', 'socavón', 'socavon', 'labor subterránea', 'labor subterranea', 'tajo', 'galería', 'galeria'], [
                $c('Ingeniería', 'Sostenimiento según la evaluación geomecánica (pernos, malla, cimbras, shotcrete)'),
                $c('Administrativo', 'Desatado de rocas antes de ingresar y durante la labor, inspección del techo y las cajas', 'Personal capacitado; no ingresar a labores sin sostenimiento.'),
                $c('Señalización', 'Bloquear el acceso a labores en mal estado'),
                $c('EPP', 'Casco con lámpara, botas, guantes, lentes, respirador y autorrescatador'),
            ]],
            ['controls', 'Explosivos y voladura', ['explosivo', 'voladura', 'disparo', 'dinamita', 'detonador', 'polvorín', 'polvorin', 'tiro cortado'], [
                $c('Ingeniería', 'Polvorines autorizados, sistemas de iniciación seguros y refugios'),
                $c('Administrativo', 'Solo personal autorizado; horario de disparo, aviso, bloqueo de accesos y conteo de tiros', 'Procedimiento para tiros cortados; ventilación antes del reingreso.'),
                $c('Señalización', 'Vigías y señales de alerta antes del disparo'),
                $c('EPP', 'Casco, lentes, protección auditiva y respirador según la labor'),
            ]],
            ['controls', 'Maquinaria agrícola', ['tractor', 'cosechadora', 'maquinaria agrícola', 'maquinaria agricola', 'toma de fuerza', 'fumigadora', 'motocultor'], [
                $c('Ingeniería', 'Cabina o arco antivuelco, cinturón y protección del eje de la toma de fuerza'),
                $c('Administrativo', 'Operadores capacitados, inspección preuso y no transportar personas en implementos', 'Detener el motor antes de desatorar o hacer mantenimiento.'),
                $c('EPP', 'Protección auditiva, calzado de seguridad y ropa ajustada sin partes sueltas'),
            ]],
            ['controls', 'Superficies calientes y cocinas', ['superficie caliente', 'cocina', 'freidora', 'olla', 'plancha caliente', 'quemadura', 'vapor caliente', 'aceite caliente'], [
                $c('Ingeniería', 'Mangos aislados, protecciones en equipos calientes y campana extractora'),
                $c('Administrativo', 'Orden en la cocina, mangos hacia adentro y aviso al pasar con objetos calientes', 'Extintor clase K para aceites y capacitación en su uso.'),
                $c('EPP', 'Guantes térmicos, mandil y calzado antideslizante'),
            ]],
            ['controls', 'Escaleras portátiles', ['escalera portátil', 'escalera portatil', 'escalera de tijera', 'escalera telescópica', 'escalera telescopica'], [
                $c('Ingeniería', 'Escaleras en buen estado, con zapatas antideslizantes y del tipo adecuado (dieléctricas cerca de electricidad)'),
                $c('Administrativo', 'Tres puntos de apoyo, no pararse en los últimos peldaños, inspección antes de usar', 'Sobre 1,8 m de altura aplicar los controles de trabajo en altura.'),
                $c('EPP', 'Calzado antideslizante y casco'),
            ]],

            // ── Guías de causas por tipo de evento ─────────────────────────────────────────────────────
            ['causes', 'Caída de altura', ['caída de altura', 'caida de altura', 'cayó del andamio', 'cayo del andamio', 'cayó de la escalera', 'cayo de la escalera', 'cayó del techo', 'cayo del techo', 'desde altura', 'arnés', 'arnes'], [
                $k('Causa inmediata · acto', '¿Usaba el arnés anclado a un punto adecuado?', 'Verifica si había punto de anclaje o línea de vida, si se desenganchó y por qué.'),
                $k('Causa inmediata · condición', '¿Cómo estaban el andamio, la escalera o la superficie?', 'Barandas, plataformas completas, estabilidad, aberturas sin protección, superficie frágil.'),
                $k('Causa básica · personal', 'Capacitación y aptitud para trabajo en altura', 'Capacitación vigente, aptitud médica para altura, experiencia en la tarea.'),
                $k('Causa básica · trabajo', 'Planificación y permiso', '¿Había PETAR y ATS? ¿Se inspeccionó el andamio y el arnés? ¿Hubo presión de tiempo?'),
                $k('Falta de control', '¿El IPERC y las inspecciones lo contemplaban?', 'Revisa si el peligro estaba en el IPERC y si las inspecciones previas detectaron la condición.'),
            ]],
            ['causes', 'Atrapamiento en máquina', ['atrap', 'máquina', 'maquina', 'rodillo', 'faja transportadora', 'engranaje', 'prensa', 'mezcladora'], [
                $k('Causa inmediata · condición', '¿La máquina tenía guardas y paradas de emergencia funcionando?', 'Guardas retiradas, dañadas o anuladas; parada de emergencia lejos o sin funcionar.'),
                $k('Causa inmediata · acto', '¿Se intervino con la máquina en marcha?', 'Limpieza, desatoro o ajuste sin bloqueo y etiquetado; uso de guantes o ropa suelta cerca de partes que giran.'),
                $k('Causa básica · trabajo', 'Procedimiento de bloqueo y mantenimiento', '¿Existía procedimiento de bloqueo? ¿Había candados y tarjetas? ¿Mantenimiento al día?'),
                $k('Causa básica · personal', 'Autorización y capacitación del operador', '¿Era operador autorizado y capacitado en esa máquina?'),
                $k('Falta de control', 'Inspecciones y cambios', '¿Hubo un cambio reciente en la máquina o el proceso sin evaluar sus riesgos?'),
            ]],
            ['causes', 'Contacto eléctrico', ['eléctric', 'electric', 'descarga', 'electrocu', 'choque eléctrico', 'arco eléctrico', 'tablero'], [
                $k('Causa inmediata · condición', 'Estado de la instalación', 'Cables pelados, tableros abiertos, falta de puesta a tierra o de interruptor diferencial.'),
                $k('Causa inmediata · acto', '¿Se trabajó con tensión?', 'Verifica bloqueo, prueba de ausencia de tensión y uso de herramientas aisladas.'),
                $k('Causa básica · personal', '¿Era personal calificado?', 'Capacitación en riesgo eléctrico y autorización para la tarea.'),
                $k('Causa básica · trabajo', 'Permiso y equipos', 'Permiso de trabajo, EPP dieléctrico disponible y en buen estado, planos actualizados.'),
                $k('Falta de control', 'Mantenimiento e inspecciones eléctricas', '¿Se inspeccionaban los tableros y herramientas? ¿Había observaciones previas sin cerrar?'),
            ]],
            ['causes', 'Incendio o quemadura', ['incendio', 'quemadura', 'fuego', 'explosión', 'explosion', 'amago', 'llama'], [
                $k('Causa inmediata · condición', 'Fuente de ignición y combustible', '¿Qué encendió el fuego y qué material se quemó? Orden, almacenamiento de inflamables.'),
                $k('Causa inmediata · acto', '¿Se hacía un trabajo en caliente o se manipulaba un inflamable?', 'Permiso de trabajo en caliente, vigía, trasvase de combustibles.'),
                $k('Causa básica · trabajo', 'Equipos contra incendio y respuesta', 'Extintores vigentes y accesibles, brigada capacitada, rutas libres.'),
                $k('Falta de control', 'Plan de emergencias', '¿Se practicaron simulacros? ¿El plan contemplaba este escenario?'),
            ]],
            ['causes', 'Exposición o intoxicación química', ['intoxica', 'químic', 'quimic', 'derrame', 'inhal', 'salpicadura', 'vapores', 'gases'], [
                $k('Causa inmediata · condición', 'Producto, envase y ventilación', '¿Qué producto fue? ¿Estaba rotulado? ¿Había ventilación o extracción?'),
                $k('Causa inmediata · acto', 'Forma de manipulación', 'Trasvase, mezcla de productos, uso del EPP indicado en la hoja de seguridad.'),
                $k('Causa básica · personal', 'Conocimiento de la hoja de seguridad', '¿La persona conocía los peligros del producto y cómo actuar?'),
                $k('Causa básica · trabajo', 'Compras y almacenamiento', 'Hojas de seguridad disponibles, almacenamiento por compatibilidad, kit antiderrame.'),
                $k('Falta de control', 'Monitoreo e IPERC', '¿Se monitoreaba el agente? ¿El IPERC incluía la tarea?'),
            ]],
            ['causes', 'Atropello o choque de vehículo', ['atropell', 'choque', 'volcadura', 'montacarga', 'vehículo', 'vehiculo', 'camión', 'camion', 'tránsito', 'transito'], [
                $k('Causa inmediata · condición', 'Vía, señalización y visibilidad', 'Separación de peatones, señales, iluminación, puntos ciegos, estado de la vía.'),
                $k('Causa inmediata · acto', 'Velocidad y maniobra', '¿Exceso de velocidad, distracción, celular, retroceso sin vigía?'),
                $k('Causa básica · personal', 'Conductor', 'Licencia y autorización interna, fatiga, horas de manejo, consumo de alcohol.'),
                $k('Causa básica · trabajo', 'Mantenimiento del vehículo', 'Frenos, luces, alarma de retroceso, inspección preuso registrada.'),
                $k('Falta de control', 'Gestión del tránsito', '¿Había un plan de tránsito y reglas de velocidad? ¿Se supervisaban?'),
            ]],
            ['causes', 'Caída al mismo nivel', ['resbal', 'tropez', 'tropiez', 'caída al mismo nivel', 'caida al mismo nivel', 'piso mojado'], [
                $k('Causa inmediata · condición', 'Piso y orden', 'Piso mojado o irregular, obstáculos, cables en el paso, iluminación.'),
                $k('Causa inmediata · acto', 'Forma de desplazarse', 'Correr, cargar objetos que tapan la vista, usar el celular al caminar.'),
                $k('Causa básica · trabajo', 'Limpieza y mantenimiento', 'Programa de orden y limpieza, señal de piso mojado, reparación de pisos.'),
                $k('Causa básica · personal', 'Calzado', '¿El calzado era adecuado y antideslizante?'),
            ]],
            ['causes', 'Sobreesfuerzo o lesión por carga', ['sobreesfuerzo', 'lumbalgia', 'espalda', 'levantar', 'cargando', 'carga pesada', 'tirón', 'tiron'], [
                $k('Causa inmediata · acto', 'Técnica de levantamiento', '¿Levantó con la espalda doblada, girando el tronco o solo una carga que requería dos personas?'),
                $k('Causa inmediata · condición', 'Peso y forma de la carga', 'Peso, agarres, distancia y altura de la carga; espacio disponible.'),
                $k('Causa básica · trabajo', 'Ayudas mecánicas y organización', '¿Había carretilla o ayuda mecánica? ¿Ritmo de trabajo, pausas?'),
                $k('Causa básica · personal', 'Capacitación', '¿Recibió capacitación en manipulación manual de cargas?'),
                $k('Falta de control', 'Evaluación ergonómica', '¿El puesto tenía evaluación ergonómica (R.M. 375-2008-TR)?'),
            ]],
            ['causes', 'Pinchazo o exposición a fluidos', ['pinchazo', 'aguja', 'punzocortante', 'fluido', 'sangre', 'salpicó', 'salpico', 'exposición biológica', 'exposicion biologica'], [
                $k('Causa inmediata · acto', 'Manipulación del objeto', '¿Se reencapuchó la aguja? ¿Se manipularon residuos con la mano?'),
                $k('Causa inmediata · condición', 'Contenedor y lugar', '¿Había contenedor rígido cerca? ¿Estaba sobrellenado?'),
                $k('Causa básica · trabajo', 'Dispositivos y carga de trabajo', 'Disponibilidad de dispositivos con seguridad, apuro por la demanda de atención.'),
                $k('Entrevista', 'Atención inmediata', 'Confirma que se aplicó el protocolo de exposición accidental y su seguimiento por el médico ocupacional.'),
            ]],
            ['causes', 'Corte con herramienta', ['corte', 'cortó', 'corto', 'cuchillo', 'cuchilla', 'cúter', 'cutter', 'machete', 'disco', 'amoladora'], [
                $k('Causa inmediata · acto', 'Uso de la herramienta', '¿Cortaba hacia el cuerpo? ¿Usaba la herramienta para otra cosa? ¿Retiró la guarda?'),
                $k('Causa inmediata · condición', 'Estado de la herramienta', 'Filo, mango, guarda, disco adecuado a la máquina y a las revoluciones.'),
                $k('Causa básica · personal', 'Capacitación y experiencia', '¿Conocía la forma segura de uso? ¿Era nuevo en la tarea?'),
                $k('Causa básica · trabajo', 'EPP y supervisión', 'Guantes anticorte disponibles, inspección de herramientas, supervisión.'),
            ]],
            ['causes', 'Golpe por caída de objetos o carga', ['cayó una carga', 'cayo una carga', 'caída de objeto', 'caida de objeto', 'izaje', 'estante', 'golpeado por', 'le cayó', 'le cayo'], [
                $k('Causa inmediata · condición', 'Almacenamiento o izaje', 'Carga mal estibada, estante dañado, accesorios de izaje en mal estado.'),
                $k('Causa inmediata · acto', 'Posición de la persona', '¿Estaba bajo la carga suspendida o en la zona de caída?'),
                $k('Causa básica · trabajo', 'Plan y delimitación', 'Plan de izaje, delimitación del área, altura máxima de apilamiento.'),
                $k('Falta de control', 'Inspecciones', '¿Se inspeccionaban estanterías y accesorios de izaje?'),
            ]],
            ['causes', 'Derrumbe en excavación', ['derrumbe', 'excavac', 'zanja', 'talud', 'sepultad'], [
                $k('Causa inmediata · condición', 'Talud y entibado', '¿El talud o entibado era el adecuado para el tipo de suelo? ¿Había material al borde?'),
                $k('Causa inmediata · acto', 'Ingreso a la zanja', '¿Se ingresó sin entibado o sin autorización?'),
                $k('Causa básica · trabajo', 'Inspección diaria y permiso', 'Inspección del talud por persona competente, lluvias o filtraciones, vibración de equipos cercanos.'),
                $k('Falta de control', 'Estudio y planificación', '¿Se consideró el estudio de suelos y el procedimiento de excavación?'),
            ]],
            ['causes', 'Lesión en los ojos', ['ojos', 'ocular', 'partícula', 'particula', 'esquirla', 'salpicadura en el ojo'], [
                $k('Causa inmediata · acto', 'Uso de protección ocular', '¿Usaba lentes o careta adecuados durante la tarea?'),
                $k('Causa inmediata · condición', 'Fuente de proyección', 'Esmerilado, corte, soldadura, químicos; guardas y pantallas.'),
                $k('Causa básica · trabajo', 'Disponibilidad del EPP', '¿Había lentes en buen estado y del tipo adecuado? ¿Lavaojos cerca?'),
            ]],
            ['causes', 'Agresión o asalto', ['agresión', 'agresion', 'asalto', 'robo', 'golpeado por un cliente', 'violencia'], [
                $k('Causa inmediata · condición', 'Lugar y medidas de seguridad', 'Barreras, cámaras, iluminación, cantidad de personal presente.'),
                $k('Causa básica · trabajo', 'Protocolos', '¿Había protocolo de atención de conflictos o de asalto? ¿Se capacitó al personal?'),
                $k('Entrevista', 'Cuidado de las personas', 'Prioriza la atención y el apoyo psicológico; entrevista con calma y en privado.'),
            ]],
            ['causes', 'Sospecha de enfermedad ocupacional', ['enfermedad ocupacional', 'hipoacusia', 'dermatitis', 'neumoconiosis', 'trastorno musculoesquelético', 'trastorno musculoesqueletico'], [
                $k('Causa básica · trabajo', 'Exposición en el puesto', 'Agente al que estuvo expuesto, tiempo de exposición y resultados de monitoreo.'),
                $k('Causa básica · trabajo', 'Controles existentes', '¿Qué controles había (ingeniería, administrativos, EPP) y si se cumplían?'),
                $k('Falta de control', 'Vigilancia médica', '¿Se hacían los exámenes ocupacionales y el seguimiento del programa de vigilancia?'),
                $k('Entrevista', 'Confidencialidad', 'Los datos clínicos los maneja el médico ocupacional; la investigación se centra en las condiciones de trabajo.'),
            ]],
            ['causes', 'Incidente peligroso o casi accidente', ['incidente peligroso', 'casi accidente', 'cuasi accidente', 'pudo pasar', 'casi se cae', 'sin lesión', 'sin lesion'], [
                $k('Causa inmediata · condición', '¿Qué condición permitió el evento?', 'Describe qué falló aunque nadie se haya lesionado.'),
                $k('Causa inmediata · acto', '¿Qué se hizo distinto a lo planificado?', 'Compara con el PETS, el ATS o el permiso de trabajo.'),
                $k('Causa básica · trabajo', 'Factores del trabajo', 'Supervisión, mantenimiento, comunicación y planificación.'),
                $k('Falta de control', 'Potencial de gravedad', 'Evalúa qué pudo pasar y prioriza las acciones según ese potencial.'),
            ]],

            // ── Guiones de charla de 5 minutos ─────────────────────────────────────────────────────────
            ['talk', 'Uso correcto del EPP', ['epp', 'equipo de protección', 'equipo de proteccion', 'casco', 'guantes', 'lentes'], $talk(
                'El EPP es la última barrera entre el peligro y nuestro cuerpo: si falla o no lo usamos, no hay otra.',
                'Golpes en la cabeza, cortes en las manos, partículas en los ojos, ruido que daña el oído poco a poco.',
                'Usa el EPP que exige tu puesto, revísalo antes de empezar, cuídalo y pide el cambio si está dañado. La entrega queda registrada.',
                '¿Qué EPP necesitas hoy para tu tarea y en qué estado está?')],
            ['talk', 'Trabajo en altura seguro', ['altura', 'arnés', 'arnes', 'andamio', 'línea de vida', 'linea de vida'], $talk(
                'Las caídas de altura están entre los accidentes más graves; sobre 1,8 m se trabaja con permiso y protección.',
                'Caer desde andamios, escaleras, techos o bordes sin baranda.',
                'PETAR y ATS firmados, arnés inspeccionado y anclado siempre a un punto resistente, barandas y plataformas completas, herramientas amarradas.',
                '¿Dónde vas a anclar tu arnés hoy y quién revisó el andamio?')],
            ['talk', 'Bloqueo y etiquetado', ['bloqueo', 'etiquetado', 'loto', 'candado', 'energía', 'energia'], $talk(
                'Muchos atrapamientos ocurren cuando alguien enciende una máquina que otro está reparando.',
                'Arranques inesperados, energía residual (presión, resortes, calor), descargas eléctricas.',
                'Identifica todas las energías, aíslalas, coloca tu propio candado y tarjeta, libera la energía residual y prueba el arranque en vacío.',
                '¿Quién tiene la llave de tu candado? Solo tú.')],
            ['talk', 'Manipulación manual de cargas', ['carga', 'levantar', 'espalda', 'peso', 'cajas'], $talk(
                'Las lesiones de espalda son frecuentes y pueden acompañarnos toda la vida.',
                'Lumbalgias, desgarros y golpes por levantar mal o cargar demasiado.',
                'Usa ayudas mecánicas, acércate a la carga, dobla las rodillas, espalda recta, no gires el tronco y pide ayuda si la carga es pesada o voluminosa.',
                '¿Qué carga de hoy deberías mover con ayuda?')],
            ['talk', 'Riesgo eléctrico', ['eléctric', 'electric', 'tablero', 'cable', 'enchufe'], $talk(
                'La electricidad no se ve ni se oye: un contacto puede ser mortal.',
                'Electrocución, quemaduras por arco eléctrico e incendios.',
                'Solo personal autorizado interviene tableros; no uses cables pelados ni extensiones dañadas; trabaja sin tensión con bloqueo; reporta enchufes y tableros en mal estado.',
                '¿Viste algún cable, enchufe o tablero dañado esta semana?')],
            ['talk', 'Prevención de incendios y uso del extintor', ['incendio', 'extintor', 'fuego', 'inflamable'], $talk(
                'Un amago atendido a tiempo no se convierte en incendio.',
                'Quemaduras, inhalación de humo y pérdida de instalaciones.',
                'Orden, inflamables en su lugar, no sobrecargar enchufes. Si hay fuego: da la alarma, usa el extintor solo si es seguro (retira el seguro, apunta a la base, presiona, barre) y evacúa si crece.',
                '¿Dónde está el extintor más cercano a tu puesto y de qué tipo es?')],
            ['talk', 'Sismo y evacuación', ['sismo', 'evacuación', 'evacuacion', 'terremoto', 'temblor', 'emergencia'], $talk(
                'Vivimos en una zona sísmica: saber qué hacer salva vidas.',
                'Caída de objetos y estructuras, pánico, bloqueos en las salidas.',
                'Conoce la ruta de evacuación y la zona segura, mantén las salidas libres, sigue a los brigadistas, no uses ascensores y ve al punto de reunión.',
                '¿Cuál es tu ruta de evacuación y tu punto de reunión?')],
            ['talk', 'Conducción segura', ['conduc', 'manejo', 'vehículo', 'vehiculo', 'chofer', 'tránsito', 'transito'], $talk(
                'Los accidentes de tránsito son una causa importante de muertes en el trabajo.',
                'Choques, atropellos y volcaduras por velocidad, distracción o fatiga.',
                'Inspección preuso, cinturón siempre, respeta la velocidad, cero celular y cero alcohol, descansa en viajes largos.',
                '¿Qué te distrae más al manejar y cómo lo vas a evitar?')],
            ['talk', 'Herramientas manuales', ['herramient', 'cuchillo', 'martillo', 'destornillador', 'cúter', 'cutter'], $talk(
                'Las herramientas sencillas causan muchos cortes y golpes en las manos.',
                'Cortes, golpes, proyección de partículas.',
                'Usa la herramienta correcta para cada tarea, revisa su estado antes de usarla, guárdala en su lugar y usa guantes y lentes cuando corresponda.',
                '¿Qué herramienta en mal estado vas a reportar o cambiar hoy?')],
            ['talk', 'Protección auditiva', ['ruido', 'auditiv', 'tapones', 'orejeras'], $talk(
                'El daño por ruido es lento, no duele y no tiene cura.',
                'Pérdida de audición, zumbidos, fatiga y dificultad para oír alarmas.',
                'Usa los protectores en las zonas señalizadas todo el tiempo de exposición, colócalos bien y mantenlos limpios.',
                '¿En qué zonas de tu trabajo es obligatorio el protector auditivo?')],
            ['talk', 'Protección respiratoria y polvo', ['polvo', 'respirador', 'mascarilla', 'sílice', 'silice', 'humos'], $talk(
                'Lo que respiramos en el trabajo puede dañar los pulmones años después.',
                'Enfermedades respiratorias por polvo, humos y vapores.',
                'Humedece antes de cortar o barrer, usa la extracción, y el respirador correcto bien ajustado; cambia los filtros según indicación.',
                '¿Tu respirador está limpio y con el filtro adecuado?')],
            ['talk', 'Sustancias químicas y hojas de seguridad', ['químic', 'quimic', 'hoja de seguridad', 'producto químico', 'rotulado'], $talk(
                'Cada producto químico tiene su hoja de seguridad que explica cómo usarlo sin dañarnos.',
                'Quemaduras, intoxicaciones, incendios por mezclas o derrames.',
                'Lee la etiqueta y la hoja de seguridad, no mezcles productos, usa envases rotulados y el EPP indicado, y limpia derrames con el kit.',
                '¿Dónde están las hojas de seguridad de los productos que usas?')],
            ['talk', 'Calor e hidratación', ['calor', 'hidrata', 'agua', 'golpe de calor', 'sol'], $talk(
                'Trabajar con calor sin hidratarse afecta la salud y la atención.',
                'Deshidratación, calambres, agotamiento y golpe de calor.',
                'Toma agua con frecuencia aunque no tengas sed, descansa en sombra, usa ropa ligera y avisa si tú o un compañero se sienten mareados.',
                '¿Cuánta agua has tomado en lo que va del turno?')],
            ['talk', 'Protección contra la radiación solar', ['radiación solar', 'radiacion solar', 'bloqueador', 'uv', 'intemperie'], $talk(
                'En el Perú la radiación solar es alta casi todo el año.',
                'Quemaduras en la piel, daño a los ojos y efectos a largo plazo.',
                'Bloqueador cada pocas horas, cubrenuca o sombrero, lentes con filtro UV, ropa de manga larga y descansos en sombra.',
                '¿Te aplicaste bloqueador antes de salir?')],
            ['talk', 'Pausas activas y ergonomía en oficina', ['pausa activa', 'pausas activas', 'oficina', 'postura', 'computadora', 'ergonom'], $talk(
                'Pasar muchas horas en la misma postura genera dolores de cuello, espalda y muñecas.',
                'Molestias musculares, fatiga visual y tensión.',
                'Ajusta la silla y el monitor, apoya los pies, haz pausas activas de estiramiento y descansa la vista mirando a lo lejos.',
                '¿A qué hora harás tu próxima pausa activa?')],
            ['talk', 'Reporte de actos y condiciones subestándar', ['reporte', 'acto subestándar', 'acto subestandar', 'condición insegura', 'condicion insegura', 'reportar'], $talk(
                'Cada reporte es una oportunidad de corregir algo antes de que alguien se lastime.',
                'Si no reportamos, la condición sigue ahí para el siguiente compañero.',
                'Reporta lo que veas (también escaneando el QR del punto de reporte, sin necesidad de usuario), describe el lugar y qué viste. Reportar es cuidar al equipo, no acusar a nadie.',
                '¿Qué condición insegura viste esta semana y la reportaste?')],
            ['talk', 'Derecho a interrumpir un trabajo peligroso', ['detener el trabajo', 'paralizar', 'peligro inminente', 'interrumpir', 'derecho'], $talk(
                'La Ley 29783 reconoce que el trabajador puede interrumpir su actividad ante un peligro grave e inminente.',
                'Seguir trabajando con una condición grave puede causar un accidente serio.',
                'Si ves un peligro grave: detén la tarea, aléjate, avisa al supervisor y no reinicies hasta que se corrija.',
                '¿A quién avisas si necesitas detener un trabajo?')],
            ['talk', 'Espacios confinados', ['confinado', 'tanque', 'pozo', 'cisterna', 'buzón', 'buzon'], $talk(
                'En un espacio confinado el aire puede matar sin que lo notemos.',
                'Falta de oxígeno, gases tóxicos o inflamables, atrapamiento.',
                'Nadie entra sin permiso, medición de gases, ventilación, vigía afuera todo el tiempo y plan de rescate listo.',
                '¿Quién es el vigía y cómo pide ayuda si algo pasa?')],
            ['talk', 'Izaje de cargas', ['izaje', 'grúa', 'grua', 'eslinga', 'carga suspendida'], $talk(
                'Una carga que cae no avisa.',
                'Golpes y aplastamientos por caída de la carga o por el balanceo.',
                'Accesorios inspeccionados, área delimitada, nadie bajo la carga, vientos para guiarla y un solo encargado de las señales.',
                '¿Quién da las señales al operador hoy?')],
            ['talk', 'Excavaciones y zanjas', ['excavac', 'zanja', 'talud', 'derrumbe'], $talk(
                'Un derrumbe en una zanja puede sepultar a una persona en segundos.',
                'Derrumbes, caídas al interior y contacto con redes enterradas.',
                'Entibado o talud adecuado, material lejos del borde, escalera de salida, barandas y señalización, inspección diaria.',
                '¿Revisaste el talud antes de bajar?')],
            ['talk', 'Uso seguro de plaguicidas', ['plaguicida', 'fumiga', 'agroquímic', 'agroquimic', 'pesticida'], $talk(
                'Los plaguicidas protegen el cultivo, pero pueden dañar a quien los aplica.',
                'Intoxicación por inhalación, contacto con la piel o ingestión.',
                'Lee la etiqueta, usa el EPP completo, no comas ni fumes al aplicar, lávate y cambia de ropa al terminar y respeta el plazo de reingreso.',
                '¿Dónde te lavas y cambias de ropa después de aplicar?')],
            ['talk', 'Lavado de manos y riesgo biológico', ['lavado de manos', 'biológ', 'biolog', 'contagio', 'higiene'], $talk(
                'Las manos llevan microbios de un lugar a otro sin que lo notemos.',
                'Contagio de enfermedades entre compañeros y pacientes o clientes.',
                'Lávate las manos antes y después de cada atención o tarea, usa guantes cuando corresponda y desecha los residuos en su tacho.',
                '¿En qué momentos del turno te lavas las manos?')],
            ['talk', 'Punzocortantes', ['punzocortante', 'aguja', 'jeringa', 'pinchazo'], $talk(
                'Un pinchazo puede transmitir enfermedades.',
                'Pinchazos y cortes con agujas, bisturís o vidrios.',
                'No reencapuches, desecha en el contenedor rígido al momento, no lo llenes de más y reporta cualquier pinchazo de inmediato.',
                '¿Sabes qué hacer en los primeros minutos después de un pinchazo?')],
            ['talk', 'Trabajos en caliente', ['soldad', 'caliente', 'oxicorte', 'esmeril'], $talk(
                'Una chispa puede viajar varios metros y empezar un incendio.',
                'Incendios, quemaduras, humos y daño a los ojos.',
                'Permiso de trabajo en caliente, retirar combustibles, extintor y vigía, careta y ropa adecuada, revisar el área al terminar.',
                '¿Quién es el vigía contra incendio en tu trabajo de hoy?')],
            ['talk', 'Tránsito de montacargas', ['montacarga', 'atropell', 'patio', 'almacén', 'almacen'], $talk(
                'Peatones y montacargas comparten el mismo espacio en el almacén.',
                'Atropellos, golpes y caída de carga.',
                'Camina solo por los pasillos peatonales, haz contacto visual con el operador, respeta la velocidad y nunca te subas a las horquillas.',
                '¿Dónde están los cruces peligrosos en nuestro almacén?')],
            ['talk', 'Atención al público y manejo de conflictos', ['atención al público', 'atencion al publico', 'cliente', 'conflicto', 'agresión', 'agresion'], $talk(
                'Atender a personas molestas es parte de muchos trabajos, pero nadie debe ser agredido.',
                'Insultos, amenazas y agresiones físicas; estrés después del evento.',
                'Mantén la calma, escucha, no discutas, pide apoyo al supervisor y aléjate si hay riesgo. Reporta cada agresión.',
                '¿A quién llamas si una situación se pone difícil?')],
            ['talk', 'Estrés laboral', ['estrés', 'estres', 'carga mental', 'psicosocial', 'ansiedad'], $talk(
                'El estrés sostenido afecta la salud y aumenta los errores.',
                'Cansancio, irritabilidad, problemas de sueño y accidentes por distracción.',
                'Organiza tus tareas, toma tus pausas, pide ayuda cuando la carga sea excesiva y participa en la encuesta psicosocial: es anónima.',
                '¿Qué te ayuda a desconectarte al final del día?')],
            ['talk', 'Primeros auxilios básicos', ['primeros auxilios', 'botiquín', 'botiquin', 'herido', 'lesionado'], $talk(
                'Los primeros minutos después de un accidente son decisivos.',
                'Empeorar una lesión por mover mal al herido o perder tiempo.',
                'Asegura la zona, pide ayuda y avisa a la brigada, no muevas al herido si no es necesario y usa el botiquín solo para lo que sabes hacer.',
                '¿Quiénes son los brigadistas de primeros auxilios de tu área?')],
            ['talk', 'Señalización de seguridad', ['señal', 'senal', 'señalización', 'senalizacion', 'colores de seguridad'], $talk(
                'Las señales nos avisan del peligro antes de llegar a él.',
                'Ignorar una señal puede llevarnos a una zona peligrosa sin protección.',
                'Rojo: prohibición y equipos contra incendio; amarillo: advertencia; azul: obligación; verde: salidas y primeros auxilios. Respétalas y reporta las dañadas.',
                '¿Qué señal ves todos los días y qué te pide?')],
            ['talk', 'ATS antes de empezar', ['ats', 'análisis de trabajo seguro', 'analisis de trabajo seguro', 'antes de empezar'], $talk(
                'El ATS nos obliga a pensar en los peligros antes de que aparezcan.',
                'Empezar sin planificar lleva a improvisar y a accidentes.',
                'Revisen en equipo los pasos de la tarea, los peligros de cada paso y sus controles; firmen solo si entienden y están de acuerdo.',
                '¿Qué paso de la tarea de hoy es el más peligroso?')],
            ['talk', 'Escaleras portátiles', ['escalera'], $talk(
                'Muchas caídas ocurren desde escaleras a poca altura.',
                'Caídas por escaleras en mal estado, mal apoyadas o mal usadas.',
                'Revisa la escalera, apóyala en piso firme, mantén tres puntos de apoyo, no subas a los últimos peldaños y no cargues objetos con las manos al subir.',
                '¿La escalera que usarás hoy está en buen estado?')],
        ];
    }
};
