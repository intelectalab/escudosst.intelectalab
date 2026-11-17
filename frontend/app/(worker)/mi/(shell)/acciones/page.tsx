"use client";

// Mis acciones (M-ACR SCR-ACR-03): las acciones correctivas a cargo del trabajador, con «Subir evidencia» desde la cámara.
// Al marcar «Terminé» la acción pasa a verificación del área de SST.

import { useCallback, useEffect, useState } from "react";
import { Alert, Empty, Loading, Pill, Title } from "@/components/worker/WorkerUi";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";
import { uploadFile } from "@/lib/records";

type Action = { id: string; code: string; title: string; description: string | null; due_on: string | null; status: string; evidences: number };
const ST: Record<string, [string, "ok" | "amber" | "bad" | "na" | "brand"]> = {
  open: ["Por hacer", "amber"], in_progress: ["En curso", "brand"], overdue: ["Vencida", "bad"], pending_verification: ["En verificación", "ok"],
};

export default function MyActionsPage() {
  const [rows, setRows] = useState<Action[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [okMsg, setOkMsg] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [photo, setPhoto] = useState<File | null>(null);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => workerApi<Action[]>("/actions").then(setRows).catch((e) => setError(errorText(e))), []);
  useEffect(() => { void load(); }, [load]);

  const send = async (id: string, done: boolean) => {
    setBusy(true);
    setError(null);
    try {
      const fileId = photo ? (await uploadFile(photo, "ACR")).id : null;
      await workerApi(`/actions/${id}/evidence`, { method: "POST", body: { file_id: fileId, description: text.trim() || null, done } });
      setOkMsg(done ? "Listo: el área de SST revisará la evidencia." : "Evidencia guardada.");
      setOpen(null); setPhoto(null); setText("");
      void load();
    } catch (e) { setError(errorText(e)); } finally { setBusy(false); }
  };

  return (
    <>
      <Title sub="Lo que te toca hacer y su evidencia.">Mis acciones</Title>
      {okMsg && <Alert tone="ok">{okMsg}</Alert>}
      {error && <Alert>{error}</Alert>}
      {!rows && !error && <Loading />}
      {rows && rows.length === 0 && <Empty text="No tienes acciones pendientes." />}
      {rows && rows.length > 0 && (
        <ul className="space-y-3">
          {rows.map((a) => {
            const [label, tone] = ST[a.status] ?? [a.status, "na"];
            return (
              <li key={a.id} className="space-y-2 rounded-2xl border border-line bg-surface p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <span className="min-w-0"><b className="block leading-tight">{a.title}</b><span className="text-xs text-muted">{a.code}{a.due_on ? ` · hasta el ${fmtDate(a.due_on)}` : ""} · {a.evidences} evidencia(s)</span></span>
                  <Pill tone={tone}>{label}</Pill>
                </div>
                {a.description && <p className="text-sm text-muted">{a.description}</p>}
                {a.status !== "pending_verification" && (open === a.id ? (
                  <div className="space-y-2">
                    <label className="block text-sm font-semibold">Foto de lo realizado
                      <input type="file" accept="image/*" capture="environment" className="mt-1 block text-sm font-normal" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                    </label>
                    <textarea className="input min-h-[72px] w-full" placeholder="¿Qué hiciste? (opcional si subes foto)" value={text} maxLength={1000} onChange={(e) => setText(e.target.value)} />
                    <div className="flex flex-wrap gap-2">
                      <button type="button" disabled={busy || (!photo && !text.trim())} className="rounded-xl border border-line px-4 py-2 text-sm font-semibold disabled:opacity-50" onClick={() => send(a.id, false)}>Guardar evidencia</button>
                      <button type="button" disabled={busy || (!photo && !text.trim())} className="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" onClick={() => send(a.id, true)}>Terminé: enviar a verificación</button>
                      <button type="button" className="text-sm text-muted underline" onClick={() => { setOpen(null); setPhoto(null); setText(""); }}>Cancelar</button>
                    </div>
                  </div>
                ) : (
                  <button type="button" className="w-full rounded-xl bg-brand px-4 py-3 font-semibold text-white" onClick={() => { setOpen(a.id); setOkMsg(null); }}>Subir evidencia</button>
                ))}
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}
