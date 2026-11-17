<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * refresh_feature_flags fallaba al desactivar el aula (M-AUL RN-AUL-20): su INSERT ... ON CONFLICT propone la fila con
 * company_enabled verdadero y Postgres revisa ck_feature_flags_2 sobre esa fila antes de resolver el conflicto.
 * La función pertenece a escudo_definer y el rol dueño no puede reemplazarla; en su lugar, este disparador deja coherente
 * la fila propuesta (un módulo desactivado por la empresa no está activado por ella). En la actualización company_enabled no cambia.
 * En bases nuevas la función ya viene corregida en database/schema_main.sql.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.trg_feature_flags_company_mode() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.mode = 'disabled_by_company' THEN
    NEW.company_enabled := false;
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_feature_flags_company_mode ON public.feature_flags;
CREATE TRIGGER trg_feature_flags_company_mode BEFORE INSERT ON public.feature_flags FOR EACH ROW EXECUTE FUNCTION public.trg_feature_flags_company_mode();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_feature_flags_company_mode ON public.feature_flags; DROP FUNCTION IF EXISTS public.trg_feature_flags_company_mode();');
    }
};
