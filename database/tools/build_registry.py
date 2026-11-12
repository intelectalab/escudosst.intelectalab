"""Arma el catálogo de pantallas de registros (backend/resources/registry/registry.json).

Fuentes:
- database/catalogs/model.json: tablas, columnas, tipos, opciones (CHECK IN) y referencias.
- database/catalogs/labels_es.json: nombres en español de columnas y opciones.
- docs/modulos/M-XXX.md §5: propósito de cada tabla (título de la pestaña) y su orden.

Uso: python database/tools/build_registry.py
"""

import glob
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "backend", "resources", "registry", "registry.json")

# Módulos con pantallas propias (no pasan por el motor de registros).
SKIP_MODULES = {"WEB", "SUS", "USR", "AUD"}
# Tablas que se gestionan con su propio flujo o son internas.
SKIP_TABLES = {"accounts", "companies", "files", "worker_consents", "arco_requests", "bulk_imports", "bulk_import_rows"}
SYSTEM = {"id", "company_id", "created_at", "created_by", "updated_at", "updated_by", "deleted_at",
          "idempotency_key", "device_id", "client_created_at", "sync_batch_id", "version"}
# Secretos que solo se muestran una vez (al crear o rotar): nunca en formularios ni fichas (M-API RN-API-03).
SECRET_COLS = {"webhooks.secret"}
HIDDEN_REFS = {"signature_requests", "signatures", "devices", "sync_batches", "accounts", "companies", "ai_requests", "memberships", "bulk_imports"}
# Campos que calcula el sistema: se muestran pero no se editan.
READONLY = {"psychosocial_surveys.is_anonymous", "psychosocial_surveys.min_responses_param", "psychosocial_responses.worker_id", "areas.depth", "workers.document_last4", "workers.activated_at", "workers.whatsapp_opt_in_at",
            "workers.full_name",
            # IPERC: lo calcula el sistema o lo fija el flujo de aprobación (M-IPC RN-IPC-01, 02, 06, 08).
            "iperc_matrices.current_version_id", "iperc_matrices.next_review_on",
            "iperc_versions.version_number", "iperc_versions.status", "iperc_versions.prepared_by", "iperc_versions.prepared_at",
            "iperc_versions.reviewed_by", "iperc_versions.approved_by", "iperc_versions.approved_at", "iperc_versions.effective_on",
            "iperc_versions.obsoleted_at", "iperc_rows.row_number", "iperc_rows.risk_level", "iperc_rows.is_significant",
            "iperc_rows.residual_level", "iperc_rows.ai_assisted", "iperc_rows.ai_suggestion_id", "iperc_controls.corrective_action_id",
            # ACR / INS: los fija el flujo (verificación, evidencias, checklist).
            "corrective_actions.completed_at", "corrective_actions.verified_by", "corrective_actions.verified_at", "corrective_actions.reopened_count",
            "action_evidences.submitted_by", "action_evidences.submitted_at", "inspections.score_pct", "inspections.started_at",
            "inspections.completed_at", "findings.corrective_action_id", "inspection_answers.is_compliant", "inspection_answers.is_critical",
            "inspection_answers.question_snapshot",
            # PAS / PCA / CAP: avance, aprobación y certificados los calcula o emite el sistema.
            "annual_programs.progress_pct", "annual_programs.approved_by", "annual_programs.approved_at", "annual_programs.closed_at",
            "program_activities.executed_quantity", "program_activities.progress_pct", "activity_executions.recorded_by",
            "activity_executions.verified_by", "activity_executions.verified_at", "training_programs.approved_at",
            "training_certificates.certificate_code", "training_certificates.verification_code", "training_certificates.holder_name", "training_certificates.replaces_certificate_id", "absences.days",
            "training_certificates.holder_document_masked", "training_certificates.course_title", "training_certificates.issued_on",
            "training_certificates.expires_on", "training_certificates.hours", "training_certificates.score", "training_certificates.validated_by",
            "training_certificates.validated_at", "training_certificates.voided_by", "training_certificates.voided_at",
            # ACC / INV / EPP / COM: plazos, totales y cierres los fija el sistema.
            "sst_events.notification_deadline_at", "sst_events.notification_overdue", "sst_events.lost_days", "sst_events.injured_count",
            "sst_events.reporter_user_id", "sst_events.closed_at", "event_notifications.deadline_at", "event_notifications.notification_overdue",
            "event_notifications.notified_by", "event_notifications.legal_parameter_key", "investigations.approved_by", "investigations.approved_at",
            "investigation_causes.corrective_action_id", "meeting_agreements.corrective_action_id", "meeting_agreements.fulfilled_at",
            "baseline_gaps.corrective_action_id", "substandard_reports.corrective_action_id", "requirement_evaluations.corrective_action_id",
            "ppe_deliveries.delivered_by", "ppe_stock_movements.ppe_delivery_id",
            # LBA / MRL: puntajes y aprobación los calcula el sistema.
            "baseline_items.score", "baseline_assessments.total_items", "baseline_assessments.compliant_items", "baseline_assessments.score_pct",
            "baseline_assessments.approved_by", "baseline_assessments.approved_at", "baseline_gaps.closed_at",
            # Documentos con versiones y registros de campo.
            "sst_policies.version_number", "sst_policies.prepared_by", "sst_policies.approved_by", "sst_policies.approved_at", "sst_policies.published_at",
            "sst_policies.obsoleted_at", "risst_versions.version_number", "risst_versions.prepared_by", "risst_versions.approved_by", "risst_versions.approved_at",
            "risst_versions.obsoleted_at", "procedure_versions.version_number", "procedure_versions.prepared_by", "procedure_versions.approved_by",
            "procedure_versions.approved_at", "procedure_versions.obsoleted_at", "procedures.current_version_id", "documents.current_version_id",
            "document_versions.version_number", "document_versions.submitted_by", "document_versions.submitted_at", "document_versions.approved_by",
            "document_versions.approved_at", "document_versions.obsoleted_at", "substandard_reports.triaged_by", "substandard_reports.triaged_at",
            "substandard_reports.closed_at", "substandard_reports.reporter_user_id", "objective_targets.achievement_pct",
            "annual_plans.approved_by", "annual_plans.approved_at", "management_reviews.approved_by", "management_reviews.approved_at",
            "audit_findings.corrective_action_id", "management_review_outputs.corrective_action_id", "measurements.corrective_action_id",
            "change_requests.decision_at", "change_requests.implemented_at",
            # Salud, contratistas, fotocheck, permisos y presupuesto.
            "credentials.credential_number", "credentials.token_last4", "credentials.issued_at", "credentials.issued_by", "credentials.last_evaluated_at",
            "credentials.blocking_reasons", "credentials.revoked_at", "credentials.qr_payload_version", "work_permits.requested_by", "work_permits.activated_at",
            "work_permits.closed_at", "work_permits.closed_by", "contractor_documents.reviewed_by", "contractor_documents.reviewed_at",
            "contractor_documents.uploaded_by", "contractor_accreditations.approved_by", "contractor_accreditations.approved_at",
            "contractor_accreditations.reviewed_by", "contractor_accreditations.reviewed_at", "contractor_accreditations.submitted_at",
            "contractor_workers.document_last4", "fitness_status.issued_by", "fitness_status.issued_at", "medical_exam_orders.requested_by",
            "absences.days", "absences.registered_by", "budgets.total_planned", "budgets.total_executed", "budget_lines.executed_amount",
            "budget_executions.recorded_by",
            # Complementos: SCTR, ingreso, homologación, ambiente, firma, mapa de riesgos.
            "sctr_coverages.status", "access_events.guard_user_id", "access_events.deny_reasons", "vendor_evaluations.total_score",
            "environmental_aspects.significance_score", "work_adjustments.approved_by", "signature_requests.requested_by", "signature_requests.signed_at",
            "risk_maps.version_number", "risk_maps.approved_by", "risk_maps.approved_at",
            "api_clients.client_key", "api_clients.last_used_at", "webhooks.secret", "webhooks.failure_count", "webhooks.disabled_at"}
# Columnas de la lista elegidas a mano donde las automáticas no dicen lo importante.
LIST = {
    # Columnas de cada lista principal como en el prototipo (prototipo/src/m-*.js) y las fichas docs/modulos.
    "monthly_statistics": ["period_year", "period_month", "site_id", "hours_worked", "frequency_index", "severity_index", "accident_rate_index", "is_closed"],
    "man_hours": ["period_year", "period_month", "site_id", "worker_count", "hours_worked"],
    "program_activities": ["code", "title", "goal_quantity", "executed_quantity", "progress_pct", "status"],
    "baseline_items": ["lineament_name", "item_code", "question", "result"],
    "sst_events": ["code", "event_type", "occurred_at", "site_id", "notification_deadline_at", "status"],
    "risk_map_markers": ["marker_type", "label", "x_pct", "y_pct", "risk_map_id"],
    "sites": ["name", "site_type", "address", "headcount_estimated", "status"],
    "areas": ["name", "site_id", "parent_area_id", "status"],
    "positions": ["name", "area_id", "occupational_group", "is_critical", "status"],
    "work_shifts": ["name", "start_time", "end_time", "site_id", "status"],
    "workers": ["full_name", "document_last4", "current_position_id", "current_area_id", "current_site_id", "status"],
    "documents": ["code", "title", "category_id", "next_review_on", "status"],
    "notification_rules": ["name", "event_code", "lead_days", "channels", "is_active"],
    "notification_deliveries": ["attempted_at", "channel", "notification_id", "status"],
    "baseline_assessments": ["title", "assessment_on", "score_pct", "status"],
    "sst_policies": ["version_number", "title", "approved_by", "approved_at", "status"],
    "sst_objectives": ["title", "indicator", "target_value", "unit", "responsible_user_id", "status"],
    "legal_requirements": ["legal_norm_id", "article", "requirement", "responsible_user_id", "next_evaluation_on", "status"],
    "annual_plans": ["year", "title", "approved_at", "status"],
    "annual_programs": ["year", "title", "progress_pct", "status"],
    "training_programs": ["year", "title", "approved_at", "status"],
    "risst_versions": ["version_number", "title", "effective_on", "status"],
    "risst_deliveries": ["worker_id", "risst_version_id", "delivered_on", "method", "status"],
    "committees": ["name", "committee_type", "period_start_on", "period_end_on", "status"],
    "committee_meetings": ["number", "meeting_type", "scheduled_at", "modality", "quorum_reached", "status"],
    "budgets": ["year", "title", "total_planned", "total_executed", "status"],
    "risk_maps": ["title", "site_id", "floor_label", "version_number", "status"],
    "monitoring_points": ["code", "name", "site_id", "agent_type_id", "status"],
    "monitoring_campaigns": ["title", "starts_on", "provider_name", "status"],
    "measurements": ["monitoring_point_id", "agent_type_id", "value", "unit", "measured_at", "result"],
    "ergonomic_assessments": ["position_id", "method", "score", "risk_level", "assessed_on", "status"],
    "psychosocial_surveys": ["title", "instrument", "starts_on", "ends_on", "status"],
    "procedures": ["code", "title", "area_id", "is_critical_task", "next_review_on", "status"],
    "change_requests": ["code", "title", "change_type", "requested_by", "requested_on", "status"],
    "inspection_templates": ["code", "name", "inspection_type", "version_number", "status"],
    "inspections": ["code", "title", "site_id", "inspector_user_id", "scheduled_on", "score_pct", "status"],
    "findings": ["inspection_id", "finding_type", "severity", "site_id", "status"],
    "report_points": ["code", "name", "site_id", "report_count", "last_used_at", "is_active"],
    "substandard_reports": ["code", "origin", "report_type", "site_id", "occurred_at", "risk_level", "status"],
    "safety_talks": ["talk_on", "topic", "speaker_worker_id", "area_id", "duration_minutes", "status"],
    "ats_forms": ["code", "task", "work_location", "performed_on", "supervisor_user_id", "status"],
    "work_permits": ["code", "permit_type_id", "site_id", "executor_leader_worker_id", "valid_from", "valid_until", "status"],
    "behavior_observations": ["observed_on", "area_id", "observer_worker_id", "task_observed", "safe_count", "at_risk_count"],
    "ppe_items": ["code", "name", "category", "useful_life_days", "min_stock", "is_active"],
    "ppe_position_requirements": ["position_id", "ppe_item_id", "quantity", "replacement_days", "is_mandatory"],
    "ppe_stock_movements": ["moved_at", "ppe_item_id", "site_id", "movement_type", "quantity"],
    "ppe_deliveries": ["worker_id", "ppe_item_id", "quantity", "delivered_at", "expires_on", "status"],
    "emergency_equipment": ["code", "equipment_type", "site_id", "area_id", "next_maintenance_on", "expires_on", "status"],
    "first_aid_kits": ["code", "kit_type", "site_id", "responsible_worker_id", "last_checked_on", "status"],
    "drills": ["code", "scheduled_at", "drill_type", "site_id", "participants_count", "evacuation_time_seconds", "status"],
    "brigades": ["name", "brigade_type", "leader_worker_id", "site_id", "status"],
    "trainings": ["code", "title", "modality", "instructor_name", "duration_minutes", "status"],
    "training_certificates": ["certificate_code", "holder_name", "course_title", "score", "issued_on", "expires_on", "status"],
    "position_training_requirements": ["position_id", "training_id", "requirement_level", "renewal_months"],
    "credentials": ["credential_number", "worker_id", "credential_type_id", "expires_on", "status"],
    "medical_exam_orders": ["worker_id", "position_id", "exam_type", "clinic_name", "scheduled_at", "status"],
    "fitness_status": ["worker_id", "status", "valid_from", "valid_until", "is_current"],
    "absences": ["worker_id", "absence_type", "starts_on", "ends_on", "days", "status"],
    "work_adjustments": ["worker_id", "adjustment_category", "starts_on", "ends_on", "status"],
    "course_offers": ["title", "course_id", "sale_price", "currency", "seats_sold", "status"],
    "orders": ["order_number", "buyer_name", "total", "currency", "paid_at", "status"],
    "course_licenses": ["buyer_legal_name", "course_id", "seats_purchased", "seats_used", "expires_at", "status"],
    "investigations": ["code", "event_id", "method", "started_on", "due_on", "status"],
    "corrective_actions": ["code", "title", "source_type", "responsible_user_id", "due_on", "status"],
    "audits": ["code", "audit_type", "lead_auditor_name", "starts_on", "status"],
    "management_reviews": ["year", "review_on", "title", "status"],
    "contractors": ["legal_name", "ruc", "contact_name", "risk_level", "status"],
    "sctr_policies": ["policy_number", "insurer_name", "coverage_kind", "starts_on", "ends_on", "status"],
    "sctr_coverages": ["worker_id", "contractor_worker_id", "sctr_policy_id", "covered_until", "status"],
    "access_points": ["code", "name", "site_id", "point_type", "is_active"],
    "access_events": ["occurred_at", "subject_type", "worker_id", "contractor_worker_id", "visitor_name", "access_point_id", "result"],
    "vendor_evaluations": ["vendor_name", "total_score", "rating", "valid_until", "status"],
    "inspection_dossiers": ["title", "request_type", "appearance_at", "period_from", "period_to", "status"],
    "devices": ["model", "user_id", "last_sync_at", "status"],
    "environmental_aspects": ["activity", "aspect", "impact", "significance_score", "is_significant"],
    "waste_records": ["generated_on", "waste_type", "quantity", "unit", "eo_rs_name", "status"],
    "api_clients": ["name", "scopes", "last_used_at", "status"],
    "webhooks": ["name", "target_url", "event_codes", "failure_count", "is_active"],
    "iso_evidences": ["iso_clause_id", "source_module", "last_reviewed_on", "status"],
}
# Datos estructurados (jsonb) que sí se editan desde un formulario propio.
WRITABLE_JSON = {"iperc_rows.probability_detail", "iperc_versions.participants", "management_reviews.participants",
                 "permit_types.checklist", "permit_types.approval_chain",
                 # El asistente del PETAR envía la cuadrilla; las mediciones de gases se registran al activar el trabajo (RN-PTR-03).
                 "work_permits.crew", "work_permits.gas_measurements", "work_permits.isolation_points"}
# Obligatorios en la base pero que calcula una regla si no se envían.
COMPUTED = {"vendor_evaluation_templates.criteria", "vendor_evaluations.scores", "work_shifts.hours_per_day", "areas.depth", "iperc_rows.probability_value",
            "iperc_rows.hazard", "iperc_rows.risk", "measurements.result", "work_permits.valid_until", "access_events.result", "access_events.occurred_at", "api_clients.scopes", "webhooks.event_codes", "corrective_actions.code", "inspections.code", "trainings.code",
            "sst_events.code", "sst_events.reported_at", "investigations.code", "investigations.started_on", "committee_meetings.number",
            "meeting_agreements.number", "ppe_deliveries.delivered_at", "ppe_stock_movements.moved_at",
            "requirement_evaluations.evaluated_on", "requirement_evaluations.evaluator_user_id", "requirement_evaluations.next_evaluation_on",
            "baseline_assessments.assessment_on", "substandard_reports.code", "substandard_reports.reported_at", "substandard_reports.origin",
            "ats_forms.code", "drills.code", "risst_deliveries.delivered_on", "document_acknowledgements.acknowledged_at",
            "audits.code", "change_requests.code", "change_requests.requested_on", "change_reviews.reviewed_at",
            "work_permits.code", "budget_executions.executed_on", "fitness_status.valid_from"}
LONG_TEXT = re.compile(r"(^|_)(description|notes|note|comment|comments|summary|functions|observations|content|body|"
                       r"details|detail|justification|reason|instructions|scope|recommendations|conclusions|text|"
                       r"statement|commitments|methodology|findings|lessons|answer|explanation|purpose)$")
LABEL_COLS = ["name", "full_name", "title", "hazard", "question", "subject", "label", "item", "description", "code"]


def load(path):
    with open(path, encoding="utf8") as f:
        return json.load(f)


def main():
    model = load(os.path.join(ROOT, "database", "catalogs", "model.json"))
    labels = load(os.path.join(ROOT, "database", "catalogs", "labels_es.json"))
    col_labels, val_labels = labels["columns"], labels["values"]
    by_table = {t["table"]: t for t in model}

    # Propósito y orden de cada tabla según la ficha del módulo.
    titles, order = {}, {}
    for path in sorted(glob.glob(os.path.join(ROOT, "docs", "modulos", "M-*.md"))):
        n = 0
        with open(path, encoding="utf8") as f:
            for line in f:
                mm = re.match(r"\|\s*`([a-z_0-9]+)`\s*\|\s*([^|]+)\|\s*([^|]+)\|", line)
                if mm and mm.group(1) not in titles:
                    titles[mm.group(1)] = mm.group(3).strip()
                    order[mm.group(1)] = n
                    n += 1

    # Registros inalterables de empresa que sí se muestran (constancias y bitácoras de uso). Los votos, las firmas
    # criptográficas, los accesos a datos sensibles y los pagos tienen su propio flujo y no se exponen aquí.
    IMMUTABLE_OK = {"document_acknowledgements", "access_events", "certificate_verifications", "credential_verifications", "notification_deliveries"}

    def table_ok(t):
        return (t["db"] == "main" and t["module"] not in SKIP_MODULES and t["table"] not in SKIP_TABLES
                and ("company" in t["kind"] or "hybrid" in t["kind"] or t["table"] in IMMUTABLE_OK))

    resources, modules = {}, {}

    def label_col(t):
        names = [c["name"] for c in t["columns"]]
        for c in LABEL_COLS:
            if c in names:
                return c
        for c in t["columns"]:
            if c["type"] == "text" and c["name"] not in SYSTEM and not c["encrypted"]:
                return c["name"]
        return "id"

    for t in model:
        if not table_ok(t):
            continue
        fields = []
        for c in t["columns"]:
            name, typ = c["name"], c["type"]
            base = typ.split("(")[0]
            if name in SYSTEM:
                continue
            f = {"name": name, "label": col_labels.get(name) or name.replace("_", " ").capitalize()}
            ref = (c["ref"] or "").split(".")[0] or None
            if base == "bytea" or name.endswith("_hash") or name.endswith("_hmac") or f'{t["table"]}.{name}' in SECRET_COLS:
                continue
            if c["generated"] or f'{t["table"]}.{name}' in READONLY:
                f["readonly"] = True
            if c["values"]:
                f["type"] = "enum"
                f["options"] = [{"value": v, "label": val_labels.get(f'{t["table"]}.{name}.{v}') or val_labels.get(v, v)} for v in c["values"]]
            elif ref == "files":
                f["type"] = "file"  # se sube con /files y se guarda su id
            elif ref:
                if ref in HIDDEN_REFS or ref not in by_table or by_table[ref]["db"] != "main":
                    continue
                f["type"] = "ref"
                f["ref"] = ref
            elif name == "source_id" and any(x["name"] == "source_type" for x in t["columns"]):
                f["type"] = "source"  # registro de origen (según source_type); lo fija el flujo que crea el registro
            elif base == "uuid" or base == "uuid[]" or base.startswith("inet"):
                continue
            elif base == "text":
                f["type"] = "longtext" if LONG_TEXT.search(name) else "text"
            elif base == "char":
                f["type"] = "text"
                f["max"] = int(re.search(r"\((\d+)\)", typ).group(1))
            elif base in ("integer", "smallint", "bigint"):
                f["type"] = "int"
            elif base == "numeric":
                f["type"] = "number"
            elif base == "date":
                f["type"] = "date"
            elif base == "timestamptz":
                f["type"] = "datetime"
            elif base == "time":
                f["type"] = "time"
            elif base == "boolean":
                f["type"] = "bool"
            elif base.endswith("[]"):
                f["type"] = "tags"
                f["item"] = base[:-2]
            elif base == "jsonb":
                f["type"] = "json"
                if f'{t["table"]}.{name}' not in WRITABLE_JSON:
                    f["readonly"] = True
            else:
                continue
            if c["encrypted"]:
                f["encrypted"] = True
            if c["not_null"] and c["default"] is None and not c["generated"] and f'{t["table"]}.{name}' not in COMPUTED:
                f["required"] = True
            if c["default"] is not None and f["type"] in ("enum", "bool", "int", "number", "text"):
                d = c["default"].strip("'")
                if f["type"] == "bool":
                    f["default"] = d == "true"
                elif f["type"] in ("int", "number"):
                    try:
                        f["default"] = float(d) if "." in d else int(d)
                    except ValueError:
                        pass
                elif not d.endswith(")"):
                    f["default"] = d.split("'::")[0]
            fields.append(f)

        lc = label_col(t)
        # Columnas visibles en la lista: código, nombre, hasta 2 referencias, 1 fecha y el estado.
        listed = []
        for n in ("code", lc):
            if any(f["name"] == n for f in fields) and n not in listed:
                listed.append(n)
        listed += [f["name"] for f in fields if f["type"] == "ref" and f.get("required")][:2]
        listed += [f["name"] for f in fields if f["type"] in ("date", "datetime") and f["name"] not in listed][:1]
        status = next((f["name"] for f in fields if f["name"] == "status"), None) or \
            next((f["name"] for f in fields if f["type"] == "enum" and f["name"] not in listed), None)
        if status and status not in listed:
            listed.append(status)
        if len(listed) < 3:
            listed += [f["name"] for f in fields if f["name"] not in listed and f["type"] not in ("json", "longtext")][: 3 - len(listed)]

        if t["table"] in LIST:
            listed = LIST[t["table"]]
        for f in fields:
            if f["name"] in listed:
                f["list"] = True

        immutable = bool(t["immutable"]) or "imm" in t["kind"]
        res = {
            "columns": [n for n in listed if any(f["name"] == n for f in fields)],
            "module": t["module"],
            "tab": labels.get("tables", {}).get(t["table"]) or t["table"],
            "title": titles.get(t["table"]) or (t["comment"] or t["table"]).split(";")[0].split("]")[-1].strip(),
            "hybrid": "hybrid" in t["kind"],
            "immutable": immutable,
            "soft_delete": any(c["name"] == "deleted_at" for c in t["columns"]),
            "stamps": any(c["name"] == "created_by" for c in t["columns"]),
            "updated_by": any(c["name"] == "updated_by" for c in t["columns"]),
            "updated_at": any(c["name"] == "updated_at" for c in t["columns"]),
            "label": lc,
            "order": order.get(t["table"], 99),
            "uniques": [u.replace(" ", "").split(",") for u in t["uniques"]],
            "fields": fields,
        }
        resources[t["table"]] = res
        modules.setdefault(t["module"], []).append(t["table"])

    # Destinos de referencia que no son de empresa (catálogos globales): solo para leer opciones.
    catalogs = {}
    for res in resources.values():
        for f in res["fields"]:
            if f["type"] == "ref" and f["ref"] not in resources and f["ref"] not in catalogs:
                t = by_table[f["ref"]]
                catalogs[f["ref"]] = {"label": label_col(t) if t["table"] != "users" else "full_name",
                                      "kind": t["kind"],
                                      "soft_delete": any(c["name"] == "deleted_at" for c in t["columns"])}

    for code in modules:
        modules[code].sort(key=lambda x: (resources[x]["order"], x))

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf8") as f:
        json.dump({"modules": modules, "resources": resources, "catalogs": catalogs}, f, ensure_ascii=False, indent=1)
    print(f"{len(resources)} tablas en {len(modules)} módulos; {len(catalogs)} catálogos de referencia → {OUT}")


if __name__ == "__main__":
    main()
