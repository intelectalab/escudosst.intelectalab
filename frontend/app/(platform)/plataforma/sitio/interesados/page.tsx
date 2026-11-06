"use client";

// Sitio web › Interesados (SCR-WEB-39 y 40): una ficha por persona (por correo), con todo lo que hizo en el sitio.
// Se diferencia de «Solicitudes»: allí está cada pedido suelto; aquí, la persona y su seguimiento comercial.

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { ChoiceCards, FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { SECTORS } from "@/lib/accounts";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";

type Status = "new" | "contacted" | "qualified" | "converted" | "discarded";
type Lead = {
  id: string;
  email: string;
  full_name: string | null;
  company_name: string | null;
  ruc: string | null;
  phone: string | null;
  sector: string | null;
  source: string;
  last_activity_at: string;
  marketing_consent: boolean;
  status: Status;
  requests: number;
};
type Detail = Lead & {
  notes: string | null;
  discard_reason: string | null;
  privacy_consent: boolean;
  privacy_version: string | null;
  marketing_consent_at: string | null;
  marketing_consent_withdrawn_at: string | null;
  created_at: string;
  timeline: { kind: string; detail: string; status: string; interest_plan_code: string | null; message: string | null; at: string }[];
};

const STATUS: Record<Status, [string, ChipTone]> = {
  new: ["Nuevo", "brand"],
  contacted: ["Contactado", "amber"],
  qualified: ["Interesado de verdad", "amber"],
  converted: ["Ya es cliente", "ok"],
  discarded: ["Descartado", "na"],
};
const SOURCE: Record<string, string> = {
  demo: "Pidió demo o cotización",
  contact: "Hizo una consulta",
  fine_calculator: "Calculadora de multas",
  self_assessment: "Autodiagnóstico",
  resource: "Descargó un recurso",
  newsletter: "Boletín",
  partner: "Partners",
  savings_calculator: "Calculadora de ahorro",
  webinar: "Webinar",
  campaign: "Campaña",
  popup: "Ventana emergente",
};
const REQUEST: Record<string, string> = { demo: "Pidió una demo", quote: "Pidió una cotización", contact: "Hizo una consulta" };
const when = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d));

export default function InteresadosPage() {
  const [rows, setRows] = useState<Lead[] | null>(null);
  const [filter, setFilter] = useState<"open" | Status>("open");
  const [q, setQ] = useState("");
  const [openId, setOpenId] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Lead[] }>("/internal/site/leads")
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
  }, []);
  useEffect(load, [load]);

  const count = useMemo(() => {
    const c = { new: 0, contacted: 0, qualified: 0, converted: 0, discarded: 0 } as Record<Status, number>;
    rows?.forEach((r) => c[r.status]++);
    return c;
  }, [rows]);
  const term = q.trim().toLowerCase();
  const list = (rows ?? [])
    .filter((r) => (filter === "open" ? ["new", "contacted", "qualified"].includes(r.status) : r.status === filter))
    .filter((r) => !term || [r.email, r.full_name, r.company_name, r.ruc].some((v) => v?.toLowerCase().includes(term)));

  async function exportCsv() {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000"}/api/v1/internal/site/leads/export`, { headers: { Authorization: `Bearer ${session.getToken()}` } });
    if (!res.ok) return setToast("No se pudo descargar.");
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement("a");
    a.href = url;
    a.download = "interesados-con-novedades.csv";
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Interesados"]]}
        icon="people"
        title="Interesados"
        description="Personas que dejaron sus datos en el sitio. Una ficha por persona con todo lo que pidió, para darle seguimiento hasta que sea cliente."
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="En seguimiento" value={count.new + count.contacted + count.qualified} hint={`${count.new} nuevos`} icon="people" tone="brand" active={filter === "open"} onClick={() => setFilter("open")} />
        <StatCard label="Interesados de verdad" value={count.qualified} icon="star" tone="amber" active={filter === "qualified"} onClick={() => setFilter("qualified")} />
        <StatCard label="Ya son clientes" value={count.converted} icon="check" tone="ok" active={filter === "converted"} onClick={() => setFilter("converted")} />
        <StatCard label="Descartados" value={count.discarded} icon="close" tone="na" active={filter === "discarded"} onClick={() => setFilter("discarded")} />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <label className="relative min-w-[16rem] flex-1">
          <Icon name="search" size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
          <input className="filter-control pl-9" placeholder="Buscar por nombre, empresa, correo o RUC" value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
        <Button icon="file" onClick={exportCsv} title="Solo quienes aceptaron recibir novedades y no se dieron de baja">
          Descargar lista para el boletín
        </Button>
      </div>

      {rows === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : list.length === 0 ? (
        <EmptyState icon="people" text={rows.length === 0 ? "Aún no hay interesados. Aparecen cuando alguien pide una demo, una cotización o hace una consulta en el sitio." : "No hay interesados con ese filtro."} />
      ) : (
        <ul className="grid grid-cols-1 gap-3">
          {list.map((r) => (
            <li key={r.id}>
              <button type="button" onClick={() => setOpenId(r.id)} className="group flex w-full flex-col gap-3 rounded-2xl border border-line bg-surface p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md sm:flex-row sm:items-center sm:p-5">
                <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-full font-display text-base font-bold ${r.status === "new" ? "bg-brand text-white" : "bg-brand-soft text-brand-strong"}`}>
                  {(r.full_name ?? r.email).slice(0, 1).toUpperCase()}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <b className="font-display text-[15px] group-hover:text-brand-strong">{r.full_name ?? r.email}</b>
                    {r.company_name && <span className="text-sm text-muted">· {r.company_name}</span>}
                    <Chip tone={STATUS[r.status][1]} dot>
                      {STATUS[r.status][0]}
                    </Chip>
                    {r.marketing_consent && <Chip tone="ok">Acepta novedades</Chip>}
                  </span>
                  <span className="mt-0.5 block truncate text-sm text-muted">
                    {r.email} · {SOURCE[r.source] ?? r.source}
                    {r.requests > 1 ? ` · ${r.requests} solicitudes` : ""}
                  </span>
                </span>
                <span className="shrink-0 text-xs text-muted">{when(r.last_activity_at)}</span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {openId && (
        <LeadModal
          id={openId}
          onClose={() => setOpenId(null)}
          onSaved={(m) => {
            setOpenId(null);
            setToast(m);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function LeadModal({ id, onClose, onSaved }: { id: string; onClose: () => void; onSaved: (m: string) => void }) {
  const [d, setD] = useState<Detail | null>(null);
  const [status, setStatus] = useState<Status>("contacted");
  const [notes, setNotes] = useState("");
  const [reason, setReason] = useState("");
  const [withdraw, setWithdraw] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api<{ data: Detail }>(`/internal/site/leads/${id}`).then((r) => {
      setD(r.data);
      setStatus(r.data.status === "new" ? "contacted" : r.data.status);
      setNotes(r.data.notes ?? "");
      setReason(r.data.discard_reason ?? "");
    });
  }, [id]);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(`/internal/site/leads/${id}`, { method: "PATCH", body: { status, notes, discard_reason: status === "discarded" ? reason : null, withdraw_marketing: withdraw } });
      onSaved("Ficha actualizada.");
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.errors ?? {});
    } finally {
      setSaving(false);
    }
  }

  const sector = SECTORS.find(([k]) => k === d?.sector)?.[1];

  return (
    <Modal
      title={d ? (d.full_name ?? d.email) : "Interesado"}
      subtitle={d ? `${d.company_name ?? "Sin empresa"} · desde el ${when(d.created_at)}` : undefined}
      icon="user"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="lead-form" icon="check" loading={saving} disabled={!d}>
            Guardar
          </Button>
        </>
      }
    >
      {!d ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : (
        <form id="lead-form" onSubmit={save} className="space-y-4" noValidate>
          <section className="form-section">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
              {(
                [
                  ["Correo", d.email],
                  ["Celular", d.phone],
                  ["RUC", d.ruc],
                  ["Sector", sector],
                  ["Llegó por", SOURCE[d.source] ?? d.source],
                  ["Privacidad", d.privacy_consent ? `Aceptó${d.privacy_version ? ` (versión ${d.privacy_version})` : ""}` : "No"],
                  [
                    "Recibir novedades",
                    d.marketing_consent ? `Sí, desde el ${when(d.marketing_consent_at!)}` : d.marketing_consent_withdrawn_at ? `Se dio de baja el ${when(d.marketing_consent_withdrawn_at)}` : "No aceptó",
                  ],
                ] as [string, string | null | undefined][]
              )
                .filter(([, v]) => v)
                .map(([k, v]) => (
                  <div key={k}>
                    <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{k}</dt>
                    <dd className="mt-0.5 break-words font-medium">{k === "Correo" ? <a href={`mailto:${v}`} className="text-brand-strong hover:underline">{v}</a> : v}</dd>
                  </div>
                ))}
            </dl>
          </section>

          <section className="form-section">
            <h3 className="mb-3 font-display text-[15px] font-semibold">Lo que hizo en el sitio</h3>
            {d.timeline.length === 0 ? (
              <p className="text-sm text-muted">Sin actividad registrada.</p>
            ) : (
              <ol className="space-y-3">
                {d.timeline.map((t, i) => (
                  <li key={i} className="flex gap-3 text-sm">
                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true" />
                    <span>
                      <b>{REQUEST[t.detail] ?? t.detail}</b>
                      <span className="text-muted"> · {when(t.at)}</span>
                      {t.message && <span className="mt-0.5 block whitespace-pre-line text-muted">«{t.message}»</span>}
                    </span>
                  </li>
                ))}
              </ol>
            )}
          </section>

          <section className="form-section space-y-4">
            <p className="text-sm font-semibold">¿En qué va?</p>
            <ChoiceCards
              label="Estado"
              value={status}
              onChange={setStatus}
              options={(["contacted", "qualified", "converted", "discarded"] as Status[]).map((s) => ({ value: s, title: STATUS[s][0] }))}
            />
            {status === "discarded" && (
              <FormField label="Motivo" error={errors.discard_reason?.[0]}>
                <input className="input" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ej.: no responde, no es el perfil" />
              </FormField>
            )}
            <FormField label="Nota interna (opcional)" hint="Solo la ve el equipo.">
              <textarea className="input min-h-[80px]" maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
            </FormField>
            {d.marketing_consent && (
              <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" className="mt-0.5 h-4 w-4" checked={withdraw} onChange={(e) => setWithdraw(e.target.checked)} />
                <span>
                  <b>Pidió no recibir más novedades.</b> <span className="text-muted">Se registra la baja y ya no sale en la lista del boletín.</span>
                </span>
              </label>
            )}
          </section>
        </form>
      )}
    </Modal>
  );
}
