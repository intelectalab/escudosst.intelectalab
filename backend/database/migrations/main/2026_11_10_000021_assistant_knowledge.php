<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asistente IA entrenado por el equipo (sin IA de pago), como el asistente del sitio:
 *  - assistant_knowledge: respuestas por tema (controles de un peligro, guía de causas, guion de charla) con sus palabras
 *    clave. El equipo las crea y edita en Panel › Asistente IA. Se precarga la biblioteca inicial de EscudoSST.
 *  - assistant_misses: lo que preguntaron las empresas y no tuvo respuesta (para enseñarle al asistente).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS assistant_knowledge (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              feature text NOT NULL CHECK (feature IN ('controls', 'causes', 'talk')),
              title text NOT NULL CHECK (length(title) BETWEEN 2 AND 120),
              keywords text[] NOT NULL DEFAULT '{}',
              items jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(items) = 'array'),
              status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
              uses integer NOT NULL DEFAULT 0,
              created_by uuid REFERENCES users (id),
              updated_by uuid REFERENCES users (id),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS assistant_misses (
              id uuid NOT NULL DEFAULT uuidv7() PRIMARY KEY,
              feature text NOT NULL CHECK (feature IN ('controls', 'causes', 'talk')),
              question text NOT NULL CHECK (length(question) BETWEEN 2 AND 300),
              times integer NOT NULL DEFAULT 1,
              status text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'taught', 'ignored')),
              last_at timestamptz NOT NULL DEFAULT now(),
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_assistant_misses UNIQUE (feature, question)
            )
            SQL);
        DB::statement('GRANT SELECT, UPDATE (uses) ON assistant_knowledge TO escudo_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON assistant_knowledge TO escudo_platform');
        DB::statement('GRANT SELECT, INSERT, UPDATE (times, last_at) ON assistant_misses TO escudo_app');
        DB::statement('GRANT SELECT, UPDATE ON assistant_misses TO escudo_platform');

        if (DB::selectOne('select count(*) as n from assistant_knowledge')->n > 0) {
            return;
        }
        $h = fn (string $g, string $t, string $d = 'Jerarquía de controles (Ley 29783, art. 21).') => ['group' => $g, 'title' => $t, 'detail' => $d];
        $seed = [
            ['controls', 'Trabajo en altura', ['altura', 'andamio', 'escalera', 'techo', 'arnés'], [
                $h('Eliminación', 'Hacer el trabajo desde el piso o con prefabricados'), $h('Ingeniería', 'Barandas, plataformas, líneas de vida y anclajes certificados'),
                $h('Administrativo', 'PETAR de altura, ATS, personal capacitado y con aptitud médica'), $h('EPP', 'Arnés de cuerpo entero con doble línea y casco con barbiquejo')]],
            ['controls', 'Ruido', ['ruido', 'sonido', 'decibel', 'auditivo'], [
                $h('Sustitución', 'Equipos menos ruidosos'), $h('Ingeniería', 'Encapsular la fuente, silenciadores y aislamiento'),
                $h('Administrativo', 'Rotación, señalización, monitoreo de ruido y audiometrías'), $h('EPP', 'Protectores auditivos según el nivel medido')]],
            ['controls', 'Sustancias químicas', ['químic', 'solvente', 'ácido', 'sustancia', 'gas', 'vapor'], [
                $h('Sustitución', 'Productos menos peligrosos (revisar hoja de seguridad)'), $h('Ingeniería', 'Ventilación o extracción localizada y contención de derrames'),
                $h('Administrativo', 'Hojas de seguridad a la vista, rotulado, capacitación y kit antiderrames'), $h('EPP', 'Guantes, lentes y respirador adecuados al producto')]],
            ['controls', 'Riesgo eléctrico', ['eléctric', 'tablero', 'cable', 'energía', 'tensión'], [
                $h('Eliminación', 'Trabajar sin tensión con bloqueo y etiquetado'), $h('Ingeniería', 'Interruptores diferenciales, puesta a tierra y tableros cerrados'),
                $h('Administrativo', 'Personal calificado, permiso de trabajo e inspección de herramientas'), $h('EPP', 'Guantes y calzado dieléctricos, careta')]],
            ['controls', 'Manipulación de cargas y posturas', ['carga', 'levant', 'manual', 'peso', 'ergonóm', 'postura'], [
                $h('Eliminación', 'Ayudas mecánicas: carretilla, montacargas o polipasto'), $h('Ingeniería', 'Rediseñar el puesto y mesas a la altura correcta'),
                $h('Administrativo', 'Límite de peso, pausas activas, levantamiento entre dos y capacitación'), $h('EPP', 'Guantes de agarre y calzado de seguridad')]],
            ['controls', 'Máquinas y herramientas', ['máquina', 'atrap', 'corte', 'herramient', 'amoladora', 'sierra'], [
                $h('Ingeniería', 'Guardas fijas, paradas de emergencia y bloqueo'), $h('Administrativo', 'Procedimiento de operación y bloqueo en mantenimiento'),
                $h('Administrativo', 'Solo personal autorizado y capacitado'), $h('EPP', 'Guantes anticorte (lejos de partes que giran) y lentes')]],
            ['controls', 'Caídas al mismo nivel', ['caída al mismo', 'piso', 'tropiez', 'resbal', 'orden'], [
                $h('Eliminación', 'Retirar obstáculos y derrames'), $h('Ingeniería', 'Pisos antideslizantes, iluminación y pasamanos'),
                $h('Administrativo', 'Orden y limpieza (5S) y señalización de piso mojado'), $h('EPP', 'Calzado antideslizante')]],
            ['controls', 'Espacios confinados', ['confinado', 'tanque', 'pozo', 'cisterna', 'buzón'], [
                $h('Eliminación', 'Hacer el trabajo desde afuera si es posible'), $h('Ingeniería', 'Ventilación forzada y medición continua de gases'),
                $h('Administrativo', 'PETAR de espacio confinado, vigía y plan de rescate'), $h('EPP', 'Equipo de respiración y arnés con línea de rescate')]],
            ['causes', 'Guía general de investigación', ['accidente', 'incidente', 'lesión', 'corte', 'caída', 'golpe'], [
                ['group' => 'Causa inmediata · acto', 'title' => '¿Se siguió el procedimiento?', 'detail' => 'Verifica si había PETS/ATS, si se cumplió y si la persona estaba autorizada y capacitada.'],
                ['group' => 'Causa inmediata · condición', 'title' => '¿Cómo estaba el lugar y el equipo?', 'detail' => 'Orden, iluminación, guardas, estado del equipo, señalización, EPP disponible y en buen estado.'],
                ['group' => 'Causa básica · personal', 'title' => 'Conocimiento, habilidad y estado', 'detail' => 'Capacitación vigente, experiencia, fatiga, apuro, comunicación.'],
                ['group' => 'Causa básica · trabajo', 'title' => 'Supervisión y planificación', 'detail' => 'Supervisión, mantenimiento, compras de EPP/equipos, diseño del puesto, carga de trabajo, IPERC actualizado.'],
                ['group' => 'Falta de control', 'title' => '¿Qué falló en el sistema?', 'detail' => 'IPERC sin este riesgo, inspecciones que no lo detectaron, acciones previas sin cerrar.'],
                ['group' => 'Entrevista', 'title' => 'Preguntas para testigos', 'detail' => '¿Qué estaba haciendo? ¿Qué pasó justo antes? ¿Era la forma habitual? ¿Qué cambiarías?']]],
            ['talk', 'Orden y limpieza', ['orden', 'limpieza', '5s'], [
                ['group' => 'Inicio (1 min)', 'title' => 'Por qué importa', 'detail' => 'Muchos tropiezos y golpes vienen de cosas fuera de su lugar.'],
                ['group' => 'Riesgos (1 min)', 'title' => 'Qué nos puede pasar', 'detail' => 'Caídas, cortes, golpes, incendios por acumulación de material.'],
                ['group' => 'Controles (2 min)', 'title' => 'Cómo lo hacemos', 'detail' => 'Cada cosa en su lugar, pasillos libres, derrames limpios al momento, residuos en su tacho.'],
                ['group' => 'Compromiso (1 min)', 'title' => 'Pregunta final', 'detail' => '¿Qué vamos a ordenar hoy en nuestra zona antes de terminar el turno?']]],
        ];
        foreach ($seed as [$f, $t, $k, $items]) {
            DB::insert('insert into assistant_knowledge (feature, title, keywords, items) values (?, ?, ?, ?::jsonb)',
                [$f, $t, '{'.implode(',', array_map(fn ($x) => '"'.str_replace('"', '', $x).'"', $k)).'}', json_encode($items, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS assistant_misses');
        DB::statement('DROP TABLE IF EXISTS assistant_knowledge');
    }
};
