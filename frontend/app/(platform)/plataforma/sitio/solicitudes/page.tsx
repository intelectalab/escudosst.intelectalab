"use client";

// Sitio web › Solicitudes (SCR-WEB-12): demos, cotizaciones y consultas que llegan desde el sitio.
// El equipo marca cada una como contactada, demo agendada, convertida o descartada, con una nota interna.

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { ChoiceCards, FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { SECTORS } from "@/lib/accounts";
import { api, ApiError } from "@/lib/api";

type Status = "new" | "contacted" | "demo_scheduled" | "converted" | "discarded";
type Req = {
  id: string;
  request_type: "demo" | "quote" | "contact";
  full_name: string;
  company_name: string;
  ruc: string | null;
  job_title: string | null;
  email: string;
  phone: string | null;
  headcount: number | null;
  economic_sector: string | null;
  interest_plan_code: string | null;
  message: string | null;
  marketing_consent: boolean;
  source_page: string | null;
  status: Status;
  demo_scheduled_at: string | null;
  discard_reason: string | null;
  notes: string | null;
  created_at: string;
};

const STATUS: Record<Status, { label: string; tone: ChipTone; icon: string }> = {
  new: { label: "Nueva", tone: "brand", icon: "bell" },
  contacted: { label: "Contactada", tone: "amber", icon: "mail" },
  demo_scheduled: { label: "Demo agendada", tone: "amber", icon: "clock" },
  converted: { label: "Convertida", tone: "ok", icon: "check" },
  discarded: { label: "Descartada", tone: "na", icon: "close" },
};
const TYPE: Record<Req["request_type"], [string, string]> = { demo: ["Demo", "clock"], quote: ["Cotización", "tag"], contact: ["Consulta", "mail"] };
const PLAN: Record<string, string> = { E: "Esencial", P: "Profesional", C: "Corporativo", K: "Consultora" };
const sector = (v: string | null) => SECTORS.find(([k]) => k === v)?.[1] ?? null;
const when = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d));

export default function SolicitudesPage() {
  const [rows, setRows] = useState<Req[] | null>(null);
  const [filter, setFilter] = useState<"open" | Status>("open");
  const [open, setOpen] = useState<Req | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Req[] }>("/internal/site/requests")
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
  }, []);
  useEffect(load, [load]);

  const counts = useMemo(() => {
    const c = { new: 0, contacted: 0, demo_scheduled: 0, converted: 0, discarded: 0 } as Record<Status, number>;
    rows?.forEach((r) => c[r.status]++);
    return c;
  }, [rows]);
  const list = (rows ?? []).filter((r) => (filter === "open" ? ["new", "contacted", "demo_scheduled"].includes(r.status) : r.status === filter));

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Solicitudes"]]}
        icon="clock"
        title="Solicitudes"
        description="Bandeja de los formularios del sitio. Cuando alguien llena «Agenda una demo», «Pedir cotización» o «Hacer una consulta», llega aquí."
      />

      <ol className="grid grid-cols-1 gap-3 md:grid-cols-3">
        {(
          [
            ["mail", "1. Llega la solicitud", "Con los datos de la persona y su empresa. Si configuraste el correo de ventas, también te avisamos por correo."],
            ["people", "2. La contactas", "Por correo o teléfono, para coordinar la demo o enviar la cotización."],
            ["check", "3. Marcas en qué quedó", "Contactada, demo agendada, convertida en cliente o descartada. Así nadie se queda sin respuesta."],
          ] as const
        ).map(([icon, title, text]) => (
          <li key={title} className="flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
              <Icon name={icon} size={17} />
            </span>
            <span>
              <b className="block text-sm">{title}</b>
              <span className="text-xs text-muted">{text}</span>
            </span>
          </li>
        ))}
      </ol>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Por atender" value={counts.new + counts.contacted + counts.demo_scheduled} icon="bell" tone="brand" active={filter === "open"} onClick={() => setFilter("open")} hint={`${counts.new} nuevas`} />
        <StatCard label="Demos agendadas" value={counts.demo_scheduled} icon="clock" tone="amber" active={filter === "demo_scheduled"} onClick={() => setFilter("demo_scheduled")} />
        <StatCard label="Convertidas" value={counts.converted} icon="check" tone="ok" active={filter === "converted"} onClick={() => setFilter("converted")} />
        <StatCard label="Descartadas" value={counts.discarded} icon="close" tone="na" active={filter === "discarded"} onClick={() => setFilter("discarded")} />
      </div>

      {rows === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : list.length === 0 ? (
        <EmptyState icon="mail" text={filter === "open" ? "No hay solicitudes por atender. Las que lleguen desde «Agenda una demo» aparecerán aquí." : "No hay solicitudes en este estado."} />
      ) : (
        <ul className="grid grid-cols-1 gap-3">
          {list.map((r) => (
            <li key={r.id}>
              <button
                type="button"
                onClick={() => setOpen(r)}
                className="group flex w-full flex-col gap-3 rounded-2xl border border-line bg-surface p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md sm:flex-row sm:items-center sm:p-5"
              >
                <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl ${r.status === "new" ? "bg-brand text-white" : "bg-brand-soft text-brand-strong"}`}>
                  <Icon name={TYPE[r.request_type][1]} size={20} />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <b className="font-display text-[15px] group-hover:text-brand-strong">{r.company_name}</b>
                    <Chip tone="na">{TYPE[r.request_type][0]}</Chip>
                    {r.interest_plan_code && <Chip tone="brand">{PLAN[r.interest_plan_code]}</Chip>}
                  </span>
                  <span className="mt-0.5 block truncate text-sm text-muted">
                    {r.full_name} · {r.email}
                    {r.phone ? ` · ${r.phone}` : ""}
                  </span>
                </span>
                <span className="flex shrink-0 items-center gap-3 sm:flex-col sm:items-end sm:gap-1">
                  <Chip tone={STATUS[r.status].tone} dot>
                    {STATUS[r.status].label}
                  </Chip>
                  <span className="text-xs text-muted">{when(r.created_at)}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {open && (
        <RequestModal
          req={open}
          onClose={() => setOpen(null)}
          onSaved={(msg) => {
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

function RequestModal({ req, onClose, onSaved }: { req: Req; onClose: () => void; onSaved: (msg: string) => void }) {
  const [status, setStatus] = useState<Status>(req.status === "new" ? "contacted" : req.status);
  const [scheduled, setScheduled] = useState(req.demo_scheduled_at ? new Date(req.demo_scheduled_at).toLocaleString("sv-SE", { timeZone: "America/Lima" }).slice(0, 16).replace(" ", "T") : "");
  const [reason, setReason] = useState(req.discard_reason ?? "");
  const [notes, setNotes] = useState(req.notes ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const details: [string, string | null][] = [
    ["Contacto", `${req.full_name}${req.job_title ? ` · ${req.job_title}` : ""}`],
    ["Correo", req.email],
    ["Celular", req.phone],
    ["RUC", req.ruc],
    ["Trabajadores", req.headcount ? String(req.headcount) : null],
    ["Sector", sector(req.economic_sector)],
    ["Plan de interés", req.interest_plan_code ? PLAN[req.interest_plan_code] : null],
    ["Recibe novedades", req.marketing_consent ? "Sí, aceptó" : "No"],
  ];

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api(`/internal/site/requests/${req.id}`, {
        method: "PATCH",
        body: {
          status,
          demo_scheduled_at: status === "demo_scheduled" && scheduled ? `${scheduled}:00-05:00` : null,
          discard_reason: status === "discarded" ? reason.trim() || null : null,
          notes: notes.trim() || null,
        },
      });
      onSaved(`Solicitud de ${req.company_name}: ${STATUS[status].label.toLowerCase()}.`);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.errors ?? {});
        setError(err.errors ? null : err.message);
      } else setError("No se pudo guardar.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={req.company_name}
      subtitle={`${TYPE[req.request_type][0]} · recibida el ${when(req.created_at)}`}
      icon={TYPE[req.request_type][1]}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="request-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="request-form" onSubmit={save} className="space-y-4" noValidate>
        {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
        <section className="form-section">
          <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            {details
              .filter(([, v]) => v)
              .map(([k, v]) => (
                <div key={k}>
                  <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{k}</dt>
                  <dd className="mt-0.5 break-words font-medium">{k === "Correo" ? <a className="text-brand-strong hover:underline" href={`mailto:${v}`}>{v}</a> : v}</dd>
                </div>
              ))}
          </dl>
          {req.message && <p className="mt-4 whitespace-pre-line rounded-xl bg-bg px-4 py-3 text-sm">«{req.message}»</p>}
        </section>

        <section className="form-section space-y-4">
          <p className="text-sm font-semibold">¿En qué quedó?</p>
          <ChoiceCards
            label="Estado"
            columns={2}
            value={status}
            onChange={setStatus}
            options={(["contacted", "demo_scheduled", "converted", "discarded"] as Status[]).map((s) => ({ value: s, title: STATUS[s].label }))}
          />
          {status === "demo_scheduled" && (
            <FormField label="Fecha y hora de la demo (hora de Lima)" error={errors.demo_scheduled_at?.[0]}>
              <input className="input" type="datetime-local" value={scheduled} onChange={(e) => setScheduled(e.target.value)} />
            </FormField>
          )}
          {status === "discarded" && (
            <FormField label="Motivo" error={errors.discard_reason?.[0]}>
              <input className="input" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ej.: no responde, eligió otra solución" />
            </FormField>
          )}
          {status === "converted" && <p className="text-xs text-muted">Crea su cuenta en «Cuentas y suscripciones» si aún no se registró sola.</p>}
          <FormField label="Nota interna (opcional)" hint="Solo la ve el equipo." error={errors.notes?.[0]}>
            <textarea className="input min-h-[80px]" maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>
        </section>
      </form>
    </Modal>
  );
}
