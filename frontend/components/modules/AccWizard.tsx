"use client";

// Registrar evento (M-ACC, SCR-ACC-01; prototipo m-personas.js, 4 pasos «Qué pasó · Persona afectada · Lesión y
// testigos · Notificación» y «Guardar y notificar»). Crea el evento (EventRules: código ACC-AAAA-NNNN, plazo de
// notificación según el tipo y apertura de la investigación si corresponde), la persona afectada y los testigos
// (event_people) y, si ya se notificó, registra la constancia en la notificación obligatoria que generó el sistema.

import Link from "next/link";
import { useEffect, useState } from "react";
import { records, recordsHref, toLocalInput, type RecordRow } from "@/lib/records";
import { FormField } from "@/components/ui/Form";
import { RefSelect } from "./RefSelect";
import { EnabledChip, RequirementChips, useRequirements } from "./WorkerChecks";
import { BTN, BTN_PRIMARY, SummaryRow, Wizard, type WizardStep } from "./Wizard";

const TYPES: { value: string; label: string; person: boolean }[] = [
  { value: "incident", label: "Incidente", person: false },
  { value: "dangerous_incident", label: "Incidente peligroso", person: false },
  { value: "minor_accident", label: "Accidente de trabajo leve", person: true },
  { value: "disabling_accident", label: "Accidente incapacitante", person: true },
  { value: "fatal_accident", label: "Accidente mortal", person: true },
  { value: "occupational_disease", label: "Enfermedad ocupacional", person: true },
];
const PLACES = ["Tópico de la sede", "Tópico y luego EsSalud", "Clínica afiliada al SCTR", "Hospital o centro de salud", "No requirió atención"];
const BODY = ["Cabeza", "Ojos", "Cara", "Cuello", "Espalda", "Tórax", "Abdomen", "Brazo", "Mano izquierda", "Mano derecha", "Dedos de la mano", "Pierna", "Rodilla", "Pie", "Varias partes"];

type Data = {
  event_type: string;
  occurred_at: string;
  site_id: string;
  site_label: string;
  exact_location: string;
  description: string;
  activity_at_time: string;
  immediate_actions: string;
  iperc_row_id: string;
  iperc_row_label: string;
  person: "worker" | "external" | "none";
  worker_id: string;
  worker_label: string;
  external_name: string;
  injury_type: string;
  body_part: string;
  injury_agent: string;
  lost_days: string;
  medical_place: string;
  witnesses: { id: string; label: string }[];
  notified: boolean;
  notification_number: string;
  notified_at: string;
};

const typeLabel = (v: string) => TYPES.find((t) => t.value === v)?.label ?? v;

/** Plazo estimado (el definitivo lo calcula el sistema al guardar con los parámetros legales vigentes). */
function estimate(type: string, occurred: string): { at: Date | null; text: string } {
  const d = occurred ? new Date(occurred) : null;
  if (!d || Number.isNaN(d.getTime())) return { at: null, text: "Indica la fecha y hora del hecho." };
  if (type === "fatal_accident" || type === "dangerous_incident") return { at: new Date(d.getTime() + 24 * 3600 * 1000), text: "Notificación al Ministerio de Trabajo (SAT) dentro de 24 horas del hecho (parámetro legal, a confirmar por especialista)." };
  if (type === "occupational_disease") return { at: null, text: "Notificación dentro de 5 días hábiles desde el diagnóstico (parámetro legal, a confirmar por especialista)." };
  if (type === "minor_accident" || type === "disabling_accident") {
    const end = new Date(d.getFullYear(), d.getMonth() + 2, 0, 23, 59);
    while (end.getDay() === 0 || end.getDay() === 6) end.setDate(end.getDate() - 1);
    return { at: end, text: "La notifica el centro médico asistencial hasta el último día hábil del mes siguiente; verifica que se haya hecho (parámetro legal)." };
  }
  return { at: null, text: "Un incidente no peligroso no se notifica a la autoridad: queda en el registro de la empresa." };
}

export function Countdown({ until }: { until: Date | string | null }) {
  const end = until ? new Date(until).getTime() : null;
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!end) return;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [end]);
  if (!end) return null;
  const left = Math.max(0, end - now);
  const days = Math.floor(left / 86400000);
  const h = Math.floor((left % 86400000) / 3600000);
  const m = Math.floor((left % 3600000) / 60000);
  const s = Math.floor((left % 60000) / 1000);
  const p = (n: number) => String(n).padStart(2, "0");
  const urgent = left < 6 * 3600000;
  return (
    <div className="text-center">
      <p className="text-xs text-muted">{left === 0 ? "Plazo vencido" : "Tiempo restante para notificar"}</p>
      <p className={`font-display text-3xl font-bold tabular-nums ${urgent ? "text-bad" : "text-amber-ink"}`} aria-live="off">
        {days > 0 ? `${days} d ` : ""}
        {p(h)}:{p(m)}:{p(s)}
      </p>
      <p className="text-xs text-muted">Vence el {new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short" }).format(new Date(end))}</p>
    </div>
  );
}

function WorkerCard({ id }: { id: string }) {
  const { data } = useRequirements([id]);
  const w = data[id];
  if (!w) return <div className="h-20 animate-pulse rounded-xl bg-bg" />;
  return (
    <div className="space-y-2 rounded-xl border border-line bg-bg/50 p-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <b>{w.name}</b>
        <EnabledChip enabled={w.enabled} />
      </div>
      <p className="text-muted">{[w.position, w.area, w.site].filter(Boolean).join(" · ") || "Sin puesto asignado"}</p>
      <RequirementChips checks={w.checks} />
    </div>
  );
}

export function AccWizard({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const initial: Data = {
    event_type: "minor_accident", occurred_at: toLocalInput(new Date().toISOString()), site_id: "", site_label: "", exact_location: "", description: "",
    activity_at_time: "", immediate_actions: "", iperc_row_id: "", iperc_row_label: "", person: "worker", worker_id: "", worker_label: "", external_name: "", injury_type: "", body_part: "", injury_agent: "",
    lost_days: "", medical_place: "", witnesses: [], notified: false, notification_number: "", notified_at: "",
  };
  const needsPerson = (d: Data) => TYPES.find((t) => t.value === d.event_type)?.person ?? false;

  const steps: WizardStep<Data>[] = [
    {
      title: "Qué pasó",
      validate: (d) =>
        !d.occurred_at ? "Indica la fecha y hora del hecho." : new Date(d.occurred_at).getTime() > Date.now() + 300000 ? "La fecha del evento no puede ser futura." : !d.site_id ? "Elige la sede." : d.description.trim().length < 10 ? "Describe qué pasó (al menos una frase)." : null,
      render: (d, set) => (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Tipo de evento">
            <select className="input" value={d.event_type} onChange={(e) => set({ event_type: e.target.value, person: TYPES.find((t) => t.value === e.target.value)?.person ? "worker" : d.person })}>
              {TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </FormField>
          <FormField label="Fecha y hora del hecho">
            <input className="input" type="datetime-local" value={d.occurred_at} max={toLocalInput(new Date().toISOString())} onChange={(e) => set({ occurred_at: e.target.value })} />
          </FormField>
          <FormField label="Sede">
            <RefSelect module="ACC" table="sst_events" field="site_id" label="Sede" value={d.site_id} onChange={(id, label) => set({ site_id: id, site_label: label })} />
          </FormField>
          <FormField label="Lugar exacto">
            <input className="input" value={d.exact_location} maxLength={300} placeholder="Ej.: Línea de prensas 3" onChange={(e) => set({ exact_location: e.target.value })} />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Descripción" hint="Qué pasó, en orden, sin juzgar a nadie.">
              <textarea className="input min-h-[96px]" value={d.description} maxLength={5000} onChange={(e) => set({ description: e.target.value })} />
            </FormField>
          </div>
          <FormField label="Tarea que se realizaba">
            <input className="input" value={d.activity_at_time} maxLength={300} onChange={(e) => set({ activity_at_time: e.target.value })} />
          </FormField>
          <FormField label="Acciones inmediatas">
            <input className="input" value={d.immediate_actions} maxLength={500} placeholder="Ej.: se detuvo la máquina y se aisló la zona" onChange={(e) => set({ immediate_actions: e.target.value })} />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Fila del IPERC relacionada (opcional)" hint="Si el peligro estaba en la matriz IPERC, elígelo: un accidente o incidente peligroso la marca «requiere revisión».">
              <RefSelect module="ACC" table="sst_events" field="iperc_row_id" label="Fila del IPERC" value={d.iperc_row_id} onChange={(id, label) => set({ iperc_row_id: id, iperc_row_label: label })} />
            </FormField>
          </div>
        </div>
      ),
    },
    {
      title: "Persona afectada",
      validate: (d) =>
        d.person === "worker" && !d.worker_id ? "Elige al trabajador afectado." : d.person === "external" && !d.external_name.trim() ? "Escribe el nombre de la persona afectada." : needsPerson(d) && d.person === "none" ? "Un accidente o enfermedad necesita la persona afectada." : null,
      render: (d, set) => (
        <div className="space-y-4">
          <div role="radiogroup" aria-label="Persona afectada" className="grid gap-2 sm:grid-cols-3">
            {([["worker", "Trabajador de la empresa"], ["external", "Otra persona (visita, tercero)"], ["none", "Nadie resultó afectado"]] as const).map(([v, l]) => (
              <button key={v} type="button" role="radio" aria-checked={d.person === v} disabled={v === "none" && needsPerson(d)} onClick={() => set({ person: v })}
                className={`min-h-12 rounded-lg border-2 px-3 text-sm font-semibold disabled:opacity-40 ${d.person === v ? "border-brand bg-brand-soft text-brand-strong" : "border-line hover:border-brand/50"}`}>
                {l}
              </button>
            ))}
          </div>
          {d.person === "worker" && (
            <>
              <FormField label="Trabajador afectado">
                <RefSelect module="ACC" table="event_people" field="worker_id" label="Trabajador afectado" value={d.worker_id} onChange={(id, label) => set({ worker_id: id, worker_label: label })} />
              </FormField>
              {d.worker_id && <WorkerCard id={d.worker_id} />}
            </>
          )}
          {d.person === "external" && (
            <FormField label="Nombre de la persona">
              <input className="input" value={d.external_name} maxLength={200} onChange={(e) => set({ external_name: e.target.value })} />
            </FormField>
          )}
        </div>
      ),
    },
    {
      title: "Lesión y testigos",
      validate: (d) =>
        d.lost_days && (Number(d.lost_days) < 0 || !Number.isInteger(Number(d.lost_days)))
          ? "Los días de descanso son un número entero desde 0."
          // RN-ACC-06: en un accidente, la persona lesionada lleva tipo de lesión, parte del cuerpo y agente.
          : d.person !== "none" && d.event_type.includes("accident") && (!d.injury_type.trim() || !d.body_part || !d.injury_agent.trim())
            ? "En un accidente indica el tipo de lesión, la parte del cuerpo y el agente que la causó."
            : null,
      render: (d, set) => (
        <div className="space-y-4">
          {d.person !== "none" && (
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Tipo de lesión">
                <input className="input" value={d.injury_type} maxLength={200} placeholder="Ej.: corte, golpe, quemadura" onChange={(e) => set({ injury_type: e.target.value })} />
              </FormField>
              <FormField label="Parte del cuerpo">
                <select className="input" value={d.body_part} onChange={(e) => set({ body_part: e.target.value })}>
                  <option value="">Elige</option>
                  {BODY.map((b) => <option key={b}>{b}</option>)}
                </select>
              </FormField>
              <FormField label="Agente que causó la lesión">
                <input className="input" value={d.injury_agent} maxLength={200} placeholder="Ej.: herramienta manual, caída de material, máquina" onChange={(e) => set({ injury_agent: e.target.value })} />
              </FormField>
              <FormField label="Días de descanso estimados">
                <input className="input" type="number" min={0} value={d.lost_days} onChange={(e) => set({ lost_days: e.target.value })} />
              </FormField>
              <FormField label="Atención médica">
                <select className="input" value={d.medical_place} onChange={(e) => set({ medical_place: e.target.value })}>
                  <option value="">Elige</option>
                  {PLACES.map((p) => <option key={p}>{p}</option>)}
                </select>
              </FormField>
            </div>
          )}
          <FormField label="Testigos" hint="Agrega a los trabajadores que vieron lo ocurrido.">
            <RefSelect module="ACC" table="event_people" field="worker_id" label="Agregar testigo" placeholder="Agregar testigo"
              exclude={[d.worker_id, ...d.witnesses.map((w) => w.id)]} value=""
              onChange={(id, label) => id && set({ witnesses: [...d.witnesses, { id, label }] })} />
          </FormField>
          {d.witnesses.length > 0 && (
            <ul className="flex flex-wrap gap-2">
              {d.witnesses.map((w) => (
                <li key={w.id} className="flex items-center gap-1 rounded-full border border-line px-2.5 py-1 text-sm">
                  {w.label}
                  <button type="button" aria-label={`Quitar a ${w.label}`} className="text-muted hover:text-bad" onClick={() => set({ witnesses: d.witnesses.filter((x) => x.id !== w.id) })}>×</button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ),
    },
    {
      title: "Notificación",
      validate: (d) => (d.notified && !d.notification_number.trim() ? "Escribe el número de la constancia de notificación." : null),
      render: (d, set) => {
        const est = estimate(d.event_type, d.occurred_at);
        return (
          <div className="space-y-4">
            <p className="rounded-lg bg-amber-soft px-3 py-2 text-sm text-amber-ink">{est.text}</p>
            {est.at && (
              <div className="rounded-xl border border-line p-4">
                <Countdown until={est.at} />
                <p className="mt-1 text-center text-xs text-muted">Plazo estimado: el definitivo se calcula al guardar con los parámetros legales vigentes.</p>
              </div>
            )}
            <label className="flex items-center gap-2 text-sm font-semibold">
              <input type="checkbox" checked={d.notified} onChange={(e) => set({ notified: e.target.checked })} />
              Ya se notificó a la autoridad
            </label>
            {d.notified && (
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField label="N.° de constancia (SAT)">
                  <input className="input" value={d.notification_number} maxLength={100} onChange={(e) => set({ notification_number: e.target.value })} />
                </FormField>
                <FormField label="Fecha y hora de la notificación">
                  <input className="input" type="datetime-local" value={d.notified_at} max={toLocalInput(new Date().toISOString())} onChange={(e) => set({ notified_at: e.target.value })} />
                </FormField>
              </div>
            )}
            <p className="text-sm text-muted">Al guardar se registra el evento y su plazo; si el tipo lo exige, se abre la investigación.</p>
          </div>
        );
      },
    },
  ];

  return (
    <Wizard<Data>
      id="acc-new"
      title="Registrar evento"
      subtitle="Accidente, incidente o enfermedad ocupacional"
      icon="alert"
      initial={initial}
      steps={steps}
      confirmLabel="Guardar y notificar"
      onClose={onClose}
      summary={(d, goTo) => (
        <div>
          <SummaryRow label="Tipo" value={typeLabel(d.event_type)} onEdit={() => goTo(0)} />
          <SummaryRow label="Fecha y hora" value={d.occurred_at.replace("T", " ").split(" ").map((p, i) => (i === 0 ? p.split("-").reverse().join("/") : p)).join(" ")} onEdit={() => goTo(0)} />
          <SummaryRow label="Lugar" value={[d.site_label, d.exact_location].filter(Boolean).join(" · ")} onEdit={() => goTo(0)} />
          <SummaryRow label="Descripción" value={d.description} onEdit={() => goTo(0)} />
          <SummaryRow label="Persona afectada" value={d.person === "worker" ? d.worker_label : d.person === "external" ? d.external_name : "Nadie"} onEdit={() => goTo(1)} />
          {d.person !== "none" && <SummaryRow label="Lesión" value={[d.injury_type, d.body_part, d.injury_agent, d.lost_days ? `${d.lost_days} día(s) de descanso` : ""].filter(Boolean).join(" · ")} onEdit={() => goTo(2)} />}
          <SummaryRow label="Testigos" value={d.witnesses.map((w) => w.label).join(", ") || "Sin testigos"} onEdit={() => goTo(2)} />
          <SummaryRow label="Notificación" value={d.notified ? `Notificado · constancia ${d.notification_number}` : "Pendiente: el sistema controla el plazo"} onEdit={() => goTo(3)} />
        </div>
      )}
      onConfirm={async (d) => {
        const ev = await records.create("ACC", "sst_events", {
          event_type: d.event_type,
          occurred_at: new Date(d.occurred_at).toISOString(),
          site_id: d.site_id,
          exact_location: d.exact_location.trim() || null,
          description: d.description.trim(),
          activity_at_time: d.activity_at_time.trim() || null,
          immediate_actions: d.immediate_actions.trim() || null,
          iperc_row_id: d.iperc_row_id || null,
          origin: "web",
          witnesses: d.witnesses.length ? d.witnesses.map((w) => w.label) : null,
        });
        const id = ev.data.id;
        const role = d.event_type === "occupational_disease" ? "ill" : d.event_type.includes("accident") ? "injured" : "affected";
        if (d.person !== "none") {
          await records.create("ACC", "event_people", {
            event_id: id, person_role: role,
            worker_id: d.person === "worker" ? d.worker_id : null,
            external_person_name: d.person === "external" ? d.external_name.trim() : d.worker_label || null,
            injury_type: d.injury_type.trim() || null, body_part: d.body_part || null, injury_agent: d.injury_agent.trim() || null,
            lost_days: d.lost_days ? Number(d.lost_days) : null, medical_attention_place: d.medical_place || null,
          });
        }
        for (const w of d.witnesses) await records.create("ACC", "event_people", { event_id: id, person_role: "witness", worker_id: w.id, external_person_name: w.label });
        let notifyError: string | null = null;
        const notes = await records.list("ACC", "event_notifications", { filters: { event_id: id } });
        if (d.notified && notes.data[0]) {
          try {
            await records.update("ACC", "event_notifications", notes.data[0].id, {
              status: "sent", channel: "sat_online", notification_number: d.notification_number.trim(),
              notified_at: d.notified_at ? new Date(d.notified_at).toISOString() : new Date().toISOString(),
            });
          } catch (e) {
            notifyError = (e as { detail?: string }).detail ?? "No se pudo registrar la constancia.";
          }
        }
        onDone();
        return <AccResult id={id} notifyError={notifyError} />;
      }}
    />
  );
}

function AccResult({ id, notifyError }: { id: string; notifyError: string | null }) {
  const [ev, setEv] = useState<RecordRow | null>(null);
  const [note, setNote] = useState<RecordRow | null>(null);
  useEffect(() => {
    records.get("ACC", "sst_events", id).then((r) => setEv(r.data)).catch(() => undefined);
    records.list("ACC", "event_notifications", { filters: { event_id: id } }).then((r) => setNote(r.data[0] ?? null)).catch(() => undefined);
  }, [id]);
  const pending = note?.status === "pending";
  return (
    <div className="space-y-3">
      <p className="rounded-lg bg-ok-soft px-3 py-2 text-sm text-ok">
        Evento {String(ev?.code ?? "")} registrado. {ev?.status === "under_investigation" ? "Se abrió su investigación." : ""}
      </p>
      {notifyError && <p role="alert" className="text-sm text-bad">{notifyError} Regístrala desde «Notificaciones».</p>}
      {pending && ev?.notification_deadline_at ? (
        <div className="rounded-xl border border-bad p-4">
          <Countdown until={String(ev.notification_deadline_at)} />
        </div>
      ) : note ? (
        <p className="text-sm text-muted">Notificación: {note.status === "sent" || note.status === "acknowledged" ? "registrada" : String(note.status ?? "")}</p>
      ) : (
        <p className="text-sm text-muted">Este tipo de evento no requiere notificación a la autoridad.</p>
      )}
      <div className="flex flex-wrap gap-2">
        <Link className={BTN_PRIMARY} href={recordsHref("ACC", "sst_events", { open: id })}>Abrir el evento</Link>
        {pending && note && <Link className={BTN} href={recordsHref("ACC", "event_notifications", { open: note.id })}>Registrar notificación</Link>}
      </div>
    </div>
  );
}
