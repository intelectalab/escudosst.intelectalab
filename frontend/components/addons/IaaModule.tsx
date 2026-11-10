"use client";

// Asistente IA (complemento IAA): controles para un peligro, guía de causas de un accidente y guion de charla de 5 minutos.
// Son propuestas: la persona revisa y decide qué usar. Sin proveedor de IA trabaja con la biblioteca de EscudoSST.

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { api, ApiError } from "@/lib/api";
import { TestModeBanner } from "./TestModeBanner";

type Item = { title: string; detail?: string; group?: string };
const FEATURES: [string, string, string, string][] = [
  ["controls", "Controles para un peligro", "Describe el peligro o la tarea", "Ej.: trabajo en altura sobre andamio para pintar fachada"],
  ["causes", "Guía para investigar un accidente", "Cuenta brevemente qué pasó", "Ej.: trabajador se cortó la mano al cambiar el disco de la amoladora"],
  ["talk", "Guion de charla de 5 minutos", "Tema de la charla", "Ej.: orden y limpieza en el almacén"],
];

export function IaaModule() {
  const [feature, setFeature] = useState("controls");
  const [text, setText] = useState("");
  const [res, setRes] = useState<{ mode: string; items: Item[]; note: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const f = FEATURES.find((x) => x[0] === feature)!;

  async function ask() {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { mode: string; items: Item[]; note: string } }>("/iaa/suggest", { method: "POST", withCompany: true, body: { feature, text } });
      setRes(r.data);
    } catch (e) {
      const ae = e as ApiError;
      setError(Object.values(ae.errors ?? {})[0]?.[0] ?? ae.detail ?? ae.title);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <TestModeBanner code="ai" />
      <section className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <div className="flex flex-wrap gap-2" role="tablist">
          {FEATURES.map(([k, label]) => (
            <button key={k} role="tab" aria-selected={feature === k} onClick={() => { setFeature(k); setRes(null); }}
              className={`rounded-full px-4 py-2 text-sm font-semibold ${feature === k ? "bg-brand text-white" : "border border-line hover:border-brand"}`}>
              {label}
            </button>
          ))}
        </div>
        <FormField label={f[2]} error={error ?? undefined}>
          <textarea className="input min-h-[90px]" maxLength={1500} value={text} placeholder={f[3]} onChange={(e) => setText(e.target.value)} />
        </FormField>
        <p className="text-xs text-muted">No escribas datos de salud ni nombres completos: el asistente no los necesita.</p>
        <Button variant="primary" icon="sparkle" loading={busy} onClick={ask} disabled={text.trim().length < 5}>Sugerir</Button>
      </section>
      {res && (
        <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
          <p className={`text-sm ${res.mode === "live" ? "text-ok" : "text-amber-ink"}`}>{res.mode === "live" ? "Sugerencias con IA. " : "Sugerencias de prueba. "}{res.note}</p>
          <ul className="space-y-2">
            {res.items.map((it, i) => (
              <li key={i} className="rounded-xl bg-bg px-4 py-3 text-sm">
                {it.group && <span className="mb-1 block text-xs font-semibold uppercase tracking-wide text-brand">{it.group}</span>}
                <b>{it.title}</b>
                {it.detail && <span className="block text-muted">{it.detail}</span>}
              </li>
            ))}
          </ul>
          <p className="text-xs text-muted">Revisa y adapta antes de usarlas en tu IPERC, investigación o charla.</p>
        </section>
      )}
    </div>
  );
}
