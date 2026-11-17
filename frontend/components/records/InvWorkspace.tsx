"use client";

// Investigación (M-INV SCR-INV-02): cronología del hecho, entrevistas y evidencias. Aprobado el informe ya no cambian
// (las precisiones van como adenda). RN-INV-07: en las entrevistas no se registran datos de salud.

import { useState } from "react";
import { records, uploadFile, type RecordRow } from "@/lib/records";
import { ApiError } from "@/lib/api";

type Step = { at: string; what: string };
type Interview = { name: string; role: string; on: string; summary: string };

const parse = <T,>(v: unknown): T[] => (Array.isArray(v) ? (v as T[]) : typeof v === "string" ? (() => { try { return JSON.parse(v) as T[]; } catch { return []; } })() : []);
const errText = (e: unknown) => (e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo guardar.");

export function InvWorkspace({ row, readOnly, onChanged }: { row: RecordRow; readOnly: boolean; onChanged: () => void }) {
  const locked = readOnly || ["approved", "actions_in_progress", "closed"].includes(String(row.status));
  const [steps, setSteps] = useState<Step[]>(parse<Step>(row.chronology));
  const [ints, setInts] = useState<Interview[]>(parse<Interview>(row.interviews));
  const [files, setFiles] = useState<string[]>(parse<string>(row.evidence_file_ids));
  const [names, setNames] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const save = (body: Record<string, unknown>) =>
    records.update("INV", "investigations", row.id, body).then(() => { setMsg({ ok: true, text: "Guardado." }); onChanged(); }).catch((e) => setMsg({ ok: false, text: errText(e) }));
  const box = "space-y-2 rounded-xl border border-line px-4 py-3 text-sm";
  return (
    <div className="space-y-3">
      <section className={box}>
        <p className="font-semibold">Cronología</p>
        {steps.length === 0 && <p className="text-muted">Ordena lo que pasó antes, durante y después del hecho.</p>}
        {steps.map((s, i) => (
          <div key={i} className="flex flex-wrap gap-2">
            <input type="datetime-local" className="input !w-52" disabled={locked} value={s.at} onChange={(e) => setSteps(steps.map((x, k) => (k === i ? { ...x, at: e.target.value } : x)))} />
            <input className="input flex-1" disabled={locked} value={s.what} placeholder="Qué pasó" onChange={(e) => setSteps(steps.map((x, k) => (k === i ? { ...x, what: e.target.value } : x)))} />
            {!locked && <button type="button" className="text-xs text-muted underline" onClick={() => setSteps(steps.filter((_, k) => k !== i))}>Quitar</button>}
          </div>
        ))}
        {!locked && (
          <div className="flex gap-2">
            <button type="button" className="rounded-lg border border-line px-3 py-1.5" onClick={() => setSteps([...steps, { at: "", what: "" }])}>Agregar momento</button>
            <button type="button" className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white" onClick={() => save({ chronology: [...steps].sort((a, b) => a.at.localeCompare(b.at)) })}>Guardar cronología</button>
          </div>
        )}
      </section>

      <section className={box}>
        <p className="font-semibold">Entrevistas</p>
        <p className="text-xs text-muted">Registra lo que vio o hizo cada persona. No anotes diagnósticos ni datos de salud.</p>
        {ints.map((x, i) => (
          <div key={i} className="space-y-1 rounded-lg bg-bg p-2">
            <div className="flex flex-wrap gap-2">
              <input className="input !w-56" disabled={locked} placeholder="Nombre" value={x.name} onChange={(e) => setInts(ints.map((y, k) => (k === i ? { ...y, name: e.target.value } : y)))} />
              <input className="input !w-44" disabled={locked} placeholder="Afectado, testigo, supervisor…" value={x.role} onChange={(e) => setInts(ints.map((y, k) => (k === i ? { ...y, role: e.target.value } : y)))} />
              <input type="date" className="input !w-40" disabled={locked} value={x.on} onChange={(e) => setInts(ints.map((y, k) => (k === i ? { ...y, on: e.target.value } : y)))} />
              {!locked && <button type="button" className="text-xs text-muted underline" onClick={() => setInts(ints.filter((_, k) => k !== i))}>Quitar</button>}
            </div>
            <textarea className="input min-h-[64px]" disabled={locked} placeholder="Resumen de la entrevista" value={x.summary} onChange={(e) => setInts(ints.map((y, k) => (k === i ? { ...y, summary: e.target.value } : y)))} />
          </div>
        ))}
        {!locked && (
          <div className="flex gap-2">
            <button type="button" className="rounded-lg border border-line px-3 py-1.5" onClick={() => setInts([...ints, { name: "", role: "", on: new Date().toISOString().slice(0, 10), summary: "" }])}>Agregar entrevista</button>
            <button type="button" className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white" onClick={() => save({ interviews: ints })}>Guardar entrevistas</button>
          </div>
        )}
      </section>

      <section className={box}>
        <p className="font-semibold">Evidencias</p>
        {files.length === 0 ? <p className="text-muted">Fotos, croquis, documentos o registros del hecho.</p> : (
          <ul className="space-y-0.5">{files.map((f) => (
            <li key={f} className="flex items-center justify-between gap-2">
              <span>{names[f] ?? "Archivo adjunto"}</span>
              {!locked && <button type="button" className="text-xs text-muted underline" onClick={() => { const n = files.filter((x) => x !== f); setFiles(n); void save({ evidence_file_ids: n }); }}>Quitar</button>}
            </li>
          ))}</ul>
        )}
        {!locked && (
          <input type="file" multiple className="block text-sm" onChange={async (e) => {
            const list = Array.from(e.target.files ?? []);
            const ids: string[] = [];
            const nm: Record<string, string> = {};
            for (const f of list) {
              try { const u = await uploadFile(f, "INV"); ids.push(u.id); nm[u.id] = u.original_name; } catch (er) { setMsg({ ok: false, text: errText(er) }); }
            }
            e.target.value = "";
            if (!ids.length) return;
            const n = [...files, ...ids];
            setFiles(n);
            setNames({ ...names, ...nm });
            void save({ evidence_file_ids: n });
          }} />
        )}
      </section>
      {locked && !readOnly && <p className="text-xs text-muted">El informe está aprobado: estos datos ya no cambian. Las precisiones se agregan como adenda.</p>}
      {msg && <p role="status" className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </div>
  );
}
