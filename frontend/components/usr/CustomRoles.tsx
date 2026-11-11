"use client";

// USR · «Roles» (M-USR SCR-USR-04, RN-USR-03, RN-USR-09): roles base en solo lectura y roles personalizados de la cuenta.
// Un rol personalizado parte de un rol base, conserva su alcance y solo puede tener permisos que el rol base tiene; los
// permisos médicos sensibles solo existen en roles derivados de un rol médico. Editar es para quien tiene
// usr.roles.create/update (administrador de cuenta); los demás ven todo en solo lectura.

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { SearchInput } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";

type RoleRow = {
  id: string;
  code: string;
  name: string;
  description: string | null;
  base_role_code: string;
  base_role_name: string;
  scope_level: string;
  is_medical: boolean;
  requires_mfa: boolean;
  is_custom: boolean;
  members: number;
  permissions: string[];
};
type Perm = { code: string; resource: string; resource_label: string; action: string; action_label: string; is_sensitive: boolean; is_medical: boolean };
type Mod = { code: string; number: string; name: string; mode: string; available: boolean; is_addon: boolean; permissions: Perm[] };
type Data = { roles: RoleRow[]; custom_roles: RoleRow[]; modules: Mod[]; actions: Record<string, string>; can: { create: boolean; update: boolean; delete: boolean } };

/** Columnas fijas de la matriz; las demás acciones del catálogo salen como casillas extra. */
const MAIN_ACTIONS: [string, string][] = [["read", "Ver"], ["create", "Crear"], ["update", "Editar"], ["delete", "Eliminar"], ["export", "Exportar"]];
const SCOPES: Record<string, string> = { account: "Toda la cuenta", company: "Toda la empresa", scoped: "Sedes o áreas asignadas", own: "Solo lo suyo", platform: "Plataforma" };
const MODE: Record<string, [string, "ok" | "amber" | "na"]> = {
  enabled: ["Contratado", "ok"],
  read_only: ["Solo lectura", "amber"],
  locked: ["No contratado", "na"],
  disabled_by_company: ["Desactivado", "na"],
};

type Editing = { kind: "view"; base: RoleRow } | { kind: "create"; base: RoleRow } | { kind: "edit"; role: RoleRow; base: RoleRow };

export function CustomRoles({ readOnly }: { readOnly: boolean }) {
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [deleting, setDeleting] = useState<RoleRow | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Data }>("/company/roles/manage", { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(load, [load]);

  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>;
  if (!data) return <p className="text-sm text-muted">Cargando roles…</p>;

  const can = { create: !readOnly && data.can.create, update: !readOnly && data.can.update, delete: !readOnly && data.can.delete };
  const baseOf = (code: string) => data.roles.find((r) => r.code === code);
  const done = (msg: string) => {
    setEditing(null);
    setDeleting(null);
    setToast(msg);
    load();
  };

  return (
    <div className="space-y-6">
      {!can.create && !can.update && (
        <p className="rounded-lg bg-bg px-4 py-3 text-sm text-muted">Ves los roles en solo lectura. Los roles personalizados los crea y edita el administrador de la cuenta.</p>
      )}
      <section className="space-y-3">
        <div>
          <h3 className="font-semibold">Roles personalizados de la cuenta</h3>
          <p className="text-sm text-muted">Parten de un rol base, con su mismo alcance, y solo pueden tener permisos que ese rol base tiene.</p>
        </div>
        {data.custom_roles.length === 0 ? (
          <EmptyState icon="people" text={can.create ? "Aún no hay roles personalizados. Crea uno a partir de un rol base." : "La cuenta no tiene roles personalizados."} />
        ) : (
          <ul className="divide-y divide-line rounded-2xl border border-line bg-surface">
            {data.custom_roles.map((r) => {
              const base = baseOf(r.base_role_code);
              const less = base ? base.permissions.length - r.permissions.length : 0;
              return (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <p className="font-medium">{r.name}</p>
                    <p className="text-xs text-muted">
                      A partir de «{r.base_role_name}» · {r.permissions.length} permiso(s){less > 0 ? ` · ${less} menos que el rol base` : ""} · {r.members} persona(s) en esta empresa
                    </p>
                    {r.description && <p className="text-sm text-muted">{r.description}</p>}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {r.is_medical && <Chip tone="brand">Médico</Chip>}
                    {base && (
                      <Button size="sm" variant={can.update ? "soft" : "ghost"} icon={can.update ? "pen" : "eye"} onClick={() => setEditing({ kind: "edit", role: r, base })}>
                        {can.update ? "Editar permisos" : "Ver permisos"}
                      </Button>
                    )}
                    {can.delete && (
                      <Button size="sm" variant="danger-ghost" icon="trash" onClick={() => setDeleting(r)}>
                        Eliminar
                      </Button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      <section className="space-y-3">
        <div>
          <h3 className="font-semibold">Roles base</h3>
          <p className="text-sm text-muted">Vienen con EscudoSST y no se editan. Para ajustar lo que puede hacer alguien, crea un rol a partir de uno de ellos.</p>
        </div>
        <ul className="grid gap-3 md:grid-cols-2">
          {data.roles.map((r) => (
            <li key={r.id} className="flex flex-col gap-2 rounded-2xl border border-line bg-surface p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="flex items-center gap-1.5 font-medium">
                    <Icon name="lock" size={15} className="text-muted" />
                    {r.name}
                  </p>
                  <p className="text-xs text-muted">Rol base · no se edita · {SCOPES[r.scope_level] ?? r.scope_level}</p>
                </div>
                <div className="flex shrink-0 gap-1">
                  {r.is_medical && <Chip tone="brand">Médico</Chip>}
                  {r.requires_mfa && <Chip tone="na">Doble factor</Chip>}
                </div>
              </div>
              {r.description && <p className="text-sm text-muted">{r.description}</p>}
              <p className="text-xs text-muted">{r.permissions.length} permiso(s) · {r.members} persona(s) en esta empresa</p>
              <div className="mt-auto flex flex-wrap gap-2">
                <Button size="sm" variant="ghost" icon="eye" onClick={() => setEditing({ kind: "view", base: r })}>
                  Ver permisos
                </Button>
                {can.create && (
                  <Button size="sm" variant="soft" icon="plus" onClick={() => setEditing({ kind: "create", base: r })}>
                    Crear rol a partir de este
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </section>

      {editing && (
        <RoleEditor
          editing={editing}
          modules={data.modules}
          canSave={editing.kind === "create" ? can.create : editing.kind === "edit" ? can.update : false}
          onClose={() => setEditing(null)}
          onDone={done}
        />
      )}
      {deleting && <DeleteRole role={deleting} options={[...data.roles, ...data.custom_roles].filter((r) => r.id !== deleting.id)} onClose={() => setDeleting(null)} onDone={done} />}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

/** Editor de permisos por módulo: Ver / Crear / Editar / Eliminar / Exportar y las demás acciones como casillas extra. */
function RoleEditor({ editing, modules, canSave, onClose, onDone }: { editing: Editing; modules: Mod[]; canSave: boolean; onClose: () => void; onDone: (msg: string) => void }) {
  const base = editing.base;
  const role = editing.kind === "edit" ? editing.role : null;
  const initial = role ? role.permissions : base.permissions;
  const [selected, setSelected] = useState<Set<string>>(() => new Set(initial));
  const [name, setName] = useState(role?.name ?? "");
  const [description, setDescription] = useState(role?.description ?? "");
  const [q, setQ] = useState("");
  const [onlyChanged, setOnlyChanged] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [detail, setDetail] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const editable = canSave && editing.kind !== "view";
  const inBase = useMemo(() => new Set(base.permissions), [base]);

  // Solo los módulos donde el rol base tiene algún permiso: lo demás nunca se puede dar.
  const shown = useMemo(() => {
    const term = q.trim().toLowerCase();
    return modules
      .map((m) => ({
        ...m,
        permissions: m.permissions.filter(
          (p) =>
            inBase.has(p.code) &&
            (!onlyChanged || !selected.has(p.code)) &&
            (!term || `${m.name} ${m.code} ${p.resource_label} ${p.action_label} ${p.code}`.toLowerCase().includes(term)),
        ),
      }))
      .filter((m) => m.permissions.length > 0);
  }, [modules, inBase, q, onlyChanged, selected]);

  const removedFromBase = base.permissions.filter((c) => !selected.has(c)).length;
  const added = role ? [...selected].filter((c) => !role.permissions.includes(c)).length : 0;
  const removed = role ? role.permissions.filter((c) => !selected.has(c)).length : 0;

  const toggle = (codes: string[], on: boolean) => {
    if (!editable) return;
    setSelected((prev) => {
      const next = new Set(prev);
      codes.forEach((c) => (on ? next.add(c) : next.delete(c)));
      return next;
    });
  };

  const save = async () => {
    setBusy(true);
    setErrors({});
    setDetail(null);
    try {
      const permissions = [...selected];
      if (editing.kind === "create") {
        await api("/company/roles", { method: "POST", withCompany: true, body: { base_role_code: base.code, name, description: description || null, permissions } });
        onDone(`Rol «${name}» creado.`);
      } else if (role) {
        await api(`/company/roles/${role.id}`, { method: "PATCH", withCompany: true, body: { name, description: description || null, permissions } });
        onDone("Rol actualizado. Los permisos se aplican desde el siguiente pedido de cada persona.");
      }
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setDetail(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };

  const title = editing.kind === "create" ? "Crear rol personalizado" : editing.kind === "edit" ? (editable ? "Editar permisos" : "Permisos del rol") : "Permisos del rol base";
  const subtitle = editing.kind === "view" ? `${base.name} · Rol base · no se edita` : `A partir de «${base.name}» · ${SCOPES[base.scope_level] ?? base.scope_level}`;

  return (
    <Modal title={title} subtitle={subtitle} icon={editing.kind === "view" ? "lock" : "shield"} size="lg" onClose={onClose}
      footer={
        <div className="flex w-full flex-wrap items-center justify-between gap-2">
          <p className="text-sm text-muted">
            {selected.size} de {base.permissions.length} permiso(s) del rol base
            {removedFromBase > 0 && editing.kind !== "view" ? ` · ${removedFromBase} quitado(s)` : ""}
            {role && (added || removed) ? ` · cambios: +${added} / −${removed}` : ""}
          </p>
          <div className="flex gap-2">
            <Button onClick={onClose}>{editable ? "Cancelar" : "Cerrar"}</Button>
            {editable && (
              <Button variant="primary" loading={busy} disabled={selected.size === 0 || name.trim().length < 3} onClick={save}>
                {editing.kind === "create" ? "Crear rol" : "Guardar"}
              </Button>
            )}
          </div>
        </div>
      }
    >
      <div className="space-y-4">
        {detail && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{detail}</p>}
        {editing.kind !== "view" && (
          <div className="grid gap-3 md:grid-cols-2">
            <FormField label="Nombre del rol *" error={errors.name?.[0]}>
              <input className="input" value={name} disabled={!editable} maxLength={80} onChange={(e) => setName(e.target.value)} placeholder="Ej.: Supervisor de turno noche" />
            </FormField>
            <FormField label="Descripción" error={errors.description?.[0]}>
              <input className="input" value={description} disabled={!editable} maxLength={500} onChange={(e) => setDescription(e.target.value)} placeholder="Para qué sirve este rol" />
            </FormField>
          </div>
        )}
        <p className="text-xs text-muted">
          Un permiso de un módulo que la empresa no tiene contratado no tiene efecto hasta que lo contrate. Sin «Ver», el módulo no aparece en el menú.
          {!base.is_medical && " Este rol no es médico: no puede tener permisos con datos de salud."}
        </p>
        <div className="flex flex-wrap items-end gap-3">
          <SearchInput label="Buscar permiso" value={q} onChange={setQ} placeholder="Módulo, registro o acción" />
          {editing.kind !== "view" && (
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={onlyChanged} onChange={(e) => setOnlyChanged(e.target.checked)} />
              Solo lo quitado del rol base
            </label>
          )}
        </div>
        {shown.length === 0 && <EmptyState icon="search" text="No hay permisos con ese filtro." />}
        {shown.map((m) => (
          <ModuleBlock key={m.code} mod={m} selected={selected} editable={editable} medicalBase={base.is_medical} onToggle={toggle} />
        ))}
      </div>
    </Modal>
  );
}

function ModuleBlock({ mod, selected, editable, medicalBase, onToggle }: { mod: Mod; selected: Set<string>; editable: boolean; medicalBase: boolean; onToggle: (codes: string[], on: boolean) => void }) {
  const codes = mod.permissions.map((p) => p.code);
  const count = codes.filter((c) => selected.has(c)).length;
  const all = count === codes.length;
  const hasRead = mod.permissions.some((p) => (p.action === "read" || p.action === "read_sensitive") && selected.has(p.code));
  const resources = Array.from(new Map(mod.permissions.map((p) => [p.resource, p.resource_label])).entries());
  const [mode, tone] = MODE[mod.mode] ?? [mod.mode, "na"];

  return (
    <section className={`rounded-2xl border border-line ${mod.available ? "bg-surface" : "bg-bg"}`}>
      <header className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-2">
        <div className="flex flex-wrap items-center gap-2">
          <p className="font-semibold">{mod.number ? `${mod.number} · ` : ""}{mod.name}</p>
          <Chip tone={tone}>{mod.is_addon && !mod.available ? "Complemento no contratado" : mode}</Chip>
          {count > 0 && !hasRead && <Chip tone="amber">Sin «Ver»: no se muestra en el menú</Chip>}
        </div>
        <div className="flex items-center gap-3 text-sm text-muted">
          <span>{count} de {codes.length}</span>
          {editable && (
            <label className="flex items-center gap-1.5">
              <input type="checkbox" checked={all} ref={(el) => { if (el) el.indeterminate = count > 0 && !all; }} onChange={(e) => onToggle(codes, e.target.checked)} aria-label={`Seleccionar todo ${mod.name}`} />
              Todo
            </label>
          )}
        </div>
      </header>
      {!mod.available && <p className="px-4 pt-2 text-xs text-muted">La empresa no tiene este módulo en su plan: estos permisos no tienen efecto por ahora.</p>}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="text-xs text-muted">
            <tr>
              <th className="px-4 py-2 font-semibold">Registro</th>
              {MAIN_ACTIONS.map(([, l]) => (
                <th key={l} className="px-2 py-2 text-center font-semibold">{l}</th>
              ))}
              <th className="px-2 py-2 font-semibold">Otras acciones</th>
            </tr>
          </thead>
          <tbody>
            {resources.map(([res, label]) => {
              const perms = mod.permissions.filter((p) => p.resource === res);
              const others = perms.filter((p) => !MAIN_ACTIONS.some(([a]) => a === p.action));
              const box = (p: Perm, text?: string) => {
                const blocked = p.is_medical && !medicalBase && !selected.has(p.code);
                return (
                  <label key={p.code} className="inline-flex items-center gap-1.5" title={p.code}>
                    <input type="checkbox" checked={selected.has(p.code)} disabled={!editable || blocked} onChange={(e) => onToggle([p.code], e.target.checked)}
                      aria-label={`${label}: ${p.action_label}`} />
                    {text}
                    {p.is_sensitive && <span className="text-[10px] font-bold text-bad">{p.is_medical ? "Médico" : "Sensible"}</span>}
                  </label>
                );
              };
              return (
                <tr key={res} className="border-t border-line">
                  <th scope="row" className="px-4 py-2 font-medium">{label}</th>
                  {MAIN_ACTIONS.map(([a]) => {
                    const p = perms.find((x) => x.action === a);
                    return <td key={a} className="px-2 py-2 text-center">{p ? box(p) : <span className="text-muted">—</span>}</td>;
                  })}
                  <td className="px-2 py-2">
                    <div className="flex flex-wrap gap-x-3 gap-y-1">{others.map((p) => box(p, p.action_label))}</div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}

/** Eliminar un rol personalizado: si alguien lo usa, pide a qué rol pasarlo. */
function DeleteRole({ role, options, onClose, onDone }: { role: RoleRow; options: RoleRow[]; onClose: () => void; onDone: (msg: string) => void }) {
  const [inUse, setInUse] = useState<string | null>(role.members > 0 ? `${role.members} persona(s) de esta empresa tienen este rol.` : null);
  const [target, setTarget] = useState(role.base_role_code);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { reassigned: number } }>(`/company/roles/${role.id}`, { method: "DELETE", withCompany: true, body: inUse ? { reassign_to: target } : {} });
      onDone(r.data.reassigned > 0 ? `Rol eliminado. ${r.data.reassigned} persona(s) pasaron a otro rol.` : "Rol eliminado.");
    } catch (e) {
      const err = e as ApiError;
      if (err.status === 409 && !inUse) setInUse(err.detail ?? "Hay personas con este rol.");
      else setError(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title="Eliminar rol" subtitle={role.name} icon="trash" size="sm" onClose={onClose}
      footer={
        <div className="flex w-full justify-end gap-2">
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="danger" loading={busy} onClick={run}>{inUse ? "Reasignar y eliminar" : "Eliminar"}</Button>
        </div>
      }
    >
      <div className="space-y-3 text-sm">
        {error && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-bad">{error}</p>}
        {inUse ? (
          <>
            <p className="rounded-lg bg-amber-soft px-3 py-2 text-amber-ink">{inUse} Elige a qué rol pasarán (en todas las empresas de la cuenta).</p>
            <FormField label="Pasar a">
              <select className="input" value={target} onChange={(e) => setTarget(e.target.value)}>
                {options.map((o) => (
                  <option key={o.id} value={o.code}>
                    {o.name}{o.is_custom ? " (personalizado)" : ""}
                  </option>
                ))}
              </select>
            </FormField>
          </>
        ) : (
          <p>El rol deja de estar disponible para invitar o cambiar el rol de una persona. Queda registrado en la bitácora.</p>
        )}
      </div>
    </Modal>
  );
}
