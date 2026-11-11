"use client";

// Solicitar PETAR (M-PTR, SCR-PTR-02/03): tipo de trabajo → lugar, tarea y horario → ejecutantes con su semáforo de
// requisitos (inducción, EMO apto vigente, SCTR, capacitación y EPP: RN-PTR-01, solo el estado de aptitud: RN-PTR-02)
// → verificaciones previas de la lista del tipo → resumen y «Enviar a aprobación». Crea el permiso (PeopleRules:
// código PTR-AAAA-NNNN, vigencia según el tipo y cadena de aprobaciones), sus verificaciones y lo pasa a «pendiente».

import Link from "next/link";
import { useEffect } from "react";
import { records, recordsHref, toLocalInput } from "@/lib/records";
import { FormField } from "@/components/ui/Form";
import { RefSelect } from "./RefSelect";
import { EnabledChip, RequirementChips, useRequirements } from "./WorkerChecks";
import { BTN_PRIMARY, SummaryRow, Wizard, type WizardStep } from "./Wizard";

type CheckItem = { code: string; question: string };
type Answer = { answer?: "yes" | "no" | "na"; comment?: string };
type Data = {
  permit_type_id: string;
  type_label: string;
  validity_hours: number | null;
  requires_ats: boolean;
  requires_gas: boolean;
  checklist: CheckItem[];
  site_id: string;
  site_label: string;
  location_text: string;
  work_description: string;
  valid_from: string;
  valid_until: string;
  executors: { id: string; label: string }[];
  watcher: { id: string; label: string } | null;
  answers: Record<string, Answer>;
  /** Resultado de la revisión de requisitos por trabajador (habilitado o no). */
  enabled: Record<string, boolean>;
};

const ANSWERS = [
  ["yes", "Sí", "border-ok bg-ok-soft text-ok"],
  ["no", "No", "border-bad bg-bad-soft text-bad"],
  ["na", "No aplica", "border-na bg-na-soft text-muted"],
] as const;

const fmt = (v: string) => (v ? new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short" }).format(new Date(v)) : "—");

function Executors({ d, set }: { d: Data; set: (p: Partial<Data>) => void }) {
  const ids = [...d.executors.map((e) => e.id), d.watcher?.id ?? ""];
  const { data, error } = useRequirements(ids);
  // Guarda el resultado para validar el paso sin volver a consultar.
  useEffect(() => {
    const list = Object.values(data);
    if (list.length) set({ enabled: Object.fromEntries(list.map((w) => [w.worker_id, w.enabled])) });
  }, [data, set]);
  const row = (p: { id: string; label: string }, onRemove: () => void, tag: string) => {
    const w = data[p.id];
    return (
      <li key={p.id} className="space-y-2 rounded-xl border border-line p-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-sm font-semibold">{p.label} <span className="font-normal text-muted">· {tag}</span></span>
          <span className="flex items-center gap-2">
            {w ? <EnabledChip enabled={w.enabled} /> : <span className="text-xs text-muted">Revisando…</span>}
            <button type="button" className="text-xs font-semibold text-bad hover:underline" onClick={onRemove}>Quitar</button>
          </span>
        </div>
        {w && <p className="text-xs text-muted">{[w.position, w.site].filter(Boolean).join(" · ")}</p>}
        {w && <RequirementChips checks={w.checks} />}
        {w && !w.enabled && <p className="text-xs text-bad">{w.checks.filter((c) => c.status === "bad").map((c) => `${c.label}: ${c.detail}`).join(" · ")}</p>}
      </li>
    );
  };
  return (
    <div className="space-y-4">
      <FormField label="Agregar ejecutante" hint="El primero es el líder del trabajo.">
        <RefSelect module="PTR" table="work_permits" field="executor_leader_worker_id" label="Agregar ejecutante" placeholder="Elige un trabajador" value=""
          exclude={ids} onChange={(id, label) => id && set({ executors: [...d.executors, { id, label }] })} />
      </FormField>
      <ul className="space-y-2">
        {d.executors.map((e, i) => row(e, () => set({ executors: d.executors.filter((x) => x.id !== e.id) }), i === 0 ? "Líder" : "Ejecutante"))}
      </ul>
      <FormField label="Vigía (opcional)">
        <RefSelect module="PTR" table="work_permits" field="executor_leader_worker_id" label="Vigía" placeholder="Sin vigía" value={d.watcher?.id ?? ""}
          exclude={d.executors.map((e) => e.id)} onChange={(id, label) => set({ watcher: id ? { id, label } : null })} />
      </FormField>
      {d.watcher && <ul>{row(d.watcher, () => set({ watcher: null }), "Vigía")}</ul>}
      {error && <p className="text-sm text-bad">{error}</p>}
    </div>
  );
}

export function PtrWizard({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const now = new Date();
  now.setMinutes(0, 0, 0);
  now.setHours(now.getHours() + 1);
  const initial: Data = {
    permit_type_id: "", type_label: "", validity_hours: null, requires_ats: false, requires_gas: false, checklist: [], site_id: "", site_label: "",
    location_text: "", work_description: "", valid_from: toLocalInput(now.toISOString()), valid_until: "", executors: [], watcher: null, answers: {}, enabled: {},
  };

  const steps: WizardStep<Data>[] = [
    {
      title: "Tipo de trabajo",
      validate: (d) => (!d.permit_type_id ? "Elige el tipo de trabajo." : null),
      render: (d, set) => (
        <div className="space-y-3">
          <FormField label="Tipo de permiso" hint="Los tipos, su lista de verificación y la cadena de aprobación los configura el Jefe SST en «Tipos de permiso».">
            <RefSelect module="PTR" table="work_permits" field="permit_type_id" label="Tipo de permiso" value={d.permit_type_id}
              onChange={async (id, label) => {
                if (!id) return set({ permit_type_id: "", type_label: "", checklist: [] });
                const t = (await records.get("PTR", "permit_types", id)).data;
                const raw = Array.isArray(t.checklist) ? (t.checklist as { code?: string; question?: string; phase?: string }[]) : [];
                set({
                  permit_type_id: id, type_label: label, validity_hours: t.validity_hours != null ? Number(t.validity_hours) : null,
                  requires_ats: !!t.requires_ats, requires_gas: !!t.requires_gas_test,
                  checklist: raw.filter((q) => (q.phase ?? "pre_start") === "pre_start" && q.question).map((q, i) => ({ code: q.code ?? `C${i + 1}`, question: String(q.question) })),
                });
              }} />
          </FormField>
          {d.permit_type_id && (
            <ul className="space-y-1 rounded-xl border border-line p-3 text-sm">
              <li>Vigencia máxima: <b>{d.validity_hours ?? 12} horas</b></li>
              <li>Verificaciones previas: <b>{d.checklist.length}</b></li>
              {d.requires_ats && <li>Exige ATS firmado antes de iniciar</li>}
              {d.requires_gas && <li>Exige medición de gases antes de iniciar</li>}
            </ul>
          )}
        </div>
      ),
    },
    {
      title: "Lugar, tarea y horario",
      validate: (d) =>
        !d.site_id ? "Elige la sede." : d.work_description.trim().length < 5 ? "Describe la tarea." : !d.valid_from ? "Indica cuándo empieza el trabajo." :
        d.valid_until && d.valid_until <= d.valid_from ? "La hora de fin debe ser posterior al inicio." :
        d.valid_until && d.validity_hours && new Date(d.valid_until).getTime() - new Date(d.valid_from).getTime() > d.validity_hours * 3600000 ? `La vigencia no puede pasar de ${d.validity_hours} horas.` : null,
      render: (d, set) => (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Sede">
            <RefSelect module="PTR" table="work_permits" field="site_id" label="Sede" value={d.site_id} onChange={(id, label) => set({ site_id: id, site_label: label })} />
          </FormField>
          <FormField label="Lugar exacto">
            <input className="input" value={d.location_text} maxLength={500} placeholder="Ej.: Nave 2, techo del almacén" onChange={(e) => set({ location_text: e.target.value })} />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Tarea">
              <textarea className="input min-h-[80px]" value={d.work_description} maxLength={3000} onChange={(e) => set({ work_description: e.target.value })} />
            </FormField>
          </div>
          <FormField label="Inicio">
            <input className="input" type="datetime-local" value={d.valid_from} onChange={(e) => set({ valid_from: e.target.value })} />
          </FormField>
          <FormField label="Fin (opcional)" hint={`Si lo dejas vacío, vence a las ${d.validity_hours ?? 12} horas del inicio.`}>
            <input className="input" type="datetime-local" value={d.valid_until} min={d.valid_from} onChange={(e) => set({ valid_until: e.target.value })} />
          </FormField>
        </div>
      ),
    },
    {
      title: "Ejecutantes",
      hint: "Se revisa a cada uno: inducción, EMO apto vigente, SCTR, capacitación y EPP de su puesto.",
      validate: (d) => {
        if (!d.executors.length) return "Agrega al menos un ejecutante.";
        const people = [...d.executors, ...(d.watcher ? [d.watcher] : [])];
        if (people.some((p) => d.enabled[p.id] === undefined)) return "Espera a que termine la revisión de requisitos.";
        const bad = people.find((p) => !d.enabled[p.id]);
        return bad ? `${bad.label} no está habilitado para este trabajo: reemplázalo o subsana sus requisitos.` : null;
      },
      render: (d, set) => <Executors d={d} set={set} />,
    },
    {
      title: "Verificaciones previas",
      hint: "Responde cada punto en el lugar del trabajo. Un «No» impide aprobar el permiso hasta corregirlo.",
      validate: (d) => {
        const left = d.checklist.filter((q) => !d.answers[q.code]?.answer).length;
        return left ? `Faltan ${left} verificación(es) por responder.` : null;
      },
      render: (d, set) =>
        d.checklist.length === 0 ? (
          <p className="text-sm text-muted">Este tipo de permiso no tiene verificaciones previas configuradas.</p>
        ) : (
          <ol className="space-y-3">
            {d.checklist.map((q) => {
              const a = d.answers[q.code] ?? {};
              const put = (p: Answer) => set({ answers: { ...d.answers, [q.code]: { ...a, ...p } } });
              return (
                <li key={q.code} className="rounded-xl border border-line p-3">
                  <p className="text-sm font-semibold">{q.question}</p>
                  <div role="radiogroup" aria-label={q.question} className="mt-2 grid grid-cols-3 gap-2">
                    {ANSWERS.map(([v, l, cls]) => (
                      <button key={v} type="button" role="radio" aria-checked={a.answer === v} onClick={() => put({ answer: v })}
                        className={`min-h-11 rounded-lg border-2 text-sm font-semibold ${a.answer === v ? cls : "border-line hover:border-brand/50"}`}>{l}</button>
                    ))}
                  </div>
                  {a.answer === "no" && (
                    <input className="input mt-2" placeholder="¿Qué falta corregir?" value={a.comment ?? ""} maxLength={500} onChange={(e) => put({ comment: e.target.value })} aria-label="Comentario" />
                  )}
                </li>
              );
            })}
          </ol>
        ),
    },
  ];

  return (
    <Wizard<Data>
      id="ptr-new"
      title="Solicitar PETAR"
      subtitle="Permiso escrito de trabajo de alto riesgo"
      icon="hardhat"
      initial={initial}
      steps={steps}
      confirmLabel="Enviar a aprobación"
      onClose={onClose}
      summary={(d, goTo) => {
        const no = d.checklist.filter((q) => d.answers[q.code]?.answer === "no").length;
        return (
          <div>
            <SummaryRow label="Tipo de trabajo" value={d.type_label} onEdit={() => goTo(0)} />
            <SummaryRow label="Lugar" value={[d.site_label, d.location_text].filter(Boolean).join(" · ")} onEdit={() => goTo(1)} />
            <SummaryRow label="Tarea" value={d.work_description} onEdit={() => goTo(1)} />
            <SummaryRow label="Horario" value={`${fmt(d.valid_from)} → ${d.valid_until ? fmt(d.valid_until) : `${d.validity_hours ?? 12} h después`}`} onEdit={() => goTo(1)} />
            <SummaryRow label="Ejecutantes" value={d.executors.map((e, i) => (i === 0 ? `${e.label} (líder)` : e.label)).join(", ")} onEdit={() => goTo(2)} />
            {d.watcher && <SummaryRow label="Vigía" value={d.watcher.label} onEdit={() => goTo(2)} />}
            <SummaryRow label="Verificaciones previas" value={`${d.checklist.length - no} conformes${no ? ` · ${no} en «No» (se deben corregir antes de aprobar)` : ""}`} onEdit={() => goTo(3)} />
          </div>
        );
      }}
      onConfirm={async (d) => {
        const crew = [
          ...d.executors.map((e, i) => ({ worker_id: e.id, name: e.label, role: i === 0 ? "leader" : "executor" })),
          ...(d.watcher ? [{ worker_id: d.watcher.id, name: d.watcher.label, role: "watcher" }] : []),
        ];
        const p = await records.create("PTR", "work_permits", {
          permit_type_id: d.permit_type_id,
          site_id: d.site_id,
          location_text: d.location_text.trim() || null,
          work_description: d.work_description.trim(),
          executor_leader_worker_id: d.executors[0].id,
          crew,
          valid_from: new Date(d.valid_from).toISOString(),
          ...(d.valid_until ? { valid_until: new Date(d.valid_until).toISOString() } : {}),
        });
        const id = p.data.id;
        for (const q of d.checklist) {
          const a = d.answers[q.code];
          if (a?.answer) await records.create("PTR", "permit_checks", { work_permit_id: id, check_phase: "pre_start", item_code: q.code, question: q.question, answer: a.answer, comment: a.comment?.trim() || null });
        }
        await records.update("PTR", "work_permits", id, { status: "pending_approval" });
        onDone();
        return (
          <div className="space-y-3">
            <p className="rounded-lg bg-ok-soft px-3 py-2 text-sm text-ok">PETAR {String(p.data.code ?? "")} enviado a aprobación. Lo aprueban los responsables de la cadena del tipo de permiso.</p>
            <Link className={BTN_PRIMARY} href={recordsHref("PTR", "work_permits", { open: id })}>Abrir el permiso</Link>
          </div>
        );
      }}
    />
  );
}
