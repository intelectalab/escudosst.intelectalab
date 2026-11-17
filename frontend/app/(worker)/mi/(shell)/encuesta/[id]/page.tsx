"use client";

// Encuesta psicosocial en la app del trabajador (M-PSI SCR-PSI-04): preguntas en tarjetas y aviso de anonimato. La respuesta no
// guarda quién respondió (RN-PSI-01); el sistema solo evita que se responda dos veces.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert, BigButton, Loading, Title } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

type Survey = { id: string; title: string; ends_on: string | null; answered: boolean;
  questions: { scale: string[]; dimensions: { code: string; name: string; items: { id: string; text: string }[] }[] } | null };

export default function SurveyPage() {
  const { id } = useParams<{ id: string }>();
  const { reload } = useWorker();
  const [s, setS] = useState<Survey | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [ans, setAns] = useState<Record<string, number>>({});
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  useEffect(() => { workerApi<Survey>(`/surveys/${id}`).then(setS).catch((e) => setError(errorText(e))); }, [id]);
  if (error && !s) return <Alert>{error}</Alert>;
  if (!s) return <Loading />;
  if (s.answered || done) return (
    <div className="space-y-4"><Title>{s.title}</Title><Alert tone="ok">Gracias. Tu respuesta quedó registrada de forma anónima.</Alert><Link href="/mi" className="text-sm font-semibold text-brand-strong underline">Volver al inicio</Link></div>
  );
  const items = s.questions?.dimensions.flatMap((d) => d.items) ?? [];
  const send = async () => {
    setBusy(true);
    setError(null);
    try { await workerApi(`/surveys/${id}`, { method: "POST", body: { answers: ans } }); setDone(true); reload(); } catch (e) { setError(errorText(e)); } finally { setBusy(false); }
  };
  return (
    <div className="space-y-4">
      <Title sub={s.ends_on ? `Disponible hasta el ${fmtDate(s.ends_on)}` : undefined}>{s.title}</Title>
      <Alert tone="ok">Es anónima: no se guarda quién respondió. Solo se ven resultados por grupo cuando hay suficientes respuestas.</Alert>
      {s.questions?.dimensions.map((d) => (
        <section key={d.code} className="space-y-2">
          <h2 className="text-sm font-semibold text-muted">{d.name}</h2>
          {d.items.map((it) => (
            <div key={it.id} className="rounded-2xl border border-line bg-surface p-3">
              <p className="mb-2 text-sm font-semibold">{it.text}</p>
              <div className="grid grid-cols-5 gap-1">
                {s.questions!.scale.map((label, v) => (
                  <button key={v} type="button" onClick={() => setAns({ ...ans, [it.id]: v })} aria-pressed={ans[it.id] === v}
                    className={`rounded-lg border px-1 py-2 text-[11px] leading-tight ${ans[it.id] === v ? "border-brand bg-brand text-white" : "border-line"}`}>{label}</button>
                ))}
              </div>
            </div>
          ))}
        </section>
      ))}
      {error && <Alert>{error}</Alert>}
      <BigButton loading={busy} disabled={items.some((it) => ans[it.id] === undefined)} onClick={send}>Enviar respuestas</BigButton>
    </div>
  );
}
