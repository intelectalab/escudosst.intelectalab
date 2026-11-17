<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-LBA §7 y §9: el diagnóstico se envía a revisión del comité o supervisor (in_review), que lo aprueba o lo devuelve con
 * observaciones (vuelve a borrador).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        $ck = DB::selectOne("select conname from pg_constraint where conrelid = 'baseline_assessments'::regclass and contype = 'c' and pg_get_constraintdef(oid) like '%''archived''%'");
        if ($ck) {
            DB::statement("alter table baseline_assessments drop constraint {$ck->conname}");
        }
        DB::statement("alter table baseline_assessments add constraint ck_baseline_assessments_status check (status in ('draft', 'in_progress', 'completed', 'in_review', 'approved', 'archived'))");
        DB::statement('alter table baseline_assessments add column if not exists review_notes text, add column if not exists submitted_for_review_at timestamptz,
                       add column if not exists previous_assessment_id uuid references baseline_assessments(id)');
    }

    public function down(): void
    {
        DB::statement('alter table baseline_assessments drop column if exists review_notes, drop column if exists submitted_for_review_at, drop column if exists previous_assessment_id');
    }
};
