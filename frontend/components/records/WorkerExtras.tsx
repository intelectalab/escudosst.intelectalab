"use client";

// Alta y ficha del trabajador (M-TRB SCR-TRB-03 / SCR-TRB-02):
//  - Consentimiento de datos (Ley 29733) en worker_consents, con la versión del texto vigente. El texto lo aprueba un
//    abogado: mientras tanto es un parámetro legal «por confirmar» con un texto neutro.
//  - Foto con recorte cuadrado: se elige la imagen, se encuadra (zoom y posición) y se sube ya recortada (512 × 512, JPG).

import { useEffect, useRef, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { downloadFile, uploadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";

export type ConsentValue = { choice: "" | "in_person" | "paper" | "refused"; file_id: string | null; file_name: string | null };
export const EMPTY_CONSENT: ConsentValue = { choice: "", file_id: null, file_name: null };

type ConsentText = { text: string; version: string; to_confirm: boolean };
type ConsentRow = { id: string; granted: boolean; granted_at: string; method: string; text_version: string; revoked_at: string | null; registered_by: string | null };

const METHOD: Record<string, string> = { in_person_assisted: "en persona", paper_scanned: "firmado en papel", app_signature: "firma en la app", web: "en la web" };
const errText = (e: unknown) => (e instanceof ApiError ? (e.errors ? Object.values(e.errors).flat()[0] : (e.detail ?? e.message)) : "No se pudo guardar.");
const fmt = (d: string) => new Date(d).toLocaleDateString("es-PE", { timeZone: "America/Lima" });

/** Registra el consentimiento elegido (si se eligió algo). */
export async function saveWorkerConsent(workerId: string, v: ConsentValue): Promise<void> {
  if (!v.choice) return;
  await api(`/trb/workers/${workerId}/consents`, {
    method: "POST", withCompany: true,
    body: { granted: v.choice !== "refused", method: v.choice === "paper" ? "paper_scanned" : "in_person_assisted", file_id: v.choice === "paper" ? v.file_id : null },
  });
}

/** Bloque del formulario de alta: el texto vigente y cómo se registró el consentimiento. */
export function WorkerConsentField({ value, onChange, disabled }: { value: ConsentValue; onChange: (v: ConsentValue) => void; disabled?: boolean }) {
  const [t, setT] = useState<ConsentText | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: ConsentText }>("/trb/consent-text", { withCompany: true }).then((r) => setT(r.data)).catch(() => setT(null));
  }, []);
  const opt = (k: ConsentValue["choice"], label: string) => (
    <label className="flex items-center gap-2 text-sm">
      <input type="radio" name="worker-consent" checked={value.choice === k} disabled={disabled} onChange={() => onChange({ ...value, choice: k })} />
      {label}
    </label>
  );
  return (
    <fieldset className="space-y-2 rounded-xl border border-line p-4 sm:col-span-2">
      <legend className="px-1 text-sm font-semibold">Consentimiento para el tratamiento de datos</legend>
      {t && (
        <div className="rounded-lg bg-bg p-3 text-sm">
          <p className="whitespace-pre-line">{t.text}</p>
          {t.to_confirm && <p className="mt-1 text-xs text-muted">Texto a confirmar: lo aprueba el área legal en Parámetros legales.</p>}
        </div>
      )}
      <div className="flex flex-wrap gap-x-5 gap-y-2">
        {opt("", "Aún no")}
        {opt("in_person", "Lo dio en persona")}
        {opt("paper", "Lo firmó en papel")}
        {opt("refused", "No lo dio")}
      </div>
      {value.choice === "paper" && (
        <div className="space-y-1">
          {value.file_name && <p className="text-sm">📎 {value.file_name}</p>}
          <input type="file" accept="application/pdf,image/jpeg,image/png" className="text-sm" disabled={disabled || busy} aria-label="Consentimiento firmado"
            onChange={async (e) => {
              const f = e.target.files?.[0];
              e.target.value = "";
              if (!f) return;
              setBusy(true);
              setError(null);
              try {
                const up = await uploadFile(f, "TRB");
                onChange({ ...value, file_id: up.id, file_name: up.original_name });
              } catch (x) {
                setError(errText(x));
              } finally {
                setBusy(false);
              }
            }} />
          {error && <p className="text-xs text-bad">{error}</p>}
        </div>
      )}
      <p className="text-xs text-muted">Se guardan la versión del texto, la fecha, cómo se dio y quién lo registra.</p>
    </fieldset>
  );
}

/** Ficha: estado del consentimiento de datos y registro si falta. */
export function WorkerConsentStatus({ workerId }: { workerId: string }) {
  const [rows, setRows] = useState<ConsentRow[] | null>(null);
  const [denied, setDenied] = useState(false);
  const [open, setOpen] = useState(false);
  const [v, setV] = useState<ConsentValue>(EMPTY_CONSENT);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const load = () => api<{ data: ConsentRow[] }>(`/trb/workers/${workerId}/consents`, { withCompany: true }).then((r) => setRows(r.data)).catch(() => setDenied(true));
  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [workerId]);
  if (denied) return null;
  if (rows === null) return <div className="h-16 animate-pulse rounded-xl bg-surface" />;
  const last = rows.find((r) => !r.revoked_at);
  return (
    <section className="space-y-2 rounded-xl border border-line bg-surface p-4">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="text-sm font-semibold">Consentimiento de datos</h2>
        {last ? <Chip tone={last.granted ? "ok" : "bad"}>{last.granted ? "Otorgado" : "No otorgado"}</Chip> : <Chip tone="amber">Pendiente</Chip>}
        {!open && <button type="button" className="ml-auto text-sm font-semibold text-brand hover:underline" onClick={() => setOpen(true)}>{last ? "Registrar de nuevo" : "Registrar"}</button>}
      </div>
      {last && (
        <p className="text-xs text-muted">
          {fmt(last.granted_at)} · {METHOD[last.method] ?? last.method}{last.registered_by ? ` · registró ${last.registered_by}` : ""} · versión del texto {last.text_version}
        </p>
      )}
      {open && (
        <div className="space-y-2">
          <WorkerConsentField value={v} onChange={setV} disabled={busy} />
          {error && <p role="alert" className="text-sm text-bad">{error}</p>}
          <div className="flex gap-2">
            <Button variant="primary" loading={busy} disabled={!v.choice || (v.choice === "paper" && !v.file_id)}
              onClick={async () => {
                setBusy(true);
                setError(null);
                try {
                  await saveWorkerConsent(workerId, v);
                  setOpen(false);
                  setV(EMPTY_CONSENT);
                  await load();
                } catch (x) {
                  setError(errText(x));
                } finally {
                  setBusy(false);
                }
              }}>Guardar</Button>
            <Button onClick={() => { setOpen(false); setV(EMPTY_CONSENT); }}>Cancelar</Button>
          </div>
        </div>
      )}
    </section>
  );
}

const OUT = 512;

/** Foto del trabajador con recorte cuadrado (canvas, sin librerías): zoom y posición, y se sube el recorte en JPG. */
export function PhotoCropInput({ value, initialLabel, disabled, onChange }: { value: string; initialLabel?: string; disabled: boolean; onChange: (v: string | null) => void }) {
  const [img, setImg] = useState<HTMLImageElement | null>(null);
  const [zoom, setZoom] = useState(1);
  const [x, setX] = useState(50);
  const [y, setY] = useState(50);
  const [busy, setBusy] = useState(false);
  const [name, setName] = useState(initialLabel ?? "");
  const [error, setError] = useState<string | null>(null);
  const canvas = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    if (!img || !canvas.current) return;
    const ctx = canvas.current.getContext("2d");
    if (!ctx) return;
    const side = Math.min(img.naturalWidth, img.naturalHeight) / zoom;
    const sx = ((img.naturalWidth - side) * x) / 100;
    const sy = ((img.naturalHeight - side) * y) / 100;
    ctx.fillStyle = "#fff";
    ctx.fillRect(0, 0, OUT, OUT);
    ctx.drawImage(img, sx, sy, side, side, 0, 0, OUT, OUT);
  }, [img, zoom, x, y]);

  function pick(file: File | undefined) {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      setError("Elige una imagen (JPG o PNG).");
      return;
    }
    setError(null);
    const url = URL.createObjectURL(file);
    const i = new Image();
    i.onload = () => { setImg(i); setZoom(1); setX(50); setY(50); URL.revokeObjectURL(url); };
    i.onerror = () => { setError("No se pudo leer la imagen."); URL.revokeObjectURL(url); };
    i.src = url;
  }

  async function save() {
    const c = canvas.current;
    if (!c) return;
    setBusy(true);
    setError(null);
    try {
      const blob = await new Promise<Blob | null>((ok) => c.toBlob(ok, "image/jpeg", 0.9));
      if (!blob) throw new Error("No se pudo recortar la imagen.");
      const up = await uploadFile(new File([blob], "foto.jpg", { type: "image/jpeg" }), "TRB");
      onChange(up.id);
      setName(up.original_name);
      setImg(null);
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.message) : (e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-2">
      {value && !img && (
        <div className="flex items-center gap-2 text-sm">
          <button type="button" className="font-semibold text-brand hover:underline" onClick={() => downloadFile(`/files/${value}`, name || "foto.jpg").catch((e: ApiError) => setError(e.detail ?? e.title))}>
            📎 {name || "Ver foto"}
          </button>
          {!disabled && <button type="button" className="text-xs text-muted hover:text-bad" onClick={() => { onChange(null); setName(""); }}>Quitar</button>}
        </div>
      )}
      {!disabled && !img && (
        <input type="file" accept="image/jpeg,image/png,image/webp" className="input file:mr-3 file:rounded-lg file:border-0 file:bg-bg file:px-3 file:py-1.5 file:font-semibold"
          aria-label="Elegir foto" onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ""; }} />
      )}
      {img && (
        <div className="space-y-2 rounded-xl border border-line p-3">
          <canvas ref={canvas} width={OUT} height={OUT} className="h-48 w-48 rounded-lg border border-line" aria-label="Vista previa del recorte" />
          <label className="block text-xs text-muted">Zoom
            <input type="range" min={1} max={4} step={0.05} value={zoom} onChange={(e) => setZoom(Number(e.target.value))} className="block w-48" />
          </label>
          <label className="block text-xs text-muted">Horizontal
            <input type="range" min={0} max={100} value={x} onChange={(e) => setX(Number(e.target.value))} className="block w-48" />
          </label>
          <label className="block text-xs text-muted">Vertical
            <input type="range" min={0} max={100} value={y} onChange={(e) => setY(Number(e.target.value))} className="block w-48" />
          </label>
          <div className="flex gap-2">
            <Button size="sm" variant="primary" loading={busy} onClick={save}>Usar este recorte</Button>
            <Button size="sm" onClick={() => setImg(null)}>Cancelar</Button>
          </div>
        </div>
      )}
      {busy && <p className="text-xs text-muted">Subiendo…</p>}
      {error && <p className="text-xs text-bad">{error}</p>}
    </div>
  );
}
