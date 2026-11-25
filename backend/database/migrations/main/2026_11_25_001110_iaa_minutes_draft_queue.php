<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/17 §1 IA-04 «Redacción de actas» y §3.4: el caso «minutes_draft» en ai_requests, y la huella de la entrada
 * (input_hash) con la lista de campos enviados (input_fields): en los casos con datos de personas (IA-02, IA-03, IA-04)
 * no se guarda el texto, solo su hash y qué campos se enviaron.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('alter table ai_requests drop constraint if exists ai_requests_feature_check');
        DB::statement("alter table ai_requests add constraint ai_requests_feature_check check (feature in ('iperc_suggestion', 'investigation_guide', 'document_reading', 'training_quiz',
                       'report_classification', 'minutes_draft', 'other'))");
        DB::statement('alter table ai_requests add column if not exists input_hash text');
        DB::statement('alter table ai_requests add column if not exists input_fields text[]');
        DB::statement("comment on column ai_requests.input_hash is 'SHA-256 de la entrada redactada enviada (docs/17 §3.4); el texto no se guarda en IA-02, IA-03 e IA-04'");
        DB::statement("comment on column ai_requests.input_fields is 'campos enviados al proveedor (lista blanca del caso de uso)'");
    }

    public function down(): void
    {
        DB::statement('alter table ai_requests drop column if exists input_fields');
        DB::statement('alter table ai_requests drop column if exists input_hash');
        DB::statement("update ai_requests set feature = 'other' where feature = 'minutes_draft'");
        DB::statement('alter table ai_requests drop constraint if exists ai_requests_feature_check');
        DB::statement("alter table ai_requests add constraint ai_requests_feature_check check (feature in ('iperc_suggestion', 'investigation_guide', 'document_reading', 'training_quiz',
                       'report_classification', 'other'))");
    }
};
