"use client";

// Método SCAT de la investigación (M-INV SCR-INV-04): asistente por pasos con el catálogo SCAT — tipo de contacto o
// evento → causas inmediatas (actos y condiciones) → causas básicas (factores personales y del trabajo) → necesidades
// de acción de control (NAC). Lo elegido se guarda como causas de la investigación (con su código, nivel y categoría,
// enlazadas en cadena) y cada NAC queda como propuesta que se convierte con «Crear acción correctiva».
// También el botón «Descargar PDF» del informe (SCR-INV-06).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import type { RecordRow } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";
import { FindingAction } from "@/components/records/Process";
import { Card } from "./Summary";
import { Wizard, type WizardStep } from "./Wizard";

type Item = { code: string; label: string };
type Catalog = { contact: Item[]; acts: Item[]; conditions: Item[]; personal: Item[]; job: Item[]; nac: Item[]; pec: Item[]; note: string };
type Cause = {
  id: string; cause_level: string; cause_category: string | null; scat_code: string; description: string; note: string; parent_code: string | null;
  corrective_action_id: string | null; action_code: string | null; action_status: string | null;
};
type State = { investigation: { id: string; code: string | null; method: string; status: string }; contact: string[]; causes: Cause[]; editable: boolean; kept?: string[] };
type Pick = { code: string; note: string; parent?: string; pec?: "P" | "E" | "C" };
type Data = { contact: string[]; immediate: Pick[]; basic: Pick[]; nac: Pick[] };

let catalogCache: Promise<Catalog> | null = null;
const loadCatalog = () =>
  (catalogCache ??= api<{ data: Catalog }>("/inv/scat/catalog", { withCompany: true }).then((r) => r.data).catch((e) => { catalogCache = null; throw e; }));

const ACTION_ST: Record<string, string> = { open: "Abierta", in_progress: "En curso", pending_verification: "Por verificar", verified: "Verificada", overdue: "Vencida", cancelled: "Anulada" };

/** Lo guardado en el servidor, en la forma del asistente. */
function toData(s: State): Data {
  const by = (lv: string) => s.causes.filter((c) => c.cause_level === lv);
  return {
    contact: s.contact,
    immediate: by("immediate").map((c) => ({ code: c.scat_code, note: c.note })),
    basic: by("basic").map((c) => ({ code: c.scat_code, note: c.note, parent: c.parent_code ?? undefined })),
    nac: by("lack_of_control").map((c) => {
      const [code, pec] = c.scat_code.split("-");
      return { code, pec: (pec as Pick["pec"]) ?? "P", note: c.note, parent: c.parent_code ?? undefined };
    }),
  };
}

const labelOf = (cat: Catalog, code: string) => {
  const [base, pec] = code.split("-");
  const all = [...cat.contact, ...cat.acts, ...cat.conditions, ...cat.personal, ...cat.job, ...cat.nac];
  const l = all.find((x) => x.code === base)?.label ?? code;
  return pec ? `${l} · ${cat.pec.find((p) => p.code === pec)?.label ?? pec}` : l;
};

/** Lista del catálogo con casilla; lo marcado muestra su nota y, si aplica, a qué causa explica y qué falló (P/E/C). */
function PickList({ items, value, onChange, parents, withPec, pecs, group }: {
  items: Item[]; value: Pick[]; onChange: (v: Pick[]) => void; parents?: { code: string; label: string }[]; withPec?: boolean; pecs?: Item[]; group: string;
}) {
  const [q, setQ] = useState("");
  const shown = q.trim() ? items.filter((i) => (i.code + " " + i.label).toLowerCase().includes(q.trim().toLowerCase())) : items;
  const toggle = (code: string) =>
    onChange(value.some((v) => v.code === code) ? value.filter((v) => v.code !== code) : [...value, { code, note: "", ...(withPec ? { pec: "P" as const } : {}), ...(parents?.length === 1 ? { parent: parents[0].code } : {}) }]);
  const patch = (code: string, p: Partial<Pick>) => onChange(value.map((v) => (v.code === code ? { ...v, ...p } : v)));
  return (
    <fieldset className="space-y-2">
      <legend className="mb-1 text-sm font-semibold">{group}</legend>
      {items.length > 10 && <input className="input" type="search" placeholder="Buscar en la lista…" value={q} onChange={(e) => setQ(e.target.value)} aria-label={`Buscar en ${group.toLowerCase()}`} />}
      <ul className="divide-y divide-line rounded-xl border border-line">
        {shown.map((i) => {
          const sel = value.find((v) => v.code === i.code);
          return (
            <li key={i.code} className={`px-3 py-2 ${sel ? "bg-brand-soft/40" : ""}`}>
              <label className="flex cursor-pointer items-start gap-2 text-sm">
                <input type="checkbox" className="mt-1" checked={!!sel} onChange={() => toggle(i.code)} />
                <span><span className="font-mono text-[11px] text-muted">{i.code}</span> {i.label}</span>
              </label>
              {sel && (
                <div className="mt-2 grid gap-2 pl-6 sm:grid-cols-2">
                  {withPec && pecs && (
                    <div className="flex flex-wrap gap-1.5 sm:col-span-2" role="radiogroup" aria-label={`Qué falló en ${i.label}`}>
                      {pecs.map((p) => (
                        <button key={p.code} type="button" role="radio" aria-checked={sel.pec === p.code} onClick={() => patch(i.code, { pec: p.code as Pick["pec"] })}
                          className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${sel.pec === p.code ? "border-brand bg-brand text-white" : "border-line text-muted hover:border-brand"}`}>
                          {p.code} · {p.label}
                        </button>
                      ))}
                    </div>
                  )}
                  {parents && (
                    <select className="input" aria-label={`Causa que explica ${i.label}`} value={sel.parent ?? ""} onChange={(e) => patch(i.code, { parent: e.target.value || undefined })}>
                      <option value="">Explica a… (opcional)</option>
                      {parents.map((p) => <option key={p.code} value={p.code}>{p.code} · {p.label}</option>)}
                    </select>
                  )}
                  <input className={`input ${parents ? "" : "sm:col-span-2"}`} placeholder="Detalle de lo que pasó (opcional)" maxLength={500} value={sel.note}
                    onChange={(e) => patch(i.code, { note: e.target.value })} aria-label={`Detalle de ${i.label}`} />
                </div>
              )}
            </li>
          );
        })}
        {shown.length === 0 && <li className="px-3 py-2 text-sm text-muted">Nada coincide con la búsqueda.</li>}
      </ul>
    </fieldset>
  );
}

function ScatWizard({ inv, state, catalog, onClose, onSaved }: { inv: RecordRow; state: State; catalog: Catalog; onClose: () => void; onSaved: (s: State) => void }) {
  const lbl = (c: string) => labelOf(catalog, c);
  const steps: WizardStep<Data>[] = [
    {
      title: "Tipo de contacto",
      hint: "¿Cómo ocurrió el contacto con la energía o sustancia? Puedes marcar más de uno.",
      render: (d, set) => (
        <div className="grid gap-2 sm:grid-cols-2">
          {catalog.contact.map((c) => {
            const on = d.contact.includes(c.code);
            return (
              <label key={c.code} className={`flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm ${on ? "border-brand bg-brand-soft/50" : "border-line hover:border-brand/50"}`}>
                <input type="checkbox" className="mt-1" checked={on} onChange={() => set({ contact: on ? d.contact.filter((x) => x !== c.code) : [...d.contact, c.code] })} />
                <span><span className="font-mono text-[11px] text-muted">{c.code}</span> {c.label}</span>
              </label>
            );
          })}
        </div>
      ),
      validate: (d) => (d.contact.length ? null : "Elige al menos un tipo de contacto o evento."),
    },
    {
      title: "Causas inmediatas",
      hint: "Actos y condiciones subestándar que estaban presentes justo antes del evento.",
      render: (d, set) => {
        const acts = d.immediate.filter((p) => p.code.startsWith("A"));
        const conds = d.immediate.filter((p) => p.code.startsWith("S"));
        return (
          <div className="space-y-4">
            <PickList group="Actos subestándar" items={catalog.acts} value={acts} onChange={(v) => set({ immediate: [...v, ...conds] })} />
            <PickList group="Condiciones subestándar" items={catalog.conditions} value={conds} onChange={(v) => set({ immediate: [...acts, ...v] })} />
          </div>
        );
      },
      validate: (d) => (d.immediate.length ? null : "Elige al menos una causa inmediata (acto o condición)."),
    },
    {
      title: "Causas básicas",
      hint: "Por qué ocurrieron los actos y condiciones: factores personales y del trabajo. Indica a qué causa inmediata explica cada una.",
      render: (d, set) => {
        const parents = d.immediate.map((p) => ({ code: p.code, label: lbl(p.code) }));
        const pers = d.basic.filter((p) => p.code.startsWith("FP"));
        const job = d.basic.filter((p) => p.code.startsWith("FT"));
        return (
          <div className="space-y-4">
            <PickList group="Factores personales" items={catalog.personal} value={pers} parents={parents} onChange={(v) => set({ basic: [...v, ...job] })} />
            <PickList group="Factores del trabajo" items={catalog.job} value={job} parents={parents} onChange={(v) => set({ basic: [...pers, ...v] })} />
            <p className="text-xs text-muted">No registres datos de salud del trabajador: describe el factor, no el diagnóstico.</p>
          </div>
        );
      },
      validate: (d) => (d.basic.length ? null : "Elige al menos una causa básica (factor personal o del trabajo)."),
    },
    {
      title: "Necesidades de control",
      hint: "Qué elemento del sistema de gestión falló y cómo: P programa, E estándares o C cumplimiento. Cada NAC es una propuesta de acción correctiva.",
      render: (d, set) => (
        <div className="space-y-3">
          {!d.basic.some((b) => b.code.startsWith("FT")) && (
            <p className="rounded-lg bg-amber-soft px-3 py-2 text-sm text-amber-ink">Solo marcaste factores personales: agrega al menos una necesidad de control para que la conclusión no se limite al trabajador.</p>
          )}
          <PickList group="Elementos del sistema de gestión" items={catalog.nac} value={d.nac} withPec pecs={catalog.pec} parents={d.basic.map((p) => ({ code: p.code, label: lbl(p.code) }))}
            onChange={(v) => set({ nac: v })} />
        </div>
      ),
      validate: (d) => (!d.basic.some((b) => b.code.startsWith("FT")) && !d.nac.length ? "Agrega al menos una necesidad de acción de control: hay solo factores personales." : null),
    },
  ];

  const summary = (d: Data, goTo: (n: number) => void) => {
    const row = (title: string, step: number, list: { code: string; note?: string; parent?: string }[]) => (
      <div className="rounded-xl border border-line p-3">
        <div className="mb-1 flex items-center justify-between gap-2">
          <b className="text-sm">{title}</b>
          <button type="button" className="text-xs font-semibold text-brand hover:underline" onClick={() => goTo(step)}>Cambiar</button>
        </div>
        <ul className="space-y-1 text-sm">
          {list.length === 0 && <li className="text-muted">—</li>}
          {list.map((p) => (
            <li key={p.code}>
              <span className="font-mono text-[11px] text-muted">{p.code}</span> {lbl(p.code)}{p.note ? `: ${p.note}` : ""}
              {p.parent && <span className="block text-xs text-muted">Explica a {p.parent}</span>}
            </li>
          ))}
        </ul>
      </div>
    );
    return (
      <div className="space-y-2">
        {row("Tipo de contacto", 0, d.contact.map((c) => ({ code: c })))}
        {row("Causas inmediatas", 1, d.immediate)}
        {row("Causas básicas", 2, d.basic)}
        {row("Necesidades de acción de control", 3, d.nac.map((n) => ({ ...n, code: `${n.code}-${n.pec ?? "P"}` })))}
        <p className="text-xs text-muted">{catalog.note}</p>
      </div>
    );
  };

  return (
    <Wizard<Data>
      id={`inv-scat-${inv.id}`}
      title="Análisis SCAT"
      subtitle={`${String(inv.code ?? "Investigación")} · las causas quedan en el árbol de esta investigación`}
      icon="layers"
      initial={toData(state)}
      steps={steps}
      summary={summary}
      confirmLabel="Guardar análisis"
      onClose={onClose}
      onConfirm={async (d) => {
        const r = await api<{ data: State }>(`/inv/investigations/${inv.id}/scat`, {
          method: "PUT", withCompany: true,
          body: { contact: d.contact, immediate: d.immediate.map(({ code, note }) => ({ code, note })), basic: d.basic, nac: d.nac.map((n) => ({ ...n, pec: n.pec ?? "P" })) },
        });
        onSaved(r.data);
        const nacs = r.data.causes.filter((c) => c.cause_level === "lack_of_control" && !c.corrective_action_id).length;
        return (
          <div className="space-y-2 text-sm">
            <p className="font-semibold text-ok">Análisis guardado: {r.data.causes.length} causas en el árbol de la investigación.</p>
            {nacs > 0 && <p>{nacs} necesidad{nacs === 1 ? "" : "es"} de control quedan como propuesta: conviértelas en acción correctiva desde la tarjeta «Análisis SCAT».</p>}
            {(r.data.kept ?? []).length > 0 && <p className="text-muted">Se conservaron {r.data.kept!.join(", ")} porque ya tienen acción correctiva.</p>}
            <Button variant="primary" onClick={onClose}>Cerrar</Button>
          </div>
        );
      }}
    />
  );
}

/** Tarjeta del método SCAT dentro de la ficha (solo si el método es SCAT) y botón «Descargar PDF» del informe. */
export function InvScatPanel({ row, readOnly, onChanged }: { row: RecordRow; readOnly: boolean; onChanged: () => void }) {
  const isScat = row.method === "scat";
  const [state, setState] = useState<State | null>(null);
  const [catalog, setCatalog] = useState<Catalog | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const version = String(row.updated_at ?? "");

  const load = useCallback(() => {
    if (!isScat) return;
    Promise.all([api<{ data: State }>(`/inv/investigations/${row.id}/scat`, { withCompany: true }), loadCatalog()])
      .then(([s, c]) => { setState(s.data); setCatalog(c); })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [row.id, isScat]);
  useEffect(() => { load(); }, [load, version]);

  const pdf = (
    <a href={`/imprimir/investigacion/${row.id}`} target="_blank" rel="noreferrer"
      className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-sm font-semibold hover:border-brand hover:text-brand-strong">
      Descargar PDF
    </a>
  );
  if (!isScat)
    return (
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-4 py-2 text-sm">
        <span className="text-muted">Informe de la investigación con evento, equipo, causas, acciones y firmas.</span>
        {pdf}
      </div>
    );
  if (error) return <p className="rounded-xl border border-line px-4 py-3 text-sm text-muted">{error}</p>;
  if (!state || !catalog) return <div className="h-24 animate-pulse rounded-xl bg-bg" />;

  const lbl = (c: string) => labelOf(catalog, c);
  const by = (lv: string) => state.causes.filter((c) => c.cause_level === lv);
  const empty = state.causes.length === 0 && state.contact.length === 0;
  const canEdit = state.editable && !readOnly;
  const changed = (s?: State) => { if (s) setState(s); onChanged(); };

  return (
    <Card
      title="Análisis SCAT"
      action={
        <div className="flex flex-wrap gap-2">
          {canEdit && <Button size="sm" variant={empty ? "primary" : "secondary"} icon="layers" onClick={() => setOpen(true)}>{empty ? "Asistente SCAT" : "Editar análisis SCAT"}</Button>}
          {pdf}
        </div>
      }
    >
      {empty ? (
        <p className="text-sm text-muted">
          Aún no se aplica el método. El asistente te lleva paso a paso: tipo de contacto, causas inmediatas, causas básicas y necesidades de acción de control.
        </p>
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          <Level title="Tipo de contacto" items={state.contact.map((c) => ({ key: c, text: lbl(c) }))} />
          <Level title="Causas inmediatas" items={by("immediate").map((c) => ({ key: c.scat_code, text: c.description, tag: c.cause_category === "unsafe_act" ? "Acto" : "Condición" }))} />
          <Level title="Causas básicas" items={by("basic").map((c) => ({ key: c.scat_code, text: c.description, tag: c.cause_category === "personal_factor" ? "Factor personal" : "Factor del trabajo", sub: c.parent_code ? `Explica a ${c.parent_code}` : undefined }))} />
          <div className="rounded-xl border border-line p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Necesidades de acción de control</p>
            {by("lack_of_control").length === 0 && <p className="text-sm text-muted">—</p>}
            <ul className="space-y-2">
              {by("lack_of_control").map((c) => (
                <li key={c.id} className="space-y-1.5 text-sm">
                  <p><span className="font-mono text-[11px] text-muted">{c.scat_code}</span> {c.description}</p>
                  {c.corrective_action_id ? (
                    <Chip tone={c.action_status === "verified" ? "ok" : "brand"}>Acción {c.action_code} · {ACTION_ST[c.action_status ?? ""] ?? c.action_status}</Chip>
                  ) : canEdit ? (
                    <FindingAction table="investigation_causes" row={{ id: c.id, own: true, corrective_action_id: null }} onChanged={() => { load(); onChanged(); }} />
                  ) : (
                    <Chip tone="amber">Propuesta sin acción</Chip>
                  )}
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
      {open && <ScatWizard inv={row} state={state} catalog={catalog} onClose={() => setOpen(false)} onSaved={changed} />}
    </Card>
  );
}

function Level({ title, items }: { title: string; items: { key: string; text: string; tag?: string; sub?: string }[] }) {
  return (
    <div className="rounded-xl border border-line p-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{title}</p>
      {items.length === 0 ? <p className="text-sm text-muted">—</p> : (
        <ul className="space-y-1.5 text-sm">
          {items.map((i) => (
            <li key={i.key}>
              <span className="font-mono text-[11px] text-muted">{i.key}</span> {i.text}
              {(i.tag || i.sub) && <span className="block text-xs text-muted">{[i.tag, i.sub].filter(Boolean).join(" · ")}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
