"use client";

// Trabajo en campo:
//  - AssistedSign: firma asistida en persona (M-FIR RN-FIR-05): el trabajador se identifica con su DNI y PIN o firma con su trazo
//    en la pantalla de quien asiste, que queda como testigo.
//  - TalkSignRound: M-CH5 SCR-CH5-02 «firma asistida uno tras otro» de los asistentes de una charla.
//  - EppQuick: M-EPP SCR-EPP-03 entregar EPP buscando al trabajador por DNI o QR del fotocheck, con sus EPP exigidos y su estado,
//    talla y la firma en el momento.
//  - AtsClose: M-ATS SCR-ATS-04 cierre del ATS con hora de término, observaciones e incidente (enlazado al evento registrado).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { records } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { SignaturePad } from "@/components/addons/SignaturePad";
import { Scanner } from "./IngGate";
import { Card } from "./Summary";

const errText = (e: unknown) => {
  const err = e as ApiError;
  return err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title ?? "No se pudo completar.");
};
type SignBody = { assisted: true; image?: string; dni?: string; pin?: string };

/** Firma asistida: DNI y PIN, o trazo; con la confirmación de que el trabajador está presente. */
export function AssistedSign({ name, busy, label = "Firmar", onSign, onCancel }: { name: string; busy: boolean; label?: string; onSign: (b: SignBody) => void; onCancel?: () => void }) {
  const [image, setImage] = useState<string | null>(null);
  const [dni, setDni] = useState("");
  const [pin, setPin] = useState("");
  const [present, setPresent] = useState(false);
  // El lienzo se reinicia si cambia esta función: debe ser estable.
  const onPad = useCallback((img: string | null) => setImage(img), []);
  const ready = present && (!!image || (dni.length >= 8 && pin.length >= 4));
  return (
    <div className="space-y-2 rounded-lg border border-line bg-bg p-3 text-sm">
      <p className="font-semibold">Firma de {name}</p>
      <p className="text-xs text-muted">El trabajador firma con su trazo aquí o se identifica con su DNI y PIN. Tú quedas como testigo de la «firma asistida en persona».</p>
      <SignaturePad height={140} onChange={onPad} />
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs text-muted">o</span>
        <input className="input !w-36" inputMode="numeric" placeholder="DNI" aria-label="DNI del trabajador" value={dni} onChange={(e) => setDni(e.target.value.replace(/\D/g, "").slice(0, 8))} />
        <input className="input !w-28" type="password" inputMode="numeric" placeholder="PIN" aria-label="PIN del trabajador" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, "").slice(0, 8))} />
      </div>
      <label className="flex items-start gap-2 text-xs">
        <input type="checkbox" checked={present} onChange={(e) => setPresent(e.target.checked)} />
        <span>El trabajador está presente y está de acuerdo con firmar.</span>
      </label>
      <div className="flex gap-2">
        <Button size="sm" variant="primary" loading={busy} disabled={!ready || busy}
          onClick={() => onSign(image && !(dni && pin) ? { assisted: true, image } : { assisted: true, dni, pin, ...(image ? { image } : {}) })}>{label}</Button>
        {onCancel && <Button size="sm" variant="ghost" onClick={onCancel}>Cancelar</Button>}
      </div>
    </div>
  );
}

type Att = { id: string; full_name: string; document_last4: string | null; signed: boolean; signature_method: string | null };

/** SCR-CH5-02: los asistentes firman uno tras otro en la pantalla de quien da la charla. */
export function TalkSignRound({ talkId, onClose }: { talkId: string; onClose: () => void }) {
  const [rows, setRows] = useState<Att[] | null>(null);
  const [skipped, setSkipped] = useState<Set<string>>(new Set());
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => {
    api<{ data: { attendances: Att[] } }>(`/ch5/talks/${talkId}/attendances`, { withCompany: true }).then((r) => setRows(r.data.attendances)).catch((e) => setMsg({ ok: false, text: errText(e) }));
  }, [talkId]);
  useEffect(load, [load]);
  if (!rows) return <div className="h-24 animate-pulse rounded-lg bg-bg" />;
  const signed = rows.filter((r) => r.signed).length;
  const next = rows.find((r) => !r.signed && !skipped.has(r.id));
  const sign = async (a: Att, body: SignBody) => {
    setBusy(true);
    setMsg(null);
    try {
      await api(`/ch5/attendances/${a.id}/sign`, { method: "POST", withCompany: true, body });
      setMsg({ ok: true, text: `Firmó ${a.full_name}.` });
      load();
    } catch (e) { setMsg({ ok: false, text: errText(e) }); } finally { setBusy(false); }
  };
  return (
    <section className="space-y-3 rounded-xl border border-brand/30 bg-surface p-4" aria-label="Firma de asistentes">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-display text-base font-semibold">Firma de asistentes · {signed} de {rows.length}</h3>
        <Button size="sm" variant="ghost" onClick={onClose}>Terminar</Button>
      </div>
      {next ? (
        <>
          <AssistedSign key={next.id} name={next.full_name} busy={busy} label="Firmar y pasar al siguiente" onSign={(b) => void sign(next, b)} />
          <button type="button" className="text-xs text-muted underline" onClick={() => setSkipped((s) => new Set(s).add(next.id))}>No está presente: pasar al siguiente</button>
        </>
      ) : (
        <p className="text-sm text-ok">{signed === rows.length ? "Firmaron todos los asistentes." : "Ya pasaste por todos; quienes faltan pueden firmar desde su app."}</p>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      <ul className="grid gap-1 text-xs sm:grid-cols-2">
        {rows.map((r) => <li key={r.id} className={r.signed ? "text-ok" : skipped.has(r.id) ? "text-muted line-through" : ""}>{r.signed ? "✓" : "•"} {r.full_name}</li>)}
      </ul>
    </section>
  );
}

type Item = { ppe_item_id: string; code: string | null; name: string; quantity: number; mandatory: boolean; state: "ok" | "due_soon" | "expired" | "missing"; until: string | null; pending_signature: boolean; sizes: string[]; stock: number };
type Look = { worker: { id: string; full_name: string; document: string | null; position: string | null; site: string | null }; items: Item[]; notice_days: number };
const STATE: Record<Item["state"], [string, string]> = { ok: ["Vigente", "text-ok"], due_soon: ["Por vencer", "text-amber-ink"], expired: ["Vencido", "text-bad"], missing: ["Faltante", "text-bad"] };
const REASONS: [string, string][] = [["initial", "Dotación inicial"], ["replacement", "Reposición"], ["loss", "Pérdida"], ["damage", "Daño"], ["other", "Otro"]];
const dmy = (s: string) => s.slice(0, 10).split("-").reverse().join("/");

/** SCR-EPP-03: entregar EPP en campo. */
export function EppQuick({ onSaved }: { onSaved?: () => void }) {
  const [code, setCode] = useState("");
  const [look, setLook] = useState<Look | null>(null);
  const [pick, setPick] = useState<Record<string, { size: string; qty: number }>>({});
  const [reason, setReason] = useState("initial");
  const [signing, setSigning] = useState(false);
  const [busy, setBusy] = useState(false);
  const [scan, setScan] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  const find = async (value: string) => {
    if (!value.trim()) return;
    setMsg(null);
    setLook(null);
    setSigning(false);
    try {
      const r = await api<{ data: Look }>(`/epp/quick/lookup?code=${encodeURIComponent(value.trim())}`, { withCompany: true });
      setLook(r.data);
      // Se proponen los obligatorios que faltan, vencieron o están por vencer.
      setPick(Object.fromEntries(r.data.items.filter((i) => i.mandatory && i.state !== "ok" && !i.pending_signature).map((i) => [i.ppe_item_id, { size: "", qty: i.quantity || 1 }])));
      setReason(r.data.items.some((i) => i.state !== "missing") ? "replacement" : "initial");
    } catch (e) { setMsg({ ok: false, text: errText(e) }); }
  };
  const deliver = async (body: SignBody) => {
    if (!look) return;
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { delivered: number } }>("/epp/quick/deliver", {
        method: "POST", withCompany: true,
        body: { worker_id: look.worker.id, delivery_reason: reason, items: Object.entries(pick).map(([id, p]) => ({ ppe_item_id: id, quantity: p.qty, size: p.size || null })), ...body },
      });
      setMsg({ ok: true, text: `Se entregaron ${r.data.delivered} EPP a ${look.worker.full_name}, con su firma.` });
      setLook(null);
      setCode("");
      setPick({});
      setSigning(false);
      onSaved?.();
    } catch (e) { setMsg({ ok: false, text: errText(e) }); } finally { setBusy(false); }
  };
  const chosen = look ? look.items.filter((i) => pick[i.ppe_item_id]) : [];
  const missingSize = chosen.some((i) => i.sizes.length > 0 && !pick[i.ppe_item_id].size);

  return (
    <Card title="Entrega rápida de EPP">
      <div className="flex flex-wrap items-center gap-2">
        <input className="input max-w-xs" inputMode="numeric" placeholder="DNI o código del fotocheck" aria-label="DNI o código del fotocheck" value={code}
          onChange={(e) => setCode(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") void find(code); }} />
        <Button size="sm" onClick={() => void find(code)} disabled={!code.trim()}>Buscar</Button>
        <Button size="sm" variant="ghost" onClick={() => setScan((s) => !s)}>{scan ? "Cerrar cámara" : "Escanear fotocheck"}</Button>
      </div>
      {scan && <div className="mt-2 max-w-sm"><Scanner paused={!!look} onCode={(c) => { setScan(false); setCode(c); void find(c); }} /></div>}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      {look && (
        <div className="mt-3 space-y-3 text-sm">
          <p><b>{look.worker.full_name}</b> <span className="text-muted">{[look.worker.document, look.worker.position, look.worker.site].filter(Boolean).join(" · ")}</span></p>
          {look.items.length === 0 ? <p className="text-muted">Su puesto no tiene EPP exigidos registrados.</p> : (
            <table className="w-full text-left">
              <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1" /><th>EPP</th><th>Estado</th><th>Talla</th><th className="text-right">Cant.</th><th className="text-right">Stock</th></tr></thead>
              <tbody>{look.items.map((i) => {
                const p = pick[i.ppe_item_id];
                return (
                  <tr key={i.ppe_item_id} className="border-b border-line last:border-0">
                    <td className="py-1.5"><input type="checkbox" aria-label={`Entregar ${i.name}`} checked={!!p} disabled={i.pending_signature}
                      onChange={(e) => setPick((x) => { const n = { ...x }; if (e.target.checked) n[i.ppe_item_id] = { size: "", qty: i.quantity || 1 }; else delete n[i.ppe_item_id]; return n; })} /></td>
                    <td>{i.name}{!i.mandatory && <span className="text-xs text-muted"> (opcional)</span>}</td>
                    <td className={STATE[i.state][1]}>{i.pending_signature ? <span className="text-muted">Pendiente de firma</span> : STATE[i.state][0]}{i.until && i.state !== "missing" ? <span className="block text-xs text-muted">hasta {dmy(i.until)}</span> : null}</td>
                    <td>{i.sizes.length > 0 && p ? (
                      <select className="input !h-8 !w-24 !py-0 text-xs" aria-label={`Talla de ${i.name}`} value={p.size} onChange={(e) => setPick({ ...pick, [i.ppe_item_id]: { ...p, size: e.target.value } })}>
                        <option value="">Talla…</option>{i.sizes.map((s) => <option key={s} value={s}>{s}</option>)}
                      </select>) : "—"}</td>
                    <td className="text-right">{p ? <input type="number" min={1} max={100} className="input !h-8 !w-16 !py-0 text-right text-xs" aria-label={`Cantidad de ${i.name}`} value={p.qty}
                      onChange={(e) => setPick({ ...pick, [i.ppe_item_id]: { ...p, qty: Math.max(1, Number(e.target.value) || 1) } })} /> : ""}</td>
                    <td className={`text-right ${i.stock <= 0 ? "text-bad" : ""}`}>{i.stock}</td>
                  </tr>
                );
              })}</tbody>
            </table>
          )}
          <p className="text-xs text-muted">«Por vencer»: vence en los próximos {look.notice_days} días.</p>
          {chosen.length > 0 && (
            <div className="flex flex-wrap items-center gap-2">
              <select className="input !w-auto" aria-label="Motivo de la entrega" value={reason} onChange={(e) => setReason(e.target.value)}>
                {REASONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
              {!signing && <Button variant="primary" disabled={missingSize} onClick={() => setSigning(true)}>Entregar {chosen.length} EPP</Button>}
              {missingSize && <span className="text-xs text-bad">Elige la talla.</span>}
            </div>
          )}
          {signing && <AssistedSign name={look.worker.full_name} busy={busy} label="Firmar la recepción" onSign={(b) => void deliver(b)} onCancel={() => setSigning(false)} />}
        </div>
      )}
    </Card>
  );
}

type AtsOpt = { id: string; code: string | null; task: string; status: string };

/** SCR-ATS-04: cierre del ATS en ejecución (o «no cerrado») con hora de término, observaciones e incidente. */
export function AtsClose({ onSaved }: { onSaved: () => void }) {
  const [forms, setForms] = useState<AtsOpt[]>([]);
  const [id, setId] = useState("");
  const [end, setEnd] = useState("");
  const [notes, setNotes] = useState("");
  const [incident, setIncident] = useState(false);
  const [events, setEvents] = useState<{ id: string; label: string }[]>([]);
  const [event, setEvent] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [v, setV] = useState(0);
  useEffect(() => {
    api<{ data: AtsOpt[] }>("/records/ATS/ats_forms?per_page=100", { withCompany: true })
      .then((r) => setForms((r.data ?? []).filter((f) => f.status === "in_execution" || f.status === "not_closed"))).catch(() => setForms([]));
  }, [v]);
  useEffect(() => {
    if (incident) records.options("ATS", "ats_forms", "incident_event_id", {}).then((r) => setEvents(r.data)).catch(() => setEvents([]));
  }, [incident]);
  if (forms.length === 0 && !msg) return null;
  const close = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await api(`/records/ATS/ats_forms/${id}`, {
        method: "PATCH", withCompany: true,
        body: { status: "closed", closed_at: end ? new Date(end).toISOString() : null, closing_notes: notes || null, incident_occurred: incident, incident_event_id: incident ? event || null : null },
      });
      setMsg({ ok: true, text: "ATS cerrado." });
      setId(""); setEnd(""); setNotes(""); setIncident(false); setEvent("");
      setV((x) => x + 1);
      onSaved();
    } catch (e) { setMsg({ ok: false, text: errText(e) }); } finally { setBusy(false); }
  };
  return (
    <Card title="Cerrar ATS">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">ATS en ejecución</span>
          <select className="input" value={id} onChange={(e) => setId(e.target.value)}>
            <option value="">Elige el ATS…</option>{forms.map((f) => <option key={f.id} value={f.id}>{f.code ? `${f.code} · ` : ""}{f.task}{f.status === "not_closed" ? " (no cerrado)" : ""}</option>)}
          </select></label>
        <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Hora de término</span>
          <input type="datetime-local" className="input" value={end} onChange={(e) => setEnd(e.target.value)} /><span className="mt-1 block text-xs text-muted">Vacía: ahora.</span></label>
        <label className="flex items-center gap-2 self-center text-sm"><input type="checkbox" checked={incident} onChange={(e) => setIncident(e.target.checked)} /> Ocurrió un incidente durante la tarea</label>
        {incident && (
          <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Evento registrado en «Accidentes e incidentes»</span>
            <select className="input" value={event} onChange={(e) => setEvent(e.target.value)}>
              <option value="">Elige el evento…</option>{events.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
            </select></label>
        )}
        <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Observaciones</span>
          <textarea className="input min-h-[64px]" value={notes} onChange={(e) => setNotes(e.target.value)} /></label>
      </div>
      <div className="mt-3"><Button variant="primary" loading={busy} disabled={!id || busy || (incident && !event)} onClick={() => void close()}>Cerrar ATS</Button></div>
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </Card>
  );
}
