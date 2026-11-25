"use client";

// Inspección por QR (M-EQE SCR-EQE-02): al escanear el QR del equipo (con la sesión iniciada, RN-EQE-05) se ve el equipo y se
// completa el checklist de su tipo (configurable por tipo de equipo; por defecto el de la ficha: presión, precinto, señalización,
// acceso libre), con una foto opcional y su resultado. Queda la fecha, la hora y el usuario. Un resultado «Inoperativo» genera la
// acción correctiva (RN-EQE-03) por las reglas del registro.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { records, uploadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";

type Eq = { id: string; code: string; equipment_type: string; location_text: string | null; status: string; next_maintenance_on: string | null; expires_on: string | null };
const TYPE: Record<string, string> = { fire_extinguisher: "Extintor", emergency_light: "Luz de emergencia", alarm: "Alarma", hose_cabinet: "Gabinete contra incendio", eyewash: "Lavaojos",
  shower: "Ducha de emergencia", stretcher: "Camilla", aed: "Desfibrilador", signage: "Señalización", other: "Otro" };
const STATUS: Record<string, string> = { operational: "Operativo", needs_maintenance: "Requiere mantenimiento", out_of_service: "Fuera de servicio", retired: "Dado de baja",
  inspection_overdue: "Inspección vencida" };
const DEFAULT_ITEMS = ["Presión", "Precinto", "Señalización", "Acceso libre"];
const ANSWERS: [string, string][] = [["compliant", "Cumple"], ["non_compliant", "No cumple"], ["na", "No aplica"]];
const RESULTS: [string, string][] = [["ok", "Conforme"], ["observed", "Con observaciones"], ["failed", "Inoperativo"]];
const fmt = (d: string | null) => (d ? d.slice(0, 10).split("-").reverse().join("/") : "—");

export default function RevisarEquipo() {
  const { id } = useParams<{ id: string }>();
  const [eq, setEq] = useState<Eq | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const [result, setResult] = useState("");
  const [comments, setComments] = useState("");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [photo, setPhoto] = useState<File | null>(null);
  const [lists, setLists] = useState<Record<string, string[]> | null>(null);

  useEffect(() => {
    records.get("EQE", "emergency_equipment", id).then((r) => setEq(r.data as unknown as Eq)).catch((e: ApiError) => setError(e.detail ?? e.title));
    api<{ data: { types: Record<string, string[]> } }>("/eqe/checklists", { withCompany: true }).then((r) => setLists(r.data.types)).catch(() => setLists({}));
  }, [id]);
  const ITEMS = (eq && lists?.[eq.equipment_type]) || DEFAULT_ITEMS;
  const suggested = Object.values(answers).includes("non_compliant") ? "observed" : "ok";
  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      const photoId = photo ? (await uploadFile(photo, "EQE")).id : null;
      await records.create("EQE", "equipment_checks", {
        equipment_id: id, check_type: "monthly_inspection", checked_at: new Date().toISOString(), result: result || suggested,
        checklist: ITEMS.map((item) => ({ item, answer: answers[item] ?? "na" })), comments: comments || null, photo_file_id: photoId,
      });
      setDone(true);
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title));
    } finally {
      setBusy(false);
    }
  };

  if (!eq || !lists) return <p className="p-4 text-sm text-muted">{error ?? "Cargando el equipo…"}</p>;
  return (
    <div className="mx-auto max-w-xl space-y-4">
      <nav className="text-xs text-muted"><Link href="/m/eqe" className="underline">Equipos de emergencia</Link> › Inspección por QR</nav>
      <div className="rounded-xl border border-line bg-surface p-4">
        <h1 className="font-display text-xl font-bold">{eq.code}</h1>
        <p className="text-sm text-muted">{TYPE[eq.equipment_type] ?? eq.equipment_type}{eq.location_text ? ` · ${eq.location_text}` : ""}</p>
        <p className="mt-1 text-sm">Estado: <b>{STATUS[eq.status] ?? eq.status}</b> · Próximo mantenimiento: {fmt(eq.next_maintenance_on)} · Vence: {fmt(eq.expires_on)}</p>
      </div>
      {done ? (
        <div role="status" className="rounded-xl border border-line bg-ok-soft p-4 text-sm text-ok">
          Inspección registrada con fecha, hora y tu usuario. <Link href="/m/eqe" className="font-semibold underline">Volver a los equipos</Link>
        </div>
      ) : (
        <div className="space-y-3 rounded-xl border border-line bg-surface p-4">
          <h2 className="font-semibold">Checklist</h2>
          {ITEMS.map((item) => (
            <fieldset key={item} className="flex flex-wrap items-center gap-3 border-b border-line pb-2 last:border-0">
              <legend className="w-32 text-sm font-semibold">{item}</legend>
              {ANSWERS.map(([v, l]) => (
                <label key={v} className="flex items-center gap-1 text-sm">
                  <input type="radio" name={item} checked={answers[item] === v} onChange={() => setAnswers({ ...answers, [item]: v })} /> {l}
                </label>
              ))}
            </fieldset>
          ))}
          <label className="block text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Resultado</span>
            <select className="input" value={result || suggested} onChange={(e) => setResult(e.target.value)}>
              {RESULTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select></label>
          <label className="block text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Comentarios</span>
            <textarea className="input min-h-[64px]" value={comments} onChange={(e) => setComments(e.target.value)} /></label>
          <label className="block text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Foto (opcional)</span>
            <input type="file" accept="image/*" capture="environment" className="text-sm" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
            {photo && <span className="mt-1 block text-xs text-muted">{photo.name}</span>}</label>
          <Button variant="primary" loading={busy} disabled={busy || ITEMS.some((i) => !answers[i])} onClick={save}>Registrar inspección</Button>
          {error && <p role="alert" className="text-sm text-bad">{error}</p>}
        </div>
      )}
    </div>
  );
}
