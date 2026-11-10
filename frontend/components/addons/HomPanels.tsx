"use client";

// Homologación de proveedores (HOM): criterios de la plantilla con su peso y calificación de cada evaluación (0 a 5).

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";
import { records, type RecordRow } from "@/lib/records";

type Criterion = { name: string; weight: number };
const parse = <T,>(v: unknown, fallback: T): T => (typeof v === "string" ? (JSON.parse(v || "null") ?? fallback) : ((v as T) ?? fallback));
const errText = (e: unknown) => Object.values((e as ApiError).errors ?? {})[0]?.[0] ?? (e as ApiError).detail ?? (e as ApiError).title;

export function CriteriaEditor({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [list, setList] = useState<Criterion[]>(() => {
    const c = parse<Criterion[]>(row.criteria, []);
    return c.length ? c : [{ name: "Cumplimiento de SST (SCTR, EPP, capacitación)", weight: 40 }, { name: "Calidad del servicio", weight: 30 }, { name: "Plazos de entrega", weight: 30 }];
  });
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const sum = list.reduce((a, c) => a + (Number(c.weight) || 0), 0);
  const set = (i: number, patch: Partial<Criterion>) => setList(list.map((c, k) => (k === i ? { ...c, ...patch } : c)));
  const save = () =>
    api(`/hom/templates/${row.id}/criteria`, { method: "PUT", withCompany: true, body: { criteria: list.map((c) => ({ name: c.name, weight: Number(c.weight) })) } })
      .then(() => { setMsg({ text: "Criterios guardados.", ok: true }); onChanged(); })
      .catch((e) => setMsg({ text: errText(e), ok: false }));
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <p className="font-semibold">Criterios de evaluación <span className={`font-normal ${Math.abs(sum - 100) < 0.01 ? "text-ok" : "text-bad"}`}>· pesos: {sum} de 100</span></p>
      {list.map((c, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2">
          <input className="input min-w-[14rem] flex-1" disabled={!canEdit} value={c.name} aria-label="Criterio" onChange={(e) => set(i, { name: e.target.value })} />
          <input className="input !w-20" disabled={!canEdit} inputMode="numeric" value={c.weight} aria-label="Peso" onChange={(e) => set(i, { weight: Number(e.target.value.replace(/[^\d.]/g, "")) })} />
          {canEdit && <button type="button" className="text-xs font-semibold text-bad" onClick={() => setList(list.filter((_, k) => k !== i))}>Quitar</button>}
        </div>
      ))}
      {canEdit && (
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="ghost" icon="plus" onClick={() => setList([...list, { name: "", weight: 0 }])}>Agregar criterio</Button>
          <Button size="sm" variant="primary" onClick={save}>Guardar criterios</Button>
        </div>
      )}
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}

export function ScoreForm({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [criteria, setCriteria] = useState<Criterion[] | null>(null);
  const [scores, setScores] = useState<Record<string, number>>(() => parse<Record<string, number>>(row.scores, {}));
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  useEffect(() => {
    if (!row.template_id) return;
    records.get("HOM", "vendor_evaluation_templates", String(row.template_id)).then((r) => setCriteria(parse<Criterion[]>(r.data.criteria, []))).catch(() => setCriteria([]));
  }, [row.template_id]);
  if (!criteria) return null;
  if (criteria.length === 0) return <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">La plantilla aún no tiene criterios: agrégalos en la plantilla.</p>;
  const save = () =>
    api<{ data: { total_score: number; rating: string; passing_score: number } }>(`/hom/evaluations/${row.id}/scores`, { method: "PUT", withCompany: true, body: { scores } })
      .then((r) => { setMsg({ text: `Puntaje ${r.data.total_score} de 100 (mínimo ${r.data.passing_score}): ${r.data.rating === "approved" ? "aprobado" : "no aprobado"}.`, ok: r.data.rating === "approved" }); onChanged(); })
      .catch((e) => setMsg({ text: errText(e), ok: false }));
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <p className="font-semibold">Calificación (0 = no cumple · 5 = cumple totalmente)</p>
      {criteria.map((c) => (
        <div key={c.name} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-bg px-3 py-2">
          <span>{c.name} <span className="text-xs text-muted">· peso {c.weight}</span></span>
          <span className="flex gap-1">
            {[0, 1, 2, 3, 4, 5].map((n) => (
              <button key={n} type="button" disabled={!canEdit} onClick={() => setScores({ ...scores, [c.name]: n })}
                className={`h-8 w-8 rounded-md text-sm font-semibold ${scores[c.name] === n ? "bg-brand text-white" : "border border-line"}`}>{n}</button>
            ))}
          </span>
        </div>
      ))}
      {canEdit && <Button size="sm" variant="primary" onClick={save}>Calcular resultado</Button>}
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}
