"use client";

// Encuesta psicosocial anónima, para responder desde el celular sin cuenta. Un tema por pantalla.
// El documento solo comprueba que eres trabajador de la empresa y que no respondes dos veces: no se guarda.

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

type Q = { scale: string[]; dimensions: { code: string; name: string; items: { id: string; text: string }[] }[] };
type Survey = { title: string; company: string | null; open: boolean; status: string; ends_on: string | null; questions: Q | null };

export function PsiSurveyForm({ path }: { path: string }) {
  const [s, setS] = useState<Survey | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [step, setStep] = useState(-1); // -1 = bienvenida
  const [answers, setAnswers] = useState<Record<string, number>>({});
  const [doc, setDoc] = useState("");
  const [sending, setSending] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    api<{ data: Survey }>(`/public/psi/${path}`)
      .then((r) => setS(r.data))
      .catch((e: ApiError) => setError(e.detail ?? "No pudimos abrir la encuesta."));
  }, [path]);

  if (error && !s) return <Card><p>{error}</p></Card>;
  if (!s) return <Card><p>Abriendo la encuesta…</p></Card>;
  if (done)
    return (
      <Card>
        <p className="text-2xl font-bold text-[#17703e]">¡Gracias por responder!</p>
        <p className="mt-2">Tu respuesta es anónima y ayudará a mejorar el trabajo en {s.company}.</p>
      </Card>
    );
  if (!s.open || !s.questions)
    return (
      <Card>
        <h1 className="text-xl font-bold">{s.title}</h1>
        <p className="mt-2">Esta encuesta {s.status === "draft" ? "todavía no empieza" : "ya cerró"}. Gracias por tu interés.</p>
      </Card>
    );

  const q = s.questions;
  const dims = q.dimensions;
  const last = step === dims.length; // pantalla final: documento y envío
  const current = step >= 0 && !last ? dims[step] : null;
  const complete = (i: number) => dims[i].items.every((it) => answers[it.id] !== undefined);

  async function send() {
    setSending(true);
    setError(null);
    try {
      await api(`/public/psi/${path}`, { method: "POST", body: { document: doc, answers } });
      setDone(true);
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.title) : "No pudimos enviar. Inténtalo de nuevo.");
    } finally {
      setSending(false);
    }
  }

  return (
    <Card>
      <p className="text-xs font-semibold uppercase tracking-wider text-[#1f7fd1]">{s.company}</p>
      <h1 className="text-xl font-bold">{s.title}</h1>
      {step >= 0 && (
        <div className="mt-3 h-2 overflow-hidden rounded-full bg-[#e6ecf3]" aria-hidden="true">
          <div className="h-full bg-[#1f7fd1] transition-all" style={{ width: `${(Math.min(step, dims.length) / dims.length) * 100}%` }} />
        </div>
      )}

      {step === -1 && (
        <div className="mt-4 space-y-3">
          <p>Queremos saber cómo te sientes en tu trabajo. Son 18 preguntas cortas (unos 5 minutos).</p>
          <p className="rounded-xl bg-[#E8F7EE] px-3 py-2 text-[#17703e]">
            <b>Es anónima:</b> nadie en la empresa puede ver quién respondió ni qué respondió cada persona. Solo se ven promedios de grupos de varias personas.
          </p>
          <button onClick={() => setStep(0)} className="w-full rounded-xl bg-[#0d3b6e] py-3 font-semibold text-white">Empezar</button>
        </div>
      )}

      {current && (
        <div className="mt-4 space-y-5">
          <p className="text-sm font-semibold text-[#4a5563]">Tema {step + 1} de {dims.length}: {current.name}</p>
          {current.items.map((it) => (
            <fieldset key={it.id} className="space-y-2">
              <legend className="font-medium">{it.text}</legend>
              <div className="grid grid-cols-5 gap-1">
                {q.scale.map((label, v) => (
                  <button
                    key={v}
                    type="button"
                    onClick={() => setAnswers({ ...answers, [it.id]: v })}
                    aria-pressed={answers[it.id] === v}
                    className={`rounded-lg border px-1 py-2 text-[11px] leading-tight ${answers[it.id] === v ? "border-[#0d3b6e] bg-[#0d3b6e] text-white" : "border-[#d5dde7] bg-white"}`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </fieldset>
          ))}
          <div className="flex gap-2">
            <button onClick={() => setStep(step - 1)} className="flex-1 rounded-xl border border-[#d5dde7] py-3 font-semibold">Atrás</button>
            <button onClick={() => setStep(step + 1)} disabled={!complete(step)} className="flex-1 rounded-xl bg-[#0d3b6e] py-3 font-semibold text-white disabled:opacity-40">
              Siguiente
            </button>
          </div>
        </div>
      )}

      {last && (
        <div className="mt-4 space-y-3">
          <p>Último paso: escribe tu número de documento. Solo sirve para comprobar que trabajas en la empresa y que respondes una vez. <b>No se guarda junto a tus respuestas.</b></p>
          <input value={doc} onChange={(e) => setDoc(e.target.value)} inputMode="numeric" autoComplete="off" placeholder="DNI o carné de extranjería"
            className="w-full rounded-xl border border-[#d5dde7] px-3 py-3 text-lg" />
          {error && <p role="alert" className="rounded-lg bg-[#FDECEB] px-3 py-2 text-[#a3261f]">{error}</p>}
          <div className="flex gap-2">
            <button onClick={() => setStep(step - 1)} className="flex-1 rounded-xl border border-[#d5dde7] py-3 font-semibold">Atrás</button>
            <button onClick={send} disabled={sending || doc.trim().length < 5} className="flex-1 rounded-xl bg-[#17703e] py-3 font-semibold text-white disabled:opacity-40">
              {sending ? "Enviando…" : "Enviar respuestas"}
            </button>
          </div>
        </div>
      )}
    </Card>
  );
}

function Card({ children }: { children: React.ReactNode }) {
  return (
    <main className="min-h-screen bg-[#f2f5f9] px-4 py-6 text-[#14202e]">
      <div className="mx-auto max-w-lg rounded-2xl bg-white p-5 shadow">{children}</div>
      <p className="mt-4 text-center text-xs text-[#6b7685]">EscudoSST · encuesta anónima</p>
    </main>
  );
}
