"use client";

// Comité SST (M-COM; prototipo m-personas.js R.COM): pestañas «Comité vigente» (integrantes, mandato con semáforo,
// próxima reunión con «Enviar convocatoria» y acuerdos pendientes), «Elección electrónica» (ComElection) y
// «Reuniones y actas» (acta con asistencia y quórum, firmas y «Descargar PDF»). Va encima de los registros del módulo.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import type { PermissionsResponse } from "@/lib/types";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { Card, Chip, Dot, type Tone } from "./Summary";
import { ComElection, fmtDateTime } from "./ComElection";

type Member = { id: string; worker_id: string; full_name: string; position: string | null; representation: string; member_type: string; role_in_committee: string; starts_on: string; ends_on: string | null };
type Meeting = { id: string; number: number | null; meeting_type: string; scheduled_at: string | null; held_at: string | null; status: string; quorum_reached: boolean | null; attendees: number; agreements: number; signatures: number };
type Current = {
  committee: { id: string; committee_type: string; name: string; period_start_on: string; period_end_on: string; status: string; days_left: number; term_tone: Tone };
  members: Member[];
  counts: { employer: number; workers: number; alternates: number };
  next_meeting: { id: string; number: number | null; meeting_type: string; scheduled_at: string | null; location: string | null; agenda: string | null; notified: boolean } | null;
  agreements: { id: string; number: number | null; description: string; due_on: string | null; status: string; meeting_number: number | null; responsible: string | null; is_overdue: boolean }[];
  meetings: Meeting[];
  can: { notify: boolean; sign_request: boolean };
};
type Minutes = {
  company: { name: string; legal_name: string; ruc: string };
  committee: { name: string; committee_type: string };
  meeting: { id: string; number: number | null; meeting_type: string; scheduled_at: string | null; held_at: string | null; location: string | null; quorum_reached: boolean | null; status: string; agenda: string | null; minutes_text: string | null; has_minutes_file: boolean };
  members: { id: string; name: string; role: string; representation: string; member_type: string; present: boolean }[];
  agreements: { number: number | null; description: string; due_on: string | null; status: string; responsible: string | null }[];
  signatures: { id: string; signer_name: string; signed_at: string; method: string; image: string | null }[];
  pending_signatures: { id: string; signer_name: string }[];
  can_manage: boolean;
  can_request_signatures: boolean;
};

export const ROLE: Record<string, string> = { president: "Presidente", secretary: "Secretario", member: "Miembro", observer: "Observador" };
export const SIDE: Record<string, string> = { employer: "Empleador", workers: "Trabajadores", supervisor: "Trabajadores (supervisor)" };
export const MEETING_TYPE: Record<string, string> = { ordinary: "Ordinaria", extraordinary: "Extraordinaria", installation: "Instalación" };
const MEETING_STATUS: Record<string, [string, Tone]> = {
  scheduled: ["Programada", "warn"], held: ["Realizada", "ok"], minutes_draft: ["Acta en borrador", "warn"], minutes_signed: ["Firmada", "ok"], cancelled: ["Cancelada", "na"],
};
const AGREEMENT: Record<string, string> = { agreed: "Pendiente", in_progress: "En proceso", overdue: "Vencido", fulfilled: "Cumplido", cancelled: "Sin efecto" };
export const fmtDate = (v: string | null | undefined) => (v ? String(v).slice(0, 10).split("-").reverse().join("/") : "—");

export function ComModule({ readOnly }: { readOnly?: boolean }) {
  const [perms, setPerms] = useState<string[] | null>(null);
  const [tab, setTab] = useState<"committee" | "election" | "meetings">("committee");
  useEffect(() => {
    api<PermissionsResponse>("/me/permissions", { withCompany: true }).then((r) => setPerms(r.data)).catch(() => setPerms([]));
    // Con la votación abierta, el elector entra directo a su cédula.
    api<{ data: { status: string }[] }>("/com/elections", { withCompany: true })
      .then((r) => r.data[0]?.status === "voting_open" && setTab("election"))
      .catch(() => undefined);
  }, []);
  const tabs: [typeof tab, string][] = [["committee", "Comité vigente"], ["election", "Elección electrónica"], ["meetings", "Reuniones y actas"]];
  return (
    <div className="space-y-4">
      <div role="tablist" aria-label="Comité SST" className="flex gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1">
        {tabs.map(([t, l]) => (
          <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
            className={`min-h-10 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold ${tab === t ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"}`}>
            {l}
          </button>
        ))}
      </div>
      {tab === "election" ? (
        <ComElection readOnly={readOnly} canCreate={!!perms?.includes("com.elections.create")} />
      ) : (
        <CommitteeTabs tab={tab} readOnly={readOnly} />
      )}
    </div>
  );
}

function CommitteeTabs({ tab, readOnly }: { tab: "committee" | "meetings"; readOnly?: boolean }) {
  const [data, setData] = useState<Current | null | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ m: string; tone?: "ok" | "bad" } | null>(null);
  const [busy, setBusy] = useState(false);
  const [acta, setActa] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Current | null }>("/com/committee", { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(() => {
    load();
  }, [load]);

  const notify = async (id: string) => {
    setBusy(true);
    try {
      const r = await api<{ data: { notified: number; emailed: number; without_user: number } }>(`/com/meetings/${id}/notify`, { method: "POST", withCompany: true });
      const d = r.data;
      setToast({ m: `Convocatoria enviada a ${d.notified} integrante${d.notified === 1 ? "" : "s"}${d.emailed ? ` (${d.emailed} por correo)` : ""}${d.without_user ? `; ${d.without_user} sin usuario: avísales por otro medio` : ""}.` });
      load();
    } catch (e) {
      setToast({ m: (e as ApiError).detail ?? (e as ApiError).title, tone: "bad" });
    } finally {
      setBusy(false);
    }
  };

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (data === undefined) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;
  if (data === null)
    return (
      <Card>
        <p className="text-sm text-muted">
          Aún no hay un comité o supervisor registrado. Elige a los representantes de los trabajadores en «Elección electrónica» o registra el comité y sus integrantes en los registros de abajo.
        </p>
      </Card>
    );

  const c = data.committee;
  const next = data.next_meeting;
  return (
    <>
      {tab === "committee" ? (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
          <Card title={c.name} action={<Chip tone={c.status === "active" ? "ok" : "warn"}>{c.status === "active" ? "Vigente" : "En formación"}</Chip>}>
            <p className="mb-3 flex items-center gap-2 text-sm">
              <Dot tone={c.term_tone} />
              Mandato del {fmtDate(c.period_start_on)} al {fmtDate(c.period_end_on)}
              <span className="text-muted">· {c.days_left < 0 ? "vencido: convoca una nueva elección" : `quedan ${c.days_left} días`}</span>
            </p>
            {data.members.length === 0 ? (
              <p className="text-sm text-muted">Sin integrantes registrados.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-line text-xs text-muted">
                    <tr><th className="py-2 pr-3">Miembro</th><th className="py-2 pr-3">Cargo</th><th className="py-2 pr-3">Representa a</th><th className="py-2">Desde</th></tr>
                  </thead>
                  <tbody>
                    {data.members.map((m) => (
                      <tr key={m.id} className="border-b border-line last:border-0">
                        <td className="py-2 pr-3"><b>{m.full_name}</b>{m.position && <span className="block text-xs text-muted">{m.position}</span>}</td>
                        <td className="py-2 pr-3">{ROLE[m.role_in_committee] ?? m.role_in_committee}{m.member_type === "alternate" ? " (suplente)" : ""}</td>
                        <td className="py-2 pr-3">{SIDE[m.representation] ?? m.representation}</td>
                        <td className="py-2">{fmtDate(m.starts_on)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {c.committee_type !== "supervisor" && (
              <p className="mt-2 text-xs text-muted">
                Paritario: {data.counts.employer} titular{data.counts.employer === 1 ? "" : "es"} del empleador y {data.counts.workers} de los trabajadores
                {data.counts.alternates ? `, ${data.counts.alternates} suplente${data.counts.alternates === 1 ? "" : "s"}` : ""}.
              </p>
            )}
          </Card>
          <div className="space-y-4">
            <Card title="Próxima reunión">
              {next ? (
                <>
                  <p className="font-semibold">{MEETING_TYPE[next.meeting_type] ?? next.meeting_type}{next.number ? ` N.° ${next.number}` : ""}</p>
                  <p className="text-sm text-muted">{fmtDateTime(next.scheduled_at)}{next.location ? ` · ${next.location}` : ""}</p>
                  {next.agenda && <p className="mt-1 line-clamp-3 text-sm">{next.agenda}</p>}
                  {data.can.notify && !readOnly && (
                    <Button className="mt-3" loading={busy} icon="mail" onClick={() => notify(next.id)}>
                      {next.notified ? "Reenviar convocatoria" : "Enviar convocatoria"}
                    </Button>
                  )}
                  {next.notified && <p className="mt-2 text-xs text-ok">Convocatoria enviada.</p>}
                </>
              ) : (
                <p className="text-sm text-muted">No hay reuniones programadas. Programa la reunión mensual en los registros de abajo.</p>
              )}
            </Card>
            <Card title="Acuerdos pendientes">
              {data.agreements.length === 0 ? (
                <p className="text-sm text-muted">Sin acuerdos pendientes.</p>
              ) : (
                <ul className="divide-y divide-line">
                  {data.agreements.map((a) => (
                    <li key={a.id} className="flex items-start justify-between gap-3 py-2">
                      <div className="min-w-0">
                        <p className="text-sm font-semibold">{a.description}</p>
                        <p className="text-xs text-muted">{a.responsible ?? "Comité"}{a.due_on ? ` · vence ${fmtDate(a.due_on)}` : ""}</p>
                      </div>
                      <Chip tone={a.is_overdue || a.status === "overdue" ? "bad" : a.status === "in_progress" ? "warn" : "na"}>
                        {a.is_overdue ? "Vencido" : AGREEMENT[a.status] ?? a.status}
                      </Chip>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs font-semibold text-muted">
              <tr>{["Reunión", "Fecha", "Asistencia", "Acuerdos", "Firmas", "Estado"].map((h) => <th key={h} className="whitespace-nowrap px-4 py-3">{h}</th>)}</tr>
            </thead>
            <tbody>
              {data.meetings.length === 0 && (
                <tr><td colSpan={6} className="px-4 py-6 text-center text-muted">Aún no hay reuniones registradas.</td></tr>
              )}
              {data.meetings.map((m) => (
                <tr key={m.id} className="cursor-pointer border-b border-line last:border-0 hover:bg-bg" onClick={() => setActa(m.id)}>
                  <td className="px-4 py-2.5 font-medium">
                    <button className="text-left hover:text-brand" onClick={() => setActa(m.id)}>
                      Acta{m.number ? ` N.° ${String(m.number).padStart(2, "0")}` : ""} · {MEETING_TYPE[m.meeting_type] ?? m.meeting_type}
                    </button>
                  </td>
                  <td className="whitespace-nowrap px-4 py-2.5">{fmtDate(m.held_at ?? m.scheduled_at)}</td>
                  <td className="px-4 py-2.5">{m.status === "scheduled" ? "—" : `${m.attendees}/${data.members.length}`}{m.quorum_reached === false && <span className="ml-1 text-xs text-bad">sin quórum</span>}</td>
                  <td className="px-4 py-2.5">{m.agreements}</td>
                  <td className="px-4 py-2.5">{m.signatures}</td>
                  <td className="px-4 py-2.5"><Chip tone={MEETING_STATUS[m.status]?.[1] ?? "na"}>{MEETING_STATUS[m.status]?.[0] ?? m.status}</Chip></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {acta && <MeetingActa id={acta} readOnly={readOnly} onClose={() => { setActa(null); load(); }} />}
      {toast && <Toast message={toast.m} tone={toast.tone} onClose={() => setToast(null)} />}
    </>
  );
}

/** Texto del acta de reunión (lo usan la vista en pantalla y la hoja para imprimir). */
export function MinutesPaper({ d }: { d: Minutes }) {
  const m = d.meeting;
  const present = d.members.filter((x) => x.present);
  return (
    <div className="space-y-3 text-sm leading-relaxed">
      <p className="text-center font-bold uppercase">Acta de reunión {MEETING_TYPE[m.meeting_type]?.toLowerCase() ?? ""}{m.number ? ` N.° ${String(m.number).padStart(2, "0")}` : ""} del {d.committee.committee_type === "supervisor" ? "Supervisor" : "Comité"} de Seguridad y Salud en el Trabajo</p>
      <p>
        {m.location ? `En ${m.location}, ` : ""}el {fmtDateTime(m.held_at ?? m.scheduled_at)}, se reunieron los miembros del {d.committee.name} de {d.company.legal_name} (RUC {d.company.ruc}){" "}
        {m.status === "scheduled" ? "(reunión programada)." : m.quorum_reached ? `con quórum de ${present.length} de ${d.members.length} miembros.` : `sin quórum (${present.length} de ${d.members.length} miembros): no se adoptan acuerdos.`}
      </p>
      {m.agenda && <p><b>Agenda:</b> {m.agenda}</p>}
      {m.minutes_text && <div><b>Desarrollo:</b><p className="whitespace-pre-line">{m.minutes_text}</p></div>}
      {d.agreements.length > 0 && (
        <div>
          <b>Acuerdos:</b>
          <ol className="ml-5 list-decimal">
            {d.agreements.map((a, i) => (
              <li key={i}>{a.description}{a.responsible ? ` — Responsable: ${a.responsible}` : ""}{a.due_on ? `, plazo ${fmtDate(a.due_on)}` : ""}.</li>
            ))}
          </ol>
        </div>
      )}
      {present.length > 0 && <p><b>Asistentes:</b> {present.map((x) => `${x.name} (${x.role})`).join(", ")}.</p>}
    </div>
  );
}

export function SignatureGrid({ d }: { d: Minutes }) {
  if (d.signatures.length === 0 && d.pending_signatures.length === 0) return <p className="text-sm text-muted">Aún no se pidieron firmas.</p>;
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
      {d.signatures.map((s) => (
        <div key={s.id} className="rounded-lg border border-line p-2 text-center">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          {s.image ? <img src={s.image} alt={`Firma de ${s.signer_name}`} className="mx-auto h-14 object-contain" /> : <p className="h-14 pt-4 text-xs text-muted">Firma electrónica</p>}
          <p className="border-t border-line pt-1 text-xs font-semibold">{s.signer_name}</p>
          <p className="text-[11px] text-muted">{fmtDateTime(s.signed_at)}</p>
        </div>
      ))}
      {d.pending_signatures.map((s) => (
        <div key={s.id} className="rounded-lg border border-dashed border-line p-2 text-center">
          <p className="h-14 pt-4 text-xs text-muted">Pendiente</p>
          <p className="border-t border-line pt-1 text-xs font-semibold">{s.signer_name}</p>
        </div>
      ))}
    </div>
  );
}

function MeetingActa({ id, readOnly, onClose }: { id: string; readOnly?: boolean; onClose: () => void }) {
  const [d, setD] = useState<Minutes | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [present, setPresent] = useState<Set<string> | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ m: string; tone: "ok" | "bad" } | null>(null);
  const load = useCallback(() => {
    api<{ data: Minutes }>(`/com/meetings/${id}/minutes`, { withCompany: true })
      .then((r) => { setD(r.data); setPresent(new Set(r.data.members.filter((x) => x.present).map((x) => x.id))); })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id]);
  useEffect(() => {
    load();
  }, [load]);
  const run = async (key: string, path: string, body?: unknown) => {
    setBusy(key);
    try {
      const r = await api<{ data: { message?: string; requested?: number } }>(`/com/meetings/${id}/${path}`, { method: "POST", body, withCompany: true });
      setMsg({ m: r.data.message ?? `Se pidió la firma a ${r.data.requested ?? 0} integrante${r.data.requested === 1 ? "" : "s"}.`, tone: "ok" });
      load();
    } catch (e) {
      setMsg({ m: (e as ApiError).detail ?? (e as ApiError).title, tone: "bad" });
    } finally {
      setBusy(null);
    }
  };
  const canEdit = !!d?.can_request_signatures && !readOnly;
  const attendanceOpen = !!d && !readOnly && d.can_manage && ["scheduled", "held", "minutes_draft"].includes(d.meeting.status);
  return (
    <Modal
      title={d ? `Acta${d.meeting.number ? ` N.° ${String(d.meeting.number).padStart(2, "0")}` : ""} · ${MEETING_TYPE[d.meeting.meeting_type] ?? ""}` : "Acta de reunión"}
      subtitle={d ? fmtDateTime(d.meeting.held_at ?? d.meeting.scheduled_at) : undefined}
      size="lg"
      onClose={onClose}
      footer={<>
        <Button onClick={onClose}>Cerrar</Button>
        <a href={`/imprimir/acta-reunion/${id}`} target="_blank" rel="noreferrer" className="inline-flex h-10 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-strong">
          Descargar PDF
        </a>
      </>}
    >
      {error && <p className="text-sm text-bad">{error}</p>}
      {msg && <p className={`mb-3 rounded-lg px-3 py-2 text-sm ${msg.tone === "ok" ? "bg-ok-soft text-ok" : "bg-bad-soft text-bad"}`}>{msg.m}</p>}
      {!d ? (
        !error && <div className="h-64 animate-pulse rounded-lg bg-bg" />
      ) : (
        <div className="space-y-5">
          <div className="rounded-xl border border-line bg-bg/40 p-4 sm:p-6"><MinutesPaper d={d} /></div>
          {attendanceOpen && present && (
            <section>
              <h4 className="mb-2 font-semibold">Asistencia</h4>
              <div className="grid gap-2 sm:grid-cols-2">
                {d.members.map((m) => (
                  <label key={m.id} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-line px-3">
                    <input type="checkbox" className="h-5 w-5" checked={present.has(m.id)}
                      onChange={(ev) => { const n = new Set(present); if (ev.target.checked) n.add(m.id); else n.delete(m.id); setPresent(n); }} />
                    <span className="text-sm"><b>{m.name}</b> <span className="text-muted">· {m.role}{m.member_type === "alternate" ? " suplente" : ""}</span></span>
                  </label>
                ))}
              </div>
              <Button className="mt-2" loading={busy === "att"} onClick={() => run("att", "attendance", { member_ids: [...present] })}>
                Guardar asistencia y calcular quórum
              </Button>
            </section>
          )}
          <section>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h4 className="font-semibold">Firmas</h4>
              {canEdit && (
                <Button size="sm" variant="soft" loading={busy === "sig"} onClick={() => run("sig", "request-signatures")}>
                  Pedir firma a los asistentes
                </Button>
              )}
            </div>
            <SignatureGrid d={d} />
            {d.pending_signatures.length > 0 && <p className="mt-2 text-xs text-muted">Cada integrante firma desde sus pendientes de firma.</p>}
          </section>
        </div>
      )}
    </Modal>
  );
}

export type { Minutes };
