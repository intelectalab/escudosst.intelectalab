<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ingreso con DNI y contraseña (SCR-USR-01 «correo o DNI»): busca al usuario por el HMAC del documento del
 * trabajador vinculado a su membresía (memberships.worker_id) o a su cuenta (workers.user_id), sin abrir las
 * tablas con RLS. Devuelve todas las coincidencias: el login solo acepta una.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        // En una base nueva la función ya viene en database/schema_main.sql (dueño escudo_definer): no se toca.
        if (DB::selectOne("select 1 as ok from pg_proc where proname = 'app_auth_lookup_user_by_document'")) {
            return;
        }
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_auth_lookup_user_by_document(p_hash bytea)
            RETURNS TABLE (id uuid, password_hash text, status text, mfa_enabled boolean, locked_until timestamptz, is_platform_staff boolean)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
              SELECT u.id, u.password_hash, u.status, u.mfa_enabled, u.locked_until, u.is_platform_staff
                FROM users u
               WHERE u.deleted_at IS NULL
                 AND u.id IN (
                   SELECT m.user_id FROM memberships m JOIN workers w ON w.id = m.worker_id
                    WHERE w.document_number_hash = p_hash AND w.deleted_at IS NULL AND m.deleted_at IS NULL AND m.status = 'active'
                   UNION
                   SELECT w.user_id FROM workers w
                    WHERE w.document_number_hash = p_hash AND w.deleted_at IS NULL AND w.user_id IS NOT NULL)
            $$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION public.app_auth_lookup_user_by_document(bytea) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.app_auth_lookup_user_by_document(bytea) TO escudo_app, escudo_platform');
        // Como las demás funciones SECURITY DEFINER (database/schema_main.sql, fin del esquema) debe pertenecer a
        // escudo_definer (BYPASSRLS). Si el rol dueño no puede asignarlo, lo hace un superusuario:
        //   ALTER FUNCTION public.app_auth_lookup_user_by_document(bytea) OWNER TO escudo_definer;
        try {
            DB::transaction(fn () => DB::statement('ALTER FUNCTION public.app_auth_lookup_user_by_document(bytea) OWNER TO escudo_definer'));
        } catch (QueryException) {
            fwrite(STDERR, "Aviso: falta asignar app_auth_lookup_user_by_document a escudo_definer (como superusuario); hasta entonces el ingreso con DNI no encuentra usuarios.\n");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.app_auth_lookup_user_by_document(bytea)');
    }
};
