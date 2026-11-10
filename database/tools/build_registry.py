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
HIDDEN_REFS = {"files", "signature_requests", "signatures", "devices", "sync_batches", "accounts", "companies", "ai_requests", "memberships", "bulk_imports"}
# Campos que calcula el sistema: se muestran pero no se editan.
READONLY = {"areas.depth", "workers.document_last4", "workers.activated_at", "workers.whatsapp_opt_in_at",
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
            "training_certificates.certificate_code", "training_certificates.verification_code", "training_certificates.holder_name",
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
            "baseline_assessments.approved_by", "baseline_assessments.approved_at", "baseline_gaps.closed_at"}
# Columnas de la lista elegidas a mano donde las automáticas no dicen lo importante.
LIST = {
    "monthly_statistics": ["period_year", "period_month", "site_id", "hours_worked", "frequency_index", "severity_index", "accident_rate_index", "is_closed"],
    "man_hours": ["period_year", "period_month", "site_id", "worker_count", "hours_worked"],
    "program_activities": ["code", "title", "goal_quantity", "executed_quantity", "progress_pct", "status"],
    "baseline_items": ["lineament_name", "item_code", "question", "result"],
    "sst_events": ["code", "event_type", "occurred_at", "site_id", "notification_deadline_at", "status"],
}
# Datos estructurados (jsonb) que sí se editan desde un formulario propio.
WRITABLE_JSON = {"iperc_rows.probability_detail", "iperc_versions.participants"}
# Obligatorios en la base pero que calcula una regla si no se envían.
COMPUTED = {"work_shifts.hours_per_day", "areas.depth", "iperc_rows.probability_value",
            "iperc_rows.hazard", "iperc_rows.risk", "corrective_actions.code", "inspections.code", "trainings.code",
            "sst_events.code", "sst_events.reported_at", "investigations.code", "investigations.started_on", "committee_meetings.number",
            "meeting_agreements.number", "ppe_deliveries.delivered_at", "ppe_stock_movements.moved_at",
            "requirement_evaluations.evaluated_on", "requirement_evaluations.evaluator_user_id", "requirement_evaluations.next_evaluation_on",
            "baseline_assessments.assessment_on"}
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

    def table_ok(t):
        return (t["db"] == "main" and t["module"] not in SKIP_MODULES and t["table"] not in SKIP_TABLES
                and ("company" in t["kind"] or "hybrid" in t["kind"]))

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
            if base == "bytea" or name.endswith("_hash") or name.endswith("_hmac"):
                continue
            if c["generated"] or f'{t["table"]}.{name}' in READONLY:
                f["readonly"] = True
            if c["values"]:
                f["type"] = "enum"
                f["options"] = [{"value": v, "label": val_labels.get(v, v)} for v in c["values"]]
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
            "module": t["module"],
            "tab": labels.get("tables", {}).get(t["table"]) or t["table"],
            "title": titles.get(t["table"]) or (t["comment"] or t["table"]).split(";")[0].split("]")[-1].strip(),
            "hybrid": "hybrid" in t["kind"],
            "immutable": immutable,
            "soft_delete": any(c["name"] == "deleted_at" for c in t["columns"]),
            "stamps": any(c["name"] == "created_by" for c in t["columns"]),
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
