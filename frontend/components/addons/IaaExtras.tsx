"use client";

// Asistente (M-IAA): «Leer documento» (SCR-IAA-03: sube el PDF de un requerimiento SUNAFIL y propone el expediente) y
// «Cursos sugeridos» (SCR-IAA-04: cursos del aula por cargo; al aceptarlos quedan en la bandeja de recomendaciones por
// aprobar del aula). Antes de procesar se quitan nombres, DNI, teléfonos y correos.

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";
import { recordsHref, uploadFile } from "@/lib/records";

type Suggestion = { id: string; status: string; rationale: string | null; confidence: number | null; payload: Record<string, unknown> };
const err = (e: unknown) => (e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo completar.");
const HIDDEN: Record<string, string> = { PERSONA: "nombres", DNI: "DNI", RUC: "RUC de persona", "TELÉFONO": "teléfonos", CORREO: "correos" };

export function IaaDocument() {
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [s, setS] = useState<Suggestion | null>(null);
  const [hidden, setHidden] = useState<string[]>([]);
  const [form, setForm] = useState<Record<string, string>>({});
  const [done, setDone] = useState<string | null>(null);

  const read = async (file?: File) => {
    if (!file) return;
    setBusy(true); setMsg(null); setS(null); setDone(null);
    try {
      const f = await uploadFile(file, "SUN");
      const r = await api<{ data: { suggestion: Suggestion; redacted: string[] } }>("/iaa/document/read", { method: "POST", withCompany: true, body: { file_id: f.id } });
      setS(r.data.suggestion); setHidden(r.data.redacted);
      const p = r.data.suggestion.payload;
      setForm({ inspection_order_number: String(p.inspection_order_number ?? ""), inspector_name: String(p.inspector_name ?? ""),
        appearance_at: String(p.appearance_at ?? "").slice(0, 16), period_from: String(p.period_from ?? ""), period_to: String(p.period_to ?? "") });
    } catch (e) { setMsg(err(e)); } finally { setBusy(false); }
  };
  const accept = async () => {
    if (!s) return;
    const p = s.payload;
    const edit = Object.fromEntries(Object.entries(form).filter(([k, v]) => v !== "" && v !== String(p[k] ?? "").slice(0, k === "appearance_at" ? 16 : undefined)));
    try {
      const r = await api<{ data: { dossier_id: string } }>(`/iaa/suggestions/${s.id}/accept`, { method: "POST", withCompany: true, body: Object.keys(edit).length ? { dossier: edit } : {} });
      setDone(r.data.dossier_id);
    } catch (e) { setMsg(err(e)); }
  };
  const input = (k: string, label: string, type = "text") => (
    <label className="block text-xs">{label}<input className="input mt-1" type={type} value={form[k] ?? ""} onChange={(e) => setForm({ ...form, [k]: e.target.value })} /></label>
  );
  const docs = (s?.payload.requested_documents as string[] | undefined) ?? [];
  return (
    <section className="space-y-3 rounded-xl border border-line bg-surface p-4 text-sm">
      <div>
        <p className="font-semibold">Leer un requerimiento de SUNAFIL</p>
        <p className="text-xs text-muted">Sube el PDF del requerimiento: el asistente propone la orden, el inspector, la comparecencia, el periodo y los documentos pedidos. Revisa antes de crear el expediente. Un PDF escaneado (foto) no se puede leer.</p>
      </div>
      <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2 font-semibold hover:border-brand">
        {busy ? "Leyendo…" : "Subir PDF"}
        <input type="file" accept="application/pdf,.pdf" className="hidden" disabled={busy} onChange={(e) => { void read(e.target.files?.[0]); e.target.value = ""; }} />
      </label>
      {msg && <p className="text-bad">{msg}</p>}
      {s && !done && (
        <div className="space-y-2 rounded-lg bg-bg p-3">
          {hidden.length > 0 && <p className="text-xs text-muted">Se ocultaron {hidden.map((h) => HIDDEN[h] ?? h).join(", ")} antes de procesar.</p>}
          <div className="grid gap-2 sm:grid-cols-2">
            {input("inspection_order_number", "Orden de inspección")}
            {input("inspector_name", "Inspector")}
            {input("appearance_at", "Comparecencia", "datetime-local")}
            <div className="grid grid-cols-2 gap-2">{input("period_from", "Periodo desde", "date")}{input("period_to", "Periodo hasta", "date")}</div>
          </div>
          {docs.length > 0 && (
            <div><p className="text-xs font-semibold">Documentos pedidos</p><ol className="list-decimal pl-5 text-xs">{docs.map((d, i) => <li key={i}>{d}</li>)}</ol></div>
          )}
          <Button variant="primary" size="sm" onClick={() => void accept()}>Crear el expediente con estos datos</Button>
        </div>
      )}
      {done && <p className="text-ok">Expediente creado. <Link className="underline" href={recordsHref("SUN", "inspection_dossiers", { open: done })}>Ábrelo para armarlo</Link>.</p>}
    </section>
  );
}

export function IaaCourses() {
  const [busy, setBusy] = useState(false);
  const [list, setList] = useState<Suggestion[] | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const run = async () => {
    setBusy(true); setMsg(null);
    try { setList((await api<{ data: { suggestions: Suggestion[] } }>("/iaa/courses/suggest", { method: "POST", withCompany: true, body: {} })).data.suggestions); }
    catch (e) { setMsg(err(e)); } finally { setBusy(false); }
  };
  const act = async (s: Suggestion, ok: boolean) => {
    try {
      await api(`/iaa/suggestions/${s.id}/${ok ? "accept" : "reject"}`, { method: "POST", withCompany: true, body: {} });
      setList((l) => (l ?? []).map((x) => (x.id === s.id ? { ...x, status: ok ? "accepted" : "rejected" } : x)));
    } catch (e) { setMsg(err(e)); }
  };
  return (
    <section className="space-y-3 rounded-xl border border-line bg-surface p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-semibold">Cursos sugeridos por cargo</p>
          <p className="text-xs text-muted">Compara los cursos publicados del aula con los peligros del IPERC y las capacitaciones exigidas de cada cargo. Al aceptar, quedan en la bandeja de recomendaciones del aula: no se envían hasta que alguien las apruebe.</p>
        </div>
        <Button size="sm" onClick={() => void run()} disabled={busy}>{busy ? "Buscando…" : "Sugerir cursos"}</Button>
      </div>
      {msg && <p className="text-bad">{msg}</p>}
      {list && list.length === 0 && <p className="text-muted">No hay cursos nuevos que sugerir: los cargos ya los llevan o no hay coincidencias.</p>}
      {list && list.length > 0 && (
        <ul className="divide-y divide-line">
          {list.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <b>{String(s.payload.course)}</b> → {String(s.payload.position)} · {String(s.payload.workers)} trabajador(es)
                <span className="block text-xs text-muted">{s.rationale}</span>
              </span>
              {s.status === "pending" ? (
                <span className="flex gap-2">
                  <Button size="sm" variant="primary" onClick={() => void act(s, true)}>Enviar a la bandeja</Button>
                  <Button size="sm" onClick={() => void act(s, false)}>Descartar</Button>
                </span>
              ) : <span className="text-xs text-muted">{s.status === "accepted" ? "En la bandeja del aula" : "Descartada"}</span>}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
