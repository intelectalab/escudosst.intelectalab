<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** M-AMB §12: un aspecto ambiental significativo puede generar su acción correctiva (origen «environmental_aspect»). */
return new class extends Migration
{
    private const TYPES = ['inspection', 'finding', 'audit', 'event', 'investigation', 'report', 'baseline', 'legal_requirement', 'committee_agreement',
        'management_review', 'monitoring', 'iperc', 'drill', 'observation', 'change', 'other'];

    public function up(): void
    {
        $this->set([...self::TYPES, 'environmental_aspect']);
    }

    public function down(): void
    {
        $this->set(self::TYPES);
    }

    private function set(array $types): void
    {
        $list = implode(', ', array_map(fn ($t) => "'{$t}'::text", $types));
        DB::statement('alter table corrective_actions drop constraint if exists corrective_actions_source_type_check');
        DB::statement("alter table corrective_actions add constraint corrective_actions_source_type_check check (source_type = any (array[{$list}]))");
    }
};
