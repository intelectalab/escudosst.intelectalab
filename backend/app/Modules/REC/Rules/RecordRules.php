<?php

namespace App\Modules\REC\Rules;

use App\Core\Http\ApiProblem;

/**
 * Reglas de negocio propias de una tabla, encima de la validación común del motor.
 * prepare() puede ajustar $values y agregar columnas calculadas en $extra (col => [expresión SQL, valores]).
 */
abstract class RecordRules
{
    /** @var array<string, class-string<RecordRules>> */
    private const MAP = [
        'sites' => TenRules::class,
        'areas' => TenRules::class,
        'positions' => TenRules::class,
        'work_shifts' => TenRules::class,
        'workers' => WorkerRules::class,
        'worker_assignments' => WorkerRules::class,
        'iperc_matrices' => IpercRules::class,
        'iperc_versions' => IpercRules::class,
        'iperc_rows' => IpercRules::class,
        'iperc_controls' => IpercRules::class,
        'corrective_actions' => AcrRules::class,
        'action_evidences' => AcrRules::class,
        'inspections' => InsRules::class,
        'findings' => InsRules::class,
        'annual_programs' => ProgramRules::class,
        'program_activities' => ProgramRules::class,
        'activity_executions' => ProgramRules::class,
        'training_programs' => ProgramRules::class,
        'training_program_items' => ProgramRules::class,
        'trainings' => ProgramRules::class,
        'training_sessions' => ProgramRules::class,
        'training_attendances' => ProgramRules::class,
        'training_certificates' => ProgramRules::class,
        'sst_events' => EventRules::class,
        'event_people' => EventRules::class,
        'event_notifications' => EventRules::class,
        'investigations' => EventRules::class,
        'ppe_deliveries' => PpeCommitteeRules::class,
        'ppe_stock_movements' => PpeCommitteeRules::class,
        'committees' => PpeCommitteeRules::class,
        'committee_meetings' => PpeCommitteeRules::class,
        'meeting_agreements' => PpeCommitteeRules::class,
        'baseline_assessments' => DiagnosticRules::class,
        'baseline_items' => DiagnosticRules::class,
        'legal_requirements' => DiagnosticRules::class,
        'requirement_evaluations' => DiagnosticRules::class,
        'monthly_statistics' => DiagnosticRules::class,
        'sst_policies' => DocsFieldRules::class,
        'risst_versions' => DocsFieldRules::class,
        'procedure_versions' => DocsFieldRules::class,
        'document_versions' => DocsFieldRules::class,
        'substandard_reports' => DocsFieldRules::class,
        'safety_talks' => DocsFieldRules::class,
        'ats_forms' => DocsFieldRules::class,
        'drills' => DocsFieldRules::class,
        'objective_targets' => DocsFieldRules::class,
        'risst_deliveries' => DocsFieldRules::class,
        'document_acknowledgements' => DocsFieldRules::class,
        'equipment_checks' => DocsFieldRules::class,
        'annual_plans' => ImprovementRules::class,
        'annual_plan_items' => ImprovementRules::class,
        'audits' => ImprovementRules::class,
        'management_reviews' => ImprovementRules::class,
        'measurements' => ImprovementRules::class,
        'change_requests' => ImprovementRules::class,
        'change_reviews' => ImprovementRules::class,
        'fitness_status' => PeopleRules::class,
        'medical_exam_orders' => PeopleRules::class,
        'absences' => PeopleRules::class,
        'contractor_workers' => PeopleRules::class,
        'contractor_documents' => PeopleRules::class,
        'contractor_accreditations' => PeopleRules::class,
        'credentials' => PeopleRules::class,
        'work_permits' => PeopleRules::class,
        'budget_lines' => PeopleRules::class,
        'budget_executions' => PeopleRules::class,
    ];

    public static function for(string $table): ?self
    {
        $class = self::MAP[$table] ?? null;

        return $class ? app($class) : null;
    }

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void {}

    public function after(string $op, array $res, string $id, array $values, ?object $current): void {}

    public function prepareDelete(array $res, object $current): void {}

    protected function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }

    protected function conflict(string $message): never
    {
        throw new ApiProblem(409, 'conflict', 'No se puede hacer este cambio', $message);
    }

    /** Valor final del campo: el nuevo si se envía, si no el actual. */
    protected function value(array $values, ?object $current, string $field): mixed
    {
        return array_key_exists($field, $values) ? $values[$field] : ($current?->{$field} ?? null);
    }
}
