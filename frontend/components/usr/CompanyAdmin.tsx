"use client";

// USR · Usuarios de la empresa (invitar con rol, cambiar rol, suspender) y AUD · Bitácora de la empresa.
// En la bitácora cada fila abre el registro que cambió (trazabilidad).

import Link from "next/link";
import { Fragment, useCallback, useEffect, useState } from "react";
import { api, ApiError, API_URL } from "@/lib/api";
import { session } from "@/lib/session";
import { recordsHref } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { useApp } from "@/components/AppContext";
import { EditAccessModal, expiresText, lastAccess, MfaChip, RolesMatrix, ScopeFields, scopeText, type Scope } from "@/components/usr/UsersExtras";
import { CustomRoles } from "@/components/usr/CustomRoles";

type Member = {
  id: string;
  status: "invited" | "active" | "suspended" | "expired" | "revoked";
  full_name: string;
  email: string;
  role_code: string;
  base_role_code?: string;
  is_custom_role?: boolean;
  role_name: string;
  worker_name: string | null;
  last_access_at: string | null;
  expires_at?: string | null;
  mfa_enabled?: boolean;
  requires_mfa?: boolean;
  scopes?: Scope[];
};
type Role = { code: string; name: string; description: string | null; requires_mfa: boolean; base_role_code: string; is_custom: boolean };

/** Opciones del selector de rol: roles base y, aparte, los personalizados de la cuenta. */
function RoleOptions({ roles }: { roles: Role[] }) {
  const custom = roles.filter((r) => r.is_custom);
  const base = roles.filter((r) => !r.is_custom);
  const opts = (list: Role[]) => list.map((r) => <option key={r.code} value={r.code}>{r.name}</option>);
  return custom.length === 0 ? <>{opts(base)}</> : (
    <>
      <optgroup label="Roles base">{opts(base)}</optgroup>
      <optgroup label="Roles personalizados">{opts(custom)}</optgroup>
    </>
  );
}

const STATUS: Record<string, [string, "ok" | "amber" | "bad" | "na"]> = {
  active: ["Activo", "ok"],
  invited: ["Invitado", "amber"],
  suspended: ["Suspendido", "bad"],
  expired: ["Vencido", "na"],
  revoked: ["Revocado", "na"],
};

export function CompanyUsers({ readOnly }: { readOnly: boolean }) {
  const { user } = useApp();
  const [rows, setRows] = useState<Member[] | null>(null);
  const [roles, setRoles] = useState<Role[]>([]);
  const [filter, setFilter] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [tab, setTab] = useState<"users" | "roles" | "matrix">("users");
  const [inviting, setInviting] = useState(false);
  const [editing, setEditing] = useState<{ m: Member; role?: string } | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Member[] }>("/company/users", { withCompany: true }).then((r) => setRows(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  const loadRoles = useCallback(() => {
    api<{ data: Role[] }>("/company/roles", { withCompany: true }).then((r) => setRoles(r.data)).catch(() => setRoles([]));
  }, []);
  useEffect(() => {
    load();
    loadRoles();
  }, [load, loadRoles]);

  const act = (fn: () => Promise<unknown>, msg: string) =>
    fn()
      .then(() => {
        setToast(msg);
        load();
      })
      .catch((e: ApiError) => setToast(`⚠ ${e.detail ?? e.title}`));

  const shown = (rows ?? []).filter((m) => (!filter || m.status === filter) && (!roleFilter || m.role_code === roleFilter));
  const roleOptions: [string, string][] = [["", "Todos"], ...Array.from(new Map((rows ?? []).map((m) => [m.role_code, m.role_name])).entries()).sort((a, b) => a[1].localeCompare(b[1]))];

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Usuarios de la empresa</h2>
          <p className="text-sm text-muted">Invita a las personas con su rol. Cada rol ve y hace solo lo que le corresponde.</p>
        </div>
        {!readOnly && tab === "users" && (
          <Button variant="primary" icon="plus" onClick={() => setInviting(true)}>
            Invitar usuario
          </Button>
        )}
      </div>
      <div role="tablist" aria-label="Usuarios y roles" className="flex gap-1 border-b border-line">
        {([["users", "Usuarios"], ["roles", "Roles"], ["matrix", "Matriz de permisos"]] as const).map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => { setTab(k); if (k === "users") { load(); loadRoles(); } }}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${tab === k ? "border-brand text-brand" : "border-transparent text-muted hover:text-fg"}`}>
            {l}
          </button>
        ))}
      </div>
      {tab === "roles" ? <CustomRoles readOnly={readOnly} /> : tab === "matrix" ? <RolesMatrix /> : <>
      <FilterBar count={rows ? `${shown.length} persona(s)` : undefined}>
        <FilterSelect label="Rol" value={roleFilter} onChange={setRoleFilter} options={roleOptions} />
        <FilterSelect label="Estado" value={filter} onChange={setFilter} options={[["", "Todos"], ...Object.entries(STATUS).slice(0, 4).map(([k, [l]]) => [k, l] as [string, string])]} />
      </FilterBar>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}
      {rows && shown.length === 0 && <EmptyState icon="search" text="No hay personas con ese filtro." />}
      {shown.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs uppercase tracking-wide text-muted">
              <tr>
                <th className="px-4 py-3">Persona</th>
                <th className="px-4 py-3">Correo</th>
                <th className="px-4 py-3">Rol</th>
                <th className="px-4 py-3">Alcance</th>
                <th className="px-4 py-3">Doble factor</th>
                <th className="px-4 py-3">Último acceso</th>
                <th className="px-4 py-3">Estado</th>
                <th className="px-4 py-3">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {shown.map((m) => (
                <tr key={m.id} className="border-b border-line last:border-0">
                  <td className="px-4 py-3">
                    <p className="font-medium">
                      {m.full_name} {m.email === user.email && <span className="text-xs text-muted">(tú)</span>}
                    </p>
                    {m.worker_name && <p className="text-xs text-muted">Ficha: {m.worker_name}</p>}
                  </td>
                  <td className="px-4 py-3 text-muted">{m.email}</td>
                  <td className="px-4 py-3">
                    {readOnly || m.email === user.email ? (
                      m.role_name
                    ) : (
                      <select className="input !h-9 !py-0 text-sm" aria-label={`Rol de ${m.full_name}`} value={m.role_code}
                        onChange={(e) => {
                          const role = e.target.value;
                          // El auditor externo (o un rol derivado de él) necesita fecha de fin: se pide en «Editar acceso» antes de guardar el rol.
                          if (roles.find((r) => r.code === role)?.base_role_code === "external_auditor" && !m.expires_at) setEditing({ m, role });
                          else act(() => api(`/company/users/${m.id}`, { method: "PATCH", body: { role_code: role }, withCompany: true }), "Rol actualizado.");
                        }}>
                        {!roles.some((r) => r.code === m.role_code) && <option value={m.role_code}>{m.role_name}</option>}
                        <RoleOptions roles={roles} />
                      </select>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <p>{scopeText(m.scopes)}</p>
                    {expiresText(m.expires_at ?? null) && <p className="text-xs text-muted">{expiresText(m.expires_at ?? null)}</p>}
                  </td>
                  <td className="px-4 py-3">
                    <MfaChip enabled={!!m.mfa_enabled} required={!!m.requires_mfa} />
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-muted">{lastAccess(m.last_access_at)}</td>
                  <td className="px-4 py-3">
                    <Chip tone={STATUS[m.status]?.[1] ?? "na"}>{STATUS[m.status]?.[0] ?? m.status}</Chip>
                  </td>
                  <td className="px-4 py-3">
                    {!readOnly && m.email !== user.email && (
                      <div className="flex flex-wrap gap-2">
                        {m.status !== "revoked" && (
                          <Button size="sm" variant="ghost" onClick={() => setEditing({ m })}>
                            Editar acceso
                          </Button>
                        )}
                        {m.status === "invited" && (
                          <Button size="sm" onClick={() => act(() => api(`/company/users/${m.id}/resend`, { method: "POST", withCompany: true }), "Invitación reenviada.")}>
                            Reenviar
                          </Button>
                        )}
                        {m.status === "active" && (
                          <Button size="sm" variant="danger-ghost" onClick={() => act(() => api(`/company/users/${m.id}`, { method: "PATCH", body: { status: "suspended" }, withCompany: true }), "Acceso suspendido.")}>
                            Suspender
                          </Button>
                        )}
                        {m.status === "suspended" && (
                          <Button size="sm" onClick={() => act(() => api(`/company/users/${m.id}`, { method: "PATCH", body: { status: "active" }, withCompany: true }), "Acceso reactivado.")}>
                            Reactivar
                          </Button>
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      </>}
      {editing && (
        <EditAccessModal member={editing.m} roleCode={editing.role} roleBase={roles.find((r) => r.code === editing.role)?.base_role_code} onClose={() => setEditing(null)} onDone={(msg) => { setEditing(null); setToast(msg); load(); }} />
      )}
      {inviting && <InviteModal roles={roles} onClose={() => setInviting(false)} onDone={(msg) => { setInviting(false); setToast(msg); load(); }} />}
      {toast && <Toast message={toast.replace(/^⚠ /, "")} tone={toast.startsWith("⚠") ? "bad" : "ok"} onClose={() => setToast(null)} />}
    </section>
  );
}

function InviteModal({ roles, onClose, onDone }: { roles: Role[]; onClose: () => void; onDone: (msg: string) => void }) {
  const [form, setForm] = useState({ email: "", full_name: "", role_code: "sst_supervisor" });
  const [scope, setScope] = useState({ site_ids: [] as string[], area_ids: [] as string[], expires_on: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [detail, setDetail] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const role = roles.find((r) => r.code === form.role_code);
  const send = async () => {
    setBusy(true);
    setErrors({});
    setDetail(null);
    try {
      const r = await api<{ data: { status: string } }>("/company/users", { method: "POST", body: { ...form, ...scope, expires_on: scope.expires_on || null }, withCompany: true });
      onDone(r.data.status === "active" ? "Ya tenía cuenta: ahora tiene acceso a esta empresa." : "Invitación enviada por correo.");
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setDetail(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal title="Invitar usuario" subtitle="Le llega un correo para crear su contraseña." icon="plus" size="sm" onClose={onClose}
      footer={
        <div className="flex w-full justify-end gap-2">
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" loading={busy} onClick={send}>Enviar invitación</Button>
        </div>
      }
    >
      <div className="space-y-3">
        {detail && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{detail}</p>}
        <FormField label="Correo *" error={errors.email?.[0]}>
          <input className="input" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
        </FormField>
        <FormField label="Nombre completo *" error={errors.full_name?.[0]}>
          <input className="input" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
        </FormField>
        <FormField label="Rol *" error={errors.role_code?.[0]} hint={role ? `${role.description ?? ""}${role.requires_mfa ? " Pide segundo factor." : ""}`.trim() || undefined : undefined}>
          <select className="input" value={form.role_code} onChange={(e) => setForm({ ...form, role_code: e.target.value })}>
            <RoleOptions roles={roles} />
          </select>
        </FormField>
        <ScopeFields value={scope} onChange={setScope} errors={errors} auditor={role?.base_role_code === "external_auditor"} />
      </div>
    </Modal>
  );
}

// ─────────────── AUD: bitácora de la empresa ───────────────
// SCR-AUD-01 «Bitácora general»: filtros por usuario, módulo y fechas; columna «Desde» (app/web e IP abreviada); al
// expandir una fila, el antes/después de cada campo (los datos protegidos solo dicen que cambiaron).
// SCR-AUD-03 «Accesos a datos sensibles»: quién consultó qué dato de quién, con su motivo y re-autenticación; nunca el
// contenido médico. Cada pestaña aparece solo si el rol tiene el permiso.

type AuditDiff = { field: string; from: string | null; to: string | null; hidden: boolean };
type AuditRow = {
  id: string;
  occurred_at: string;
  action: string;
  module_code: string;
  entity_type: string;
  entity_id: string | null;
  changes: Record<string, unknown> | null;
  diff: AuditDiff[];
  actor_name: string | null;
  is_support_session: boolean;
  ip: string | null;
  channel: string | null;
  tab: string;
  module_of_entity: string | null;
};
type SensitiveRow = {
  id: string; occurred_at: string; user_name: string; role_name: string | null; data_label: string; action: string; purpose: string | null;
  reauth: boolean; result: "granted" | "denied"; deny_reason: string | null; ip: string | null;
};
type AuditFilters = {
  can: { general: boolean; sensitive: boolean };
  users: { id: string; full_name: string }[];
  modules: { code: string; name: string }[];
  sensitive_users: { id: string; full_name: string }[];
};
type PageMeta = { page: number; pages: number; total: number };

const ACTIONS: Record<string, string> = {
  create: "Creó",
  update: "Modificó",
  delete: "Archivó",
  submit: "Envió a aprobación",
  approve: "Aprobó",
  return: "Devolvió con observaciones",
  finish: "Finalizó",
  member_invited: "Invitó",
  member_updated: "Cambió el acceso",
  ack_campaign: "Pidió cargos de lectura",
  signature_requested: "Pidió una firma",
  signature_signed: "Firmó",
  signature_declined: "Rechazó la firma",
  signature_reminded: "Reenvió recordatorio de firma",
};

const fmtAudit = (d: string) => new Date(d).toLocaleString("es-PE", { timeZone: "America/Lima", dateStyle: "short", timeStyle: "short" });

export function CompanyAudit() {
  const [filters, setFilters] = useState<AuditFilters | null>(null);
  const [tab, setTab] = useState<"general" | "sensitive">("general");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: AuditFilters }>("/company/audit/filters", { withCompany: true })
      .then((r) => {
        setFilters(r.data);
        if (!r.data.can.general) setTab("sensitive");
      })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);

  return (
    <section className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold">Bitácora de la empresa</h2>
        <p className="text-sm text-muted">Quién hizo qué y cuándo. Los accesos a datos médicos se registran aparte. Los registros no se pueden modificar.</p>
      </div>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}
      {filters && filters.can.general && filters.can.sensitive && (
        <div role="tablist" aria-label="Bitácora" className="flex gap-1 border-b border-line">
          {([["general", "Bitácora general"], ["sensitive", "Accesos a datos sensibles"]] as const).map(([k, l]) => (
            <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
              className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${tab === k ? "border-brand text-brand" : "border-transparent text-muted hover:text-fg"}`}>
              {l}
            </button>
          ))}
        </div>
      )}
      {filters && (tab === "general" && filters.can.general ? <GeneralAudit filters={filters} /> : filters.can.sensitive ? <SensitiveAudit filters={filters} /> : null)}
    </section>
  );
}

function Pager({ meta, page, setPage }: { meta: PageMeta; page: number; setPage: (p: number) => void }) {
  if (meta.pages <= 1) return null;
  return (
    <div className="flex items-center justify-end gap-2 text-sm">
      <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
      <span className="text-muted">Página {meta.page} de {meta.pages}</span>
      <Button size="sm" disabled={page >= meta.pages} onClick={() => setPage(page + 1)}>Siguiente</Button>
    </div>
  );
}

function DateFilter({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-xs font-semibold text-muted">{label}</span>
      <input className={`filter-control ${value ? "is-active" : ""}`} type="date" value={value} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}

function GeneralAudit({ filters }: { filters: AuditFilters }) {
  const [rows, setRows] = useState<AuditRow[] | null>(null);
  const [meta, setMeta] = useState<PageMeta>({ page: 1, pages: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [f, setF] = useState({ user_id: "", module: "", from: "", to: "" });
  const [open, setOpen] = useState<string | null>(null);

  useEffect(() => {
    const q = new URLSearchParams({ page: String(page) });
    Object.entries(f).forEach(([k, v]) => v && q.set(k, v));
    api<{ data: AuditRow[]; meta: PageMeta }>(`/company/audit?${q}`, { withCompany: true }).then((r) => {
      setRows(r.data);
      setMeta(r.meta);
    });
  }, [page, f]);
  const set = (k: keyof typeof f) => (v: string) => {
    setF({ ...f, [k]: v });
    setPage(1);
  };
  const any = Object.values(f).some(Boolean);

  return (
    <div className="space-y-4">
      <FilterBar count={`${meta.total} movimiento(s)`} onClear={any ? () => { setF({ user_id: "", module: "", from: "", to: "" }); setPage(1); } : undefined}>
        <FilterSelect label="Usuario" value={f.user_id} onChange={set("user_id")} options={[["", "Todos"], ...filters.users.map((u) => [u.id, u.full_name] as [string, string])]} />
        <FilterSelect label="Módulo" value={f.module} onChange={set("module")} options={[["", "Todos"], ...filters.modules.map((m) => [m.code, m.name] as [string, string])]} />
        <DateFilter label="Desde" value={f.from} onChange={set("from")} />
        <DateFilter label="Hasta" value={f.to} onChange={set("to")} />
      </FilterBar>
      {rows && rows.length === 0 && <EmptyState icon="search" text="Sin movimientos con esos filtros." />}
      {rows && rows.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs uppercase tracking-wide text-muted">
              <tr>
                <th className="px-4 py-3">Fecha y hora</th>
                <th className="px-4 py-3">Usuario</th>
                <th className="px-4 py-3">Acción</th>
                <th className="px-4 py-3">Registro</th>
                <th className="px-4 py-3">Desde</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const expanded = open === r.id;
                return (
                  <Fragment key={r.id}>
                    <tr tabIndex={0} aria-expanded={expanded} onClick={() => setOpen(expanded ? null : r.id)} onKeyDown={(e) => e.key === "Enter" && setOpen(expanded ? null : r.id)}
                      className={`cursor-pointer border-b border-line hover:bg-brand-soft/40 ${expanded ? "bg-bg" : ""}`}>
                      <td className="whitespace-nowrap px-4 py-3">{fmtAudit(r.occurred_at)}</td>
                      <td className="px-4 py-3">
                        {r.actor_name ?? "Sistema"}
                        {r.is_support_session && <span className="ml-1 text-xs text-amber-ink">(soporte)</span>}
                      </td>
                      <td className="px-4 py-3">{ACTIONS[r.action] ?? r.action}</td>
                      <td className="px-4 py-3">{r.module_code} · {r.tab}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-muted">{[r.channel, r.ip].filter(Boolean).join(" · ") || "—"}</td>
                    </tr>
                    {expanded && (
                      <tr className="border-b border-line bg-bg">
                        <td colSpan={5} className="px-4 pb-4 pt-1">
                          <AuditDiffTable row={r} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      <Pager meta={meta} page={page} setPage={setPage} />
    </div>
  );
}

function AuditDiffTable({ row }: { row: AuditRow }) {
  const create = row.action === "create";
  return (
    <div className="space-y-2">
      {row.diff.length === 0 ? (
        <p className="text-sm text-muted">Este movimiento no tiene campos cambiados que mostrar.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-line bg-surface">
          <table className="w-full text-left text-xs">
            <thead className="border-b border-line text-muted">
              <tr>
                <th className="px-3 py-2">Campo</th>
                {!create && <th className="px-3 py-2">Antes</th>}
                <th className="px-3 py-2">{create ? "Valor registrado" : "Después"}</th>
              </tr>
            </thead>
            <tbody>
              {row.diff.map((d, i) => (
                <tr key={i} className="border-b border-line last:border-0 align-top">
                  <td className="px-3 py-2 font-semibold">{d.field}</td>
                  {d.hidden ? (
                    <td colSpan={create ? 1 : 2} className="px-3 py-2 text-muted">Dato protegido: cambió, pero su valor no se muestra.</td>
                  ) : (
                    <>
                      {!create && <td className="break-words px-3 py-2 text-bad"><span className="line-through decoration-bad/40">{d.from ?? "—"}</span></td>}
                      <td className="break-words px-3 py-2 text-ok">{d.to ?? "—"}</td>
                    </>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {row.module_of_entity && row.entity_id && row.action !== "delete" && (
        <Link href={recordsHref(row.module_of_entity, row.entity_type, { open: row.entity_id })} className="inline-block text-sm font-semibold text-brand hover:underline">
          Abrir el registro
        </Link>
      )}
    </div>
  );
}

function SensitiveAudit({ filters }: { filters: AuditFilters }) {
  const [rows, setRows] = useState<SensitiveRow[] | null>(null);
  const [meta, setMeta] = useState<PageMeta>({ page: 1, pages: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [f, setF] = useState({ user_id: "", result: "", from: "", to: "" });
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const q = new URLSearchParams({ page: String(page) });
    Object.entries(f).forEach(([k, v]) => v && q.set(k, v));
    api<{ data: SensitiveRow[]; meta: PageMeta }>(`/company/audit/sensitive?${q}`, { withCompany: true })
      .then((r) => {
        setRows(r.data);
        setMeta(r.meta);
      })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [page, f]);
  const set = (k: keyof typeof f) => (v: string) => {
    setF({ ...f, [k]: v });
    setPage(1);
  };
  const any = Object.values(f).some(Boolean);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);
  // «Exportar» (CSV) con los mismos filtros; nunca incluye contenido médico y la exportación queda en la bitácora.
  const exportCsv = async () => {
    setExporting(true);
    setExportError(null);
    try {
      const q = new URLSearchParams();
      Object.entries(f).forEach(([k, v]) => v && q.set(k, v));
      const headers: Record<string, string> = { Accept: "text/csv" };
      const token = session.getToken();
      const company = session.getCompanyId();
      if (token) headers.Authorization = `Bearer ${token}`;
      if (company) headers["X-Company-Id"] = company;
      const res = await fetch(`${API_URL}/api/v1/company/audit/sensitive/export?${q}`, { headers });
      if (!res.ok) {
        const d = await res.json().catch(() => ({}));
        throw new Error(d.detail ?? "No se pudo exportar. Intenta de nuevo.");
      }
      const url = URL.createObjectURL(await res.blob());
      const a = document.createElement("a");
      a.href = url;
      a.download = `accesos-datos-sensibles-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      setExportError(e instanceof Error ? e.message : "No se pudo exportar.");
    } finally {
      setExporting(false);
    }
  };

  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>;
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="flex-1 rounded-lg bg-bg px-4 py-3 text-sm text-muted">Cada consulta de datos médicos o sensibles queda registrada antes de mostrarse. Aquí se ve quién consultó y por qué, nunca el contenido.</p>
        <Button icon="download" onClick={exportCsv} loading={exporting} disabled={meta.total === 0}>
          Exportar
        </Button>
      </div>
      {exportError && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{exportError}</p>}
      <FilterBar count={`${meta.total} acceso(s)`} onClear={any ? () => { setF({ user_id: "", result: "", from: "", to: "" }); setPage(1); } : undefined}>
        <FilterSelect label="Usuario" value={f.user_id} onChange={set("user_id")} options={[["", "Todos"], ...filters.sensitive_users.map((u) => [u.id, u.full_name] as [string, string])]} />
        <FilterSelect label="Resultado" value={f.result} onChange={set("result")} options={[["", "Todos"], ["granted", "Aceptado"], ["denied", "Rechazado"]]} />
        <DateFilter label="Desde" value={f.from} onChange={set("from")} />
        <DateFilter label="Hasta" value={f.to} onChange={set("to")} />
      </FilterBar>
      {rows && rows.length === 0 && <EmptyState icon="search" text="Sin accesos a datos sensibles con esos filtros." />}
      {rows && rows.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs uppercase tracking-wide text-muted">
              <tr>
                <th className="px-4 py-3">Fecha y hora</th>
                <th className="px-4 py-3">Usuario</th>
                <th className="px-4 py-3">Dato consultado</th>
                <th className="px-4 py-3">Motivo</th>
                <th className="px-4 py-3">Re-autenticación</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-b border-line last:border-0">
                  <td className="whitespace-nowrap px-4 py-3">
                    {fmtAudit(r.occurred_at)}
                    {r.ip && <span className="block text-xs text-muted">{r.ip}</span>}
                  </td>
                  <td className="px-4 py-3">
                    {r.user_name}
                    {r.role_name && <span className="block text-xs text-muted">{r.role_name}</span>}
                  </td>
                  <td className="px-4 py-3">
                    {r.data_label}
                    <span className="block text-xs text-muted">{r.action}</span>
                  </td>
                  <td className="px-4 py-3">{r.purpose ?? "—"}</td>
                  <td className="px-4 py-3">
                    <Chip tone={r.result === "granted" ? "ok" : "bad"}>{r.result === "granted" ? "Aceptado" : "Rechazado"}</Chip>
                    <span className="mt-1 block text-xs text-muted">
                      {r.result === "denied" && r.deny_reason ? r.deny_reason : r.reauth ? "Con clave médica" : "Sin re-autenticación"}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager meta={meta} page={page} setPage={setPage} />
    </div>
  );
}
