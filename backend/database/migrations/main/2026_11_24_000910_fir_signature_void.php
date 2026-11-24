<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-FIR RN-FIR-01: «signatures» es inalterable (solo INSERT); una firma errónea se anula con una nueva fila de anulación
 * vinculada (voids_signature_id) y con motivo (void_reason), nunca borrando. Una firma se anula una sola vez y una fila de
 * anulación no se anula. §9: la solicitud firmada pasa a «voided» solo mediante esa fila.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table signatures add column if not exists voids_signature_id uuid');
        DB::statement('alter table signatures add column if not exists void_reason text');
        DB::statement('alter table signatures drop constraint if exists signatures_method_check');
        DB::statement("alter table signatures add constraint signatures_method_check check (method in ('stroke', 'pin', 'otp', 'assisted_in_person', 'scanned', 'void'))");
        DB::statement('alter table signatures drop constraint if exists ck_signatures_void');
        DB::statement("alter table signatures add constraint ck_signatures_void check ((method = 'void') = (voids_signature_id is not null)
                       and (voids_signature_id is null or length(btrim(coalesce(void_reason, ''))) > 0) and (voids_signature_id is not null or void_reason is null))");
        DB::statement('alter table signatures drop constraint if exists fk_signatures_voids_signature_id');
        DB::statement('alter table signatures add constraint fk_signatures_voids_signature_id foreign key (company_id, voids_signature_id) references signatures (company_id, id)');
        DB::statement('create unique index if not exists uq_signatures_voids_signature_id on signatures (company_id, voids_signature_id) where voids_signature_id is not null');

        DB::statement('alter table signature_requests drop constraint if exists signature_requests_status_check');
        DB::statement("alter table signature_requests add constraint signature_requests_status_check check (status in ('pending', 'signed', 'declined', 'expired', 'cancelled', 'voided'))");
    }

    public function down(): void
    {
    }
};
