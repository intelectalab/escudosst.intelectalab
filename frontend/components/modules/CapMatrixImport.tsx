"use client";

// Matriz de capacitación por cargo desde Excel (M-CAP SCR-CAP-07): plantilla con la matriz actual, revisión fila por fila (qué se crea,
// qué cambia y qué tiene errores) y guardado de las filas válidas. Un cambio guarda la versión anterior de la fila (RN-CAP-06).

import { useState } from "react";
import { API_URL } from "@/lib/api";
import { session } from "@/lib/session";
import { downloadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";

type Row = { row: number; position: string; training: string; level: string | null; renewal_months: number | null; due_within_days: number | null; action: "create" | "update" | "same" | "error"; errors: string[] };
type Result = { applied: boolean; saved: number; rows: Row[]; counts: Record<string, number> };
const ACTION: Record<Row["action"], [string, string]> = {
  create: ["Nueva", "bg-ok-soft text-ok"], update: ["Cambia", "bg-brand-soft text-brand-strong"], same: ["Sin cambios", "bg-bg text-muted"], error: ["Con error", "bg-bad-soft text-bad"],
};

export function CapMatrixImport({ onSaved }: { onSaved: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<Result | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const send = async (apply: boolean) => {
    if (!file) return;
    setBusy(true);
    setMsg(null);
    try {
      const body = new FormData();
      body.append("file", file);
      if (apply) body.append("apply", "1");
      const headers: Record<string, string> = { Accept: "application/json" };
      const t = session.getToken();
      const c = session.getCompanyId();
      if (t) headers.Authorization = `Bearer ${t}`;
      if (c) headers["X-Company-Id"] = c;
      const r = await fetch(`${API_URL}/api/v1/cap/matrix/import`, { method: "POST", headers, body });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) { setMsg({ ok: false, text: data.detail ?? data.title ?? "No se pudo leer el archivo." }); return; }
      setRes(data.data);
      if (apply) { setMsg({ ok: true, text: `Se guardaron ${data.data.saved} fila(s) de la matriz.` }); onSaved(); }
    } finally {
      setBusy(false);
    }
  };
  const ready = res && !res.applied ? res.rows.filter((r) => r.action === "create" || r.action === "update").length : 0;

  return (
    <details className="rounded-xl border border-line bg-surface">
      <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Cargar la matriz desde Excel</summary>
      <div className="space-y-3 border-t border-line p-4 text-sm">
        <p className="text-muted">Columnas: Cargo, Tema, Nivel (obligatorio o sugerido), Renovación (meses) y Plazo desde el ingreso (días). El cargo y el tema van con su código o su nombre como están en EscudoSST.</p>
        <div className="flex flex-wrap items-center gap-2">
          <Button size="sm" icon="download" onClick={() => downloadFile("/cap/matrix/template.xlsx", "matriz-capacitacion.xlsx")}>Plantilla con la matriz actual</Button>
          <input type="file" accept=".xlsx,.csv" aria-label="Archivo de la matriz (Excel o CSV)" className="text-sm" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setRes(null); setMsg(null); }} />
          <Button size="sm" disabled={!file || busy} loading={busy && !res} onClick={() => void send(false)}>Revisar</Button>
        </div>
        {res && (
          <>
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Revisión de la matriz">
              <table className="w-full text-left text-xs">
                <thead className="border-b border-line text-muted"><tr><th className="py-1.5 pr-2">Fila</th><th>Cargo</th><th>Tema</th><th>Nivel</th><th>Renovación</th><th>Plazo</th><th>Resultado</th></tr></thead>
                <tbody>
                  {res.rows.map((r) => (
                    <tr key={r.row} className="border-b border-line last:border-0 align-top">
                      <td className="py-1.5 pr-2">{r.row}</td><td>{r.position}</td><td>{r.training}</td>
                      <td>{r.level === "mandatory" ? "Obligatorio" : r.level === "suggested" ? "Sugerido" : "—"}</td>
                      <td>{r.renewal_months != null ? `${r.renewal_months} meses` : "—"}</td><td>{r.due_within_days != null ? `${r.due_within_days} días` : "—"}</td>
                      <td><span className={`rounded px-1.5 py-0.5 font-semibold ${ACTION[r.action][1]}`}>{ACTION[r.action][0]}</span>{r.errors.length > 0 && <span className="block text-bad">{r.errors.join(" ")}</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {!res.applied && <Button variant="primary" size="sm" disabled={!ready || busy} loading={busy} onClick={() => void send(true)}>Guardar {ready} fila(s) válida(s)</Button>}
          </>
        )}
        {msg && <p role="status" className={`rounded-lg px-3 py-2 ${msg.ok ? "bg-ok-soft text-ok" : "bg-bad-soft text-bad"}`}>{msg.text}</p>}
      </div>
    </details>
  );
}
