"use client";

// Protocolos por puesto (M-EMO SCR-EMO-06), sin datos clínicos: protocolos con sus exámenes y la exposición que los justifica,
// y por puesto: si exige EMO, su protocolo, su periodicidad (RN-EMO-05) y el estado de aptitud de sus trabajadores.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Protocol = { id: string; code: string; name: string; exams: string[]; exposures: string | null; notes: string | null };
type Position = { id: string; code: string | null; name: string; area: string | null; requires_emo: boolean | null; emo_protocol_code: string | null; emo_periodicity_param: string | null;
  periodicity_months: number | null; workers: number; valid: number; due_soon: number; missing: number; unfit: number; exposures: string[];
  mag_exposures?: { agent: string; workers: number }[] };
type Data = { protocols: Protocol[]; positions: Position[]; periodicity: Record<string, number>; notice_days: number };
const blank = { id: "", code: "", name: "", exams: "", exposures: "", notes: "" };

export function EmoProtocols({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState<typeof blank | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => api<{ data: Data }>("/emo/protocols", { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title)), []);
  useEffect(() => { void load(); }, [load]);

  const fail = (e: unknown) => {
    const err = e as ApiError;
    setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) });
  };
  const saveProtocol = async () => {
    if (!form) return;
    setBusy(true);
    setMsg(null);
    try {
      await api("/emo/protocols", { method: "POST", withCompany: true, body: { id: form.id || null, code: form.code, name: form.name,
        exams: form.exams.split("\n").map((x) => x.trim()).filter(Boolean), exposures: form.exposures || null, notes: form.notes || null } });
      setForm(null);
      setMsg({ ok: true, text: "Protocolo guardado." });
      void load();
    } catch (e) { fail(e); } finally { setBusy(false); }
  };
  const savePosition = async (p: Position, patch: Partial<Position>) => {
    setMsg(null);
    const next = { ...p, ...patch };
    try {
      await api(`/emo/positions/${p.id}`, { method: "PATCH", withCompany: true,
        body: { requires_emo: !!next.requires_emo, emo_protocol_code: next.emo_protocol_code || null, emo_periodicity_param: next.emo_periodicity_param || "emo_periodic_months" } });
      void load();
    } catch (e) { fail(e); }
  };

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (!d) return <div className="h-28 animate-pulse rounded-xl bg-surface" />;

  return (
    <div className="space-y-4">
      <Card title="Protocolos de exámenes">
        <p className="mb-3 text-sm text-muted">Cada protocolo indica los exámenes que se hacen al trabajador del puesto. No guarda resultados ni diagnósticos.</p>
        {d.protocols.length === 0 ? <p className="text-sm text-muted">Aún no hay protocolos.</p> : (
          <ul className="space-y-2">
            {d.protocols.map((p) => (
              <li key={p.id} className="rounded-lg border border-line p-3 text-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <span><b>{p.code}</b> · {p.name}</span>
                  {!readOnly && <button type="button" className="text-xs font-semibold text-brand-strong underline"
                    onClick={() => setForm({ id: p.id, code: p.code, name: p.name, exams: p.exams.join("\n"), exposures: p.exposures ?? "", notes: p.notes ?? "" })}>Editar</button>}
                </div>
                <p className="mt-1 text-xs text-muted">Exámenes: {p.exams.join(", ")}</p>
                {p.exposures && <p className="text-xs text-muted">Exposición: {p.exposures}</p>}
              </li>
            ))}
          </ul>
        )}
        {!readOnly && !form && <div className="mt-3"><Button icon="plus" onClick={() => setForm({ ...blank })}>Nuevo protocolo</Button></div>}
        {form && (
          <div className="mt-3 grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-2">
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Código</span>
              <input className="input" value={form.code} maxLength={40} onChange={(e) => setForm({ ...form, code: e.target.value })} /></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Nombre</span>
              <input className="input" value={form.name} maxLength={200} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
            <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Exámenes (uno por línea)</span>
              <textarea className="input min-h-[96px]" value={form.exams} onChange={(e) => setForm({ ...form, exams: e.target.value })} /></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Exposición que lo justifica (opcional)</span>
              <textarea className="input min-h-[64px]" value={form.exposures} onChange={(e) => setForm({ ...form, exposures: e.target.value })} /></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Notas (opcional)</span>
              <textarea className="input min-h-[64px]" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label>
            <div className="flex gap-2 sm:col-span-2">
              <Button variant="primary" loading={busy} disabled={busy} onClick={saveProtocol}>Guardar protocolo</Button>
              <Button variant="ghost" onClick={() => setForm(null)}>Cancelar</Button>
            </div>
          </div>
        )}
        {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      </Card>

      <Card title="Protocolo por puesto">
        <p className="mb-3 text-sm text-muted">
          «Por vencer» = la aptitud vence en {d.notice_days} días o menos. Cada día se crea sola la orden de examen de quien la tenga por vencer o no tenga aptitud, en los puestos que exigen EMO.
        </p>
        <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Protocolo por puesto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs font-semibold text-muted">
              <tr><th className="px-2 py-2">Puesto</th><th className="px-2 py-2">Exige EMO</th><th className="px-2 py-2">Protocolo</th><th className="px-2 py-2">Periodicidad</th>
                <th className="px-2 py-2 text-right">Trab.</th><th className="px-2 py-2 text-right">Vigente</th><th className="px-2 py-2 text-right">Por vencer</th>
                <th className="px-2 py-2 text-right">Vencida o sin EMO</th><th className="px-2 py-2">Exposición en el IPERC</th><th className="px-2 py-2">Medida sobre el límite (MAG)</th></tr>
            </thead>
            <tbody>
              {d.positions.map((p) => (
                <tr key={p.id} className="border-b border-line align-top last:border-0">
                  <td className="px-2 py-2"><b>{p.name}</b>{p.area ? <span className="block text-xs text-muted">{p.area}</span> : null}</td>
                  <td className="px-2 py-2"><input type="checkbox" aria-label={`Exige EMO: ${p.name}`} checked={!!p.requires_emo} disabled={readOnly || (!p.requires_emo && d.protocols.length === 0)}
                    onChange={(e) => savePosition(p, { requires_emo: e.target.checked, emo_protocol_code: p.emo_protocol_code ?? d.protocols[0]?.code ?? null })} /></td>
                  <td className="px-2 py-2">
                    <select className="input min-w-[9rem]" aria-label={`Protocolo: ${p.name}`} value={p.emo_protocol_code ?? ""} disabled={readOnly}
                      onChange={(e) => savePosition(p, { emo_protocol_code: e.target.value || null, requires_emo: e.target.value ? p.requires_emo : false })}>
                      <option value="">—</option>{d.protocols.map((x) => <option key={x.id} value={x.code}>{x.code}</option>)}
                    </select>
                  </td>
                  <td className="px-2 py-2">
                    <select className="input min-w-[10rem]" aria-label={`Periodicidad: ${p.name}`} value={p.emo_periodicity_param ?? "emo_periodic_months"} disabled={readOnly}
                      onChange={(e) => savePosition(p, { emo_periodicity_param: e.target.value })}>
                      <option value="emo_periodic_months">Normal · {d.periodicity.emo_periodic_months} meses</option>
                      <option value="emo_periodic_months_high_risk">Alto riesgo · {d.periodicity.emo_periodic_months_high_risk} meses</option>
                    </select>
                  </td>
                  <td className="px-2 py-2 text-right">{p.workers}</td>
                  <td className="px-2 py-2 text-right text-ok">{p.valid}</td>
                  <td className="px-2 py-2 text-right text-amber-ink">{p.due_soon}</td>
                  <td className={`px-2 py-2 text-right ${p.missing ? "font-semibold text-bad" : ""}`}>{p.missing}</td>
                  <td className="px-2 py-2 text-xs text-muted">{p.exposures.length ? p.exposures.join(", ") : "—"}</td>
                  <td className="px-2 py-2 text-xs">{p.mag_exposures?.length
                    ? p.mag_exposures.map((x) => `${x.agent} (${x.workers} trab.)`).join(", ") : <span className="text-muted">—</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}

type OrderExposure = { agent: string; point: string | null; site: string | null; ratio_to_limit: number | null; value: number | null; unit: string | null;
  personal: boolean; measured_at: string; valid_until: string };
type OrderExposures = { position: string | null; protocol: { code: string; name: string; exams: string[]; exposures: string | null } | null; exposures: OrderExposure[] };

/**
 * SCR-EMO-02 en la ficha de la orden: protocolo del puesto (exámenes incluidos) y exposiciones vigentes del trabajador medidas
 * en MAG sobre el límite (RN-MAG-06). Sin datos clínicos.
 */
export function EmoOrderExposures({ orderId }: { orderId: string }) {
  const [d, setD] = useState<OrderExposures | null>(null);
  useEffect(() => {
    api<{ data: OrderExposures }>(`/emo/orders/${orderId}/exposures`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null));
  }, [orderId]);
  if (!d) return null;
  return (
    <div className="rounded-lg border border-line p-3 text-sm">
      <p className="font-semibold">Protocolo y exposiciones del trabajador</p>
      {d.protocol ? (
        <p className="mt-1 text-xs text-muted">Protocolo {d.protocol.code} · {d.protocol.name}{d.position ? ` (puesto ${d.position})` : ""}: {d.protocol.exams.join(", ")}</p>
      ) : <p className="mt-1 text-xs text-muted">El puesto no tiene protocolo asignado.</p>}
      {d.exposures.length ? (
        <ul className="mt-2 space-y-1">
          {d.exposures.map((e, i) => (
            <li key={i} className="text-xs">
              <b>{e.agent}</b> sobre el límite{e.ratio_to_limit !== null ? ` (${Math.round(e.ratio_to_limit * 100)} %)` : ""} en {e.point ?? "el punto"}{e.site ? ` · ${e.site}` : ""}
              {e.personal ? " · medición personal" : ""} · medido el {new Date(e.measured_at).toLocaleDateString("es-PE")} · vigente hasta {new Date(`${e.valid_until}T12:00:00`).toLocaleDateString("es-PE")}
            </li>
          ))}
        </ul>
      ) : <p className="mt-2 text-xs text-muted">Sin exposiciones vigentes sobre el límite en el monitoreo de agentes.</p>}
    </div>
  );
}
