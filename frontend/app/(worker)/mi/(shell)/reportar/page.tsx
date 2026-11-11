"use client";

// Reportar (M-ACT, docs/07 §5.2 punto 3) y Mis reportes (SCR-ACT-06). Usa el mismo alta de reportes del módulo ACT:
// sin señal y con la app offline (OFF) contratada, el reporte queda en la cola del equipo y se envía solo al volver
// la conexión (docs/16). La foto necesita señal.

import { useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { Alert, BigButton, Empty, Panel, Pill, Title, type Tone } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { canQueue, queueFile } from "@/lib/offline";
import { records, uploadFile } from "@/lib/records";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

type Report = { id: string; code: string | null; report_type: string; description: string; location_text: string | null; status: string; reported_at: string; rejection_reason: string | null };

const TYPES = [
  { v: "unsafe_condition", l: "Condición insegura", hint: "Algo del lugar o del equipo que puede causar daño" },
  { v: "unsafe_act", l: "Acto inseguro", hint: "Una forma de trabajar que pone en riesgo" },
  { v: "near_miss", l: "Casi accidente", hint: "Pudo pasar algo y no pasó" },
];
const STATUS: Record<string, [string, Tone]> = {
  received: ["Recibido", "brand"], triaged: ["En revisión", "amber"], action_assigned: ["Con acción asignada", "amber"], closed: ["Atendido", "ok"], rejected: ["Descartado", "na"],
};

export default function ReportPage() {
  const { home, reload } = useWorker();
  const [type, setType] = useState("unsafe_condition");
  const [desc, setDesc] = useState("");
  const [place, setPlace] = useState(home.worker.site ?? "");
  const [anon, setAnon] = useState(false);
  const [photo, setPhoto] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ tone: Tone; text: string } | null>(null);
  const [mine, setMine] = useState<Report[] | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const loadMine = () => workerApi<Report[]>("/reports").then(setMine).catch(() => setMine([]));
  useEffect(() => {
    void loadMine();
  }, []);
  useEffect(() => {
    if (!photo) return setPreview(null);
    const u = URL.createObjectURL(photo);
    setPreview(u);
    return () => URL.revokeObjectURL(u);
  }, [photo]);

  async function send() {
    if (desc.trim().length < 5) return setMsg({ tone: "bad", text: "Cuéntanos qué viste (al menos unas palabras)." });
    setBusy(true);
    setMsg(null);
    try {
      let photoId: string | null = null;
      if (photo) {
        // Con señal se sube ya; sin señal la foto queda cifrada en el equipo y se sube junto con el reporte.
        if (navigator.onLine) photoId = await uploadFile(photo, "ACT").then((f) => f.id).catch((e) => (canQueue("substandard_reports") && (e instanceof TypeError || e?.status === 0) ? queueFile(photo, "ACT") : Promise.reject(e)));
        else if (canQueue("substandard_reports")) photoId = await queueFile(photo, "ACT");
      }
      const body: Record<string, unknown> = { report_type: type, description: desc.trim(), location_text: place.trim() || null, is_anonymous: anon, origin: "app",
        occurred_at: new Date().toISOString(), ...(photoId ? { photo_file_id: photoId } : {}) };
      const r = await records.create("ACT", "substandard_reports", body);
      const queued = (r.data as { offline_pending?: boolean }).offline_pending;
      setMsg({ tone: "ok", text: queued
        ? `Sin señal: tu reporte${photo ? " y su foto quedaron guardados" : " quedó guardado"} en este equipo y se enviará${photo ? "n" : ""} solo cuando vuelva la conexión.`
        : "Reporte enviado. Lo recibe el área de SST. ¡Gracias por cuidarnos!" });
      setDesc("");
      setPhoto(null);
      setAnon(false);
      void loadMine();
      void reload();
    } catch (e) {
      setMsg({ tone: "bad", text: !navigator.onLine && !canQueue("substandard_reports") ? "No tienes señal. Inténtalo de nuevo cuando tengas conexión." : errorText(e) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Title sub="Lo recibe el área de SST al instante. Puedes enviarlo sin tu nombre.">Reportar</Title>
      <Panel>
        <div className="space-y-2" role="radiogroup" aria-label="Qué quieres reportar">
          {TYPES.map((t) => (
            <button key={t.v} type="button" role="radio" aria-checked={type === t.v} onClick={() => setType(t.v)}
              className={`flex min-h-12 w-full flex-col items-start rounded-xl border px-4 py-2 text-left ${type === t.v ? "border-brand bg-brand-soft" : "border-line hover:border-brand/50"}`}>
              <b>{t.l}</b>
              <span className="text-xs text-muted">{t.hint}</span>
            </button>
          ))}
        </div>
        <input ref={fileRef} type="file" accept="image/*" capture="environment" className="hidden" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
        {preview ? (
          <div className="space-y-2">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={preview} alt="Foto del reporte" className="max-h-56 w-full rounded-xl object-cover" />
            <BigButton variant="secondary" onClick={() => setPhoto(null)}>Quitar la foto</BigButton>
          </div>
        ) : (
          <BigButton variant="secondary" onClick={() => fileRef.current?.click()}><Icon name="image" size={20} /> Tomar foto</BigButton>
        )}
        <label htmlFor="rep-desc" className="block space-y-1">
          <span className="text-sm font-semibold">¿Qué viste?</span>
          <textarea id="rep-desc" rows={4} value={desc} onChange={(e) => setDesc(e.target.value)} maxLength={2000}
            className="w-full rounded-xl border border-line bg-surface px-4 py-3 text-base focus:border-brand focus:outline-none" />
        </label>
        <label htmlFor="rep-place" className="block space-y-1">
          <span className="text-sm font-semibold">¿Dónde?</span>
          <input id="rep-place" value={place} onChange={(e) => setPlace(e.target.value)} maxLength={300}
            className="h-12 w-full rounded-xl border border-line bg-surface px-4 text-base focus:border-brand focus:outline-none" />
        </label>
        <label className="flex min-h-12 items-center gap-3 text-sm">
          <input type="checkbox" className="h-5 w-5" checked={anon} onChange={(e) => setAnon(e.target.checked)} />
          Enviar sin mi nombre (anónimo)
        </label>
        {msg && <Alert tone={msg.tone}>{msg.text}</Alert>}
        <BigButton onClick={send} loading={busy}>Enviar reporte</BigButton>
      </Panel>

      <Panel title="Mis reportes">
        {!mine ? <p className="text-sm text-muted">Cargando…</p> : mine.length === 0 ? <Empty text="Aún no enviaste reportes con tu nombre." /> : (
          <ul className="divide-y divide-line">
            {mine.map((r) => (
              <li key={r.id} className="space-y-1 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <b className="text-sm">{r.code ?? "Reporte"} · {TYPES.find((t) => t.v === r.report_type)?.l ?? "Sugerencia"}</b>
                  <Pill tone={STATUS[r.status]?.[1] ?? "na"}>{STATUS[r.status]?.[0] ?? r.status}</Pill>
                </div>
                <p className="line-clamp-2 text-sm text-muted">{r.description}</p>
                <p className="text-xs text-muted">{fmtDate(r.reported_at, true)}{r.rejection_reason ? ` · ${r.rejection_reason}` : ""}</p>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </>
  );
}
