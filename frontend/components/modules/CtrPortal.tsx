"use client";

// Portal del contratista (M-CTR SCR-CTR-05/06/07; docs/05 §4.4): lo que ve el administrador de contratista al entrar.
// Su empresa, los requisitos que le pide la empresa principal con su estado y la carga de documentos, sus
// trabajadores (alta uno por uno y documentos de cada uno) y la solicitud de acreditación. Nunca muestra datos de la
// empresa principal más allá de sus requisitos (RN-CTR-04) ni datos médicos (RN-CTR-05).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { uploadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { Bar, Card, Chip, Kpi } from "./Summary";
import { AccreditationChip, type DocState, fmtDate, StateChip } from "./CtrShared";

type ReqItem = {
  id: string;
  code: string;
  name: string;
  description: string | null;
  is_mandatory: boolean;
  validity_months: number | null;
  state: DocState;
  document: { id: string; status: string; issued_on: string | null; expires_on: string | null; review_comments: string | null; file_name: string | null } | null;
};
type PortalWorker = {
  id: string;
  name: string;
  document_type: string;
  document_last4: string | null;
  position_text: string | null;
  status: string;
  access_enabled: boolean;
  fitness_valid: boolean;
  requirements: ReqItem[];
  complete: boolean;
};
type Accreditation = {
  id: string;
  contract_reference: string | null;
  service_description: string | null;
  starts_on: string | null;
  ends_on: string | null;
  expires_on: string | null;
  status: string;
  observations: string | null;
  rejection_reason: string | null;
  suspended_reason: string | null;
  site_name: string | null;
};
type Portal = {
  contractor: { id: string; ruc: string; legal_name: string; trade_name: string | null; contact_name: string | null; contact_email: string | null; contact_phone: string | null; services_description: string | null; status: string };
  principal: string | null;
  company_requirements: ReqItem[];
  worker_requirements: { id: string; name: string; is_mandatory: boolean }[];
  workers: PortalWorker[];
  accreditations: Accreditation[];
  sites: { id: string; name: string }[];
  kpis: { requirements_ok: number; requirements_total: number; observed: number; workers: number; workers_ready: number; accreditation: string | null };
};

type Upload = { req: ReqItem; worker?: PortalWorker };

export function CtrPortal() {
  const [data, setData] = useState<Portal | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [upload, setUpload] = useState<Upload | null>(null);
  const [addWorker, setAddWorker] = useState(false);
  const [requestAcc, setRequestAcc] = useState(false);
  const [editCompany, setEditCompany] = useState(false);
  const [openWorker, setOpenWorker] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Portal }>("/ctr/portal", { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(load, [load]);

  const done = (msg: string) => {
    setUpload(null);
    setAddWorker(false);
    setRequestAcc(false);
    setEditCompany(false);
    setToast(msg);
    load();
  };

  if (error) return <p className="rounded-xl border border-line bg-surface p-6 text-muted">{error}</p>;
  if (!data) return <div className="h-64 animate-pulse rounded-xl bg-surface" />;

  const k = data.kpis;
  const pct = k.requirements_total ? Math.round((k.requirements_ok / k.requirements_total) * 100) : 100;
  const pendingAcc = data.accreditations.some((a) => ["submitted", "under_review"].includes(a.status));
  const current = data.accreditations[0];

  return (
    <div className="space-y-4">
      <Card
        title={data.contractor.trade_name || data.contractor.legal_name}
        action={<Button size="sm" icon="pen" onClick={() => setEditCompany(true)}>Editar datos</Button>}
      >
        <p className="text-sm text-muted">
          RUC {data.contractor.ruc}
          {data.principal ? ` · contratista de ${data.principal}` : ""}
          {data.contractor.services_description ? ` · ${data.contractor.services_description}` : ""}
        </p>
        <p className="mt-1 text-sm text-muted">
          Contacto: {data.contractor.contact_name ?? "—"} · {data.contractor.contact_email ?? "—"} · {data.contractor.contact_phone ?? "—"}
        </p>
      </Card>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Requisitos de empresa aprobados" value={`${k.requirements_ok} / ${k.requirements_total}`} color={pct >= 100 ? "text-ok" : "text-amber-ink"} />
        <Kpi label="Por corregir" value={String(k.observed)} color={k.observed ? "text-bad" : "text-ok"} />
        <Kpi label="Trabajadores listos" value={`${k.workers_ready} / ${k.workers}`} color={k.workers && k.workers_ready === k.workers ? "text-ok" : "text-amber-ink"} />
        <div className="rounded-xl border border-line bg-surface p-4">
          <p className="text-sm text-muted">Acreditación</p>
          <div className="mt-2"><AccreditationChip status={k.accreditation} /></div>
          {current?.expires_on && <p className="mt-1 text-xs text-muted">Vence {fmtDate(current.expires_on)}</p>}
        </div>
      </div>

      <Card title="Documentos de la empresa">
        <div className="mb-3 flex items-center gap-3">
          <div className="flex-1"><Bar pct={pct} /></div>
          <span className="text-sm font-semibold">{pct} %</span>
        </div>
        {data.company_requirements.length === 0 ? (
          <p className="text-sm text-muted">La empresa principal aún no definió requisitos para tu empresa.</p>
        ) : (
          <RequirementList items={data.company_requirements} onUpload={(req) => setUpload({ req })} />
        )}
      </Card>

      <Card title="Trabajadores" action={<Button size="sm" variant="primary" icon="plus" onClick={() => setAddWorker(true)}>Agregar trabajador</Button>}>
        {data.workers.length === 0 ? (
          <p className="text-sm text-muted">Agrega a las personas que trabajarán en las instalaciones de la empresa principal.</p>
        ) : (
          <ul className="divide-y divide-line">
            {data.workers.map((w) => {
              const ok = w.requirements.filter((r) => ["approved", "expiring"].includes(r.state)).length;
              const isOpen = openWorker === w.id;
              return (
                <li key={w.id} className="py-3">
                  <button className="flex w-full flex-wrap items-center gap-3 text-left" onClick={() => setOpenWorker(isOpen ? null : w.id)} aria-expanded={isOpen}>
                    <span className="min-w-0 flex-1">
                      <span className="block font-semibold">{w.name}</span>
                      <span className="block text-xs text-muted">
                        {w.document_type} …{w.document_last4 ?? "—"}{w.position_text ? ` · ${w.position_text}` : ""} · Aptitud médica: {w.fitness_valid ? "vigente" : "no vigente"}
                      </span>
                    </span>
                    <span className="text-sm text-muted">{ok} / {w.requirements.length} documentos</span>
                    {w.status !== "active" ? <Chip tone="bad">Bloqueado</Chip> : w.complete ? <Chip tone="ok">Listo</Chip> : <Chip tone="warn">Incompleto</Chip>}
                  </button>
                  {isOpen && (
                    <div className="mt-3 rounded-lg border border-line p-3">
                      {w.requirements.length === 0 ? (
                        <p className="text-sm text-muted">No hay documentos por trabajador exigidos.</p>
                      ) : (
                        <RequirementList items={w.requirements} onUpload={(req) => setUpload({ req, worker: w })} />
                      )}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      <Card
        title="Acreditación"
        action={!pendingAcc && <Button size="sm" variant="primary" icon="check" onClick={() => setRequestAcc(true)}>Solicitar acreditación</Button>}
      >
        {data.accreditations.length === 0 ? (
          <p className="text-sm text-muted">Cuando tengas tus documentos cargados, solicita la acreditación del servicio. La empresa principal la revisa y aprueba.</p>
        ) : (
          <ul className="divide-y divide-line">
            {data.accreditations.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="font-semibold">{a.service_description ?? "Servicio"}</p>
                  <p className="text-xs text-muted">
                    {fmtDate(a.starts_on)} – {fmtDate(a.ends_on)}{a.site_name ? ` · ${a.site_name}` : ""}{a.contract_reference ? ` · ${a.contract_reference}` : ""}
                  </p>
                  {(a.observations || a.rejection_reason || a.suspended_reason) && (
                    <p className="mt-1 text-sm text-bad">{a.rejection_reason ?? a.suspended_reason ?? a.observations}</p>
                  )}
                </div>
                <AccreditationChip status={a.status} />
              </li>
            ))}
          </ul>
        )}
      </Card>

      {upload && <UploadModal upload={upload} onClose={() => setUpload(null)} onDone={() => done("Documento cargado: queda en revisión")} />}
      {addWorker && <WorkerModal onClose={() => setAddWorker(false)} onDone={() => done("Trabajador agregado")} />}
      {requestAcc && <AccreditationModal sites={data.sites} defaultService={data.contractor.services_description ?? ""} onClose={() => setRequestAcc(false)} onDone={() => done("Solicitud de acreditación enviada")} />}
      {editCompany && <CompanyModal portal={data} onClose={() => setEditCompany(false)} onDone={() => done("Datos guardados")} />}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function RequirementList({ items, onUpload }: { items: ReqItem[]; onUpload: (r: ReqItem) => void }) {
  return (
    <ul className="divide-y divide-line">
      {items.map((r) => (
        <li key={r.id} className="flex flex-wrap items-center gap-3 py-2.5">
          <div className="min-w-0 flex-1">
            <p className="font-medium">
              {r.name} {!r.is_mandatory && <span className="text-xs font-normal text-muted">(opcional)</span>}
            </p>
            <p className="text-xs text-muted">
              {r.document ? `${r.document.file_name ?? "Archivo"} · vence ${fmtDate(r.document.expires_on)}` : r.description ?? "Sin documento cargado"}
            </p>
            {r.document?.review_comments && ["observed", "rejected"].includes(r.state) && (
              <p className="mt-1 text-sm text-bad">Observación: {r.document.review_comments}</p>
            )}
          </div>
          <StateChip state={r.state} />
          {r.state !== "submitted" && (
            <Button size="sm" icon="file" variant={["pending", "observed", "rejected", "expired"].includes(r.state) ? "primary" : "secondary"} onClick={() => onUpload(r)}>
              {r.state === "pending" ? "Subir" : "Reemplazar"}
            </Button>
          )}
        </li>
      ))}
    </ul>
  );
}

function errorText(e: unknown): [string, Record<string, string[]>] {
  const err = e as ApiError;
  return [err.detail ?? err.title, err.errors ?? {}];
}

function UploadModal({ upload, onClose, onDone }: { upload: Upload; onClose: () => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [issued, setIssued] = useState("");
  const [expires, setExpires] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const medical = /emo|apt|médic|medic/i.test(`${upload.req.code} ${upload.req.name}`);

  async function send() {
    if (!file) return setError("Elige el archivo del documento.");
    setBusy(true);
    setError(null);
    try {
      const f = await uploadFile(file, "CTR");
      await api("/ctr/portal/documents", {
        method: "POST",
        withCompany: true,
        body: { requirement_id: upload.req.id, file_id: f.id, contractor_worker_id: upload.worker?.id, issued_on: issued || undefined, expires_on: expires || undefined },
      });
      onDone();
    } catch (e) {
      const [msg] = errorText(e);
      setError(msg);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={upload.req.name}
      subtitle={upload.worker ? upload.worker.name : "Documento de la empresa"}
      icon="file"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" loading={busy} onClick={send}>Subir documento</Button>
        </>
      }
    >
      <div className="space-y-4">
        {medical && (
          <p className="rounded-lg bg-amber-soft px-3 py-2 text-sm text-amber-ink">Sube solo la constancia de aptitud médica. No subas informes clínicos ni diagnósticos.</p>
        )}
        <FormField label="Archivo" hint="PDF o imagen, hasta 15 MB">
          <input className="input" type="file" accept="application/pdf,image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </FormField>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Fecha de emisión">
            <input className="input" type="date" value={issued} onChange={(e) => setIssued(e.target.value)} />
          </FormField>
          <FormField label="Vence" hint={upload.req.validity_months ? `Vigencia habitual: ${upload.req.validity_months} meses` : "Si el documento tiene vencimiento"}>
            <input className="input" type="date" value={expires} onChange={(e) => setExpires(e.target.value)} />
          </FormField>
        </div>
        {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      </div>
    </Modal>
  );
}

function WorkerModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ document_type: "DNI", document_number: "", first_name: "", paternal_last_name: "", maternal_last_name: "", position_text: "", phone_e164: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });

  async function send() {
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      await api("/ctr/portal/workers", { method: "POST", withCompany: true, body: Object.fromEntries(Object.entries(f).filter(([, v]) => v !== "")) });
      onDone();
    } catch (e) {
      const [msg, errs] = errorText(e);
      setErrors(errs);
      setError(msg);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Agregar trabajador"
      icon="user"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" loading={busy} onClick={send}>Agregar</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Tipo de documento">
          <select className="input" value={f.document_type} onChange={set("document_type")}>
            {[["DNI", "DNI"], ["CE", "Carné de extranjería"], ["PTP", "PTP"], ["PASSPORT", "Pasaporte"], ["OTHER", "Otro"]].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <FormField label="Número de documento" error={errors.document_number?.[0]}>
          <input className="input" value={f.document_number} onChange={set("document_number")} aria-invalid={!!errors.document_number} />
        </FormField>
        <FormField label="Nombres" error={errors.first_name?.[0]}>
          <input className="input" value={f.first_name} onChange={set("first_name")} aria-invalid={!!errors.first_name} />
        </FormField>
        <FormField label="Apellido paterno" error={errors.paternal_last_name?.[0]}>
          <input className="input" value={f.paternal_last_name} onChange={set("paternal_last_name")} aria-invalid={!!errors.paternal_last_name} />
        </FormField>
        <FormField label="Apellido materno">
          <input className="input" value={f.maternal_last_name} onChange={set("maternal_last_name")} />
        </FormField>
        <FormField label="Puesto">
          <input className="input" value={f.position_text} onChange={set("position_text")} />
        </FormField>
        <FormField label="Celular" error={errors.phone_e164?.[0]} hint="Opcional">
          <input className="input" type="tel" value={f.phone_e164} onChange={set("phone_e164")} />
        </FormField>
      </div>
      {error && !Object.keys(errors).length && <p className="mt-4 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </Modal>
  );
}

function AccreditationModal({ sites, defaultService, onClose, onDone }: { sites: { id: string; name: string }[]; defaultService: string; onClose: () => void; onDone: () => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [f, setF] = useState({ service_description: defaultService, contract_reference: "", site_id: "", starts_on: today, ends_on: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });

  async function send() {
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      await api("/ctr/portal/accreditations", { method: "POST", withCompany: true, body: Object.fromEntries(Object.entries(f).filter(([, v]) => v !== "")) });
      onDone();
    } catch (e) {
      const [msg, errs] = errorText(e);
      setErrors(errs);
      setError(msg);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Solicitar acreditación"
      subtitle="La empresa principal revisa que tus requisitos estén aprobados y vigentes."
      icon="check"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" loading={busy} onClick={send}>Enviar solicitud</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <FormField label="Servicio" error={errors.service_description?.[0]}>
            <textarea className="input min-h-20" value={f.service_description} onChange={set("service_description")} aria-invalid={!!errors.service_description} />
          </FormField>
        </div>
        <FormField label="Sede" hint="Opcional">
          <select className="input" value={f.site_id} onChange={set("site_id")}>
            <option value="">Todas / por definir</option>
            {sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </FormField>
        <FormField label="N.° de contrato u orden" hint="Opcional">
          <input className="input" value={f.contract_reference} onChange={set("contract_reference")} />
        </FormField>
        <FormField label="Inicio" error={errors.starts_on?.[0]}>
          <input className="input" type="date" value={f.starts_on} onChange={set("starts_on")} />
        </FormField>
        <FormField label="Fin" error={errors.ends_on?.[0]}>
          <input className="input" type="date" value={f.ends_on} onChange={set("ends_on")} />
        </FormField>
      </div>
      {error && !Object.keys(errors).length && <p className="mt-4 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </Modal>
  );
}

function CompanyModal({ portal, onClose, onDone }: { portal: Portal; onClose: () => void; onDone: () => void }) {
  const c = portal.contractor;
  const [f, setF] = useState({ trade_name: c.trade_name ?? "", contact_name: c.contact_name ?? "", contact_phone: c.contact_phone ?? "", services_description: c.services_description ?? "" });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });

  async function send() {
    setBusy(true);
    setError(null);
    try {
      await api("/ctr/portal/company", { method: "PATCH", withCompany: true, body: f });
      onDone();
    } catch (e) {
      setError(errorText(e)[0]);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Datos de tu empresa"
      subtitle={`${c.legal_name} · RUC ${c.ruc}`}
      icon="building"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" loading={busy} onClick={send}>Guardar</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Nombre comercial">
          <input className="input" value={f.trade_name} onChange={set("trade_name")} />
        </FormField>
        <FormField label="Persona de contacto">
          <input className="input" value={f.contact_name} onChange={set("contact_name")} />
        </FormField>
        <FormField label="Teléfono de contacto">
          <input className="input" type="tel" value={f.contact_phone} onChange={set("contact_phone")} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField label="Servicios que brinda">
            <textarea className="input min-h-20" value={f.services_description} onChange={set("services_description")} />
          </FormField>
        </div>
      </div>
      {error && <p className="mt-4 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </Modal>
  );
}
