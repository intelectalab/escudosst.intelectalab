"use client";

// Nuevo expediente SUNAFIL (M-SUN, SCR-SUN-03): tipo y orden de inspección → inspector y comparecencia → periodo →
// trabajadores inspeccionados (uno o varios: una sección por trabajador) → obligaciones requeridas → resumen. Al confirmar crea el expediente y lo arma con los
// datos reales de la empresa (POST /sun/dossiers/{id}/build, DossierBuilder): queda «completo» o «con brechas».

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { records, recordsHref } from "@/lib/records";
import { FormField } from "@/components/ui/Form";
import { RefSelect } from "./RefSelect";
import { Chip } from "./Summary";
import { BTN, BTN_PRIMARY, SummaryRow, Wizard, type WizardStep } from "./Wizard";

type Area = { name: string; module: string; enabled: boolean };
type Data = {
  request_type: string;
  inspection_order_number: string;
  inspector_name: string;
  appearance_at: string;
  period_from: string;
  period_to: string;
  site_id: string;
  site_label: string;
  workers: { id: string; label: string }[];
  obligations: string[];
  obligation_names: Record<string, string>;
};

const TYPES = [
  ["sunafil_inspection", "Inspección de SUNAFIL"],
  ["self_review", "Revisión interna"],
  ["client_audit", "Auditoría de un cliente"],
  ["other", "Otro requerimiento"],
] as const;

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const dmy = (v: string) => (v ? v.slice(0, 10).split("-").reverse().join("/") : "—");

function Obligations({ d, set }: { d: Data; set: (p: Partial<Data>) => void }) {
  const [areas, setAreas] = useState<Area[] | null>(null);
  useEffect(() => {
    api<{ data: { areas: Area[] } }>("/sun/diagnosis", { withCompany: true })
      .then((r) => setAreas(r.data.areas))
      .catch(() => setAreas([]));
  }, []);
  if (areas === null) return <div className="h-32 animate-pulse rounded-xl bg-bg" />;
  const toggle = (a: Area) => {
    const on = d.obligations.includes(a.module);
    set({
      obligations: on ? d.obligations.filter((m) => m !== a.module) : [...d.obligations, a.module],
      obligation_names: { ...d.obligation_names, [a.module]: a.name },
    });
  };
  const unique = [...new Map(areas.map((a) => [a.module, a])).values()];
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2 text-sm">
        <button type="button" className="font-semibold text-brand hover:underline" onClick={() => set({ obligations: unique.map((a) => a.module), obligation_names: Object.fromEntries(unique.map((a) => [a.module, a.name])) })}>Marcar todas</button>
        <button type="button" className="font-semibold text-brand hover:underline" onClick={() => set({ obligations: [] })}>Quitar todas</button>
      </div>
      <ul className="grid gap-2 sm:grid-cols-2">
        {unique.map((a) => {
          const on = d.obligations.includes(a.module);
          return (
            <li key={a.module}>
              <button type="button" role="checkbox" aria-checked={on} onClick={() => toggle(a)}
                className={`flex min-h-12 w-full items-center gap-3 rounded-xl border p-3 text-left text-sm ${on ? "border-brand bg-brand-soft/50 ring-1 ring-brand" : "border-line hover:border-brand/50"}`}>
                <span className={`grid h-5 w-5 shrink-0 place-items-center rounded border-2 text-xs ${on ? "border-brand bg-brand text-white" : "border-line"}`}>{on ? "✓" : ""}</span>
                <span className="flex-1 font-medium">{a.name}</span>
                {!a.enabled && <span className="text-xs text-muted">No está en tu plan</span>}
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export function SunWizard({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const now = new Date();
  const initial: Data = {
    request_type: "sunafil_inspection", inspection_order_number: "", inspector_name: "", appearance_at: "", period_from: `${now.getFullYear()}-01-01`,
    period_to: iso(now), site_id: "", site_label: "", workers: [], obligations: [], obligation_names: {},
  };
  const steps: WizardStep<Data>[] = [
    {
      title: "Orden de inspección",
      validate: (d) => (d.request_type === "sunafil_inspection" && !d.inspection_order_number.trim() ? "Escribe el número de la orden de inspección." : null),
      render: (d, set) => (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Tipo de requerimiento">
            <select className="input" value={d.request_type} onChange={(e) => set({ request_type: e.target.value })}>
              {TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </FormField>
          <FormField label="Número de orden de inspección" hint="Figura en el requerimiento de SUNAFIL.">
            <input className="input" value={d.inspection_order_number} maxLength={100} placeholder="Ej.: 01234-2026-SUNAFIL/IRE-LIM" onChange={(e) => set({ inspection_order_number: e.target.value })} />
          </FormField>
        </div>
      ),
    },
    {
      title: "Inspector y comparecencia",
      render: (d, set) => (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Inspector">
            <input className="input" value={d.inspector_name} maxLength={200} onChange={(e) => set({ inspector_name: e.target.value })} />
          </FormField>
          <FormField label="Fecha y hora de comparecencia">
            <input className="input" type="datetime-local" value={d.appearance_at} onChange={(e) => set({ appearance_at: e.target.value })} />
          </FormField>
        </div>
      ),
    },
    {
      title: "Periodo",
      validate: (d) => (!d.period_from || !d.period_to ? "Indica el periodo inspeccionado." : d.period_to < d.period_from ? "El fin del periodo no puede ser anterior al inicio." : null),
      render: (d, set) => (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Desde"><input className="input" type="date" value={d.period_from} onChange={(e) => set({ period_from: e.target.value })} /></FormField>
          <FormField label="Hasta"><input className="input" type="date" value={d.period_to} onChange={(e) => set({ period_to: e.target.value })} /></FormField>
          <div className="sm:col-span-2">
            <FormField label="Sede (opcional)">
              <RefSelect module="SUN" table="inspection_dossiers" field="site_id" label="Sede" placeholder="Toda la empresa" value={d.site_id} onChange={(id, label) => set({ site_id: id, site_label: id ? label : "" })} />
            </FormField>
          </div>
        </div>
      ),
    },
    {
      title: "Trabajadores",
      hint: "Si la inspección es por uno o varios trabajadores, agrégalos: cada uno tiene su sección (inducción, capacitaciones, aptitud, EPP, RISST, IPERC y fotocheck).",
      validate: (d) => (d.workers.length > 50 ? "Elige hasta 50 trabajadores por expediente." : null),
      render: (d, set) => (
        <div className="space-y-3">
          <FormField label="Agregar trabajador inspeccionado (opcional)">
            <RefSelect module="SUN" table="dossier_items" field="worker_id" label="Trabajador" placeholder={d.workers.length ? "Agregar otro trabajador" : "Ninguno: expediente de la empresa"} value=""
              exclude={d.workers.map((w) => w.id)}
              onChange={(id, label) => id && !d.workers.some((w) => w.id === id) && set({ workers: [...d.workers, { id, label }] })} />
          </FormField>
          {d.workers.length > 0 && (
            <ul className="space-y-2" aria-label="Trabajadores inspeccionados">
              {d.workers.map((w, i) => (
                <li key={w.id} className="flex min-h-12 items-center gap-3 rounded-xl border border-line px-3 py-2 text-sm">
                  <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-strong">{i + 1}</span>
                  <span className="flex-1 font-medium">{w.label}</span>
                  <button type="button" className="font-semibold text-bad hover:underline" aria-label={`Quitar a ${w.label}`} onClick={() => set({ workers: d.workers.filter((x) => x.id !== w.id) })}>Quitar</button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ),
    },
    {
      title: "Obligaciones",
      hint: "Marca lo que pide el requerimiento. El expediente revisa siempre los puntos clave de la empresa.",
      validate: (d) => (d.obligations.length === 0 ? "Marca al menos una obligación requerida." : null),
      render: (d, set) => <Obligations d={d} set={set} />,
    },
  ];

  return (
    <Wizard<Data>
      id="sun-new"
      title="Nuevo expediente"
      subtitle="Prepara lo que pide la inspección con los datos del sistema"
      icon="shield"
      initial={initial}
      steps={steps}
      confirmLabel="Crear y armar expediente"
      onClose={onClose}
      summary={(d, goTo) => (
        <div>
          <SummaryRow label="Tipo" value={TYPES.find(([v]) => v === d.request_type)?.[1]} onEdit={() => goTo(0)} />
          <SummaryRow label="Orden de inspección" value={d.inspection_order_number} onEdit={() => goTo(0)} />
          <SummaryRow label="Inspector" value={d.inspector_name} onEdit={() => goTo(1)} />
          <SummaryRow label="Comparecencia" value={d.appearance_at ? new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short" }).format(new Date(d.appearance_at)) : ""} onEdit={() => goTo(1)} />
          <SummaryRow label="Periodo" value={`${dmy(d.period_from)} al ${dmy(d.period_to)}${d.site_label ? ` · ${d.site_label}` : ""}`} onEdit={() => goTo(2)} />
          <SummaryRow label="Trabajadores" value={d.workers.length ? `${d.workers.length}: ${d.workers.map((w) => w.label).join(", ")}` : "Expediente de la empresa"} onEdit={() => goTo(3)} />
          <SummaryRow label="Obligaciones" value={d.obligations.map((m) => d.obligation_names[m] ?? m).join(", ")} onEdit={() => goTo(4)} />
        </div>
      )}
      onConfirm={async (d) => {
        const title = d.request_type === "sunafil_inspection" ? `Inspección SUNAFIL · Orden ${d.inspection_order_number.trim()}` : `${TYPES.find(([v]) => v === d.request_type)?.[1]} ${dmy(d.period_to)}`;
        const r = await records.create("SUN", "inspection_dossiers", {
          title: title.slice(0, 200),
          request_type: d.request_type,
          inspection_order_number: d.inspection_order_number.trim() || null,
          inspector_name: d.inspector_name.trim() || null,
          appearance_at: d.appearance_at ? new Date(d.appearance_at).toISOString() : null,
          period_from: d.period_from,
          period_to: d.period_to,
          site_id: d.site_id || null,
          scope_modules: d.obligations,
          scope: { obligations: d.obligations, worker_ids: d.workers.map((w) => w.id), worker_id: d.workers[0]?.id ?? null },
        });
        const id = r.data.id;
        const b = await api<{ data: { total: number; gaps: number } }>(`/sun/dossiers/${id}/build`, { method: "POST", withCompany: true, body: { worker_ids: d.workers.map((w) => w.id) } });
        onDone();
        return (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3 rounded-xl border border-line p-4">
              <div className="flex-1">
                <p className="text-sm text-muted">Expediente armado</p>
                <p className="font-display text-2xl font-bold">{b.data.total - b.data.gaps} de {b.data.total} puntos incluidos</p>
              </div>
              <Chip tone={b.data.gaps ? "warn" : "ok"}>{b.data.gaps ? `${b.data.gaps} brecha(s)` : "Completo"}</Chip>
            </div>
            {b.data.gaps > 0 && <p className="text-sm text-muted">Abre el expediente para ver lo que falta, subsanarlo y volver a armarlo antes de la comparecencia.</p>}
            <div className="flex flex-wrap gap-2">
              <Link className={BTN_PRIMARY} href={recordsHref("SUN", "inspection_dossiers", { open: id })}>Abrir el expediente</Link>
              <Link className={BTN} href={recordsHref("SUN", "dossier_items", { filters: { dossier_id: id } })}>Ver ítems</Link>
            </div>
          </div>
        );
      }}
    />
  );
}
