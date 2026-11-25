"use client";

// Propuesta de la matriz de capacitación por cargo desde el IPERC y el PCA (M-CAP SCR-CAP-07): los controles del IPERC vigente que piden una
// capacitación y los temas del programa anual aprobado con sus puestos objetivo. Nada se aplica solo: se revisa y se aceptan las elegidas.

import { useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";

type Row = { position_id: string; position: string; training_id: string; training: string; source: "iperc" | "pca"; origin: string; level: "mandatory" | "suggested"; action: "create" | "exists" };
const key = (r: Row) => `${r.position_id}|${r.training_id}|${r.source}`;

export function CapMatrixProposals({ onSaved }: { onSaved: () => void }) {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [sel, setSel] = useState<Record<string, boolean>>({});
  const [level, setLevel] = useState<Record<string, Row["level"]>>({});
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: Row[] }>("/cap/matrix/proposals", { withCompany: true });
      setRows(r.data);
      setSel({});
      setLevel({});
    } catch (e) {
      setMsg({ ok: false, text: (e as ApiError).detail ?? (e as ApiError).title });
    } finally {
      setBusy(false);
    }
  };
  const chosen = (rows ?? []).filter((r) => r.action === "create" && sel[key(r)]);
  const accept = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { saved: number; errors: string[] } }>("/cap/matrix/proposals", { method: "POST", withCompany: true,
        body: { items: chosen.map((x) => ({ position_id: x.position_id, training_id: x.training_id, source: x.source, requirement_level: level[key(x)] ?? x.level })) } });
      setMsg({ ok: r.data.errors.length === 0, text: `Se agregaron ${r.data.saved} fila(s) a la matriz.${r.data.errors.length ? " " + r.data.errors.join(" ") : ""}` });
      onSaved();
      await load();
    } catch (e) {
      const x = e as ApiError;
      setMsg({ ok: false, text: x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title) });
    } finally {
      setBusy(false);
    }
  };
  const pending = (rows ?? []).filter((r) => r.action === "create");

  return (
    <details className="rounded-xl border border-line bg-surface" onToggle={(e) => { if ((e.target as HTMLDetailsElement).open && !rows) void load(); }}>
      <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Propuesta desde el IPERC y el programa de capacitación</summary>
      <div className="space-y-3 border-t border-line p-4 text-sm">
        <p className="text-muted">Capacitaciones que piden los controles del IPERC vigente (para el puesto de la fila o de la matriz) y los temas del programa anual aprobado con sus puestos objetivo. Revisa y acepta las que correspondan: nada se agrega solo.</p>
        {!rows ? <p className="text-muted">{busy ? "Buscando propuestas…" : ""}</p> : rows.length === 0 ? (
          <p className="text-muted">No hay propuestas: ningún control del IPERC ni tema del programa aprobado tiene capacitación y puesto.</p>
        ) : (
          <>
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Propuestas de la matriz">
              <table className="w-full text-left text-xs">
                <thead className="border-b border-line text-muted"><tr>
                  <th className="py-1.5 pr-2"><input type="checkbox" aria-label="Elegir todas" checked={pending.length > 0 && pending.every((r) => sel[key(r)])}
                    onChange={(e) => setSel(Object.fromEntries(pending.map((r) => [key(r), e.target.checked])))} /></th>
                  <th>Cargo</th><th>Tema</th><th>Origen</th><th>Nivel</th><th>Estado</th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={key(r)} className="border-b border-line last:border-0 align-top">
                      <td className="py-1.5 pr-2">{r.action === "create" && <input type="checkbox" aria-label={`Elegir ${r.position} · ${r.training}`} checked={!!sel[key(r)]} onChange={(e) => setSel({ ...sel, [key(r)]: e.target.checked })} />}</td>
                      <td>{r.position}</td><td>{r.training}</td>
                      <td><span className="font-semibold">{r.source === "iperc" ? "IPERC" : "PCA"}</span> <span className="text-muted">{r.origin}</span></td>
                      <td>{r.action === "create" ? (
                        <select className="input py-0.5 text-xs" aria-label="Nivel" value={level[key(r)] ?? r.level} onChange={(e) => setLevel({ ...level, [key(r)]: e.target.value as Row["level"] })}>
                          <option value="mandatory">Obligatorio</option><option value="suggested">Sugerido</option>
                        </select>) : (r.level === "mandatory" ? "Obligatorio" : "Sugerido")}</td>
                      <td>{r.action === "exists" ? <span className="rounded bg-bg px-1.5 py-0.5 font-semibold text-muted">Ya está en la matriz</span> : <span className="rounded bg-ok-soft px-1.5 py-0.5 font-semibold text-ok">Nueva</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" size="sm" disabled={chosen.length === 0 || busy} loading={busy} onClick={() => void accept()}>Aceptar {chosen.length} propuesta(s)</Button>
              <Button size="sm" disabled={busy} onClick={() => void load()}>Volver a buscar</Button>
            </div>
          </>
        )}
        {msg && <p role="status" className={`rounded-lg px-3 py-2 ${msg.ok ? "bg-ok-soft text-ok" : "bg-bad-soft text-bad"}`}>{msg.text}</p>}
      </div>
    </details>
  );
}
