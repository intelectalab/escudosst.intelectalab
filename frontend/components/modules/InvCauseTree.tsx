"use client";

// Árbol de causas de una investigación (M-INV, SCR-INV-03; prototipo m-personas.js «arbolCausas» y R.INV): la lesión o
// el evento como raíz, las causas encadenadas por «causa padre» y, al lado, el equipo investigador y las causas por tipo.
// Se muestra dentro de la ficha de la investigación; las causas se agregan en la lista «Causas» de la misma ficha.

import { useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import type { RecordRow } from "@/lib/records";
import { Card } from "./Summary";

type Cause = { id: string; parent_cause_id: string | null; cause_level: string; cause_category: string | null; description: string; corrective_action_id: string | null };
type Member = { id: string; name: string; role: string };
type Tree = { investigation: { code: string | null; method: string; status: string; event_code: string | null; event_description: string | null }; causes: Cause[]; team: Member[] };

const LEVEL: Record<string, string> = { immediate: "Causa inmediata", basic: "Causa básica", root: "Causa raíz", lack_of_control: "Falta de control" };
const LEVEL_PLURAL: [string, string][] = [["immediate", "Causas inmediatas"], ["basic", "Causas básicas"], ["root", "Causas raíz"], ["lack_of_control", "Falta de control"]];
const CATEGORY: Record<string, string> = { unsafe_act: "Acto", unsafe_condition: "Condición", personal_factor: "Factor personal", job_factor: "Factor de trabajo", management_system: "Sistema de gestión" };
const ROLE: Record<string, string> = { leader: "Líder", member: "Integrante", committee_representative: "Representante del comité", worker_representative: "Representante de los trabajadores", expert: "Especialista", witness: "Testigo" };
const BOX: Record<string, string> = { immediate: "bg-brand-soft border-brand/40", basic: "bg-surface border-line", root: "bg-amber-soft border-[#F4B90F]/60", lack_of_control: "bg-na-soft border-line" };

const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((x) => x[0]?.toUpperCase()).join("");

export function InvCauseTree({ row }: { row: RecordRow }) {
  const [data, setData] = useState<Tree | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  const version = String(row.updated_at ?? "");
  useEffect(() => {
    api<{ data: Tree }>(`/inv/investigations/${row.id}/tree`, { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [row.id, version, tick]);

  // Hijos por causa padre; una causa cuyo padre no está (o sin padre) cuelga de la raíz.
  const kids = useMemo(() => {
    const ids = new Set((data?.causes ?? []).map((c) => c.id));
    const m = new Map<string, Cause[]>();
    for (const c of data?.causes ?? []) {
      const key = c.parent_cause_id && ids.has(c.parent_cause_id) ? c.parent_cause_id : "root";
      m.set(key, [...(m.get(key) ?? []), c]);
    }
    return m;
  }, [data]);

  if (error) return <p className="rounded-xl border border-line px-4 py-3 text-sm text-muted">{error}</p>;
  if (!data) return <div className="h-32 animate-pulse rounded-xl bg-bg" />;

  const node = (c: Cause, depth: number): React.ReactNode => (
    <div key={c.id} className="space-y-2">
      <div className={`rounded-lg border px-3 py-2 text-sm ${BOX[c.cause_level] ?? BOX.basic}`}>
        {c.description}
        <span className="mt-0.5 block text-[11px] text-muted">
          {LEVEL[c.cause_level] ?? c.cause_level}
          {c.cause_category ? ` · ${CATEGORY[c.cause_category] ?? c.cause_category}` : ""}
          {c.corrective_action_id ? " · con acción correctiva" : ""}
        </span>
      </div>
      {(kids.get(c.id) ?? []).length > 0 && depth < 8 && (
        <div className="ml-5 space-y-2 border-l-2 border-dashed border-line pl-3">{(kids.get(c.id) ?? []).map((k) => node(k, depth + 1))}</div>
      )}
    </div>
  );
  const top = kids.get("root") ?? [];
  const inv = data.investigation;

  return (
    <div className="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card
        title={`${inv.code ?? "Investigación"} · Árbol de causas`}
        action={<button type="button" className="text-xs font-semibold text-brand hover:underline" onClick={() => setTick((t) => t + 1)}>Actualizar árbol</button>}
      >
        <div className="space-y-2">
          <div className="rounded-lg border border-bad bg-bad-soft px-3 py-2 text-sm font-bold">
            {inv.event_description || "Evento investigado"}
            {inv.event_code && <span className="block text-[11px] font-normal text-muted">{inv.event_code}</span>}
          </div>
          {top.length > 0 ? (
            <div className="ml-5 space-y-2 border-l-2 border-dashed border-line pl-3">{top.map((c) => node(c, 0))}</div>
          ) : (
            <p className="text-sm text-muted">Aún no hay causas. Agrégalas en «Causas», más abajo, e indica de qué causa dependen para armar el árbol.</p>
          )}
        </div>
        <p className="mt-3 text-[11px] text-muted">Rojo: el evento. Azul: causas inmediatas (hechos). Blanco: causas básicas. Ámbar: causas raíz.</p>
      </Card>
      <div className="space-y-3">
        <Card title="Equipo investigador">
          {data.team.length === 0 ? (
            <p className="text-sm text-muted">Sin integrantes. Agrégalos en «Equipo investigador».</p>
          ) : (
            <ul className="space-y-2">
              {data.team.map((m) => (
                <li key={m.id} className="flex items-center gap-2 text-sm">
                  <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-strong" aria-hidden="true">{initials(m.name)}</span>
                  <span className="min-w-0">
                    <b className="block truncate">{m.name}</b>
                    <span className="block text-xs text-muted">{ROLE[m.role] ?? m.role}</span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Causas por tipo">
          <dl className="space-y-2 text-sm">
            {LEVEL_PLURAL.filter(([lv]) => lv === "immediate" || lv === "basic" || data.causes.some((c) => c.cause_level === lv)).map(([lv, label]) => {
              const list = data.causes.filter((c) => c.cause_level === lv);
              return (
                <div key={lv}>
                  <dt className="text-xs font-semibold text-muted">{label} ({list.length})</dt>
                  <dd>{list.length ? list.map((c) => (c.cause_category ? `${CATEGORY[c.cause_category] ?? c.cause_category}: ` : "") + c.description).join(" · ") : "—"}</dd>
                </div>
              );
            })}
          </dl>
        </Card>
      </div>
    </div>
  );
}
