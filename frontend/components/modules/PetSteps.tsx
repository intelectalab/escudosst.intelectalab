"use client";

// Editor de PETS (M-PET SCR-PET-02): tabla paso–riesgo–control con la marca de riesgo significativo (RN-PET-04: necesita
// control), capacitaciones requeridas (RN-PET-03: quedan exigidas para el puesto al aprobar) y filas del IPERC vinculadas
// (RN-PET-05: si se revisa la fila, se revisa el PETS). Solo se edita en borrador; aprobado se muestra como lectura.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";

type Step = { step: string; risk: string; control: string; significant: boolean };
type Data = {
  status: string;
  steps: Step[];
  required_training_ids: string[];
  iperc_row_ids: string[];
  trainings: { id: string; title: string }[];
  iperc_rows: { id: string; row_number: number; task: string; hazard: string | null; risk: string | null; is_significant: boolean; matrix: string }[];
};

export function PetSteps({ versionId, readOnly }: { versionId: string; readOnly: boolean }) {
  const [d, setD] = useState<Data | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => {
    api<{ data: Data }>(`/pet/versions/${versionId}/steps`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null));
  }, [versionId]);
  useEffect(load, [load]);
  if (!d) return null;

  const editable = !readOnly && d.status === "draft";
  const setStep = (i: number, patch: Partial<Step>) => setD({ ...d, steps: d.steps.map((s, k) => (k === i ? { ...s, ...patch } : s)) });
  const toggle = (key: "required_training_ids" | "iperc_row_ids", id: string) =>
    setD({ ...d, [key]: d[key].includes(id) ? d[key].filter((x) => x !== id) : [...d[key], id] });
  const save = () => {
    setBusy(true);
    api<{ data: Data }>(`/pet/versions/${versionId}/steps`, { method: "PUT", withCompany: true,
      body: { steps: d.steps.filter((s) => s.step.trim()), required_training_ids: d.required_training_ids, iperc_row_ids: d.iperc_row_ids } })
      .then((r) => { setD(r.data); setMsg({ text: "Pasos guardados.", ok: true }); })
      .catch((e: ApiError) => setMsg({ text: Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title, ok: false }))
      .finally(() => setBusy(false));
  };

  return (
    <section className="space-y-4 rounded-xl border border-line bg-surface p-4">
      <div>
        <h3 className="font-semibold">Pasos, riesgos y controles</h3>
        <p className="text-sm text-muted">Un paso con riesgo significativo necesita su control antes de enviar el PETS a revisión.</p>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-line text-xs font-semibold text-muted">
            <tr><th className="w-10 px-2 py-2">N.°</th><th className="px-2 py-2">Paso</th><th className="px-2 py-2">Riesgo</th><th className="px-2 py-2">Control</th><th className="px-2 py-2">Significativo</th>{editable && <th className="px-2 py-2"><span className="sr-only">Quitar</span></th>}</tr>
          </thead>
          <tbody>
            {d.steps.length === 0 && <tr><td colSpan={6} className="px-2 py-3 text-muted">Aún no hay pasos.</td></tr>}
            {d.steps.map((s, i) => (
              <tr key={i} className="border-b border-line align-top last:border-0">
                <td className="px-2 py-2 font-semibold">{i + 1}</td>
                {editable ? (
                  <>
                    <td className="px-2 py-2"><textarea className="input min-h-[60px]" aria-label={`Paso ${i + 1}`} value={s.step} onChange={(e) => setStep(i, { step: e.target.value })} /></td>
                    <td className="px-2 py-2"><textarea className="input min-h-[60px]" aria-label={`Riesgo del paso ${i + 1}`} value={s.risk} onChange={(e) => setStep(i, { risk: e.target.value })} /></td>
                    <td className="px-2 py-2"><textarea className={`input min-h-[60px] ${s.significant && !s.control.trim() ? "border-bad" : ""}`} aria-label={`Control del paso ${i + 1}`} value={s.control} onChange={(e) => setStep(i, { control: e.target.value })} /></td>
                    <td className="px-2 py-2"><input type="checkbox" aria-label={`Riesgo significativo del paso ${i + 1}`} checked={s.significant} onChange={(e) => setStep(i, { significant: e.target.checked })} /></td>
                    <td className="px-2 py-2"><button type="button" className="text-sm text-bad hover:underline" onClick={() => setD({ ...d, steps: d.steps.filter((_, k) => k !== i) })}>Quitar</button></td>
                  </>
                ) : (
                  <>
                    <td className="px-2 py-2">{s.step}</td><td className="px-2 py-2">{s.risk || "—"}</td><td className="px-2 py-2">{s.control || "—"}</td><td className="px-2 py-2">{s.significant ? "Sí" : "No"}</td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {editable && <Button size="sm" variant="secondary" icon="plus" onClick={() => setD({ ...d, steps: [...d.steps, { step: "", risk: "", control: "", significant: false }] })}>Agregar paso</Button>}

      <div className="grid gap-4 md:grid-cols-2">
        <fieldset className="space-y-1">
          <legend className="text-sm font-semibold">Capacitaciones requeridas</legend>
          <p className="text-xs text-muted">Al aprobar el PETS quedan exigidas para el puesto del procedimiento.</p>
          <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
            {d.trainings.length === 0 && <p className="text-muted">No hay capacitaciones registradas.</p>}
            {d.trainings.map((t) => (
              <label key={t.id} className="flex items-center gap-2">
                <input type="checkbox" disabled={!editable} checked={d.required_training_ids.includes(t.id)} onChange={() => toggle("required_training_ids", t.id)} /> {t.title}
              </label>
            ))}
          </div>
        </fieldset>
        <fieldset className="space-y-1">
          <legend className="text-sm font-semibold">Filas del IPERC vinculadas</legend>
          <p className="text-xs text-muted">Si una de estas matrices pasa a «requiere revisión», el PETS también debe revisarse.</p>
          <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
            {d.iperc_rows.length === 0 && <p className="text-muted">No hay matrices IPERC vigentes.</p>}
            {d.iperc_rows.map((r) => (
              <label key={r.id} className="flex items-start gap-2">
                <input type="checkbox" className="mt-1" disabled={!editable} checked={d.iperc_row_ids.includes(r.id)} onChange={() => toggle("iperc_row_ids", r.id)} />
                <span>{r.matrix} · fila {r.row_number} · {r.task}{r.hazard ? ` · ${r.hazard}` : ""}{r.is_significant ? <b className="text-bad"> (significativo)</b> : null}</span>
              </label>
            ))}
          </div>
        </fieldset>
      </div>
      {editable && <Button variant="primary" disabled={busy} onClick={save}>Guardar pasos</Button>}
      {msg && <p className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </section>
  );
}
