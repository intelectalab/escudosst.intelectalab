"use client";

// Mi IPERC (M-IPC SCR-IPC-08): las tareas de mi puesto con su peligro, riesgo, nivel (con color) y los controles que debo
// aplicar, en cada matriz vigente; botón «Lo conozco» que firma con PIN la versión vigente.

import { useCallback, useEffect, useState } from "react";
import { Alert, BigButton, Empty, Loading, Pill, SignSheet, Title, type Tone } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

type Control = { hierarchy: string; description: string; is_existing: boolean };
type Row = { activity: string | null; task: string | null; is_routine: boolean | null; hazard: string; risk: string; consequence: string | null; risk_level: string | null;
  is_significant: boolean; residual_level: string | null; controls: Control[] };
type Matrix = { version_id: string; code: string | null; title: string; version_number: number; effective_on: string | null; signed: boolean; signed_at: string | null; rows: Row[] };

const LEVEL: Record<string, [string, Tone]> = {
  trivial: ["Trivial", "ok"], tolerable: ["Tolerable", "ok"], moderate: ["Moderado", "amber"], important: ["Importante", "bad"], intolerable: ["Intolerable", "bad"],
};
const HIERARCHY: Record<string, string> = { elimination: "Eliminación", substitution: "Sustitución", engineering: "Ingeniería", administrative: "Administrativo", signage: "Señalización", ppe: "EPP" };

export default function MyIpercPage() {
  const { reload } = useWorker();
  const [data, setData] = useState<{ position: string | null; matrices: Matrix[] } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sign, setSign] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const load = useCallback(() => workerApi<{ position: string | null; matrices: Matrix[] }>("/iperc").then(setData).catch((e) => setError(errorText(e))), []);
  useEffect(() => {
    void load();
  }, [load]);

  return (
    <>
      <Title sub={data?.position ? `Riesgos de tu puesto: ${data.position}.` : "Los peligros de tu puesto y cómo cuidarte."}>Mi IPERC</Title>
      {done && <Alert tone="ok">Firmaste que conoces los riesgos de tu puesto. ¡Gracias por cuidarte!</Alert>}
      {error && <Alert>{error}</Alert>}
      {!data && !error && <Loading />}
      {data && data.matrices.length === 0 && <Empty text="Aún no hay una matriz IPERC vigente para tu puesto. Consulta al área de SST." />}
      {data?.matrices.map((m) => (
        <section key={m.version_id} className="space-y-3">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <span className="min-w-0">
              <b className="block leading-tight">{m.title}</b>
              <span className="text-sm text-muted">{[m.code, `versión ${m.version_number}`, m.effective_on ? `vigente desde ${fmtDate(m.effective_on)}` : null].filter(Boolean).join(" · ")}</span>
            </span>
            {m.signed ? <Pill tone="ok">Lo conozco · {fmtDate(m.signed_at)}</Pill> : <Pill tone="amber">Falta firmar</Pill>}
          </div>
          <ul className="space-y-3">
            {m.rows.map((r, i) => {
              const lv = LEVEL[r.residual_level ?? r.risk_level ?? ""];
              return (
                <li key={i} className="space-y-2 rounded-2xl border border-line bg-surface p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <b className="leading-tight">{r.task ?? r.activity ?? "Tarea"}</b>
                    {lv && <Pill tone={lv[1]}>{lv[0]}</Pill>}
                  </div>
                  <p className="text-sm"><b>Peligro:</b> {r.hazard}</p>
                  <p className="text-sm"><b>Riesgo:</b> {r.risk}{r.consequence ? ` (${r.consequence})` : ""}</p>
                  {r.is_significant && <p className="text-xs font-semibold text-bad">Riesgo significativo: aplica todos los controles.</p>}
                  {r.controls.length > 0 && (
                    <div>
                      <p className="text-sm font-semibold">Controles que debes aplicar:</p>
                      <ul className="list-disc space-y-0.5 pl-5 text-sm">
                        {r.controls.map((c, j) => <li key={j}>{c.description} <span className="text-xs text-muted">({HIERARCHY[c.hierarchy] ?? c.hierarchy}{c.is_existing ? "" : ", por implementar"})</span></li>)}
                      </ul>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
          {!m.signed && <BigButton onClick={() => setSign(m.version_id)}>Lo conozco</BigButton>}
        </section>
      ))}
      {sign && (
        <SignSheet kind="iperc" id={sign} onClose={() => setSign(null)}
          onSigned={() => {
            setSign(null);
            setDone(true);
            void load();
            void reload();
          }} />
      )}
    </>
  );
}
