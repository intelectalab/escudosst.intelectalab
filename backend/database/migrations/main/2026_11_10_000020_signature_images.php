<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Firma manuscrita digitalizada (complementa la firma electrónica de FIR):
 *  - user_signatures: la firma guardada de cada usuario (dibujada en pantalla o foto subida), para pegarla sola en
 *    los documentos que firma o valida (certificados, actas, solicitudes de firma).
 *  - signature_strokes: la imagen tal como quedó en cada firma hecha (también la del trabajador que firma asistido en
 *    la pantalla del supervisor). Así el documento muestra la firma del momento aunque luego la persona la cambie.
 * Solo se accede desde la aplicación, que valida la empresa y el dueño de cada firma.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_signatures (
              user_id uuid PRIMARY KEY REFERENCES users (id),
              image bytea NOT NULL CHECK (octet_length(image) <= 600000),
              mime_type text NOT NULL CHECK (mime_type IN ('image/png', 'image/jpeg')),
              kind text NOT NULL CHECK (kind IN ('drawn', 'uploaded')),
              updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS signature_strokes (
              signature_id uuid PRIMARY KEY,
              company_id uuid NOT NULL REFERENCES companies (id),
              image bytea NOT NULL CHECK (octet_length(image) <= 600000),
              mime_type text NOT NULL CHECK (mime_type IN ('image/png', 'image/jpeg')),
              created_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON user_signatures TO escudo_app');
        DB::statement('GRANT SELECT, INSERT ON signature_strokes TO escudo_app');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS signature_strokes');
        DB::statement('DROP TABLE IF EXISTS user_signatures');
    }
};
