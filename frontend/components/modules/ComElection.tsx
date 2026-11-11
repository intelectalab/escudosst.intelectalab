"use client";

// Elección electrónica de los representantes de los trabajadores (M-COM SCR-COM-02..06; prototipo m-personas.js
// «eleccion»): pasos Convocatoria → Inscripción de candidatos → Votación → Escrutinio → Acta, padrón con exclusión
// justificada, candidaturas con requisitos, cédula de votación secreta (el trabajador con sesión), participación en
// vivo, escrutinio con titulares, suplentes y desempate, y el acta para imprimir o guardar en PDF.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { VoteCredentialsModal } from "./ComVoteLinks";
import { Toast } from "@/components/ui/Toast";
import { Bar, Card, Chip, type Tone } from "./Summary";

type Req = { label: string; ok: boolean; blocking: boolean; detail: string };
type Candidate = {
  id: string; worker_id: string; name: string; position: string | null; list_name: string | null; status: string;
  rejection_reason: string | null; votes_count: number | null; requirements: Req[] | null;
};
type Ranking = { candidate_id: string; worker_id: string; name: string; votes: number; result: string; position: number };
type Results = { total: number; blank: number; null: number; valid: number; eligible: number; participation_pct: number | null; tie_break_rule: string; ties: { note: string }[]; ranking: Ranking[] };
export type Election = {
  id: string; title: string; period_label: string | null; status: string; step: number; seats_titular: number; seats_alternate: number;
  tie_break_rule: "seniority" | "lottery"; allow_blank: boolean; electoral_board: { name: string; role?: string }[];
  convened_at: string | null; nominations_open_at: string | null; nominations_close_at: string | null; voting_opens_at: string | null; voting_closes_at: string | null;
  roll_snapshot_at: string | null; results_published_at: string | null; annulled_reason: string | null; committee_id: string | null;
  roll: { total: number; excluded: number }; eligible_voters: number; votes_cast: number; participation_pct: number | null;
  candidates: Candidate[]; results: Results | null;
  can: { manage: boolean; close: boolean; vote: boolean; apply: boolean; see_roll: boolean };
  me: { linked: boolean; on_roll: boolean; has_voted: boolean; candidacy: { status: string; rejection_reason: string | null } | null };
};
type RollRow = { id: string; worker_id: string; full_name: string; document_last4: string | null; position: string | null; excluded: boolean; exclusion_reason: string | null; has_voted: boolean };

const STEPS = ["Convocatoria", "Inscripción de candidatos", "Votación", "Escrutinio", "Acta"];
const CAND: Record<string, [string, Tone]> = {
  nominated: ["Por revisar", "warn"], accepted: ["Habilitado", "ok"], rejected: ["Observado", "bad"], withdrawn: ["Retirado", "na"],
  elected_titular: ["Titular", "ok"], elected_alternate: ["Suplente", "ok"], not_elected: ["No elegido", "na"],
};
const RESULT: Record<string, string> = { elected_titular: "Titular", elected_alternate: "Suplente", not_elected: "No elegido" };

export const fmtDateTime = (v: string | null | undefined) =>
  v ? new Date(v).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";
const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join("").toUpperCase();
const toLocalInput = (iso: string | null) => {
  const d = iso ? new Date(iso) : new Date(Date.now() + 2 * 86400000);
  const p = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
};

function Stepper({ step }: { step: number }) {
  return (
    <ol className="flex flex-wrap items-center gap-2 text-sm" aria-label="Pasos de la elección">
      {STEPS.map((s, i) => {
        const done = i < step;
        const cur = i === step;
        return (
          <li key={s} className="flex items-center gap-2">
            {i > 0 && <span className="h-px w-5 bg-line" aria-hidden="true" />}
            <span className={`grid h-7 w-7 place-items-center rounded-full text-xs font-bold ${done ? "bg-ok text-white" : cur ? "bg-brand text-white" : "bg-bg text-muted"}`}>{done ? "✓" : i + 1}</span>
            <span className={cur ? "font-semibold" : done ? "" : "text-muted"} aria-current={cur ? "step" : undefined}>{s}</span>
          </li>
        );
      })}
    </ol>
  );
}

function Avatar({ name }: { name: string }) {
  return <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-soft text-sm font-bold text-brand-strong" aria-hidden="true">{initials(name)}</span>;
}

export function ComElection({ readOnly, canCreate }: { readOnly?: boolean; canCreate: boolean }) {
  const [list, setList] = useState<{ id: string; status: string }[] | null>(null);
  const [e, setE] = useState<Election | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [toast, setToast] = useState<{ m: string; tone?: "ok" | "bad" } | null>(null);
  const [modal, setModal] = useState<null | "new" | "roll" | "candidate" | "voting" | "cancel" | "close" | "credentials">(null);

  const load = useCallback(async () => {
    try {
      const r = await api<{ data: { id: string; status: string }[] }>("/com/elections", { withCompany: true });
      setList(r.data);
      const cur = r.data[0];
      setE(cur ? (await api<{ data: Election }>(`/com/elections/${cur.id}`, { withCompany: true })).data : null);
    } catch (err) {
      setError((err as ApiError).detail ?? (err as ApiError).title);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);
  // Participación en vivo mientras la votación está abierta.
  useEffect(() => {
    if (e?.status !== "voting_open") return;
    const t = setInterval(() => {
      api<{ data: Election }>(`/com/elections/${e.id}`, { withCompany: true }).then((r) => setE(r.data)).catch(() => undefined);
    }, 15000);
    return () => clearInterval(t);
  }, [e?.id, e?.status]);

  const act = async (path: string, body: unknown, ok: string) => {
    if (!e) return;
    setBusy(path);
    try {
      const r = await api<{ data: Election }>(`/com/elections/${e.id}/${path}`, { method: "POST", body, withCompany: true });
      setE(r.data);
      setToast({ m: ok });
      setModal(null);
      return true;
    } catch (err) {
      setToast({ m: (err as ApiError).detail ?? (err as ApiError).title, tone: "bad" });
      return false;
    } finally {
      setBusy(null);
    }
  };

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (!list) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;

  const finished = !e || ["results_published", "cancelled", "annulled"].includes(e.status);
  const manage = !!e?.can.manage && !readOnly;

  return (
    <div className="space-y-4">
      {(!e || finished) && (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h3 className="font-display text-base font-semibold">{e ? "Nueva elección" : "Aún no hay elecciones"}</h3>
              <p className="text-sm text-muted">
                Convoca la elección de los representantes de los trabajadores: padrón, candidaturas, voto secreto desde la app y acta automática.
              </p>
            </div>
            {canCreate && !readOnly && (
              <Button variant="primary" icon="plus" onClick={() => setModal("new")}>
                Convocar elección
              </Button>
            )}
          </div>
        </Card>
      )}

      {e && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h3 className="font-display text-lg font-semibold">{e.title}</h3>
              <p className="text-sm text-muted">
                {e.seats_titular} titular{e.seats_titular === 1 ? "" : "es"} y {e.seats_alternate} suplente{e.seats_alternate === 1 ? "" : "s"} · desempate por{" "}
                {e.tie_break_rule === "seniority" ? "mayor antigüedad" : "sorteo registrado"}
              </p>
            </div>
            {manage && e.step >= 0 && e.step <= 2 && (
              <Button variant="danger-ghost" size="sm" onClick={() => setModal("cancel")}>
                {e.status === "voting_open" ? "Anular elección" : "Cancelar elección"}
              </Button>
            )}
          </div>
          {e.step >= 0 ? (
            <Stepper step={e.step} />
          ) : (
            <p role="status" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">
              Elección {e.status === "annulled" ? "anulada" : "cancelada"}{e.annulled_reason ? `: ${e.annulled_reason}` : ""}.
            </p>
          )}

          {e.step === 0 && <Convocation e={e} />}
          {e.step === 1 && (
            <Candidates
              e={e}
              manage={manage}
              busy={busy}
              onReview={(cid, decision, reason) => act(`candidates/${cid}/review`, { decision, reason }, decision === "accept" ? "Candidato habilitado" : "Candidatura observada")}
              onApply={() => act("apply", {}, "Postulación registrada: la junta electoral la revisará")}
              readOnly={readOnly}
            />
          )}
          {e.step === 2 && (
            <div className="grid gap-4 lg:grid-cols-2">
              {e.can.vote && !readOnly ? <Ballot election={e} onVoted={load} /> : <Card title="Cédula de votación"><p className="text-sm text-muted">Tu rol no vota en esta elección.</p></Card>}
              <Participation e={e} />
            </div>
          )}
          {e.step >= 3 && e.results && <Scrutiny e={e} />}
          {e.step === 4 && (
            <p role="status" className="rounded-lg bg-ok-soft px-4 py-3 text-sm text-ok">
              Resultados publicados el {fmtDateTime(e.results_published_at)}. Los elegidos se registraron en el comité con la vigencia del mandato.
            </p>
          )}

          {e.can.see_roll && e.step >= 0 && e.step <= 4 && e.roll.total > 0 && (
            <p className="text-sm text-muted">
              Padrón: <b className="text-fg">{e.roll.total - e.roll.excluded}</b> electores habilitados{e.roll.excluded ? `, ${e.roll.excluded} excluidos con motivo` : ""}
              {e.roll_snapshot_at ? ` · congelado el ${fmtDateTime(e.roll_snapshot_at)}` : " · se congela al abrir la votación"}.{" "}
              <button className="font-semibold text-brand hover:underline" onClick={() => setModal("roll")}>
                Ver padrón
              </button>
            </p>
          )}

          <div className="flex flex-wrap gap-2">
            {manage && e.step === 0 && (
              <Button variant="primary" loading={busy === "open-nominations"} onClick={() => act("open-nominations", {}, "Inscripción abierta: se avisó a los trabajadores del padrón")}>
                Abrir inscripción
              </Button>
            )}
            {manage && e.step === 1 && (
              <>
                <Button icon="plus" onClick={() => setModal("candidate")}>
                  Inscribir candidato
                </Button>
                <Button variant="primary" onClick={() => setModal("voting")}>
                  Abrir votación
                </Button>
              </>
            )}
            {manage && (e.step === 1 || e.step === 2) && (
              <Button icon="key" onClick={() => setModal("credentials")}>
                Credenciales de voto
              </Button>
            )}
            {e.can.close && !readOnly && e.step === 2 && (
              <Button variant="primary" onClick={() => setModal("close")}>
                Cerrar votación y escrutar
              </Button>
            )}
            {manage && e.step === 3 && (
              <Button variant="primary" loading={busy === "publish"} onClick={() => act("publish", {}, "Acta generada y elegidos registrados en el comité")}>
                Generar acta
              </Button>
            )}
            {e.step >= 3 && (
              <a href={`/imprimir/acta-eleccion/${e.id}`} target="_blank" rel="noreferrer" className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand">
                {e.step === 4 ? "Descargar acta en PDF" : "Vista previa del acta"}
              </a>
            )}
          </div>
        </>
      )}

      {modal === "new" && <NewElection onClose={() => setModal(null)} onDone={(m) => { setModal(null); setToast({ m }); load(); }} />}
      {modal === "credentials" && e && <VoteCredentialsModal electionId={e.id} onClose={() => { setModal(null); load(); }} />}
      {modal === "roll" && e && <RollModal e={e} editable={manage && e.step <= 1} onClose={() => { setModal(null); load(); }} />}
      {modal === "candidate" && e && <AddCandidate e={e} onClose={() => setModal(null)} onAdd={(wid) => act("candidates", { worker_id: wid }, "Candidato inscrito: revisa sus requisitos")} />}
      {modal === "voting" && e && <OpenVoting e={e} busy={busy === "open-voting"} onClose={() => setModal(null)} onOpen={(closes) => act("open-voting", { voting_closes_at: closes }, "Votación abierta: se avisó a los electores")} />}
      {modal === "close" && e && (
        <Modal title="Cerrar votación y escrutar" onClose={() => setModal(null)} size="sm"
          footer={<>
            <Button onClick={() => setModal(null)}>Volver</Button>
            <Button variant="primary" loading={busy === "close"} onClick={() => act("close", {}, "Votación cerrada y escrutada")}>Cerrar y escrutar</Button>
          </>}>
          <p className="text-sm">Han votado <b>{e.votes_cast}</b> de {e.eligible_voters} electores. Al cerrar ya nadie podrá votar y se calculan titulares y suplentes.</p>
        </Modal>
      )}
      {modal === "cancel" && e && <ReasonModal title={e.status === "voting_open" ? "Anular elección" : "Cancelar elección"} hint={e.status === "voting_open" ? "Los votos se conservan, sin efecto." : undefined}
        onClose={() => setModal(null)} onConfirm={(reason) => act("cancel", { reason }, "Elección cerrada sin efecto")} />}
      {toast && <Toast message={toast.m} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function Convocation({ e }: { e: Election }) {
  const rows: [string, string][] = [
    ["Convocada", fmtDateTime(e.convened_at)],
    ["Cierre de inscripción", fmtDateTime(e.nominations_close_at)],
    ["Votación", `${fmtDateTime(e.voting_opens_at)} a ${fmtDateTime(e.voting_closes_at)}`],
  ];
  return (
    <Card title="Convocatoria">
      <dl className="grid gap-2 text-sm sm:grid-cols-3">
        {rows.map(([k, v]) => (
          <div key={k}>
            <dt className="text-muted">{k}</dt>
            <dd className="font-medium">{v}</dd>
          </div>
        ))}
      </dl>
      {e.electoral_board.length > 0 && (
        <p className="mt-3 text-sm">
          <span className="text-muted">Junta electoral:</span> {e.electoral_board.map((b) => (b.role ? `${b.name} (${b.role})` : b.name)).join(", ")}
        </p>
      )}
      <p className="mt-3 text-sm text-muted">Al abrir la inscripción se arma el padrón con los trabajadores activos y se avisa a todos en la app.</p>
    </Card>
  );
}

function Candidates({ e, manage, busy, onReview, onApply, readOnly }: {
  e: Election; manage: boolean; busy: string | null; readOnly?: boolean;
  onReview: (cid: string, decision: "accept" | "reject", reason?: string) => void; onApply: () => void;
}) {
  const [observe, setObserve] = useState<Candidate | null>(null);
  const canApply = e.can.apply && !readOnly && e.status === "nominations_open" && e.me.linked && e.me.on_roll && (!e.me.candidacy || e.me.candidacy.status === "rejected");
  return (
    <Card
      title={`Candidatos inscritos (${e.candidates.length})`}
      action={canApply && <Button variant="primary" size="sm" loading={busy === "apply"} onClick={onApply}>{e.me.candidacy ? "Subsanar y volver a postular" : "Postular como candidato"}</Button>}
    >
      {e.me.candidacy && (
        <p className={`mb-3 rounded-lg px-3 py-2 text-sm ${e.me.candidacy.status === "rejected" ? "bg-bad-soft text-bad" : "bg-brand-soft text-brand-strong"}`}>
          Tu candidatura: {CAND[e.me.candidacy.status]?.[0] ?? e.me.candidacy.status}
          {e.me.candidacy.rejection_reason ? ` · ${e.me.candidacy.rejection_reason}` : ""}
        </p>
      )}
      {e.candidates.length === 0 ? (
        <p className="text-sm text-muted">Aún no hay candidatos. Los trabajadores postulan desde la app o la junta los inscribe.</p>
      ) : (
        <ul className="divide-y divide-line">
          {e.candidates.map((c) => (
            <li key={c.id} className="flex flex-wrap items-start gap-3 py-3">
              <Avatar name={c.name} />
              <div className="min-w-0 flex-1">
                <p className="font-semibold">
                  {c.name} {c.list_name && <span className="text-sm font-normal text-muted">· {c.list_name}</span>}
                </p>
                <p className="text-xs text-muted">{c.position ?? "Sin puesto registrado"}</p>
                {c.requirements && (
                  <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {c.requirements.map((r) => (
                      <Chip key={r.label} tone={r.ok ? "ok" : r.blocking ? "bad" : "warn"}>
                        <span title={r.detail}>{r.label}</span>
                      </Chip>
                    ))}
                  </div>
                )}
                {c.rejection_reason && <p className="mt-1 text-xs text-bad">Observación: {c.rejection_reason}</p>}
              </div>
              <div className="flex items-center gap-2">
                <Chip tone={CAND[c.status]?.[1] ?? "na"}>{CAND[c.status]?.[0] ?? c.status}</Chip>
                {manage && c.status !== "accepted" && (
                  <Button size="sm" variant="soft" loading={busy === `candidates/${c.id}/review`} onClick={() => onReview(c.id, "accept")}>
                    Habilitar
                  </Button>
                )}
                {manage && c.status !== "rejected" && (
                  <Button size="sm" variant="ghost" onClick={() => setObserve(c)}>
                    Observar
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {observe && (
        <ReasonModal title={`Observar a ${observe.name}`} hint="El candidato puede subsanar dentro del periodo de inscripción." label="Qué debe subsanar"
          onClose={() => setObserve(null)} onConfirm={(reason) => { onReview(observe.id, "reject", reason); setObserve(null); }} />
      )}
    </Card>
  );
}

function Participation({ e }: { e: Election }) {
  const pct = e.participation_pct ?? 0;
  return (
    <Card title="Participación en vivo">
      <p className="font-display text-4xl font-bold text-brand">
        {e.votes_cast} <span className="text-2xl text-muted">/ {e.eligible_voters}</span>
      </p>
      <p className="mb-3 text-sm text-muted">{Math.round(pct)} % del padrón ha votado · cierre {fmtDateTime(e.voting_closes_at)}</p>
      <Bar pct={pct} tone="ok" />
      <p className="mt-3 text-sm text-muted">Los resultados por candidato se muestran solo al cerrar la votación.</p>
    </Card>
  );
}

function Scrutiny({ e }: { e: Election }) {
  const r = e.results!;
  const max = Math.max(1, ...r.ranking.map((x) => x.votes));
  return (
    <Card title="Escrutinio">
      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[["Votos emitidos", r.total, "text-brand"], ["Válidos", r.valid, "text-ok"], ["En blanco", r.blank, "text-muted"], ["Participación", r.participation_pct == null ? "—" : `${Math.round(r.participation_pct)} %`, "text-brand"]].map(([l, v, c]) => (
          <div key={String(l)} className="rounded-xl border border-line p-3">
            <p className="text-xs text-muted">{l}</p>
            <p className={`font-display text-2xl font-bold ${c}`}>{v}</p>
          </div>
        ))}
      </div>
      <ul className="space-y-3">
        {r.ranking.map((x) => (
          <li key={x.candidate_id} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 sm:grid-cols-[14rem_minmax(0,1fr)_3rem]">
            <span className="flex items-center gap-2 text-sm font-medium">
              {x.name} {x.result !== "not_elected" && <Chip tone="ok">{RESULT[x.result]}</Chip>}
            </span>
            <b className="text-right font-display sm:order-last">{x.votes}</b>
            <div className="col-span-2 sm:col-span-1">
              <Bar pct={(x.votes / max) * 100} tone={x.result === "not_elected" ? "na" : "ok"} />
            </div>
          </li>
        ))}
      </ul>
      {r.ties.map((t) => (
        <p key={t.note} role="note" className="mt-3 rounded-lg bg-amber-soft px-3 py-2 text-sm text-amber-ink">{t.note}</p>
      ))}
    </Card>
  );
}

/** Cédula de votación (SCR-COM-05): un voto secreto por elector, con confirmación y comprobante sin indicar el voto. */
export function Ballot({ election, onVoted }: { election: Pick<Election, "id">; onVoted?: () => void }) {
  type B = { election: { title: string; voting_closes_at: string | null; allow_blank: boolean }; open: boolean; linked: boolean; on_roll: boolean; has_voted: boolean; candidates: { id: string; name: string; position: string | null; list_name: string | null }[] };
  const [b, setB] = useState<B | null>(null);
  const [sel, setSel] = useState<string | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [receipt, setReceipt] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: B }>(`/com/elections/${election.id}/ballot`, { withCompany: true }).then((r) => setB(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [election.id]);
  const vote = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { receipt: string } }>(`/com/elections/${election.id}/vote`, { method: "POST", body: sel === "blank" ? { blank: true } : { candidate_id: sel }, withCompany: true });
      setReceipt(r.data.receipt);
      setB((x) => (x ? { ...x, has_voted: true } : x));
      setConfirm(false);
      onVoted?.();
    } catch (e) {
      setError((e as ApiError).detail ?? (e as ApiError).title);
      setConfirm(false);
    } finally {
      setBusy(false);
    }
  };
  const chosen = sel === "blank" ? "voto en blanco" : b?.candidates.find((c) => c.id === sel)?.name;
  return (
    <Card title="Cédula de votación">
      {error && <p className="mb-3 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      {!b ? (
        !error && <div className="h-32 animate-pulse rounded-lg bg-bg" />
      ) : receipt || b.has_voted ? (
        <div className="rounded-lg bg-ok-soft px-4 py-3 text-ok">
          <p className="font-semibold">Ya votaste. Tu voto es secreto y quedó registrado de forma inalterable.</p>
          {receipt && (
            <p className="mt-1 text-sm">
              Comprobante: <b className="font-mono">{receipt}</b> (no indica por quién votaste).
            </p>
          )}
        </div>
      ) : !b.open ? (
        <p className="text-sm text-muted">La votación no está abierta en este momento.</p>
      ) : !b.linked ? (
        <p className="text-sm text-muted">Tu usuario no está vinculado a un trabajador de la empresa. Pide a Recursos Humanos que lo vincule para poder votar.</p>
      ) : !b.on_roll ? (
        <p className="text-sm text-muted">No figuras en el padrón electoral de esta elección.</p>
      ) : (
        <div className="space-y-2" role="radiogroup" aria-label="Candidatos">
          <p className="text-sm text-muted">Elige a un candidato. Un voto por persona, secreto y sin posibilidad de cambio.</p>
          {b.candidates.map((c) => (
            <button key={c.id} role="radio" aria-checked={sel === c.id} onClick={() => setSel(c.id)}
              className={`flex min-h-12 w-full items-center gap-3 rounded-xl border px-3 py-2 text-left ${sel === c.id ? "border-brand bg-brand-soft" : "border-line hover:border-brand/50"}`}>
              <Avatar name={c.name} />
              <span className="min-w-0">
                <b className="block">{c.name}</b>
                <span className="text-xs text-muted">{[c.position, c.list_name].filter(Boolean).join(" · ")}</span>
              </span>
            </button>
          ))}
          {b.election.allow_blank && (
            <button role="radio" aria-checked={sel === "blank"} onClick={() => setSel("blank")}
              className={`flex min-h-12 w-full items-center rounded-xl border px-4 text-left text-sm font-semibold ${sel === "blank" ? "border-brand bg-brand-soft" : "border-line text-muted hover:border-brand/50"}`}>
              Voto en blanco
            </button>
          )}
          <Button variant="primary" size="lg" full disabled={!sel} onClick={() => setConfirm(true)}>
            Emitir mi voto
          </Button>
        </div>
      )}
      {confirm && (
        <Modal title="Confirmar voto" size="sm" onClose={() => setConfirm(false)}
          footer={<>
            <Button onClick={() => setConfirm(false)}>Volver</Button>
            <Button variant="primary" loading={busy} onClick={vote}>Confirmar voto</Button>
          </>}>
          <p>Vas a votar por <b>{chosen}</b>. Una vez emitido no se puede cambiar.</p>
        </Modal>
      )}
    </Card>
  );
}

function ReasonModal({ title, hint, label = "Motivo", onClose, onConfirm }: { title: string; hint?: string; label?: string; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState("");
  return (
    <Modal title={title} size="sm" onClose={onClose}
      footer={<>
        <Button onClick={onClose}>Volver</Button>
        <Button variant="primary" disabled={!reason.trim()} onClick={() => onConfirm(reason.trim())}>Confirmar</Button>
      </>}>
      <FormField label={label} hint={hint}>
        <textarea className="input min-h-24" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} autoFocus />
      </FormField>
    </Modal>
  );
}

function NewElection({ onClose, onDone }: { onClose: () => void; onDone: (m: string) => void }) {
  const y = new Date().getFullYear() + 1;
  const [d, setD] = useState({ title: `Elección de representantes ${y}–${y + 2}`, seats_titular: 3, seats_alternate: 3, tie_break_rule: "seniority", nominations_close_at: "", voting_opens_at: "", voting_closes_at: "", board: "" });
  const [err, setErr] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const set = (p: Partial<typeof d>) => setD((x) => ({ ...x, ...p }));
  const save = async () => {
    setBusy(true);
    setErr({});
    try {
      const iso = (v: string) => (v ? new Date(v).toISOString() : null);
      await api("/com/elections", {
        method: "POST",
        withCompany: true,
        body: {
          title: d.title, seats_titular: d.seats_titular, seats_alternate: d.seats_alternate, tie_break_rule: d.tie_break_rule,
          nominations_close_at: iso(d.nominations_close_at), voting_opens_at: iso(d.voting_opens_at), voting_closes_at: iso(d.voting_closes_at),
          electoral_board: d.board.split("\n").map((l) => l.trim()).filter(Boolean).map((l) => {
            const [name, role] = l.split(/\s*[-–]\s*/);
            return role ? { name, role } : { name };
          }),
        },
      });
      onDone("Convocatoria registrada");
    } catch (e) {
      const ae = e as ApiError;
      setErr(Object.fromEntries(Object.entries(ae.errors ?? {}).map(([k, v]) => [k, v[0]])));
      if (!ae.errors) setErr({ title: ae.detail ?? ae.title });
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal title="Convocar elección" subtitle="Representantes de los trabajadores ante el Comité SST" icon="users" onClose={onClose}
      footer={<>
        <Button onClick={onClose}>Cancelar</Button>
        <Button variant="primary" loading={busy} onClick={save}>Publicar convocatoria</Button>
      </>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <FormField label="Nombre de la elección" error={err.title}>
            <input className="input" value={d.title} maxLength={200} onChange={(e) => set({ title: e.target.value })} />
          </FormField>
        </div>
        <FormField label="Titulares a elegir" error={err.seats_titular} hint="Mitad del comité (paritario)">
          <input className="input" type="number" min={1} max={6} value={d.seats_titular} onChange={(e) => set({ seats_titular: Number(e.target.value) })} />
        </FormField>
        <FormField label="Suplentes a elegir" error={err.seats_alternate}>
          <input className="input" type="number" min={0} max={6} value={d.seats_alternate} onChange={(e) => set({ seats_alternate: Number(e.target.value) })} />
        </FormField>
        <FormField label="Cierre de inscripción" error={err.nominations_close_at}>
          <input className="input" type="datetime-local" value={d.nominations_close_at} onChange={(e) => set({ nominations_close_at: e.target.value })} />
        </FormField>
        <FormField label="Regla de desempate" hint="Se aplica si hay empate en el último puesto">
          <select className="input" value={d.tie_break_rule} onChange={(e) => set({ tie_break_rule: e.target.value })}>
            <option value="seniority">Mayor antigüedad en la empresa</option>
            <option value="lottery">Sorteo registrado</option>
          </select>
        </FormField>
        <FormField label="Inicio de la votación" error={err.voting_opens_at}>
          <input className="input" type="datetime-local" value={d.voting_opens_at} onChange={(e) => set({ voting_opens_at: e.target.value })} />
        </FormField>
        <FormField label="Cierre de la votación" error={err.voting_closes_at}>
          <input className="input" type="datetime-local" value={d.voting_closes_at} onChange={(e) => set({ voting_closes_at: e.target.value })} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField label="Junta electoral" hint="Una persona por línea, con su cargo: «Percy Rojas – Presidente»" error={err.electoral_board}>
            <textarea className="input min-h-20" value={d.board} onChange={(e) => set({ board: e.target.value })} />
          </FormField>
        </div>
      </div>
    </Modal>
  );
}

function useRoll(id: string) {
  const [rows, setRows] = useState<RollRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: RollRow[] }>(`/com/elections/${id}/roll`, { withCompany: true }).then((r) => setRows(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id]);
  useEffect(() => {
    load();
  }, [load]);
  return { rows, error, load };
}

function RollModal({ e, editable, onClose }: { e: Election; editable: boolean; onClose: () => void }) {
  const { rows, error, load } = useRoll(e.id);
  const [q, setQ] = useState("");
  const [excl, setExcl] = useState<RollRow | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const toggle = async (r: RollRow, excluded: boolean, reason?: string) => {
    try {
      await api(`/com/elections/${e.id}/roll/${r.id}`, { method: "PATCH", body: { excluded, reason }, withCompany: true });
      setExcl(null);
      load();
    } catch (err) {
      setMsg((err as ApiError).detail ?? (err as ApiError).title);
    }
  };
  const shown = (rows ?? []).filter((r) => r.full_name.toLowerCase().includes(q.toLowerCase()));
  return (
    <Modal title="Padrón electoral" subtitle="Trabajadores habilitados al corte. Excluye al personal de dirección y de confianza con su motivo." size="lg" onClose={onClose}>
      {error || msg ? <p className="mb-3 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error ?? msg}</p> : null}
      <input className="input mb-3" placeholder="Buscar por nombre" value={q} onChange={(ev) => setQ(ev.target.value)} aria-label="Buscar en el padrón" />
      {!rows ? (
        <div className="h-40 animate-pulse rounded-lg bg-bg" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs text-muted">
              <tr><th className="py-2 pr-3">Trabajador</th><th className="py-2 pr-3">Puesto</th><th className="py-2 pr-3">Estado</th><th className="py-2" /></tr>
            </thead>
            <tbody>
              {shown.map((r) => (
                <tr key={r.id} className="border-b border-line last:border-0">
                  <td className="py-2 pr-3 font-medium">{r.full_name}{r.document_last4 && <span className="text-xs text-muted"> · DNI ****{r.document_last4}</span>}</td>
                  <td className="py-2 pr-3 text-muted">{r.position ?? "—"}</td>
                  <td className="py-2 pr-3">
                    {r.excluded ? <span title={r.exclusion_reason ?? ""}><Chip tone="bad">Excluido</Chip></span> : <Chip tone="ok">Habilitado</Chip>}
                    {r.excluded && r.exclusion_reason && <p className="text-xs text-muted">{r.exclusion_reason}</p>}
                  </td>
                  <td className="py-2 text-right">
                    {editable && (r.excluded ? (
                      <Button size="sm" variant="ghost" onClick={() => toggle(r, false)}>Reincorporar</Button>
                    ) : (
                      <Button size="sm" variant="danger-ghost" onClick={() => setExcl(r)}>Excluir</Button>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {excl && <ReasonModal title={`Excluir a ${excl.full_name}`} label="Motivo de la exclusión" hint="Por ejemplo: gerente general (personal de dirección)."
        onClose={() => setExcl(null)} onConfirm={(reason) => toggle(excl, true, reason)} />}
    </Modal>
  );
}

function AddCandidate({ e, onClose, onAdd }: { e: Election; onClose: () => void; onAdd: (workerId: string) => Promise<boolean | undefined> }) {
  const { rows, error } = useRoll(e.id);
  const [q, setQ] = useState("");
  const taken = new Set(e.candidates.filter((c) => c.status !== "rejected").map((c) => c.worker_id));
  const shown = (rows ?? []).filter((r) => !r.excluded && !taken.has(r.worker_id) && r.full_name.toLowerCase().includes(q.toLowerCase())).slice(0, 30);
  return (
    <Modal title="Inscribir candidato" subtitle="Solo trabajadores del padrón" size="sm" onClose={onClose}>
      {error && <p className="mb-3 text-sm text-bad">{error}</p>}
      <input className="input mb-3" placeholder="Buscar trabajador" value={q} onChange={(ev) => setQ(ev.target.value)} autoFocus aria-label="Buscar trabajador" />
      <ul className="max-h-80 divide-y divide-line overflow-y-auto">
        {shown.map((r) => (
          <li key={r.id}>
            <button className="flex min-h-12 w-full items-center gap-3 px-1 py-2 text-left hover:bg-bg" onClick={async () => { if (await onAdd(r.worker_id)) onClose(); }}>
              <Avatar name={r.full_name} />
              <span><b className="block text-sm">{r.full_name}</b><span className="text-xs text-muted">{r.position ?? "Sin puesto"}</span></span>
            </button>
          </li>
        ))}
        {rows && shown.length === 0 && <li className="py-3 text-sm text-muted">Sin resultados.</li>}
      </ul>
    </Modal>
  );
}

function OpenVoting({ e, busy, onClose, onOpen }: { e: Election; busy: boolean; onClose: () => void; onOpen: (closesIso: string) => void }) {
  const [closes, setCloses] = useState(toLocalInput(e.voting_closes_at && new Date(e.voting_closes_at) > new Date() ? e.voting_closes_at : null));
  const accepted = e.candidates.filter((c) => c.status === "accepted").length;
  const pending = e.candidates.filter((c) => c.status === "nominated").length;
  return (
    <Modal title="Abrir votación" size="sm" onClose={onClose}
      footer={<>
        <Button onClick={onClose}>Volver</Button>
        <Button variant="primary" loading={busy} disabled={!closes} onClick={() => onOpen(new Date(closes).toISOString())}>Abrir votación</Button>
      </>}>
      <div className="space-y-3 text-sm">
        <p>Se cierra la inscripción con <b>{accepted}</b> candidato{accepted === 1 ? "" : "s"} habilitado{accepted === 1 ? "" : "s"} y se congela el padrón. Cada elector podrá votar una sola vez desde la app.</p>
        {pending > 0 && <p className="rounded-lg bg-amber-soft px-3 py-2 text-amber-ink">Hay {pending} candidatura{pending === 1 ? "" : "s"} sin revisar: habilítalas u obsérvalas antes.</p>}
        <FormField label="Cierre de la votación">
          <input className="input" type="datetime-local" value={closes} onChange={(ev) => setCloses(ev.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}
