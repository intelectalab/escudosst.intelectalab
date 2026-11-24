"use client";

// Árbol de causas de una investigación (M-INV, SCR-INV-03; prototipo m-personas.js «arbolCausas» y R.INV): la lesión o
// el evento como raíz, las causas encadenadas por «causa padre» y, al lado, el equipo investigador y las causas por tipo.
// Editor visual: en cada nodo se agrega una causa que depende de él, se edita, se mueve a otra causa (sin ciclos), se
// reordena entre sus hermanas o se quita (sus hijas suben a la causa superior; la acción correctiva se conserva en ACR).
// Con el informe aprobado o cerrado (RN-INV-05) o sin permiso el árbol solo se consulta; el servidor también lo impide.

import { useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import type { RecordRow } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { FindingAction } from "@/components/records/Process";
import { Card } from "./Summary";

type Cause = {
  id: string;
  parent_cause_id: string | null;
  cause_level: string;
  cause_category: string | null;
  description: string;
  corrective_action_id: string | null;
  action_code: string | null;
};
type Member = { id: string; name: string; role: string };
type Can = { create: boolean; update: boolean; delete: boolean; action: boolean };
type Tree = {
  investigation: { code: string | null; method: string; status: string; event_code: string | null; event_description: string | null };
  causes: Cause[];
  team: Member[];
  locked: boolean;
  can: Can;
};
type Draft = { cause_level: string; cause_category: string; description: string };
type Dialog =
  | { kind: "add"; parent: Cause | null }
  | { kind: "edit"; cause: Cause }
  | { kind: "move"; cause: Cause }
  | { kind: "delete"; cause: Cause };

const LEVEL: Record<string, string> = { immediate: "Causa inmediata", basic: "Causa básica", root: "Causa raíz", lack_of_control: "Falta de control" };
const LEVEL_PLURAL: [string, string][] = [["immediate", "Causas inmediatas"], ["basic", "Causas básicas"], ["root", "Causas raíz"], ["lack_of_control", "Falta de control"]];
const CATEGORY: Record<string, string> = { unsafe_act: "Acto", unsafe_condition: "Condición", personal_factor: "Factor personal", job_factor: "Factor de trabajo", management_system: "Sistema de gestión" };
const ROLE: Record<string, string> = { leader: "Líder", member: "Integrante", committee_representative: "Representante del comité", worker_representative: "Representante de los trabajadores", expert: "Especialista", witness: "Testigo" };
const BOX: Record<string, string> = { immediate: "bg-brand-soft border-brand/40", basic: "bg-surface border-line", root: "bg-amber-soft border-[#F4B90F]/60", lack_of_control: "bg-na-soft border-line" };
/** RN-INV-02: niveles que llevan acción correctiva (los mismos que exige la aprobación). */
const NEEDS_ACTION = new Set(["basic", "root", "lack_of_control"]);
/** Nivel propuesto para una causa nueva según de qué depende: evento → inmediata → básica → falta de control. */
const NEXT_LEVEL: Record<string, string> = { root: "immediate", immediate: "basic", basic: "lack_of_control", lack_of_control: "lack_of_control" };

const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((x) => x[0]?.toUpperCase()).join("");
const short = (t: string, n = 70) => (t.length > n ? `${t.slice(0, n - 1)}…` : t);

export function InvCauseTree({ row, readOnly = false }: { row: RecordRow; readOnly?: boolean }) {
  const [data, setData] = useState<Tree | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [actionFor, setActionFor] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const version = String(row.updated_at ?? "");
  useEffect(() => {
    api<{ data: Tree }>(`/inv/investigations/${row.id}/tree`, { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [row.id, version, tick]);
  const refresh = () => setTick((t) => t + 1);

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

  const open = !readOnly && !data.locked;
  const can = { create: open && data.can.create, update: open && data.can.update, delete: open && data.can.delete, action: !readOnly && data.can.action };
  const editing = can.create || can.update || can.delete;

  // Reordenar entre hermanas: la causa sube o baja una posición.
  const shift = async (c: Cause, siblings: Cause[], by: number) => {
    const at = siblings.findIndex((s) => s.id === c.id) + by;
    try {
      await api(`/inv/investigations/${row.id}/tree/causes/${c.id}/move`, { method: "POST", body: { parent_cause_id: c.parent_cause_id, position: at }, withCompany: true });
      refresh();
    } catch (e) {
      setNotice((e as ApiError).detail ?? (e as ApiError).title);
    }
  };

  const node = (c: Cause, siblings: Cause[], depth: number): React.ReactNode => {
    const i = siblings.findIndex((s) => s.id === c.id);
    const noAction = NEEDS_ACTION.has(c.cause_level) && !c.corrective_action_id;
    return (
      <div key={c.id} className="space-y-2">
        <div className={`rounded-lg border px-3 py-2 text-sm ${BOX[c.cause_level] ?? BOX.basic}`}>
          {c.description}
          <span className="mt-0.5 flex flex-wrap items-center gap-x-1 text-[11px] text-muted">
            {LEVEL[c.cause_level] ?? c.cause_level}
            {c.cause_category ? ` · ${CATEGORY[c.cause_category] ?? c.cause_category}` : ""}
            {c.corrective_action_id && ` · acción ${c.action_code ?? "correctiva"}`}
            {noAction && <span className="rounded-full bg-amber-soft px-1.5 font-semibold text-amber-ink">Sin acción</span>}
          </span>
          {(editing || (noAction && can.action)) && (
            <div className="mt-1.5 flex flex-wrap gap-1">
              {can.create && <Button size="sm" variant="ghost" className="!h-7 !px-2 text-xs" icon="plus" onClick={() => setDialog({ kind: "add", parent: c })}>Causa</Button>}
              {can.update && <Button size="sm" variant="ghost" className="!h-7 !px-2 text-xs" icon="pen" onClick={() => setDialog({ kind: "edit", cause: c })}>Editar</Button>}
              {can.update && <Button size="sm" variant="ghost" className="!h-7 !px-2 text-xs" icon="swap" onClick={() => setDialog({ kind: "move", cause: c })}>Mover</Button>}
              {can.update && i > 0 && <Button size="sm" variant="ghost" className="!h-7 !px-2 text-xs" aria-label="Subir en el orden" onClick={() => shift(c, siblings, -1)}>↑</Button>}
              {can.update && i < siblings.length - 1 && <Button size="sm" variant="ghost" className="!h-7 !px-2 text-xs" aria-label="Bajar en el orden" onClick={() => shift(c, siblings, 1)}>↓</Button>}
              {can.delete && <Button size="sm" variant="danger-ghost" className="!h-7 !px-2 text-xs" icon="trash" onClick={() => setDialog({ kind: "delete", cause: c })}>Quitar</Button>}
              {noAction && can.action && (
                <Button size="sm" variant="ghost" className="!h-7 !px-2 text-xs" icon="link" onClick={() => setActionFor(actionFor === c.id ? null : c.id)}>Acción correctiva</Button>
              )}
            </div>
          )}
        </div>
        {actionFor === c.id && noAction && (
          <FindingAction table="investigation_causes" row={{ id: c.id, own: true, corrective_action_id: c.corrective_action_id }} onChanged={() => { setActionFor(null); refresh(); }} />
        )}
        {(kids.get(c.id) ?? []).length > 0 && depth < 8 && (
          <div className="ml-5 space-y-2 border-l-2 border-dashed border-line pl-3">{(kids.get(c.id) ?? []).map((k) => node(k, kids.get(c.id) ?? [], depth + 1))}</div>
        )}
      </div>
    );
  };
  const top = kids.get("root") ?? [];
  const inv = data.investigation;

  return (
    <div className="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card
        title={`${inv.code ?? "Investigación"} · Árbol de causas`}
        action={<button type="button" className="text-xs font-semibold text-brand hover:underline" onClick={refresh}>Actualizar árbol</button>}
      >
        {data.locked && !readOnly && (
          <p className="mb-3 rounded-lg border border-line bg-bg px-3 py-2 text-xs text-muted">
            {inv.status === "closed" ? "La investigación está cerrada: el árbol solo se consulta." : "El informe está aprobado: el árbol ya no se edita. Los nuevos hallazgos van como adenda."}
          </p>
        )}
        {notice && (
          <p className="mb-3 flex items-start justify-between gap-2 rounded-lg border border-bad/40 bg-bad-soft px-3 py-2 text-xs text-bad">
            {notice}
            <button type="button" className="font-semibold" aria-label="Cerrar aviso" onClick={() => setNotice(null)}>×</button>
          </p>
        )}
        <div className="space-y-2">
          <div className="rounded-lg border border-bad bg-bad-soft px-3 py-2 text-sm font-bold">
            {inv.event_description || "Evento investigado"}
            {inv.event_code && <span className="block text-[11px] font-normal text-muted">{inv.event_code}</span>}
            {can.create && (
              <div className="mt-1.5">
                <Button size="sm" variant="ghost" className="!h-7 !px-2 text-xs" icon="plus" onClick={() => setDialog({ kind: "add", parent: null })}>Causa</Button>
              </div>
            )}
          </div>
          {top.length > 0 ? (
            <div className="ml-5 space-y-2 border-l-2 border-dashed border-line pl-3">{top.map((c) => node(c, top, 0))}</div>
          ) : (
            <p className="text-sm text-muted">
              {can.create ? "Aún no hay causas. Usa «+ Causa» en el evento para agregar las causas inmediatas y, en cada una, las causas que la explican." : "Aún no hay causas."}
            </p>
          )}
        </div>
        <p className="mt-3 text-[11px] text-muted">Rojo: el evento. Azul: causas inmediatas (hechos). Blanco: causas básicas. Ámbar: causas raíz. Cada causa básica, raíz o falta de control necesita su acción correctiva.</p>
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
      {dialog && <CauseDialog investigationId={row.id} dialog={dialog} causes={data.causes} kids={kids} onClose={() => setDialog(null)} onDone={() => { setDialog(null); refresh(); }} />}
    </div>
  );
}

/** Ventanas del editor: agregar, editar, mover y quitar una causa. */
function CauseDialog({ investigationId, dialog, causes, kids, onClose, onDone }: {
  investigationId: string;
  dialog: Dialog;
  causes: Cause[];
  kids: Map<string, Cause[]>;
  onClose: () => void;
  onDone: () => void;
}) {
  const base = `/inv/investigations/${investigationId}/tree/causes`;
  const cause = dialog.kind === "add" ? null : dialog.cause;
  const [draft, setDraft] = useState<Draft>(() =>
    cause
      ? { cause_level: cause.cause_level, cause_category: cause.cause_category ?? "", description: cause.description }
      : { cause_level: NEXT_LEVEL[dialog.kind === "add" ? (dialog.parent?.cause_level ?? "root") : "root"] ?? "basic", cause_category: "", description: "" },
  );
  const [parent, setParent] = useState<string>(cause?.parent_cause_id ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  // Destinos válidos al mover: ni la causa misma ni las que dependen de ella.
  const blocked = useMemo(() => {
    const out = new Set<string>();
    if (!cause) return out;
    const walk = (id: string) => {
      out.add(id);
      for (const k of kids.get(id) ?? []) walk(k.id);
    };
    walk(cause.id);
    return out;
  }, [cause, kids]);
  const children = cause ? (kids.get(cause.id) ?? []).length : 0;

  const send = async () => {
    setBusy(true);
    setErrors({});
    try {
      if (dialog.kind === "add") {
        await api(base, { method: "POST", body: { ...draft, cause_category: draft.cause_category || null, parent_cause_id: dialog.parent?.id ?? null }, withCompany: true });
      } else if (dialog.kind === "edit") {
        await api(`${base}/${dialog.cause.id}`, { method: "PATCH", body: { ...draft, cause_category: draft.cause_category || null }, withCompany: true });
      } else if (dialog.kind === "move") {
        await api(`${base}/${dialog.cause.id}/move`, { method: "POST", body: { parent_cause_id: parent || null }, withCompany: true });
      } else {
        await api(`${base}/${dialog.cause.id}`, { method: "DELETE", withCompany: true });
      }
      onDone();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? { _: [err.detail ?? err.title] });
    } finally {
      setBusy(false);
    }
  };

  const title = { add: "Agregar causa", edit: "Editar causa", move: "Mover causa", delete: "Quitar causa" }[dialog.kind];
  const subtitle = dialog.kind === "add" ? `Depende de: ${dialog.parent ? short(dialog.parent.description) : "el evento"}` : short(dialog.cause.description);
  const general = errors._?.[0] ?? (dialog.kind === "move" || dialog.kind === "delete" ? Object.values(errors)[0]?.[0] : undefined);

  return (
    <Modal title={title} subtitle={subtitle} icon={dialog.kind === "delete" ? "trash" : dialog.kind === "move" ? "swap" : dialog.kind === "edit" ? "pen" : "plus"} size="sm" z={60} onClose={onClose}
      footer={
        <div className="flex w-full justify-end gap-2">
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant={dialog.kind === "delete" ? "danger" : "primary"} loading={busy} onClick={send}>
            {dialog.kind === "delete" ? "Quitar" : dialog.kind === "move" ? "Mover" : "Guardar"}
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        {(dialog.kind === "add" || dialog.kind === "edit") && (
          <>
            <FormField label="Nivel de causa *" error={errors.cause_level?.[0]}>
              <select className="input" value={draft.cause_level} onChange={(e) => setDraft({ ...draft, cause_level: e.target.value })}>
                {Object.entries(LEVEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </FormField>
            <FormField label="Categoría" error={errors.cause_category?.[0]}>
              <select className="input" value={draft.cause_category} onChange={(e) => setDraft({ ...draft, cause_category: e.target.value })}>
                <option value="">Sin categoría</option>
                {Object.entries(CATEGORY).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </FormField>
            <FormField label="Descripción *" error={errors.description?.[0]}>
              <textarea className="input min-h-[5rem]" value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} />
            </FormField>
            {errors.parent_cause_id?.[0] && <p className="text-xs font-medium text-bad">{errors.parent_cause_id[0]}</p>}
          </>
        )}
        {dialog.kind === "move" && (
          <FormField label="Depende de *" hint="Una causa no puede depender de sí misma ni de una causa que depende de ella.">
            <select className="input" value={parent} onChange={(e) => setParent(e.target.value)}>
              <option value="">El evento (raíz del árbol)</option>
              {causes.filter((c) => !blocked.has(c.id)).map((c) => (
                <option key={c.id} value={c.id}>{`${LEVEL[c.cause_level] ?? c.cause_level}: ${short(c.description, 60)}`}</option>
              ))}
            </select>
          </FormField>
        )}
        {dialog.kind === "delete" && (
          <div className="space-y-2 text-sm">
            <p>La causa sale del árbol (queda en la bitácora).</p>
            {children > 0 && <p>{children === 1 ? "Su causa hija pasa" : `Sus ${children} causas hijas pasan`} a depender de {dialog.cause.parent_cause_id ? "la causa superior" : "el evento"}.</p>}
            {dialog.cause.corrective_action_id && <p>Su acción correctiva {dialog.cause.action_code ?? ""} no se borra: sigue en Acciones correctivas.</p>}
          </div>
        )}
        {general && <p className="text-xs font-medium text-bad">{general}</p>}
      </div>
    </Modal>
  );
}
