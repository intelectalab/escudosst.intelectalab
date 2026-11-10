"use client";

// USR · Usuarios de la empresa (invitar con rol, cambiar rol, suspender) y AUD · Bitácora de la empresa.
// En la bitácora cada fila abre el registro que cambió (trazabilidad).

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { recordsHref } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { useApp } from "@/components/AppContext";

type Member = {
  id: string;
  status: "invited" | "active" | "suspended" | "expired" | "revoked";
  full_name: string;
  email: string;
  role_code: string;
  role_name: string;
  worker_name: string | null;
  last_access_at: string | null;
};
type Role = { code: string; name: string; description: string; requires_mfa: boolean };

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
  const [inviting, setInviting] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Member[] }>("/company/users", { withCompany: true }).then((r) => setRows(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(() => {
    load();
    api<{ data: Role[] }>("/company/roles", { withCompany: true }).then((r) => setRoles(r.data)).catch(() => setRoles([]));
  }, [load]);

  const act = (fn: () => Promise<unknown>, msg: string) =>
    fn()
      .then(() => {
        setToast(msg);
        load();
      })
      .catch((e: ApiError) => setToast(`⚠ ${e.detail ?? e.title}`));

  const shown = (rows ?? []).filter((m) => !filter || m.status === filter);

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Usuarios de la empresa</h2>
          <p className="text-sm text-muted">Invita a las personas con su rol. Cada rol ve y hace solo lo que le corresponde.</p>
        </div>
        {!readOnly && (
          <Button variant="primary" icon="plus" onClick={() => setInviting(true)}>
            Invitar usuario
          </Button>
        )}
      </div>
      <FilterBar count={rows ? `${shown.length} persona(s)` : undefined}>
        <FilterSelect label="Estado" value={filter} onChange={setFilter} options={[["", "Todos"], ...Object.entries(STATUS).slice(0, 3).map(([k, [l]]) => [k, l] as [string, string])]} />
      </FilterBar>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}
      {rows && shown.length === 0 && <EmptyState icon="search" text="No hay personas con ese filtro." />}
      {shown.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs uppercase tracking-wide text-muted">
              <tr>
                <th className="px-4 py-3">Persona</th>
                <th className="px-4 py-3">Rol</th>
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
                    <p className="text-xs text-muted">{m.email}{m.worker_name && ` · ficha: ${m.worker_name}`}</p>
                  </td>
                  <td className="px-4 py-3">
                    {readOnly || m.email === user.email ? (
                      m.role_name
                    ) : (
                      <select className="input !h-9 !py-0 text-sm" aria-label={`Rol de ${m.full_name}`} value={m.role_code}
                        onChange={(e) => act(() => api(`/company/users/${m.id}`, { method: "PATCH", body: { role_code: e.target.value }, withCompany: true }), "Rol actualizado.")}>
                        {!roles.some((r) => r.code === m.role_code) && <option value={m.role_code}>{m.role_name}</option>}
                        {roles.map((r) => (
                          <option key={r.code} value={r.code}>
                            {r.name}
                          </option>
                        ))}
                      </select>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <Chip tone={STATUS[m.status]?.[1] ?? "na"}>{STATUS[m.status]?.[0] ?? m.status}</Chip>
                  </td>
                  <td className="px-4 py-3">
                    {!readOnly && m.email !== user.email && (
                      <div className="flex flex-wrap gap-2">
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
      {inviting && <InviteModal roles={roles} onClose={() => setInviting(false)} onDone={(msg) => { setInviting(false); setToast(msg); load(); }} />}
      {toast && <Toast message={toast.replace(/^⚠ /, "")} tone={toast.startsWith("⚠") ? "bad" : "ok"} onClose={() => setToast(null)} />}
    </section>
  );
}

function InviteModal({ roles, onClose, onDone }: { roles: Role[]; onClose: () => void; onDone: (msg: string) => void }) {
  const [form, setForm] = useState({ email: "", full_name: "", role_code: "sst_supervisor" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [detail, setDetail] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const role = roles.find((r) => r.code === form.role_code);
  const send = async () => {
    setBusy(true);
    setErrors({});
    setDetail(null);
    try {
      const r = await api<{ data: { status: string } }>("/company/users", { method: "POST", body: form, withCompany: true });
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
        <FormField label="Rol *" error={errors.role_code?.[0]} hint={role ? `${role.description}${role.requires_mfa ? " Pide segundo factor." : ""}` : undefined}>
          <select className="input" value={form.role_code} onChange={(e) => setForm({ ...form, role_code: e.target.value })}>
            {roles.map((r) => (
              <option key={r.code} value={r.code}>
                {r.name}
              </option>
            ))}
          </select>
        </FormField>
      </div>
    </Modal>
  );
}

// ─────────────── AUD: bitácora de la empresa ───────────────

type AuditRow = {
  id: string;
  occurred_at: string;
  action: string;
  module_code: string;
  entity_type: string;
  entity_id: string | null;
  changes: Record<string, unknown> | null;
  actor_name: string | null;
  tab: string;
  module_of_entity: string | null;
};

const ACTIONS: Record<string, string> = {
  create: "Creó",
  update: "Modificó",
  delete: "Archivó",
  submit: "Envió a aprobación",
  approve: "Aprobó",
  return: "Observó",
  finish: "Finalizó",
  member_invited: "Invitó",
  member_updated: "Cambió el acceso",
};

export function CompanyAudit() {
  const [rows, setRows] = useState<AuditRow[] | null>(null);
  const [meta, setMeta] = useState({ page: 1, pages: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [module, setModule] = useState("");
  const [from, setFrom] = useState("");
  const [open, setOpen] = useState<AuditRow | null>(null);

  useEffect(() => {
    const q = new URLSearchParams({ page: String(page) });
    if (module) q.set("module", module);
    if (from) q.set("from", from);
    api<{ data: AuditRow[]; meta: typeof meta }>(`/company/audit?${q}`, { withCompany: true }).then((r) => {
      setRows(r.data);
      setMeta(r.meta);
    });
  }, [page, module, from]);

  const fmt = (d: string) => new Date(d).toLocaleString("es-PE", { dateStyle: "short", timeStyle: "short" });

  return (
    <section className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold">Bitácora de la empresa</h2>
        <p className="text-sm text-muted">Quién hizo qué y cuándo. Toca una fila para ver el detalle y abrir el registro.</p>
      </div>
      <FilterBar count={`${meta.total} movimiento(s)`} onClear={module || from ? () => { setModule(""); setFrom(""); } : undefined}>
        <label className="flex flex-col gap-1.5">
          <span className="text-xs font-semibold text-muted">Módulo (código)</span>
          <input className="filter-control" maxLength={3} placeholder="p. ej. ACR" value={module} onChange={(e) => { setModule(e.target.value.toUpperCase()); setPage(1); }} />
        </label>
        <label className="flex flex-col gap-1.5">
          <span className="text-xs font-semibold text-muted">Desde</span>
          <input className="filter-control" type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
        </label>
      </FilterBar>
      {rows && rows.length === 0 && <EmptyState icon="search" text="Sin movimientos con esos filtros." />}
      {rows && rows.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs uppercase tracking-wide text-muted">
              <tr>
                <th className="px-4 py-3">Fecha</th>
                <th className="px-4 py-3">Persona</th>
                <th className="px-4 py-3">Acción</th>
                <th className="px-4 py-3">Registro</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} tabIndex={0} onClick={() => setOpen(r)} onKeyDown={(e) => e.key === "Enter" && setOpen(r)} className="cursor-pointer border-b border-line last:border-0 hover:bg-brand-soft/40">
                  <td className="px-4 py-3 whitespace-nowrap">{fmt(r.occurred_at)}</td>
                  <td className="px-4 py-3">{r.actor_name ?? "Sistema"}</td>
                  <td className="px-4 py-3">{ACTIONS[r.action] ?? r.action}</td>
                  <td className="px-4 py-3">
                    {r.module_code} · {r.tab}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {meta.pages > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
          <span className="text-muted">Página {meta.page} de {meta.pages}</span>
          <Button size="sm" disabled={page >= meta.pages} onClick={() => setPage(page + 1)}>Siguiente</Button>
        </div>
      )}
      {open && (
        <Modal title={`${ACTIONS[open.action] ?? open.action} · ${open.tab}`} subtitle={`${open.actor_name ?? "Sistema"} · ${fmt(open.occurred_at)}`} icon="clipboard" size="md" onClose={() => setOpen(null)}
          footer={
            open.module_of_entity && open.entity_id && open.action !== "delete" ? (
              <div className="flex w-full justify-end">
                <Link href={recordsHref(open.module_of_entity, open.entity_type, { open: open.entity_id })} className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-strong">
                  Abrir el registro
                </Link>
              </div>
            ) : undefined
          }
        >
          <pre className="max-h-[50vh] overflow-auto whitespace-pre-wrap rounded-lg bg-bg p-3 text-xs">{JSON.stringify(open.changes ?? {}, null, 2)}</pre>
        </Modal>
      )}
    </section>
  );
}
