"use client";

// Diagnóstico de línea base (M-LBA, SCR-LBA-03; prototipo m-modulos.js «LBA»): resumen del último diagnóstico con
// las cuatro tarjetas (línea base %, ítems evaluados, brechas abiertas y cerradas) y la tabla por lineamiento
// (ítems, cumplimiento con barra, brechas y estado). Cada fila y tarjeta abre los registros de donde sale.

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { records, type RecordRow } from "@/lib/records";
import { useApp } from "@/components/AppContext";
import { Button } from "@/components/ui/Button";
import { LbaWizard } from "./LbaWizard";

const D = (d: unknown) => (d ? new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeZone: "UTC" }).format(new Date(String(d))) : "—");

async function all(table: string, filters: Record<string, string>): Promise<RecordRow[]> {
  const out: RecordRow[] = [];
  for (let page = 1; page <= 20; page++) {
    const r = await records.list("LBA", table, { page, filters });
    out.push(...r.data);
    if (page >= r.meta.pages) break;
  }
  return out;
}

export function LbaSummary() {
  const { modules } = useApp();
  const canEdit = modules.find((x) => x.code === "LBA")?.mode === "enabled";
  const [wizard, setWizard] = useState(false);
  const [reload, setReload] = useState(0);
  const [list, setList] = useState<RecordRow[] | null>(null);
  const [sel, setSel] = useState<string>("");
  const [items, setItems] = useState<RecordRow[] | null>(null);
  const [gaps, setGaps] = useState<RecordRow[]>([]);
  const [status, setStatus] = useState("");

  useEffect(() => {
    all("baseline_assessments", {}).then((rows) => {
      const sorted = [...rows].sort((a, b) => String(b.assessment_on ?? "").localeCompare(String(a.assessment_on ?? "")));
      setList(sorted);
      setSel(sorted[0]?.id ?? "");
    }).catch(() => setList([]));
  }, [reload]);
  useEffect(() => {
    if (!sel) return;
    setItems(null);
    Promise.all([all("baseline_items", { assessment_id: sel }), all("baseline_gaps", { assessment_id: sel })])
      .then(([i, g]) => { setItems(i); setGaps(g); })
      .catch(() => setItems([]));
  }, [sel, reload]);

  const current = list?.find((a) => a.id === sel);
  const rows = useMemo(() => {
    const by = new Map<string, { name: string; items: number; points: number; gaps: number }>();
    for (const it of items ?? []) {
      if (it.result === "not_applicable") continue;
      const key = String(it.lineament_name ?? it.lineament_code ?? "Sin lineamiento");
      const r = by.get(key) ?? { name: key, items: 0, points: 0, gaps: 0 };
      r.items += 1;
      r.points += it.result === "compliant" ? 1 : it.result === "partial" ? 0.5 : 0;
      by.set(key, r);
    }
    const itemLine = new Map((items ?? []).map((i) => [i.id, String(i.lineament_name ?? i.lineament_code ?? "Sin lineamiento")]));
    for (const g of gaps) {
      const line = itemLine.get(String(g.baseline_item_id));
      if (line && by.has(line) && (g.status === "open" || g.status === "in_progress")) by.get(line)!.gaps += 1;
    }
    return [...by.values()].map((r) => {
      const pct = r.items ? Math.round((r.points / r.items) * 100) : 0;
      const st = pct >= 90 ? ["Cumple", "ok"] : pct >= 60 ? ["En proceso", "amber"] : ["No cumple", "bad"];
      return { ...r, pct, st: st[0], tone: st[1] };
    });
  }, [items, gaps]);
  const shown = status ? rows.filter((r) => r.st === status) : rows;

  // «Nuevo diagnóstico» abre el asistente (SCR-LBA-02).
  const toolbar = canEdit ? (
    <div className="flex justify-end">
      <Button variant="primary" icon="plus" onClick={() => setWizard(true)}>Nuevo diagnóstico</Button>
      {wizard && <LbaWizard onClose={() => setWizard(false)} onDone={() => setReload((n) => n + 1)} />}
    </div>
  ) : null;
  if (list === null) return <div className="h-40 animate-pulse rounded-xl bg-surface" />;
  if (list.length === 0) return toolbar;
  const open = gaps.filter((g) => g.status === "open" || g.status === "in_progress").length;
  const closed = gaps.filter((g) => g.status === "closed" || g.status === "accepted_risk").length;
  const href = (filters: Record<string, string>) => `/m/lba?t=baseline_assessments&open=${sel}${Object.entries(filters).map(([k, v]) => `&f[${k}]=${v}`).join("")}`;
  // Puntaje: el guardado al aprobar o, si no lo tiene, el que sale de los ítems respondidos (cumple 1, parcial 0,5).
  const tot = rows.reduce((a, r) => a + r.items, 0);
  const pct = Number(current?.score_pct) > 0 ? Number(current?.score_pct) : tot ? Math.round((rows.reduce((a, r) => a + (r.pct * r.items) / 100, 0) / tot) * 100) : 0;
  const tone = (t: string) => (t === "ok" ? "bg-ok" : t === "amber" ? "bg-[#F4B90F]" : "bg-bad");

  return (
    <section className="space-y-4" aria-label="Resultado del diagnóstico">
      {toolbar}
      {list.length > 1 && (
        <label className="flex items-center gap-2 text-sm">
          <span className="text-muted">Diagnóstico</span>
          <select className="filter-control !w-auto" value={sel} onChange={(e) => setSel(e.target.value)}>
            {list.map((a) => <option key={a.id} value={a.id}>{String(a.title)} · {D(a.assessment_on)}</option>)}
          </select>
        </label>
      )}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          [`Línea base ${String(current?.assessment_on ?? "").slice(0, 4)}`, `${pct.toLocaleString("es-PE", { maximumFractionDigits: 0 })}%`, "text-brand", `Evaluada el ${D(current?.assessment_on)}`, href({})],
          ["Ítems evaluados", String(items?.filter((i) => i.result !== "pending" && i.result !== "not_applicable").length ?? "—"), "text-ok", "", href({})],
          ["Brechas abiertas", String(open), "text-[#b45309]", "", href({})],
          ["Brechas cerradas", String(closed), "text-[#0f766e]", "", href({})],
        ].map(([label, value, color, sub, h]) => (
          <Link key={label} href={h} className="rounded-xl border border-line bg-surface p-4 hover:border-brand/40">
            <p className="text-sm text-muted">{label}</p>
            <p className={`font-display text-3xl font-bold ${color}`}>{value}</p>
            {sub && <p className="text-xs text-muted">{sub}</p>}
          </Link>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <select className="filter-control !w-auto" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Estado">
          <option value="">Estado: todos</option>
          <option value="Cumple">Cumple</option>
          <option value="En proceso">En proceso</option>
          <option value="No cumple">No cumple</option>
        </select>
        <span className="ml-auto text-sm text-muted">{shown.length} de {rows.length}</span>
      </div>
      <div className="overflow-x-auto rounded-xl border border-line bg-surface">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-line bg-bg text-xs font-semibold text-muted">
            <tr><th className="px-4 py-3">Lineamiento</th><th className="px-4 py-3">Ítems</th><th className="px-4 py-3">Cumplimiento</th><th className="px-4 py-3">Brechas</th><th className="px-4 py-3">Estado</th></tr>
          </thead>
          <tbody>
            {items === null ? (
              <tr><td colSpan={5} className="px-4 py-6 text-muted">Cargando…</td></tr>
            ) : shown.length === 0 ? (
              <tr><td colSpan={5} className="px-4 py-6 text-muted">Este diagnóstico aún no tiene ítems respondidos.</td></tr>
            ) : shown.map((r) => (
              <tr key={r.name} className="cursor-pointer border-b border-line last:border-0 hover:bg-brand-soft/40" onClick={() => (window.location.href = href({}))}>
                <td className="px-4 py-3 font-medium">{r.name}</td>
                <td className="px-4 py-3">{r.items}</td>
                <td className="px-4 py-3">
                  <div className="h-2 w-32 overflow-hidden rounded-full bg-bg"><div className={`h-full ${tone(r.tone)}`} style={{ width: `${r.pct}%` }} /></div>
                  <span className="text-xs">{r.pct}%</span>
                </td>
                <td className="px-4 py-3">{r.gaps}</td>
                <td className="px-4 py-3">
                  <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${r.tone === "ok" ? "bg-ok-soft text-ok" : r.tone === "amber" ? "bg-amber-soft text-amber-ink" : "bg-bad-soft text-bad"}`}>
                    <span className={`h-1.5 w-1.5 rounded-full ${tone(r.tone)}`} />{r.st}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
