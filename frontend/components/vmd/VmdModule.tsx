"use client";

// Vigilancia médica (M-VMD): solo para el médico ocupacional. Antes de ver datos médicos se confirma la contraseña con el código de la app
// (dura 15 minutos y cada acceso queda registrado). Programas → casos por trabajador → notas clínicas (sin editar:
// las correcciones se agregan como adenda).

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { MedReauth } from "@/components/modules/MedicalGate";
import { Modal } from "@/components/ui/Modal";
import { RefSelect } from "@/components/records/ModuleRecords";

type Program = { id: string; code: string; name: string; target_risk: string; description: string | null; protocol: string | null; status: string; open_cases: number; overdue: number;
  agent_type_code?: string | null; target_positions?: string[]; exposed_workers?: number; exposed_without_case?: number };
type AgentExposure = { agent_code: string; agent: string; agent_family: string; workers: number; positions: { id: string; name: string | null }[] };
type ProgramExposed = { worker_id: string; worker: string; position: string | null; area: string | null; agent: string; ratio_to_limit: number | null; measured_at: string;
  valid_until: string; has_open_case: boolean };
type Case = {
  id: string; program_id: string; program: string; worker_id: string; worker: string; document_masked: string | null; opened_on: string; reason: string | null;
  risk_classification: "low" | "medium" | "high" | null; next_control_on: string | null; closed_on: string | null; status: string; overdue: boolean; closure_reason?: string | null;
};
type Note = { id: string; note_type: string; noted_at: string; author: string; subjective: string | null; objective: string | null; assessment: string | null; plan: string | null; amended_note_id: string | null; shared?: boolean };

const RISKS: Record<string, string> = {
  noise: "Ruido", respiratory: "Respiratorio", musculoskeletal: "Musculoesquelético", psychosocial: "Psicosocial", chemical: "Químico", biological: "Biológico",
  radiation: "Radiaciones", cardiovascular: "Cardiovascular", visual: "Visual", other: "Otro",
};
const LEVEL = { low: ["Bajo", "bg-ok-soft text-ok"], medium: ["Medio", "bg-amber-soft text-amber-ink"], high: ["Alto", "bg-bad-soft text-bad"] } as const;
const STATUS: Record<string, string> = { open: "Abierto", in_follow_up: "En seguimiento", closed: "Cerrado" };
const NOTE_TYPES: Record<string, string> = { consultation: "Consulta", follow_up: "Seguimiento", evaluation: "Evaluación", referral: "Derivación", other: "Otra" };
const d = (v: string | null) => (v ? v.slice(0, 10).split("-").reverse().join("/") : "—");

type Filter = { program_id?: string; status?: string; overdue?: boolean; risk?: string };

export function VmdModule({ readOnly }: { readOnly: boolean }) {
  const [reauth, setReauth] = useState<{ id: string; expires: number } | null>(null);
  const [programs, setPrograms] = useState<Program[] | null>(null);
  const [cases, setCases] = useState<Case[] | null>(null);
  const [filter, setFilter] = useState<Filter>({ status: "open,in_follow_up" });
  const [error, setError] = useState<string | null>(null);
  const [newProgram, setNewProgram] = useState(false);
  const [newCase, setNewCase] = useState(false);
  const [openCase, setOpenCase] = useState<string | null>(null);
  const [exposedOf, setExposedOf] = useState<Program | null>(null);
  const [caseWorker, setCaseWorker] = useState<{ program_id: string; worker_id: string } | null>(null);

  // Llamada médica: lleva la clave de re-autenticación; si venció, se vuelve a pedir la contraseña y el código.
  const med = useCallback(
    async <T,>(path: string, opts: { method?: "GET" | "POST" | "PATCH"; body?: unknown } = {}): Promise<T> => {
      if (!reauth || reauth.expires < Date.now()) {
        setReauth(null);
        throw new ApiError(403, "reauth_required", "Confirma tu identidad");
      }
      try {
        return await api<T>(path, { ...opts, withCompany: true, headers: { "X-Medical-Reauth": reauth.id } });
      } catch (e) {
        if (e instanceof ApiError && e.type === "reauth_required") setReauth(null);
        throw e;
      }
    },
    [reauth],
  );

  const load = useCallback(() => {
    if (!reauth) return;
    setError(null);
    const q = new URLSearchParams();
    if (filter.program_id) q.set("program_id", filter.program_id);
    if (filter.status) q.set("status", filter.status);
    if (filter.overdue) q.set("overdue", "1");
    med<{ data: Program[] }>("/vmd/programs").then((r) => setPrograms(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
    med<{ data: Case[] }>(`/vmd/cases?${q}`).then((r) => setCases(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [reauth, filter, med]);

  useEffect(load, [load]);

  const shown = useMemo(() => (cases ?? []).filter((c) => !filter.risk || c.risk_classification === filter.risk), [cases, filter.risk]);

  if (!reauth) return <Reauth onOk={(id, minutes) => setReauth({ id, expires: Date.now() + minutes * 60_000 })} />;

  const active = (programs ?? []).filter((p) => p.status === "active");
  const tiles: { label: string; n: number; tone: string; on: boolean; set: Filter }[] = [
    { label: "Casos abiertos", n: active.reduce((a, p) => a + Number(p.open_cases), 0), tone: "text-fg", on: !filter.overdue && !filter.risk && filter.status === "open,in_follow_up" && !filter.program_id, set: { status: "open,in_follow_up" } },
    { label: "Controles vencidos", n: active.reduce((a, p) => a + Number(p.overdue), 0), tone: "text-bad", on: !!filter.overdue, set: { overdue: true } },
    { label: "Riesgo alto", n: (cases ?? []).filter((c) => c.risk_classification === "high" && c.status !== "closed").length, tone: "text-amber-ink", on: filter.risk === "high", set: { status: "open,in_follow_up", risk: "high" } },
    { label: "Casos cerrados", n: -1, tone: "text-ok", on: filter.status === "closed", set: { status: "closed" } },
  ];
  const programName = filter.program_id ? programs?.find((p) => p.id === filter.program_id)?.name : null;

  return (
    <div className="space-y-5">
      <p className="rounded-lg bg-brand-soft px-4 py-2 text-sm">
        Datos médicos confidenciales: cada consulta queda registrada a tu nombre. La clave vence a las {new Date(reauth.expires).toLocaleTimeString("es-PE", { hour: "2-digit", minute: "2-digit" })}
      </p>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {tiles.map((t) => (
          <button key={t.label} onClick={() => setFilter(t.set)} aria-pressed={t.on}
            className={`rounded-2xl border bg-surface p-4 text-left shadow-sm hover:shadow-md ${t.on ? "border-brand ring-2 ring-brand/30" : "border-line"}`}>
            <p className={`font-display text-3xl font-semibold ${t.tone}`}>{t.n >= 0 ? t.n : "Ver"}</p>
            <p className="text-sm text-muted">{t.label}</p>
          </button>
        ))}
      </div>

      <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="font-semibold">Programas de vigilancia</h2>
          {!readOnly && <Button variant="primary" onClick={() => setNewProgram(true)}>+ Nuevo programa</Button>}
        </div>
        {programs?.length === 0 && <p className="text-sm text-muted">Aún no hay programas. Crea el primero (por ejemplo, conservación auditiva para puestos con ruido).</p>}
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {programs?.map((p) => (
            <div key={p.id} className={`rounded-xl border p-4 hover:border-brand ${filter.program_id === p.id ? "border-brand ring-2 ring-brand/30" : "border-line"}`}>
              <button onClick={() => setFilter({ program_id: p.id, status: "open,in_follow_up" })} className="block w-full text-left">
                <p className="text-xs text-muted">{p.code} · {RISKS[p.target_risk] ?? p.target_risk}{p.status === "closed" ? " · cerrado" : ""}</p>
                <p className="font-semibold">{p.name}</p>
                <p className="mt-1 text-sm">
                  <b>{p.open_cases}</b> casos abiertos{Number(p.overdue) > 0 && <span className="text-bad"> · {p.overdue} con control vencido</span>}
                </p>
              </button>
              {(p.agent_type_code || (p.target_positions?.length ?? 0) > 0) && (
                <p className="mt-1 text-xs">
                  {p.exposed_workers ?? 0} expuestos vigentes (monitoreo){(p.exposed_without_case ?? 0) > 0 && <span className="text-amber-ink"> · {p.exposed_without_case} sin caso</span>}
                  {(p.exposed_workers ?? 0) > 0 && <button className="ml-2 text-brand underline" onClick={() => setExposedOf(p)}>Ver expuestos</button>}
                </p>
              )}
            </div>
          ))}
        </div>
      </section>

      <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="font-semibold">Casos</h2>
            <p className="text-xs text-muted">
              Mostrando: {programName ? `${programName} · ` : ""}{filter.overdue ? "controles vencidos" : filter.status === "closed" ? "cerrados" : "abiertos y en seguimiento"}
              {filter.risk ? " · riesgo alto" : ""}
              {(filter.program_id || filter.overdue || filter.risk || filter.status !== "open,in_follow_up") && (
                <button className="ml-2 text-brand hover:underline" onClick={() => setFilter({ status: "open,in_follow_up" })}>Limpiar filtros</button>
              )}
            </p>
          </div>
          {!readOnly && active.length > 0 && <Button variant="primary" onClick={() => setNewCase(true)}>+ Abrir caso</Button>}
        </div>
        {shown.length === 0 ? (
          <p className="text-sm text-muted">No hay casos con estos filtros.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-xs text-muted">
                  <th className="py-2 pr-3">Trabajador</th><th className="py-2 pr-3">Programa</th><th className="py-2 pr-3">Riesgo</th>
                  <th className="py-2 pr-3">Próximo control</th><th className="py-2 pr-3">Estado</th>
                </tr>
              </thead>
              <tbody>
                {shown.map((c) => (
                  <tr key={c.id} onClick={() => setOpenCase(c.id)} className="cursor-pointer border-b border-line last:border-0 hover:bg-bg">
                    <td className="py-2 pr-3"><span className="font-semibold text-brand">{c.worker}</span> <span className="text-xs text-muted">{c.document_masked}</span></td>
                    <td className="py-2 pr-3">{c.program}</td>
                    <td className="py-2 pr-3">{c.risk_classification ? <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${LEVEL[c.risk_classification][1]}`}>{LEVEL[c.risk_classification][0]}</span> : "—"}</td>
                    <td className={`py-2 pr-3 ${c.overdue ? "font-semibold text-bad" : ""}`}>{d(c.next_control_on)}{c.overdue ? " · vencido" : ""}</td>
                    <td className="py-2 pr-3">{STATUS[c.status] ?? c.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {newProgram && <ProgramForm med={med} onClose={() => setNewProgram(false)} onSaved={() => { setNewProgram(false); load(); }} />}
      {newCase && <CaseForm med={med} programs={active} preset={filter.program_id} onClose={() => setNewCase(false)} onSaved={() => { setNewCase(false); load(); }} />}
      {exposedOf && (
        <ExposedList med={med} program={exposedOf} readOnly={readOnly || exposedOf.status !== "active"} onClose={() => setExposedOf(null)}
          onOpenCase={(w) => { setCaseWorker({ program_id: exposedOf.id, worker_id: w }); setExposedOf(null); }} />
      )}
      {caseWorker && <CaseForm med={med} programs={active} preset={caseWorker.program_id} presetWorker={caseWorker.worker_id}
        onClose={() => setCaseWorker(null)} onSaved={() => { setCaseWorker(null); load(); }} />}
      {openCase && <CaseDetail id={openCase} med={med} readOnly={readOnly} onClose={() => setOpenCase(null)} onChanged={load} />}
    </div>
  );
}

type Med = <T>(path: string, opts?: { method?: "GET" | "POST" | "PATCH"; body?: unknown }) => Promise<T>;

/** Contraseña + código de la app (o de recuperación) + motivo: el mismo formulario de la clave médica de los demás módulos. */
function Reauth({ onOk }: { onOk: (id: string, minutes: number) => void }) {
  return (
    <MedReauth endpoint="/vmd/reauth" who="el médico ocupacional" submitLabel="Entrar a vigilancia médica"
      onOk={(k) => onOk(k.id, Math.max(1, Math.round((k.expires - Date.now()) / 60_000)))} />
  );
}

/** Expuestos vigentes del programa (monitoreo de agentes) y si ya tienen caso; desde aquí se abre el caso del que falta. */
function ExposedList({ med, program, readOnly, onClose, onOpenCase }: { med: Med; program: Program; readOnly: boolean; onClose: () => void; onOpenCase: (workerId: string) => void }) {
  const [rows, setRows] = useState<ProgramExposed[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    med<{ data: ProgramExposed[] }>(`/vmd/programs/${program.id}/exposed`).then((r) => setRows(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [med, program.id]);
  return (
    <Modal title={`Expuestos: ${program.name}`} onClose={onClose} footer={<Button onClick={onClose}>Cerrar</Button>}>
      {error && <p className="text-bad">{error}</p>}
      {rows?.length === 0 && <p className="text-sm text-muted">No hay expuestos vigentes para este programa.</p>}
      <ul className="divide-y divide-line text-sm">
        {rows?.map((r, i) => (
          <li key={`${r.worker_id}-${i}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <span>
              <b>{r.worker}</b>{r.position ? ` · ${r.position}` : ""}
              <span className="block text-xs text-muted">
                {r.agent} sobre el límite{r.ratio_to_limit !== null ? ` (${Math.round(r.ratio_to_limit * 100)} %)` : ""} · medido el {d(r.measured_at)} · vigente hasta {d(r.valid_until)}
              </span>
            </span>
            {r.has_open_case ? <span className="text-xs text-ok">Con caso abierto</span>
              : !readOnly && <Button onClick={() => onOpenCase(r.worker_id)}>Abrir caso</Button>}
          </li>
        ))}
      </ul>
    </Modal>
  );
}

function ProgramForm({ med, onClose, onSaved }: { med: Med; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ code: "", name: "", target_risk: "noise", description: "", protocol: "", agent_type_code: "", target_positions: [] as string[] });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [agents, setAgents] = useState<AgentExposure[]>([]);
  // «El médico define programas a partir de exposiciones (MAG/IPERC)»: agentes medidos sobre el límite con sus puestos.
  useEffect(() => {
    api<{ data: AgentExposure[] }>("/vmd/exposures", { withCompany: true }).then((r) => setAgents(r.data)).catch(() => setAgents([]));
  }, []);
  const agent = agents.find((a) => a.agent_code === f.agent_type_code);
  async function save() {
    setBusy(true);
    try {
      await med("/vmd/programs", { method: "POST", body: { ...f, agent_type_code: f.agent_type_code || null } });
      onSaved();
    } catch (e) {
      setErrors(e instanceof ApiError ? (e.errors ?? { name: [e.detail ?? e.title] }) : {});
    } finally {
      setBusy(false);
    }
  }
  return (
    <Modal title="Nuevo programa de vigilancia" onClose={onClose} footer={<><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Guardar</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <FormField label="Código" error={errors.code?.[0]}><input className="filter-control w-full" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} placeholder="VMD-RUIDO" /></FormField>
        <FormField label="Riesgo vigilado" error={errors.target_risk?.[0]}>
          <select className="filter-control w-full" value={f.target_risk} onChange={(e) => setF({ ...f, target_risk: e.target.value })}>
            {Object.entries(RISKS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </FormField>
        <div className="sm:col-span-2"><FormField label="Nombre" error={errors.name?.[0]}><input className="filter-control w-full" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Conservación auditiva" /></FormField></div>
        <div className="sm:col-span-2">
          <FormField label="Exposición medida (monitoreo de agentes)" error={errors.agent_type_code?.[0] ?? errors.target_positions?.[0]}>
            <select className="filter-control w-full" value={f.agent_type_code}
              onChange={(e) => setF({ ...f, agent_type_code: e.target.value, target_positions: agents.find((a) => a.agent_code === e.target.value)?.positions.map((x) => x.id) ?? [] })}>
              <option value="">Sin exposición vinculada</option>
              {agents.map((a) => <option key={a.agent_code} value={a.agent_code}>{a.agent} · {a.workers} expuestos</option>)}
            </select>
          </FormField>
          {agent && agent.positions.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-3 text-sm">
              {agent.positions.map((x) => (
                <label key={x.id} className="flex items-center gap-1">
                  <input type="checkbox" checked={f.target_positions.includes(x.id)}
                    onChange={(e) => setF({ ...f, target_positions: e.target.checked ? [...f.target_positions, x.id] : f.target_positions.filter((y) => y !== x.id) })} />
                  {x.name ?? "Puesto"}
                </label>
              ))}
            </div>
          )}
        </div>
        <div className="sm:col-span-2"><FormField label="Protocolo (exámenes y frecuencia)"><textarea className="filter-control w-full" rows={3} value={f.protocol} onChange={(e) => setF({ ...f, protocol: e.target.value })} placeholder="Audiometría anual; control a los 6 meses si hay caída." /></FormField></div>
      </div>
    </Modal>
  );
}

function CaseForm({ med, programs, preset, presetWorker, onClose, onSaved }: { med: Med; programs: Program[]; preset?: string; presetWorker?: string; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ program_id: preset ?? programs[0]?.id ?? "", worker_id: presetWorker ?? "", reason: presetWorker ? "Expuesto sobre el límite en el monitoreo de agentes" : "", risk_classification: "medium", next_control_on: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  async function save() {
    setBusy(true);
    setMsg(null);
    try {
      await med("/vmd/cases", { method: "POST", body: { ...f, next_control_on: f.next_control_on || null } });
      onSaved();
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        if (!e.errors) setMsg(e.detail ?? e.title);
      }
    } finally {
      setBusy(false);
    }
  }
  return (
    <Modal title="Abrir caso de vigilancia" onClose={onClose} footer={<><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Abrir caso</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <FormField label="Programa" error={errors.program_id?.[0]}>
          <select className="filter-control w-full" value={f.program_id} onChange={(e) => setF({ ...f, program_id: e.target.value })}>
            {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </FormField>
        <FormField label="Trabajador" error={errors.worker_id?.[0]}>
          <RefSelect code="EMO" table="fitness_status" f={{ name: "worker_id", label: "Trabajador", type: "ref", ref: "workers" }} value={f.worker_id}
            disabled={false} invalid={!!errors.worker_id} onChange={(v) => setF({ ...f, worker_id: String(v ?? "") })} />
        </FormField>
        <FormField label="Riesgo">
          <select className="filter-control w-full" value={f.risk_classification} onChange={(e) => setF({ ...f, risk_classification: e.target.value })}>
            <option value="low">Bajo</option><option value="medium">Medio</option><option value="high">Alto</option>
          </select>
        </FormField>
        <FormField label="Próximo control" error={errors.next_control_on?.[0]}><input type="date" className="filter-control w-full" value={f.next_control_on} onChange={(e) => setF({ ...f, next_control_on: e.target.value })} /></FormField>
        <div className="sm:col-span-2"><FormField label="Motivo"><textarea className="filter-control w-full" rows={2} value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></FormField></div>
      </div>
      {msg && <p className="mt-2 text-bad">{msg}</p>}
    </Modal>
  );
}

function CaseDetail({ id, med, readOnly, onClose, onChanged }: { id: string; med: Med; readOnly: boolean; onClose: () => void; onChanged: () => void }) {
  const [data, setData] = useState<{ case: Case; notes: Note[]; can_notes: boolean } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState({ note_type: "follow_up", subjective: "", objective: "", assessment: "", plan: "", amended_note_id: "", share_with_worker: false });
  const [restriction, setRestriction] = useState("");
  const [control, setControl] = useState("");
  const [closing, setClosing] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    med<{ data: { case: Case; notes: Note[]; can_notes: boolean } }>(`/vmd/cases/${id}`).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id, med]);
  useEffect(load, [load]);

  async function run(fn: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
      load();
      onChanged();
      return true;
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.title) : "No se pudo guardar.");
      return false;
    } finally {
      setBusy(false);
    }
  }

  const c = data?.case;
  const amended = (nid: string) => data?.notes.find((n) => n.id === nid);
  return (
    <Modal title={c ? `${c.worker} · ${c.program}` : "Caso"} subtitle={c ? `Abierto el ${d(c.opened_on)} · ${STATUS[c.status]}` : undefined} onClose={onClose}>
      {error && <p className="mb-3 rounded-lg bg-bad-soft px-3 py-2 text-bad">{error}</p>}
      {!c ? <p className="text-muted">Cargando…</p> : (
        <div className="space-y-4 text-sm">
          <div className="grid gap-2 sm:grid-cols-3">
            <p><span className="text-xs text-muted">Riesgo</span><br />{c.risk_classification ? LEVEL[c.risk_classification][0] : "—"}</p>
            <p><span className="text-xs text-muted">Próximo control</span><br /><span className={c.overdue ? "font-semibold text-bad" : ""}>{d(c.next_control_on)}{c.overdue ? " (vencido)" : ""}</span></p>
            <p><span className="text-xs text-muted">Motivo</span><br />{c.reason ?? "—"}</p>
          </div>
          {c.status !== "closed" && !readOnly && (
            <div className="flex flex-wrap items-end gap-2 rounded-xl border border-line p-3">
              <div className="flex w-full flex-wrap items-end gap-2">
                <div className="min-w-[16rem] flex-1"><FormField label="Restricción funcional para su aptitud (sin diagnóstico)">
                  <input className="filter-control w-full" value={restriction} onChange={(e) => setRestriction(e.target.value)} placeholder="Ej.: Sin exposición a ruido mayor a 80 dB" /></FormField></div>
                <Button disabled={!restriction.trim() || busy} onClick={() => run(() => med(`/vmd/cases/${id}`, { method: "PATCH", body: { work_restrictions: restriction } })).then((ok) => ok && setRestriction(""))}>Publicar en la aptitud</Button>
              </div>
              <FormField label="Reprogramar próximo control"><input type="date" className="filter-control" value={control} onChange={(e) => setControl(e.target.value)} /></FormField>
              <Button disabled={!control || busy} onClick={() => run(() => med(`/vmd/cases/${id}`, { method: "PATCH", body: { next_control_on: control } })).then((ok) => ok && setControl(""))}>Guardar fecha</Button>
              {!closing ? (
                <Button variant="danger-ghost" onClick={() => setClosing(true)}>Cerrar caso</Button>
              ) : (
                <div className="flex w-full flex-wrap items-end gap-2">
                  <div className="min-w-[16rem] flex-1"><FormField label="Motivo de cierre"><input className="filter-control w-full" value={reason} onChange={(e) => setReason(e.target.value)} /></FormField></div>
                  <Button variant="primary" disabled={!reason || busy} onClick={() => run(() => med(`/vmd/cases/${id}`, { method: "PATCH", body: { status: "closed", closure_reason: reason } }))}>Confirmar cierre</Button>
                  <Button onClick={() => setClosing(false)}>No cerrar</Button>
                </div>
              )}
            </div>
          )}

          <div className="space-y-2">
            <h3 className="font-semibold">Notas clínicas</h3>
            {!data.can_notes && <p className="text-muted">Tu rol no ve las notas clínicas.</p>}
            {data.can_notes && data.notes.length === 0 && <p className="text-muted">Aún no hay notas.</p>}
            {data.notes.map((n) => (
              <article key={n.id} className="rounded-xl border border-line p-3">
                <p className="text-xs text-muted">
                  {new Date(n.noted_at).toLocaleString("es-PE")} · {NOTE_TYPES[n.note_type]} · {n.author}
                  {n.amended_note_id && <span className="ml-1 rounded bg-amber-soft px-1 text-amber-ink">Adenda de la nota del {new Date(amended(n.amended_note_id)?.noted_at ?? "").toLocaleDateString("es-PE")}</span>}
                </p>
                {n.subjective && <p><b>S:</b> {n.subjective}</p>}
                {n.objective && <p><b>O:</b> {n.objective}</p>}
                {n.assessment && <p><b>A:</b> {n.assessment}</p>}
                {n.plan && <p><b>P:</b> {n.plan}</p>}
                {n.shared && <p className="text-xs font-semibold text-brand-strong">Compartida con el trabajador</p>}
                {c.status !== "closed" && !readOnly && (
                  <button className="mt-1 text-xs font-semibold text-brand hover:underline" onClick={() => setNote({ ...note, note_type: "other", amended_note_id: n.id })}>Corregir con una adenda</button>
                )}
              </article>
            ))}
          </div>

          {data.can_notes && c.status !== "closed" && !readOnly && (
            <div className="space-y-2 rounded-xl border border-line p-3">
              <h3 className="font-semibold">{note.amended_note_id ? "Nueva adenda (la nota original no se modifica)" : "Nueva nota"}</h3>
              <select className="filter-control" value={note.note_type} onChange={(e) => setNote({ ...note, note_type: e.target.value })}>
                {Object.entries(NOTE_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
              {(["subjective", "objective", "assessment", "plan"] as const).map((k) => (
                <textarea key={k} rows={2} className="filter-control w-full" value={note[k]} onChange={(e) => setNote({ ...note, [k]: e.target.value })}
                  placeholder={{ subjective: "S · Lo que refiere el trabajador", objective: "O · Hallazgos y resultados", assessment: "A · Evaluación", plan: "P · Plan y próximos pasos" }[k]} />
              ))}
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={note.share_with_worker} onChange={(e) => setNote({ ...note, share_with_worker: e.target.checked })} />
                Compartir esta nota con el trabajador (la verá en su app, confirmando su identidad)</label>
              <div className="flex gap-2">
                <Button variant="primary" loading={busy} onClick={() => run(() => med(`/vmd/cases/${id}/notes`, { method: "POST", body: { ...note, amended_note_id: note.amended_note_id || null } }))
                  .then((ok) => ok && setNote({ note_type: "follow_up", subjective: "", objective: "", assessment: "", plan: "", amended_note_id: "", share_with_worker: false }))}>Firmar y guardar nota</Button>
                {note.amended_note_id && <Button onClick={() => setNote({ ...note, amended_note_id: "" })}>Cancelar adenda</Button>}
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
