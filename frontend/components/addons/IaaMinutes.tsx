"use client";

// Asistente (docs/17 IA-04 «Redacción de actas»): desde el acta de la sesión del comité, el asistente arma un borrador con la
// agenda, las notas del secretario, los asistentes por cargo y los acuerdos dictados. Queda como sugerencia: la persona lo
// revisa, lo corrige y lo acepta (se guarda en el acta y se registran los acuerdos) o lo descarta. Si lo redacta la IA, va a la
// cola y se espera el resultado.

import { useState } from "react";
import { useApp } from "@/components/AppContext";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { ApiError } from "@/lib/api";
import { iaa, waitForRequest, type MinutesAgreement, type MinutesPayload, type Suggestion } from "@/lib/iaa";

const msgOf = (e: unknown) => (e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : e instanceof Error ? e.message : "No se pudo completar.");
const fmt = (d: string | null) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString("es-PE") : "");
const line = (a: MinutesAgreement) => [a.description, a.responsible_position ? `responsable: ${a.responsible_position}` : "", fmt(a.due_on)].filter(Boolean).join(" · ");

export function IaaMinutesDraft({ meetingId, onSaved }: { meetingId: string; onSaved: () => void }) {
  const { modules } = useApp();
  const [notes, setNotes] = useState("");
  const [agreements, setAgreements] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ m: string; tone: "ok" | "bad" | "info" } | null>(null);
  const [s, setS] = useState<Suggestion<MinutesPayload> | null>(null);
  const [text, setText] = useState("");
  const [agreed, setAgreed] = useState("");
  if (!modules.some((m) => m.code === "IAA" && m.mode === "enabled" && m.role_can_use)) return null;

  const show = (sug: Suggestion<MinutesPayload>) => {
    setS(sug);
    setText(sug.payload.minutes_text);
    setAgreed(sug.payload.agreements.filter((a) => !a.existing).map(line).join("\n"));
  };
  const draft = async () => {
    setBusy("draft"); setMsg(null); setS(null);
    try {
      const r = await iaa.minutesDraft({ meeting_id: meetingId, notes: notes.trim() || undefined, agreements: agreements.split("\n").map((l) => l.trim()).filter(Boolean) });
      if (r.data.suggestion) show(r.data.suggestion);
      else {
        setMsg({ m: r.data.message ?? "El asistente está redactando el acta.", tone: "info" });
        const done = await waitForRequest(r.data.request_id);
        if (done.status === "completed" && done.suggestions[0]) { show(done.suggestions[0] as unknown as Suggestion<MinutesPayload>); setMsg(null); }
        else setMsg({ m: done.message ?? "El asistente no pudo redactar el acta.", tone: "bad" });
      }
    } catch (e) { setMsg({ m: msgOf(e), tone: "bad" }); } finally { setBusy(null); }
  };
  const accept = async () => {
    if (!s) return;
    setBusy("accept");
    try {
      const original = s.payload.agreements.filter((a) => !a.existing).map(line).join("\n");
      const body: Record<string, unknown> = { minutes_text: text };
      if (agreed.trim() !== original.trim()) body.agreements = agreed.split("\n").map((l) => l.trim()).filter(Boolean).map((l) => l.replace(/ · responsable: /i, " responsable: "));
      const r = await iaa.accept(s.id, body) as { data: { agreements?: number } };
      setMsg({ m: `Borrador guardado en el acta${r.data.agreements ? ` y ${r.data.agreements} acuerdo${r.data.agreements === 1 ? "" : "s"} registrado${r.data.agreements === 1 ? "" : "s"}` : ""}.`, tone: "ok" });
      setS(null);
      onSaved();
    } catch (e) { setMsg({ m: msgOf(e), tone: "bad" }); } finally { setBusy(null); }
  };
  const discard = async () => {
    if (!s) return;
    setBusy("reject");
    try { await iaa.reject(s.id); setS(null); setMsg({ m: "Borrador descartado.", tone: "info" }); } catch (e) { setMsg({ m: msgOf(e), tone: "bad" }); } finally { setBusy(null); }
  };

  return (
    <section className="space-y-3 rounded-xl border border-line p-4 text-sm">
      <div>
        <h4 className="font-semibold">Redactar el acta con el asistente</h4>
        <p className="text-xs text-muted">Usa la agenda, tus notas, los asistentes por cargo (sin nombres) y los acuerdos. No escribas datos de salud. El borrador es una sugerencia: revísalo antes de guardarlo.</p>
      </div>
      {!s && (
        <>
          <FormField label="Notas de la sesión (opcional)" hint="Lo tratado en cada punto de la agenda.">
            <textarea className="input min-h-24" maxLength={8000} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>
          <FormField label="Acuerdos dictados (opcional)" hint="Uno por línea. Ej.: Señalizar el almacén responsable: Jefe de Almacén 15/12/2026">
            <textarea className="input min-h-20" value={agreements} onChange={(e) => setAgreements(e.target.value)} />
          </FormField>
          <Button icon="sparkle" loading={busy === "draft"} onClick={draft}>Redactar borrador</Button>
        </>
      )}
      {s && (
        <div className="space-y-3">
          <p className="rounded-lg bg-amber-soft px-3 py-2 text-xs text-amber-ink">Sugerido por IA. Revisa antes de aceptar.{s.rationale ? ` ${s.rationale}` : ""}</p>
          <FormField label="Borrador del acta">
            <textarea className="input min-h-64 font-mono text-xs" value={text} onChange={(e) => setText(e.target.value)} />
          </FormField>
          {s.payload.quorum_reached === false ? (
            <p className="text-xs text-muted">Sin quórum: no se registran acuerdos.</p>
          ) : (
            <FormField label="Acuerdos que se registrarán" hint="Uno por línea: descripción · responsable: cargo · fecha (dd/mm/aaaa).">
              <textarea className="input min-h-20" value={agreed} onChange={(e) => setAgreed(e.target.value)} />
            </FormField>
          )}
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" loading={busy === "accept"} onClick={accept} disabled={text.trim().length < 20}>Aceptar y guardar en el acta</Button>
            <Button variant="danger-ghost" loading={busy === "reject"} onClick={discard}>Descartar</Button>
          </div>
        </div>
      )}
      {msg && <p className={`rounded-lg px-3 py-2 text-xs ${msg.tone === "ok" ? "bg-ok-soft text-ok" : msg.tone === "bad" ? "bg-bad-soft text-bad" : "bg-bg text-muted"}`}>{msg.m}</p>}
    </section>
  );
}
