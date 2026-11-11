"use client";

// Mis resultados médicos (M-EMO SCR-EMO-07; docs/05 §4.3, docs/10 §4): sin clave ves solo tu aptitud y tu próxima
// cita; tus resultados se abren con tu PIN y la clave dura unos minutos. Solo tú y el médico ocupacional los ven.

import { useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { Alert, BigButton, Loading, Panel, PinField, Pill, Title, type Tone } from "@/components/worker/WorkerUi";
import { errorText, FITNESS, fmtDate, openProtectedFile, workerApi } from "@/lib/workerApp";

type Summary = {
  fitness: { status: string; valid_from: string | null; valid_until: string | null; work_restrictions: string | null } | null;
  next_exam: { exam_type: string; scheduled_at: string | null; clinic_name: string | null } | null;
  last_exam: { exam_type: string; at: string | null } | null;
  reauth_minutes: number;
};
type Exam = {
  id: string; exam_type: string; exam_on: string; clinic_name: string | null; examining_doctor_name: string | null;
  results: { exam: string; result: string }[]; observations: string | null; diagnoses: { code: string; description: string }[];
  recommendations: string | null; fitness_result: string; work_restrictions: string | null; fitness_valid_until: string | null;
  files: { id: string; file_kind: string; name: string }[];
};

const EXAM: Record<string, string> = { pre_employment: "Preocupacional", periodic: "Periódico", exit: "De retiro", return_to_work: "Reintegro", position_change: "Cambio de puesto", other: "Otro" };
const TONE: Record<string, Tone> = { fit: "ok", fit_with_restrictions: "amber", unfit: "bad", pending: "na", expired: "bad" };

export default function MyHealthPage() {
  const [s, setS] = useState<Summary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pin, setPin] = useState("");
  const [asking, setAsking] = useState(false);
  const [pinError, setPinError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [reauth, setReauth] = useState<string | null>(null);
  const [exams, setExams] = useState<Exam[] | null>(null);

  useEffect(() => {
    workerApi<Summary>("/health").then(setS).catch((e) => setError(errorText(e)));
  }, []);

  async function unlock() {
    setBusy(true);
    setPinError(null);
    try {
      const r = await workerApi<{ reauth_id: string }>("/health/reauth", { method: "POST", body: { pin } });
      const list = await workerApi<{ exams: Exam[] }>("/health/results", { headers: { "X-Medical-Reauth": r.reauth_id } });
      setReauth(r.reauth_id);
      setExams(list.exams);
      setAsking(false);
    } catch (e) {
      setPinError(errorText(e));
    } finally {
      setPin("");
      setBusy(false);
    }
  }

  if (error) return <Alert>{error}</Alert>;
  if (!s) return <Loading />;
  const f = s.fitness;

  return (
    <>
      <Title sub="Solo tú y el médico ocupacional pueden ver tus resultados.">Mis resultados médicos</Title>
      <Panel title="Mi aptitud">
        {f ? (
          <div className="space-y-1">
            <Pill tone={TONE[f.status] ?? "na"}>{FITNESS[f.status] ?? f.status}</Pill>
            {f.valid_until && <p className="text-sm">Vigente hasta el {fmtDate(f.valid_until)}</p>}
            {f.work_restrictions && <p className="rounded-xl bg-amber-soft px-3 py-2 text-sm text-amber-ink">Restricción: {f.work_restrictions}</p>}
          </div>
        ) : <p className="text-sm text-muted">Aún no tienes aptitud registrada.</p>}
        {s.last_exam && <p className="text-sm text-muted">Último examen: {EXAM[s.last_exam.exam_type] ?? s.last_exam.exam_type} · {fmtDate(s.last_exam.at)}</p>}
      </Panel>
      <Panel title="Mi próximo examen">
        {s.next_exam ? (
          <p className="text-sm">{EXAM[s.next_exam.exam_type] ?? s.next_exam.exam_type} · {fmtDate(s.next_exam.scheduled_at, true)}{s.next_exam.clinic_name ? ` · ${s.next_exam.clinic_name}` : ""}</p>
        ) : <p className="text-sm text-muted">No tienes un examen programado. Te avisaremos cuando se programe.</p>}
      </Panel>

      {!exams && !asking && (
        <BigButton onClick={() => setAsking(true)}><Icon name="lock" size={18} /> Ver mis resultados</BigButton>
      )}
      {!exams && asking && (
        <Panel title="Confirma que eres tú">
          <PinField id="health-pin" label="Tu PIN" value={pin} onChange={setPin} error={pinError} autoFocus />
          <BigButton onClick={unlock} loading={busy} disabled={pin.length < 4}>Ver</BigButton>
          <p className="text-xs text-muted">Tu acceso queda registrado. La clave dura {s.reauth_minutes} minutos.</p>
        </Panel>
      )}
      {exams && exams.length === 0 && <Alert tone="na">Aún no hay resultados revisados por el médico.</Alert>}
      {exams?.map((e) => (
        <Panel key={e.id} title={`${EXAM[e.exam_type] ?? e.exam_type} · ${fmtDate(e.exam_on)}`}>
          {e.clinic_name && <p className="text-sm text-muted">{e.clinic_name}{e.examining_doctor_name ? ` · ${e.examining_doctor_name}` : ""}</p>}
          {e.results.length > 0 && (
            <dl className="divide-y divide-line text-sm">
              {e.results.map((r, i) => (
                <div key={i} className="flex justify-between gap-3 py-1.5"><dt className="text-muted">{r.exam}</dt><dd className="text-right font-medium">{r.result}</dd></div>
              ))}
            </dl>
          )}
          {e.diagnoses.length > 0 && <p className="text-sm"><b>Diagnóstico:</b> {e.diagnoses.map((d) => d.description).join("; ")}</p>}
          {e.observations && <p className="text-sm"><b>Observaciones:</b> {e.observations}</p>}
          {e.recommendations && <p className="text-sm"><b>Recomendación:</b> {e.recommendations}</p>}
          <p className="text-sm"><b>Aptitud:</b> {FITNESS[e.fitness_result] ?? e.fitness_result}{e.work_restrictions ? ` · ${e.work_restrictions}` : ""}</p>
          {reauth && e.files.map((file) => (
            <BigButton key={file.id} variant="secondary" onClick={() => openProtectedFile(`/me/worker/health/files/${file.id}`, { "X-Medical-Reauth": reauth }).catch((x) => setError(errorText(x)))}>
              <Icon name="download" size={18} /> {file.name}
            </BigButton>
          ))}
        </Panel>
      ))}
    </>
  );
}
