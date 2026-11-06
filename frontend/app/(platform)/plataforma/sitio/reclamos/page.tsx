"use client";

// Sitio web › Libro de Reclamaciones (SCR-WEB-25): hojas que llegan desde el sitio, con semáforo de plazo
// (verde en plazo, ámbar por vencer, rojo vencido, gris atendido), ficha con datos e historial, y acciones:
// empezar revisión, prorrogar con motivo (hasta el tope legal), responder (se envía por correo), cerrar y anotar.

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { SitePanelTabs } from "@/components/site/SitePanelTabs";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";

type Light = "green" | "amber" | "red" | "gray";
type Row = {
  id: string;
  complaint_code: string;
  complaint_type: "claim" | "complaint";
  filed_at: string;
  status: string;
  consumer_name: string;
  due_on: string;
  days_left: number | null;
  light: Light;
};
type Rules = { response_days: number | null; extension_days: number | null; warning_days: number | null; retention_years: number | null };
type Detail = Record<string, unknown> & {
  id: string;
  complaint_code: string;
  complaint_type: "claim" | "complaint";
  status: string;
  filed_at: string;
  response_due_on: string;
  extended_until: string | null;
  max_extension_on: string | null;
  response_text: string | null;
  events: { event_type: string; reason: string | null; actor_type: string; actor_name: string | null; occurred_at: string; details: string | null }[];
};

const TYPE = { claim: "Reclamo", complaint: "Queja" };
const STATUS: Record<string, [string, ChipTone]> = {
  received: ["Recibida", "brand"],
  in_progress: ["En revisión", "amber"],
  extended: ["Prorrogada", "amber"],
  answered: ["Respondida", "ok"],
  closed: ["Cerrada", "na"],
};
const LIGHT: Record<Light, [string, string]> = {
  green: ["En plazo", "bg-ok"],
  amber: ["Por vencer", "bg-amber-ink"],
  red: ["Vencida", "bg-bad"],
  gray: ["Atendida", "bg-na"],
};
const EVENT: Record<string, string> = {
  registered: "Hoja registrada por el consumidor",
  acknowledgment_sent: "Constancia enviada por correo",
  in_progress: "Revisión iniciada",
  extended: "Plazo prorrogado",
  answered: "Respuesta enviada",
  closed: "Hoja cerrada",
  note: "Nota interna",
  assigned: "Asignada",
};
const day = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeZone: "America/Lima" }).format(new Date(d.length === 10 ? `${d}T12:00:00-05:00` : d));
const when = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d));

export default function ReclamosPage() {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [rules, setRules] = useState<Rules | null>(null);
  const [filter, setFilter] = useState<"open" | Light>("open");
  const [openId, setOpenId] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Row[]; rules: Rules }>("/internal/site/complaints")
      .then((r) => {
        setRows(r.data);
        setRules(r.rules);
      })
      .catch(() => setRows([]));
  }, []);
  useEffect(load, [load]);

  const count = useMemo(() => {
    const c = { green: 0, amber: 0, red: 0, gray: 0 } as Record<Light, number>;
    rows?.forEach((r) => c[r.light]++);
    return c;
  }, [rows]);
  const list = (rows ?? []).filter((r) => (filter === "open" ? r.light !== "gray" : r.light === filter));

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Libro de Reclamaciones"]]}
        icon="globe"
        title="Sitio web"
        description="Reclamos y quejas de consumidores sobre EscudoSST. La ley exige responder dentro del plazo; el semáforo te avisa cuáles vencen."
      />
      <SitePanelTabs />

      <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <h2 className="font-display font-semibold">Cómo se atiende una hoja</h2>
        <ol className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-4">
          {(
            [
              ["file", "1. Llega", "El consumidor la llena en el sitio. Recibe por correo su constancia con código y clave."],
              ["play", "2. La revisas", "Abre la hoja y pulsa «Empezar revisión». Puedes agregar notas internas."],
              ["clock", "3. Si necesitas tiempo", `«Prorrogar» con un motivo${rules?.extension_days ? ` (hasta ${rules.extension_days} días hábiles más)` : ""}. Al consumidor le llega un correo con la nueva fecha.`],
              ["mail", "4. Respondes", "Escribes la respuesta y se envía al correo del consumidor. Luego cierras la hoja."],
            ] as const
          ).map(([icon, title, text]) => (
            <li key={title} className="flex items-start gap-3 rounded-xl bg-bg/60 p-3.5">
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
        <p className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
          {rules?.response_days && <span>Plazo de respuesta: <b className="text-fg">{rules.response_days} días hábiles</b></span>}
          {rules?.warning_days && <span>Aviso «por vencer»: <b className="text-fg">{rules.warning_days} días antes</b></span>}
          {rules?.retention_years && <span>Las hojas se guardan <b className="text-fg">{rules.retention_years} años</b> y no se pueden borrar</span>}
          <span>Los datos personales se guardan cifrados</span>
          <span>Plazos: Catálogos › Parámetros (a confirmar con el abogado)</span>
        </p>
      </section>

      <ExportBar onError={(m) => setToast(m)} />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Por atender" value={count.green + count.amber + count.red} icon="bell" tone="brand" active={filter === "open"} onClick={() => setFilter("open")} />
        <StatCard label="Por vencer" value={count.amber} icon="clock" tone="amber" active={filter === "amber"} onClick={() => setFilter("amber")} />
        <StatCard label="Vencidas" value={count.red} icon="history" tone="bad" active={filter === "red"} onClick={() => setFilter("red")} />
        <StatCard label="Atendidas" value={count.gray} icon="check" tone="ok" active={filter === "gray"} onClick={() => setFilter("gray")} />
      </div>

      {rows === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : list.length === 0 ? (
        <EmptyState icon="file" text={filter === "open" ? "No hay hojas por atender. Las que registren los consumidores en el sitio aparecerán aquí." : "No hay hojas en este grupo."} />
      ) : (
        <ul className="grid grid-cols-1 gap-3">
          {list.map((r) => (
            <li key={r.id}>
              <button
                type="button"
                onClick={() => setOpenId(r.id)}
                className="group flex w-full flex-col gap-3 rounded-2xl border border-line bg-surface p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md sm:flex-row sm:items-center sm:p-5"
              >
                <span className={`h-11 w-1.5 shrink-0 rounded-full max-sm:h-1.5 max-sm:w-11 ${LIGHT[r.light][1]}`} title={LIGHT[r.light][0]} aria-hidden="true" />
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <b className="font-mono text-[15px] group-hover:text-brand-strong">{r.complaint_code}</b>
                    <Chip tone="na">{TYPE[r.complaint_type]}</Chip>
                    <Chip tone={STATUS[r.status][1]} dot>
                      {STATUS[r.status][0]}
                    </Chip>
                  </span>
                  <span className="mt-0.5 block truncate text-sm text-muted">
                    {r.consumer_name} · recibida el {when(r.filed_at)}
                  </span>
                </span>
                <span className="shrink-0 text-sm sm:text-right">
                  <b className="block">{LIGHT[r.light][0]}</b>
                  <span className="text-xs text-muted">
                    {r.days_left === null ? `Vencía el ${day(r.due_on)}` : r.days_left < 0 ? `Venció hace ${-r.days_left} días` : r.days_left === 0 ? "Vence hoy" : `Vence el ${day(r.due_on)} (${r.days_left} días)`}
                  </span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {openId && (
        <ComplaintModal
          id={openId}
          onClose={() => setOpenId(null)}
          onDone={(msg) => {
            setToast(msg);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function ComplaintModal({ id, onClose, onDone }: { id: string; onClose: () => void; onDone: (msg: string) => void }) {
  const [d, setD] = useState<Detail | null>(null);
  const [mode, setMode] = useState<null | "extend" | "answer" | "note">(null);
  const [text, setText] = useState("");
  const [until, setUntil] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const reload = useCallback(() => api<{ data: Detail }>(`/internal/site/complaints/${id}`).then((r) => setD(r.data)), [id]);
  useEffect(() => {
    reload().catch(() => setError("No se pudo abrir la hoja."));
  }, [reload]);

  async function act(action: string, body: Record<string, unknown> = {}, msg = "Hoja actualizada.") {
    setSaving(true);
    setError(null);
    setErrors({});
    try {
      await api(`/internal/site/complaints/${id}/actions`, { method: "POST", body: { action, ...body } });
      setMode(null);
      setText("");
      await reload();
      onDone(msg);
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        if (!e.errors) setError(e.message);
      } else setError("No se pudo guardar.");
    } finally {
      setSaving(false);
    }
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    if (mode === "extend") act("extend", { text, until }, "Plazo prorrogado.");
    if (mode === "answer") act("answer", { text, channel: "email" }, "Respuesta enviada al correo del consumidor.");
    if (mode === "note") act("note", { text }, "Nota agregada.");
  }

  const s = (k: string) => (d?.[k] as string | null | undefined) ?? null;
  const open = d && !["answered", "closed"].includes(d.status);

  return (
    <Modal title={d ? `${TYPE[d.complaint_type]} ${d.complaint_code}` : "Libro de Reclamaciones"} subtitle={d ? `Recibida el ${when(d.filed_at)}` : undefined} icon="file" size="lg" onClose={onClose}>
      {!d ? (
        <p className="text-sm text-muted">{error ?? "Cargando…"}</p>
      ) : (
        <div className="space-y-4">
          {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
          <div className="flex flex-wrap items-center gap-2">
            <Chip tone={STATUS[d.status][1]} dot>
              {STATUS[d.status][0]}
            </Chip>
            <span className="text-sm text-muted">
              Responder a más tardar el <b className="text-fg">{day(d.extended_until ?? d.response_due_on)}</b>
              {d.extended_until && ` (prorrogada; vencía el ${day(d.response_due_on)})`}
            </span>
          </div>

          <section className="form-section">
            <h3 className="mb-3 font-display text-[15px] font-semibold">Consumidor</h3>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              {(
                [
                  ["Nombre", s("consumer_full_name")],
                  ["Documento", `${s("consumer_document_type")} ${s("consumer_document_number")}`],
                  ["Correo", s("consumer_email")],
                  ["Celular", s("consumer_phone")],
                  ["Dirección", s("consumer_address")],
                  ["Apoderado (menor de edad)", d.is_minor ? `${s("guardian_full_name")} · ${s("guardian_document_type")} ${s("guardian_document_number")}` : null],
                ] as [string, string | null][]
              )
                .filter(([, v]) => v)
                .map(([k, v]) => (
                  <div key={k}>
                    <dt className="text-xs font-semibold uppercase tracking-wide text-muted">{k}</dt>
                    <dd className="mt-0.5 break-words font-medium">{v}</dd>
                  </div>
                ))}
            </dl>
          </section>

          <section className="form-section space-y-3 text-sm">
            <h3 className="font-display text-[15px] font-semibold">
              {s("item_type") === "product" ? "Producto" : "Servicio"}: {s("item_description")}
              {s("amount_claimed") && <span className="font-normal text-muted"> · monto reclamado S/ {s("amount_claimed")}</span>}
            </h3>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-muted">Detalle</p>
              <p className="mt-1 whitespace-pre-line">{s("detail")}</p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-muted">Pedido</p>
              <p className="mt-1 whitespace-pre-line">{s("consumer_request")}</p>
            </div>
            {d.response_text && (
              <div className="rounded-xl bg-ok-soft px-4 py-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-ok">Respuesta enviada</p>
                <p className="mt-1 whitespace-pre-line">{d.response_text}</p>
              </div>
            )}
          </section>

          {mode ? (
            <form onSubmit={submit} className="form-section space-y-4" noValidate>
              <h3 className="font-display text-[15px] font-semibold">{mode === "extend" ? "Prorrogar el plazo" : mode === "answer" ? "Responder al consumidor" : "Nota interna"}</h3>
              {mode === "extend" && (
                <FormField
                  label="Nueva fecha límite"
                  hint={d.max_extension_on ? `Como máximo hasta el ${day(d.max_extension_on)} (tope legal de la prórroga).` : undefined}
                  error={errors.until?.[0]}
                >
                  <input className="input" type="date" min={d.response_due_on} max={d.max_extension_on ?? undefined} value={until} onChange={(e) => setUntil(e.target.value)} />
                </FormField>
              )}
              <FormField
                label={mode === "extend" ? "Motivo (se le envía al consumidor por correo)" : mode === "answer" ? "Respuesta" : "Nota (solo la ve el equipo)"}
                hint={mode === "answer" ? "Se envía al correo del consumidor y queda en la hoja. Revisa bien antes de enviar: no se puede editar." : undefined}
                error={errors.text?.[0]}
              >
                <textarea className="input min-h-[120px]" maxLength={6000} value={text} onChange={(e) => setText(e.target.value)} />
              </FormField>
              <div className="flex flex-wrap justify-end gap-2">
                <Button onClick={() => setMode(null)}>Cancelar</Button>
                <Button type="submit" variant="primary" icon="check" loading={saving}>
                  {mode === "answer" ? "Enviar respuesta" : "Guardar"}
                </Button>
              </div>
            </form>
          ) : (
            <div className="flex flex-wrap gap-2">
              {d.status === "received" && (
                <Button icon="play" loading={saving} onClick={() => act("start", {}, "Revisión iniciada.")}>
                  Empezar revisión
                </Button>
              )}
              {open && (
                <Button variant="primary" icon="mail" onClick={() => setMode("answer")}>
                  Responder
                </Button>
              )}
              {open && d.status !== "extended" && d.max_extension_on && (
                <Button icon="clock" onClick={() => setMode("extend")}>
                  Prorrogar
                </Button>
              )}
              {d.status === "answered" && (
                <Button icon="check" loading={saving} onClick={() => act("close", {}, "Hoja cerrada.")}>
                  Cerrar hoja
                </Button>
              )}
              <Button variant="ghost" icon="pen" onClick={() => setMode("note")}>
                Agregar nota
              </Button>
            </div>
          )}

          <section className="form-section">
            <h3 className="mb-3 font-display text-[15px] font-semibold">Historial</h3>
            <ol className="space-y-3">
              {d.events.map((e, i) => (
                <li key={i} className="flex gap-3 text-sm">
                  <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true" />
                  <span>
                    <b>{EVENT[e.event_type] ?? e.event_type}</b>
                    <span className="text-muted">
                      {" "}
                      · {when(e.occurred_at)}
                      {e.actor_name ? ` · ${e.actor_name}` : e.actor_type === "consumer" ? " · consumidor" : ""}
                    </span>
                    {e.reason && <span className="mt-0.5 block text-muted">{e.reason}</span>}
                  </span>
                </li>
              ))}
            </ol>
          </section>
        </div>
      )}
    </Modal>
  );
}

/** Reporte del Libro para Indecopi: CSV (se abre con Excel), sin datos personales, filtrable por fechas. */
function ExportBar({ onError }: { onError: (msg: string) => void }) {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [busy, setBusy] = useState(false);

  async function download() {
    setBusy(true);
    try {
      const q = new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) });
      const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000"}/api/v1/internal/site/complaints/export?${q}`, {
        headers: { Authorization: `Bearer ${session.getToken()}` },
      });
      if (!res.ok) throw new Error();
      const url = URL.createObjectURL(await res.blob());
      const a = document.createElement("a");
      a.href = url;
      a.download = `libro-de-reclamaciones${from ? `-desde-${from}` : ""}${to ? `-hasta-${to}` : ""}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      onError("No se pudo descargar el reporte. Revisa las fechas.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="flex flex-wrap items-end gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
      <div className="min-w-[12rem] flex-1">
        <p className="text-sm font-semibold">Reporte para Indecopi</p>
        <p className="text-xs text-muted">Una fila por hoja: código, fechas, tipo, estado y si se respondió a tiempo. Sin datos personales. Se abre con Excel.</p>
      </div>
      <label className="text-xs font-semibold text-muted">
        Desde
        <input className="input mt-1 !py-2" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
      </label>
      <label className="text-xs font-semibold text-muted">
        Hasta
        <input className="input mt-1 !py-2" type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} />
      </label>
      <Button icon="file" loading={busy} onClick={download}>
        Descargar reporte
      </Button>
    </section>
  );
}
