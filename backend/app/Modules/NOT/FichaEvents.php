<?php

namespace App\Modules\NOT;

use Illuminate\Support\Facades\DB;

/**
 * Avisos inmediatos (§10 de las fichas) que nacen al guardar un registro desde las pantallas: se llaman después
 * de las reglas del módulo. Nunca impiden guardar: si algo falla, no se avisa.
 */
final class FichaEvents
{
    /** Revisores del comité o del supervisor SST (planes y programas enviados a aprobación). */
    private const REVIEWERS = ['committee_member', 'sst_supervisor'];

    public static function after(string $op, string $table, string $id, array $values, ?object $current): void
    {
        try {
            match ($table) {
                'annual_plans' => self::entered($values, $current, 'in_review') && self::submitted($id, 'pla.submitted', 'PLA', 'Plan Anual por aprobar',
                    'select title as name from annual_plans where id = ?', '/m/pla?t=annual_plans&open='),
                'training_programs' => self::entered($values, $current, 'committee_review') && self::submitted($id, 'pca.submitted', 'PCA', 'Programa de capacitación por aprobar',
                    'select title as name from training_programs where id = ?', '/m/pca?t=training_programs&open='),
                'baseline_assessments' => self::entered($values, $current, 'completed') && self::submitted($id, 'lba.submitted', 'LBA', 'Diagnóstico de línea base por revisar',
                    'select title as name from baseline_assessments where id = ?', '/m/lba?t=baseline_assessments&open='),
                'psychosocial_surveys' => self::entered($values, $current, 'open') && self::surveyOpen($id),
                'ergonomic_assessments' => self::ergonomic($id, $values, $current),
                'procedure_versions' => self::procedureApproved($id, $values, $current),
                'change_reviews' => self::reviewAssigned($id, $values, $current),
                'workers', 'worker_assignments' => self::worker($op, $table, $id, $values, $current),
                'document_versions' => self::entered($values, $current, 'current') && AckCampaigns::documentPublished($id) >= 0,
                'sst_policies' => self::policy($id, $values, $current),
                'risst_versions' => self::entered($values, $current, 'current') && AckCampaigns::risstPublished($id) >= 0,
                'budgets' => self::entered($values, $current, 'in_review') && self::toRoles(['management'], 'pre.submitted', 'PRE', $id, 'Presupuesto por aprobar',
                    'select title as name from budgets where id = ?', '/m/pre?t=budgets&open='),
                'risk_maps' => self::entered($values, $current, 'in_review') && self::submitted($id, 'map.submitted', 'MAP', 'Mapa de riesgos por aprobar',
                    'select title as name from risk_maps where id = ?', '/m/map?t=risk_maps&open='),
                'investigation_team' => $op === 'create' && self::teamMember($id),
                'medical_exam_orders' => self::examOrder($id, $values, $current),
                'fitness_status' => self::fitness($id, $values, $current),
                'measurements' => self::measurement($id, $values, $current),
                'training_certificates' => self::certificate($id, $values['status'] ?? null, $current?->status),
                'training_evaluations' => $op === 'create' && self::evaluationFailed($id),
                'sst_events' => ($op === 'create' || (array_key_exists('iperc_row_id', $values) && $values['iperc_row_id'] !== $current?->iperc_row_id)) && self::iperReview('sst_events', $id),
                'change_requests' => self::entered($values, $current, 'approved') && self::iperReview('change_requests', $id),
                'objective_targets' => self::targetRed($id, $current),
                default => null,
            };
        } catch (\Throwable) {
            // El aviso nunca impide guardar el registro.
        }
    }

    private static function entered(array $values, ?object $current, string $status): bool
    {
        return ($values['status'] ?? null) === $status && $current?->status !== $status;
    }

    private static function toRoles(array $roles, string $event, string $module, string $id, string $title, string $sql, string $link): bool
    {
        $r = DB::selectOne($sql, [$id]);
        Notify::users(Notify::roles($roles), $event, $module, $id, $title, "«{$r->name}» se envió a aprobación.", $link.$id, 'normal', $event.':'.$id);

        return true;
    }

    /** M-POL §10: meta en rojo (no lograda) al medir → responsable del objetivo y Jefe SST. */
    private static function targetRed(string $id, ?object $current): bool
    {
        $t = DB::selectOne('select t.id, t.status, t.period_label, o.id as objective, o.title, o.responsible_user_id from objective_targets t join sst_objectives o on o.id = t.objective_id where t.id = ?', [$id]);
        if (! $t || $t->status !== 'missed' || $current?->status === 'missed') {
            return false;
        }
        Notify::users([$t->responsible_user_id, ...Notify::roles(['sst_manager'])], 'pol.target_red', 'POL', $id, 'Meta en rojo',
            "La meta del objetivo «{$t->title}»".($t->period_label ? " ({$t->period_label})" : '').' no se logró.', '/m/pol?t=objective_targets&open='.$id, 'normal', 'pol.target_red:'.$id);

        return true;
    }

    /** M-FIR §10: firma solicitada → firmante (usuario o trabajador). */
    public static function signatureRequested(string $requestId): void
    {
        try {
            $r = DB::selectOne('select id, subject_type, signer_user_id, signer_worker_id, role_in_document, expires_at from signature_requests where id = ?', [$requestId]);
            $what = \App\Modules\REC\Registry::data()['resources'][$r->subject_type]['title'] ?? 'un registro';
            $body = "Te pidieron firmar {$what}".($r->expires_at ? ' antes del '.date('d/m/Y', strtotime($r->expires_at)) : '').'.';
            if ($r->signer_user_id) {
                Notify::users([$r->signer_user_id], 'fir.signature_requested', 'FIR', $r->id, 'Firma solicitada', $body, '/inicio', 'normal', 'fir.signature_requested:'.$r->id);
            } elseif ($r->signer_worker_id) {
                Notify::workers([$r->signer_worker_id], 'fir.signature_requested', 'FIR', $r->id, 'Firma solicitada', $body.' Entra a tu app, en «Mis pendientes».', '/mi/pendientes', 'normal', 'fir.signature_requested:'.$r->id);
            }
        } catch (\Throwable) {
        }
    }

    /** M-FIR §10: registro completamente firmado → quienes pidieron las firmas. */
    public static function signed(string $requestId): void
    {
        try {
            $r = DB::selectOne('select subject_type, subject_id from signature_requests where id = ?', [$requestId]);
            if (DB::selectOne("select 1 as ok from signature_requests where subject_type = ? and subject_id = ? and deleted_at is null and status = 'pending' limit 1", [$r->subject_type, $r->subject_id])) {
                return;
            }
            $who = array_column(DB::select("select distinct requested_by from signature_requests where subject_type = ? and subject_id = ? and deleted_at is null and status = 'signed' and requested_by is not null",
                [$r->subject_type, $r->subject_id]), 'requested_by');
            $what = \App\Modules\REC\Registry::data()['resources'][$r->subject_type]['title'] ?? 'El registro';
            Notify::users($who, 'fir.completed', 'FIR', $r->subject_id, 'Registro firmado', "{$what} ya tiene todas sus firmas.", '/inicio', 'normal', 'fir.completed:'.$r->subject_id);
        } catch (\Throwable) {
        }
    }

    /** Jefe del área (usuario del trabajador que dirige el área). */
    private static function areaLead(?string $areaId): ?string
    {
        return $areaId ? DB::selectOne('select w.user_id from areas a join workers w on w.id = a.manager_worker_id where a.id = ?', [$areaId])?->user_id : null;
    }

    /** M-POL §10: política en revisión → comité y gerencia; vigente → campaña de cargos (RN-POL-04). */
    private static function policy(string $id, array $values, ?object $current): bool
    {
        if (self::entered($values, $current, 'in_review')) {
            $r = DB::selectOne('select title from sst_policies where id = ?', [$id]);
            Notify::users(Notify::roles(['committee_member', 'management']), 'pol.in_review', 'POL', $id, 'Política SST en revisión',
                "La política «{$r->title}» se envió a revisión.", '/m/pol?t=sst_policies&open='.$id, 'normal', 'pol.in_review:'.$id);
        }
        if (self::entered($values, $current, 'current')) {
            AckCampaigns::policyPublished($id);
        }

        return true;
    }

    /** M-INV §10: designación en el equipo de investigación → integrante (usuario o trabajador). */
    private static function teamMember(string $id): bool
    {
        $t = DB::selectOne('select t.user_id, t.worker_id, t.role, i.id as inv, i.code from investigation_team t join investigations i on i.id = t.investigation_id where t.id = ?', [$id]);
        $title = 'Te designaron en una investigación';
        $body = "Formas parte del equipo de la investigación {$t->code}.";
        if ($t->user_id) {
            Notify::users([$t->user_id], 'inv.team_assigned', 'INV', $t->inv, $title, $body, '/m/inv?t=investigations&open='.$t->inv, 'normal', 'inv.team_assigned:'.$id);
        } elseif ($t->worker_id) {
            Notify::workers([$t->worker_id], 'inv.team_assigned', 'INV', $t->inv, $title, $body, '/mi', 'normal', 'inv.team_assigned:'.$id);
        }

        return true;
    }

    /** M-EMO §10: cita agendada → trabajador (sin datos clínicos); inasistencia → personal de salud y jefe del área. */
    private static function examOrder(string $id, array $values, ?object $current): bool
    {
        $o = DB::selectOne('select o.id, o.worker_id, o.scheduled_at, o.status, w.full_name, w.current_area_id from medical_exam_orders o join workers w on w.id = o.worker_id where o.id = ?', [$id]);
        if (array_key_exists('scheduled_at', $values) && $o->scheduled_at && $o->scheduled_at !== $current?->scheduled_at) {
            Notify::workers([$o->worker_id], 'emo.appointment', 'EMO', $id, 'Cita de examen médico',
                'Tienes un examen médico ocupacional el '.date('d/m/Y H:i', strtotime($o->scheduled_at)).'.', '/mi/salud', 'normal', 'emo.appointment:'.$id.':'.date('YmdHi', strtotime($o->scheduled_at)));
        }
        if (self::entered($values, $current, 'no_show')) {
            Notify::users([...Notify::roles(['occupational_doctor', 'health_staff']), self::areaLead($o->current_area_id)], 'emo.no_show', 'EMO', $id, 'Inasistencia a examen médico',
                "{$o->full_name} no asistió a su cita de examen médico ocupacional.", '/m/emo?t=medical_exam_orders&open='.$id, 'normal', 'emo.no_show:'.$id);
        }

        return true;
    }

    /**
     * M-EMO §10: resultado «no apto» o «con restricciones» → Jefe SST, jefe del área y RR.HH.; solo el estado y la
     * restricción funcional (RN-EMO-02, RN-NOT-01: nunca diagnósticos).
     */
    public static function fitness(string $id, array $values, ?object $current): bool
    {
        $f = DB::selectOne('select f.id, f.status, f.work_restrictions, w.full_name, w.current_area_id from fitness_status f join workers w on w.id = f.worker_id where f.id = ?', [$id]);
        if (! $f || ! in_array($f->status, ['unfit', 'fit_with_restrictions'], true) || $f->status === $current?->status) {
            return false;
        }
        $what = $f->status === 'unfit' ? 'no apto' : 'apto con restricciones'.($f->work_restrictions ? ': '.mb_substr((string) $f->work_restrictions, 0, 200) : '');
        Notify::users([...Notify::roles(['sst_manager', 'hr']), self::areaLead($f->current_area_id)], 'emo.fitness_restricted', 'EMO', $id, 'Resultado de aptitud',
            "{$f->full_name} quedó {$what}.", '/m/emo?t=fitness_status&open='.$id, 'high', 'emo.fitness_restricted:'.$id);

        return true;
    }

    /** M-MAG §10: medición sobre el límite → Jefe SST, médico ocupacional y jefe del área del punto. */
    private static function measurement(string $id, array $values, ?object $current): bool
    {
        $m = DB::selectOne('select m.id, m.result, m.value, m.unit, p.name as point, p.area_id, a.name as agent from measurements m left join monitoring_points p on p.id = m.monitoring_point_id
                              left join agent_types a on a.id = m.agent_type_id where m.id = ?', [$id]);
        if ($m->result !== 'exceeds_limit' || $current?->result === 'exceeds_limit') {
            return false;
        }
        Notify::users([...Notify::roles(['sst_manager', 'occupational_doctor']), self::areaLead($m->area_id)], 'mag.exceeds_limit', 'MAG', $id, 'Medición sobre el límite',
            trim(($m->agent ?? 'Agente').' en '.($m->point ?? 'el punto').": {$m->value} {$m->unit}, sobre el límite permitido."), '/m/mag?t=measurements&open='.$id, 'high', 'mag.exceeds_limit:'.$id);

        return true;
    }

    /** M-CAP §10: certificado validado → titular; anulado → titular y Jefe SST. */
    public static function certificate(string $id, ?string $to, ?string $from): bool
    {
        if (! in_array($to, ['validated', 'voided'], true) || $to === $from) {
            return false;
        }
        $c = DB::selectOne('select id, worker_id, course_title, certificate_code, void_reason from training_certificates where id = ?', [$id]);
        if ($to === 'validated') {
            Notify::workers([$c->worker_id], 'cap.certificate_valid', 'CAP', $id, 'Certificado listo', "Tu certificado de «{$c->course_title}» ({$c->certificate_code}) ya es válido.",
                '/mi/capacitaciones', 'normal', 'cap.certificate_valid:'.$id);
        } else {
            $body = "Se anuló el certificado {$c->certificate_code} de «{$c->course_title}».";
            Notify::workers([$c->worker_id], 'cap.certificate_voided', 'CAP', $id, 'Certificado anulado', $body, '/mi/capacitaciones', 'normal', 'cap.certificate_voided:'.$id);
            Notify::users(Notify::roles(['sst_manager']), 'cap.certificate_voided', 'CAP', $id, 'Certificado anulado', $body.($c->void_reason ? " Motivo: {$c->void_reason}" : ''),
                '/m/cap?t=training_certificates&open='.$id, 'normal', 'cap.certificate_voided:'.$id);
        }

        return true;
    }

    /** M-CAP §10: evaluación desaprobada → participante y capacitador. */
    private static function evaluationFailed(string $id): bool
    {
        $e = DB::selectOne('select e.id, e.worker_id, e.passed, t.title, s.instructor_user_id, w.full_name from training_evaluations e join trainings t on t.id = e.training_id
                              left join training_sessions s on s.id = e.session_id left join workers w on w.id = e.worker_id where e.id = ?', [$id]);
        if (! $e || $e->passed !== false) {
            return false;
        }
        Notify::workers([$e->worker_id], 'cap.evaluation_failed', 'CAP', $id, 'Evaluación desaprobada', "No aprobaste la evaluación de «{$e->title}».", '/mi/capacitaciones', 'normal', 'cap.evaluation_failed:'.$id);
        Notify::users([$e->instructor_user_id], 'cap.evaluation_failed', 'CAP', $id, 'Evaluación desaprobada', ($e->full_name ?? 'Un participante')." no aprobó la evaluación de «{$e->title}».",
            '/m/cap?t=training_evaluations&open='.$id, 'normal', 'cap.evaluation_failed:'.$id);

        return true;
    }

    /** M-IPC RN-IPC-09 / M-ACC RN-ACC-08: evento ligado a una fila, o cambio aprobado ligado a una versión, marca la matriz. */
    private static function iperReview(string $table, string $id): bool
    {
        $table === 'sst_events' ? \App\Modules\IPC\Services\IpercReview::fromEvent($id) : \App\Modules\IPC\Services\IpercReview::fromChange($id);

        return true;
    }

    /** M-IPC §10 «IPERC de mi puesto actualizado» y M-MAP «Mapa requiere revisión por cambio de IPERC», al aprobarse una versión. */
    public static function ipercApproved(string $versionId): void
    {
        try {
            $m = DB::selectOne('select m.id, m.code, m.title, m.position_id, m.area_id, m.site_id, v.version_number from iperc_versions v join iperc_matrices m on m.id = v.iperc_matrix_id where v.id = ?', [$versionId]);
            if ($m->position_id) {
                $workers = array_column(DB::select("select id from workers where current_position_id = ? and status = 'active' and deleted_at is null", [$m->position_id]), 'id');
                Notify::workers($workers, 'ipc.position_updated', 'IPC', $m->id, 'IPERC de tu puesto actualizado',
                    "Se aprobó la versión {$m->version_number} de la matriz de riesgos de tu puesto ({$m->code}).", '/mi', 'normal', 'ipc.position_updated:'.$versionId);
            }
            foreach (DB::select("select id, title from risk_maps where deleted_at is null and status = 'current'
                                  and ((?::uuid is not null and area_id = ?::uuid) or (?::uuid is not null and site_id = ?::uuid))", [$m->area_id, $m->area_id, $m->site_id, $m->site_id]) as $map) {
                DB::update("update risk_maps set status = 'review_required', review_reason = ? where id = ? and status = 'current'", ["Se aprobó una nueva versión del IPERC {$m->code}", $map->id]);
                Notify::users(Notify::roles(['sst_manager']), 'map.review_needed', 'MAP', $map->id, 'Mapa de riesgos por revisar',
                    "Cambió la matriz IPERC {$m->code}: revisa el mapa «{$map->title}».", '/m/map?t=risk_maps&open='.$map->id, 'normal', 'map.review_needed:'.$map->id.':'.$versionId);
            }
        } catch (\Throwable) {
            // El aviso nunca impide aprobar la matriz.
        }
    }

    /** Alta o cambio de puesto: EPP pendiente (M-EPP), requisitos pendientes (M-TRB) y cargos de política y RISST (RN-POL-04, RN-RIS-04). */
    private static function worker(string $op, string $table, string $id, array $values, ?object $current): bool
    {
        self::ppePending($table, $id, $values, $current);
        if ($table === 'workers' && $op === 'create') {
            AckCampaigns::newWorker($id);
            $req = app(\App\Modules\TRB\WorkerRequirements::class)->evaluate([$id])[0] ?? null;
            $missing = array_values(array_filter($req['checks'] ?? [], fn ($c) => $c['status'] === 'bad'));
            if ($missing) {
                $w = DB::selectOne('select full_name from workers where id = ?', [$id]);
                Notify::users(Notify::roles(['sst_manager']), 'trb.new_worker_pending', 'TRB', $id, 'Trabajador nuevo con requisitos pendientes',
                    "{$w->full_name} ingresó con pendientes: ".implode(', ', array_slice(array_column($missing, 'label'), 0, 6)).'.', '/m/trb?t=workers&open='.$id, 'normal', 'trb.new_worker_pending:'.$id);
            }
        }

        return true;
    }

    private static function submitted(string $id, string $event, string $module, string $title, string $sql, string $link): bool
    {
        $r = DB::selectOne($sql, [$id]);
        Notify::users(Notify::roles(self::REVIEWERS), $event, $module, $id, $title, "«{$r->name}» se envió a revisión.", $link.$id, 'normal', $event.':'.$id);

        return true;
    }

    /** M-PSI §10: encuesta abierta → trabajadores con cuenta dentro del alcance (sedes o áreas, si se indicaron). */
    private static function surveyOpen(string $id): bool
    {
        $s = DB::selectOne('select id, title, ends_on, target_scope from psychosocial_surveys where id = ?', [$id]);
        $workers = array_column(DB::select(
            "select w.id from workers w, psychosocial_surveys s
              where s.id = ? and w.status = 'active' and w.deleted_at is null
                and (jsonb_typeof(s.target_scope -> 'site_ids') is distinct from 'array' or w.current_site_id::text in (select jsonb_array_elements_text(s.target_scope -> 'site_ids')))
                and (jsonb_typeof(s.target_scope -> 'area_ids') is distinct from 'array' or w.current_area_id::text in (select jsonb_array_elements_text(s.target_scope -> 'area_ids')))",
            [$id]
        ), 'id');
        $until = $s->ends_on ? ' Tienes hasta el '.date('d/m/Y', strtotime($s->ends_on)).'.' : '';
        Notify::workers($workers, 'psi.survey_open', 'PSI', $id, 'Nueva encuesta para responder', "Ya puedes responder la encuesta «{$s->title}». Es anónima.{$until}",
            '/mi/pendientes', 'normal', 'psi.survey_open:'.$id);

        return true;
    }

    /** M-PSI §10: evaluación ergonómica con nivel alto o muy alto → Jefe SST (sin repetir si el nivel no cambia). */
    private static function ergonomic(string $id, array $values, ?object $current): bool
    {
        $level = $values['risk_level'] ?? null;
        if (! in_array($level, ['high', 'very_high'], true) || $level === $current?->risk_level) {
            return false;
        }
        $e = DB::selectOne('select e.method, p.name as position from ergonomic_assessments e left join positions p on p.id = e.position_id where e.id = ?', [$id]);
        Notify::users(Notify::roles(['sst_manager']), 'psi.ergonomic_high', 'PSI', $id, 'Evaluación ergonómica con nivel alto',
            'Una evaluación ergonómica'.($e->position ? " del puesto {$e->position}" : '').' salió con riesgo '.($level === 'very_high' ? 'muy alto' : 'alto').': revisa sus recomendaciones.',
            '/m/psi?t=ergonomic_assessments&open='.$id, 'high', 'psi.ergonomic_high:'.$id.':'.$level);

        return true;
    }

    /** M-PET §10: PETS aprobado → trabajadores con cuenta de los puestos vinculados. */
    private static function procedureApproved(string $id, array $values, ?object $current): bool
    {
        $live = ['approved', 'current'];
        if (! in_array($values['status'] ?? null, $live, true) || in_array($current?->status, $live, true)) {
            return false;
        }
        \App\Modules\PET\PetStepsController::requireTrainings($id);
        $p = DB::selectOne('select p.id, p.code, p.title, p.position_id, v.version_number from procedure_versions v join procedures p on p.id = v.procedure_id where v.id = ?', [$id]);
        if (! $p->position_id) {
            return false;
        }
        $workers = array_column(DB::select("select id from workers where current_position_id = ? and status = 'active' and deleted_at is null", [$p->position_id]), 'id');
        Notify::workers($workers, 'pet.approved', 'PET', $p->id, 'Nuevo procedimiento de tu puesto',
            "Se aprobó {$p->code} · {$p->title} (versión {$p->version_number}) para tu puesto.", '/mi', 'normal', 'pet.approved:'.$id);

        return true;
    }

    /** M-MOC §10: revisión asignada → al revisor. */
    private static function reviewAssigned(string $id, array $values, ?object $current): bool
    {
        $to = $values['reviewer_user_id'] ?? null;
        if (! $to || $to === $current?->reviewer_user_id) {
            return false;
        }
        $c = DB::selectOne('select c.id, c.code, c.title from change_reviews r join change_requests c on c.id = r.change_request_id where r.id = ?', [$id]);
        Notify::users([$to], 'moc.review_assigned', 'MOC', $c->id, 'Revisión de cambio asignada', "Te asignaron revisar el cambio {$c->code} · {$c->title}.",
            '/m/moc?t=change_requests&open='.$c->id, 'normal', 'moc.review_assigned:'.$id);

        return true;
    }

    /** M-EPP §10: trabajador nuevo o con nuevo puesto que tiene EPP obligatorio sin entregar → supervisores que ven la sede del trabajador. */
    private static function ppePending(string $table, string $id, array $values, ?object $current): bool
    {
        if ($table === 'workers') {
            $position = $values['current_position_id'] ?? null;
            if (! $position || $position === $current?->current_position_id) {
                return false;
            }
            $worker = $id;
        } else {
            if ($current || ! ($values['is_primary'] ?? true) || ! ($values['position_id'] ?? null)) {
                return false;
            }
            $worker = $values['worker_id'];
            $position = $values['position_id'];
        }
        $w = DB::selectOne("select id, full_name, current_site_id from workers where id = ? and status <> 'terminated'", [$worker]);
        if (! $w) {
            return false;
        }
        $missing = DB::select(
            "select i.name from ppe_position_requirements r join ppe_items i on i.id = r.ppe_item_id
              where r.position_id = ? and r.deleted_at is null and r.is_mandatory
                and not exists (select 1 from ppe_deliveries d where d.worker_id = ? and d.ppe_item_id = r.ppe_item_id and d.deleted_at is null and d.status = 'delivered')
              order by i.name",
            [$position, $worker]
        );
        if ($missing === []) {
            return false;
        }
        $names = implode(', ', array_slice(array_column($missing, 'name'), 0, 6)).(count($missing) > 6 ? '…' : '');
        Notify::users(Notify::siteTeam(['sst_supervisor'], $w->current_site_id), 'epp.new_worker_pending', 'EPP', $worker, 'Trabajador con EPP pendiente',
            "{$w->full_name} necesita su EPP obligatorio: {$names}.", '/m/epp?t=ppe_deliveries&worker='.$worker, 'normal', 'epp.new_worker_pending:'.$worker.':'.$position);

        return true;
    }
}
