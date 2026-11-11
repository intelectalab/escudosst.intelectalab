"use client";

// Contratistas, lado de la empresa principal (M-CTR SCR-CTR-02 y SCR-CTR-04; prototipo m-modulos.js cfgSimple('CTR')):
// «Invitar contratista» (RUC, razón social, correo del administrador, servicio y nivel de riesgo) y la bandeja de
// revisión de documentos con visor y «Aprobar» / «Observar» con motivo.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { Card, Kpi } from "./Summary";
import { fmtDate, FileViewer, StateChip } from "./CtrShared";

type ReviewDoc = {
  id: string;
  status: string;
  issued_on: string | null;
  expires_on: string | null;
  review_comments: string | null;
  created_at: string;
  document_kind: string | null;
  contractor_id: string;
  contractor_name: string;
  ruc: string;
  requirement_name: string | null;
  applies_to: "company" | "worker" | null;
  worker_name: string | null;
  document_last4: string | null;
  file_id: string | null;
  file_name: string | null;
  mime_type: string | null;
  uploaded_by_name: string | null;
  reviewed_by_name: string | null;
};
type ReviewResponse = { data: ReviewDoc[]; counts: { submitted: number; observed: number; approved_month: number; expired: number }; can_review: boolean };

const SERVICES = ["Mantenimiento eléctrico", "Limpieza industrial", "Transporte", "Seguridad", "Obras civiles"];
const RISKS: [string, string][] = [["", "Sin definir"], ["low", "Bajo"], ["medium", "Medio"], ["high", "Alto"], ["very_high", "Muy alto"]];
const TABS: [string, string][] = [["submitted", "Por revisar"], ["observed", "Observados"], ["approved", "Aprobados"], ["all", "Todos"]];

export function CtrClientPanel({ readOnly }: { readOnly?: boolean }) {
  const [invite, setInvite] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [reload, setReload] = useState(0);
  return (
    <div className="space-y-4">
      {!readOnly && (
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="primary" icon="mail" onClick={() => setInvite(true)}>
            Invitar contratista
          </Button>
        </div>
      )}
      <ReviewInbox readOnly={readOnly} reload={reload} />
      {invite && (
        <InviteModal
          onClose={() => setInvite(false)}
          onDone={(msg) => {
            setInvite(false);
            setToast(msg);
            setReload((n) => n + 1);
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function InviteModal({ onClose, onDone }: { onClose: () => void; onDone: (msg: string) => void }) {
  const [f, setF] = useState({ ruc: "", legal_name: "", admin_email: "", admin_name: "", services_description: SERVICES[0], risk_level: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });

  async function send() {
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      const r = await api<{ data: { contractor_created: boolean; status: string } }>("/ctr/invitations", { method: "POST", withCompany: true, body: f });
      onDone(r.data.status === "active" ? "El administrador ya tenía cuenta: se le dio acceso al portal" : "Invitación enviada al portal de contratistas");
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setError(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Invitar contratista"
      subtitle="Recibirá un correo para crear su contraseña y entrar a su portal."
      icon="briefcase"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" loading={busy} onClick={send}>
            Enviar invitación
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="RUC" error={errors.ruc?.[0]}>
          <input className="input" inputMode="numeric" maxLength={11} value={f.ruc} onChange={set("ruc")} aria-invalid={!!errors.ruc} />
        </FormField>
        <FormField label="Razón social" error={errors.legal_name?.[0]}>
          <input className="input" value={f.legal_name} onChange={set("legal_name")} aria-invalid={!!errors.legal_name} />
        </FormField>
        <FormField label="Correo del administrador" error={errors.admin_email?.[0]}>
          <input className="input" type="email" value={f.admin_email} onChange={set("admin_email")} aria-invalid={!!errors.admin_email} />
        </FormField>
        <FormField label="Nombre del administrador" hint="Opcional">
          <input className="input" value={f.admin_name} onChange={set("admin_name")} />
        </FormField>
        <FormField label="Servicio">
          <input className="input" list="ctr-services" value={f.services_description} onChange={set("services_description")} />
          <datalist id="ctr-services">
            {SERVICES.map((s) => <option key={s} value={s} />)}
          </datalist>
        </FormField>
        <FormField label="Nivel de riesgo" error={errors.risk_level?.[0]} hint="Define qué requisitos se le exigen">
          <select className="input" value={f.risk_level} onChange={set("risk_level")}>
            {RISKS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
      </div>
      {error && !Object.keys(errors).length && <p className="mt-4 rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}
    </Modal>
  );
}

function ReviewInbox({ readOnly, reload }: { readOnly?: boolean; reload: number }) {
  const [tab, setTab] = useState("submitted");
  const [res, setRes] = useState<ReviewResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<ReviewDoc | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    setError(null);
    api<ReviewResponse>(`/ctr/review?status=${tab}`, { withCompany: true })
      .then(setRes)
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [tab]);
  useEffect(load, [load, reload]);

  return (
    <div className="space-y-4">
      {res && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Kpi label="Documentos por revisar" value={String(res.counts.submitted)} color={res.counts.submitted ? "text-amber-ink" : "text-ok"} />
          <Kpi label="Observados" value={String(res.counts.observed)} color={res.counts.observed ? "text-bad" : "text-ok"} />
          <Kpi label="Aprobados este mes" value={String(res.counts.approved_month)} color="text-ok" />
          <Kpi label="Aprobados ya vencidos" value={String(res.counts.expired)} color={res.counts.expired ? "text-bad" : "text-ok"} />
        </div>
      )}
      <Card
        title="Revisión de documentos"
        action={
          <div className="flex flex-wrap gap-1" role="tablist" aria-label="Estado">
            {TABS.map(([v, l]) => (
              <button key={v} role="tab" aria-selected={tab === v} onClick={() => setTab(v)}
                className={`rounded-full px-3 py-1 text-sm font-semibold ${tab === v ? "bg-brand text-white" : "border border-line text-muted hover:text-fg"}`}>
                {l}
              </button>
            ))}
          </div>
        }
      >
        {error ? (
          <p className="text-sm text-muted">{error}</p>
        ) : !res ? (
          <div className="h-32 animate-pulse rounded-lg bg-bg" />
        ) : res.data.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted">{tab === "submitted" ? "No hay documentos por revisar." : "No hay documentos en esta lista."}</p>
        ) : (
          <ul className="divide-y divide-line">
            {res.data.map((d) => (
              <li key={d.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="font-semibold">{d.requirement_name ?? d.document_kind ?? "Documento"}</p>
                  <p className="text-sm text-muted">
                    {d.contractor_name} · RUC {d.ruc}
                    {d.worker_name ? ` · ${d.worker_name}${d.document_last4 ? ` (doc. …${d.document_last4})` : ""}` : ""}
                  </p>
                  <p className="text-xs text-muted">
                    Cargado {fmtDate(d.created_at)}{d.uploaded_by_name ? ` por ${d.uploaded_by_name}` : ""} · vence {fmtDate(d.expires_on)}
                    {d.review_comments ? ` · «${d.review_comments}»` : ""}
                  </p>
                </div>
                <StateChip state={d.status} />
                <Button size="sm" icon="eye" onClick={() => setOpen(d)}>
                  {d.status === "submitted" && res.can_review && !readOnly ? "Revisar" : "Ver"}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {open && (
        <ReviewModal
          doc={open}
          canReview={!!res?.can_review && !readOnly && open.status !== "approved"}
          onClose={() => setOpen(null)}
          onDone={(msg) => {
            setOpen(null);
            setToast(msg);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function ReviewModal({ doc, canReview, onClose, onDone }: { doc: ReviewDoc; canReview: boolean; onClose: () => void; onDone: (msg: string) => void }) {
  const [observing, setObserving] = useState(false);
  const [comment, setComment] = useState("");
  const [expires, setExpires] = useState(doc.expires_on ?? "");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function decide(decision: "approve" | "observe") {
    setBusy(true);
    setError(null);
    try {
      await api(`/ctr/review/${doc.id}`, { method: "POST", withCompany: true, body: { decision, comment, expires_on: expires || undefined } });
      onDone(decision === "approve" ? "Documento aprobado" : "Observación enviada al contratista");
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.comment?.[0] ?? err.errors?.expires_on?.[0] ?? err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={doc.requirement_name ?? doc.document_kind ?? "Documento"}
      subtitle={`${doc.contractor_name}${doc.worker_name ? ` · ${doc.worker_name}` : ""}`}
      icon="file"
      size="lg"
      onClose={onClose}
      footer={
        canReview ? (
          observing ? (
            <>
              <Button onClick={() => setObserving(false)}>Volver</Button>
              <Button variant="danger" loading={busy} onClick={() => decide("observe")}>
                Enviar observación
              </Button>
            </>
          ) : (
            <>
              <Button onClick={() => setObserving(true)}>Observar</Button>
              <Button variant="primary" icon="check" loading={busy} onClick={() => decide("approve")}>
                Aprobar
              </Button>
            </>
          )
        ) : (
          <Button onClick={onClose}>Cerrar</Button>
        )
      }
    >
      <div className="grid gap-4 lg:grid-cols-[1fr_16rem]">
        <div>{doc.file_id ? <FileViewer fileId={doc.file_id} name={doc.file_name} mime={doc.mime_type} /> : <p className="text-sm text-muted">El documento no tiene archivo adjunto.</p>}</div>
        <dl className="space-y-3 text-sm">
          <div>
            <dt className="text-muted">Estado</dt>
            <dd><StateChip state={doc.status} /></dd>
          </div>
          <div>
            <dt className="text-muted">Contratista</dt>
            <dd className="font-medium">{doc.contractor_name} · {doc.ruc}</dd>
          </div>
          <div>
            <dt className="text-muted">Emitido</dt>
            <dd>{fmtDate(doc.issued_on)}</dd>
          </div>
          {canReview ? (
            <FormField label="Vence" hint="Revisa que coincida con el documento">
              <input type="date" className="input" value={expires} onChange={(e) => setExpires(e.target.value)} />
            </FormField>
          ) : (
            <div>
              <dt className="text-muted">Vence</dt>
              <dd>{fmtDate(doc.expires_on)}</dd>
            </div>
          )}
          {doc.review_comments && (
            <div>
              <dt className="text-muted">Comentario de revisión</dt>
              <dd>{doc.review_comments}{doc.reviewed_by_name ? ` (${doc.reviewed_by_name})` : ""}</dd>
            </div>
          )}
          {observing && (
            <FormField label="Motivo de la observación" hint="El contratista lo verá en su portal">
              <textarea className="input min-h-24" value={comment} onChange={(e) => setComment(e.target.value)} autoFocus />
            </FormField>
          )}
          {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-bad">{error}</p>}
        </dl>
      </div>
    </Modal>
  );
}
