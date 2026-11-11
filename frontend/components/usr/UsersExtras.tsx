"use client";

// USR · piezas de «Usuarios de la empresa» (M-USR SCR-USR-02/03/04): alcance por sede/área, vigencia,
// doble factor y la matriz de roles y permisos en solo lectura (los roles personalizados se editan en CustomRoles.tsx).

import { useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";

export type Scope = { type: "site" | "area" | "module" | "contractor"; id: string | null; name: string };
type ScopeOptions = { sites: { id: string; name: string }[]; areas: { id: string; name: string; site_id: string }[] };

/** Texto de la columna «Alcance»: sin filas = toda la empresa. */
export function scopeText(scopes: Scope[] | undefined): string {
  if (!scopes || scopes.length === 0) return "Toda la empresa";
  return scopes.map((s) => (s.type === "site" ? `Sede ${s.name}` : s.type === "area" ? `Área ${s.name}` : s.type === "contractor" ? `Contratista ${s.name}` : s.name)).join(" · ");
}

export function MfaChip({ enabled, required }: { enabled: boolean; required: boolean }) {
  if (enabled) return <Chip tone="ok">Activo</Chip>;
  return required ? <Chip tone="amber">Pendiente</Chip> : <Chip tone="na">No usa</Chip>;
}

export function lastAccess(v: string | null): string {
  if (!v) return "Nunca";
  const d = new Date(v);
  return d.toLocaleString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function expiresText(v: string | null): string | null {
  return v ? `Hasta el ${new Date(v).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric" })}` : null;
}

/** Alcance (sedes/áreas) y vigencia opcional del acceso, para la invitación (SCR-USR-03). */
export function ScopeFields({
  value,
  onChange,
  errors,
  auditor,
}: {
  value: { site_ids: string[]; area_ids: string[]; expires_on: string };
  onChange: (v: { site_ids: string[]; area_ids: string[]; expires_on: string }) => void;
  errors: Record<string, string[]>;
  auditor: boolean;
}) {
  const [opts, setOpts] = useState<ScopeOptions | null>(null);
  const [limited, setLimited] = useState(value.site_ids.length > 0 || value.area_ids.length > 0);
  useEffect(() => {
    api<{ data: ScopeOptions }>("/company/scope-options", { withCompany: true }).then((r) => setOpts(r.data)).catch(() => setOpts({ sites: [], areas: [] }));
  }, []);
  const toggle = (key: "site_ids" | "area_ids", id: string) => {
    const list = value[key].includes(id) ? value[key].filter((x) => x !== id) : [...value[key], id];
    onChange({ ...value, [key]: list });
  };
  const today = new Date().toISOString().slice(0, 10);

  return (
    <>
      <fieldset className="space-y-2">
        <legend className="mb-1 text-sm font-semibold">Alcance</legend>
        <div className="flex flex-wrap gap-4 text-sm">
          <label className="flex items-center gap-2">
            <input type="radio" name="scope" checked={!limited} onChange={() => { setLimited(false); onChange({ ...value, site_ids: [], area_ids: [] }); }} />
            Toda la empresa
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" name="scope" checked={limited} onChange={() => setLimited(true)} />
            Solo algunas sedes o áreas
          </label>
        </div>
        {limited && (
          <div className="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-line p-3">
            {!opts && <p className="text-sm text-muted">Cargando sedes…</p>}
            {opts && opts.sites.length === 0 && <p className="text-sm text-muted">Aún no hay sedes. Créalas en Empresa y sedes.</p>}
            {opts?.sites.map((s) => {
              const areas = opts.areas.filter((a) => a.site_id === s.id);
              return (
                <div key={s.id}>
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" checked={value.site_ids.includes(s.id)} onChange={() => toggle("site_ids", s.id)} />
                    {s.name}
                  </label>
                  {areas.length > 0 && (
                    <div className="ml-6 mt-1 flex flex-wrap gap-x-4 gap-y-1">
                      {areas.map((a) => (
                        <label key={a.id} className="flex items-center gap-2 text-sm text-muted">
                          <input type="checkbox" checked={value.area_ids.includes(a.id)} onChange={() => toggle("area_ids", a.id)} />
                          {a.name}
                        </label>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
        {(errors.site_ids?.[0] || errors.area_ids?.[0]) && <p className="text-sm text-bad">{errors.site_ids?.[0] ?? errors.area_ids?.[0]}</p>}
        {limited && <p className="text-xs text-muted">Marca la sede para ver toda la sede, o solo las áreas que le corresponden.</p>}
      </fieldset>
      <FormField label={auditor ? "Acceso hasta *" : "Acceso hasta (opcional)"} error={errors.expires_on?.[0]}
        hint={auditor ? "El auditor externo tiene acceso temporal: al vencer, el acceso se cierra solo." : "Déjalo vacío para un acceso sin fecha de fin."}>
        <input className="input" type="date" min={today} value={value.expires_on} onChange={(e) => onChange({ ...value, expires_on: e.target.value })} />
      </FormField>
    </>
  );
}

type MatrixRole = { code: string; name: string; description: string; is_medical: boolean; requires_mfa: boolean };
type MatrixPermission = { code: string; resource: string; resource_label: string; action: string; action_label: string; is_sensitive: boolean; roles: string[] };
type MatrixModule = { code: string; number: string; name: string; permissions: MatrixPermission[] };

/** Matriz de roles y permisos (SCR-USR-04), solo lectura: roles base × permisos de cada módulo. */
export function RolesMatrix() {
  const [data, setData] = useState<{ roles: MatrixRole[]; modules: MatrixModule[] } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [mod, setMod] = useState("");
  const [q, setQ] = useState("");
  const [onlySensitive, setOnlySensitive] = useState("");

  useEffect(() => {
    api<{ data: { roles: MatrixRole[]; modules: MatrixModule[] } }>("/company/roles/matrix", { withCompany: true })
      .then((r) => {
        setData(r.data);
        setMod(r.data.modules[0]?.code ?? "");
      })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);

  const current = data?.modules.find((m) => m.code === mod);
  const rows = useMemo(() => {
    const term = q.trim().toLowerCase();
    return (current?.permissions ?? []).filter(
      (p) => (!onlySensitive || p.is_sensitive) && (!term || `${p.resource_label} ${p.action_label} ${p.code}`.toLowerCase().includes(term)),
    );
  }, [current, q, onlySensitive]);

  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>;
  if (!data) return <p className="text-sm text-muted">Cargando roles y permisos…</p>;

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted">
        Lo que puede hacer cada rol base, por módulo. Los permisos marcados como <Chip tone="bad">Sensible</Chip> dan acceso a datos médicos o personales
        protegidos; solo los roles médicos ven el dato de salud. Los roles base no se editan.
      </p>
      <FilterBar count={`${rows.length} permiso(s)`}>
        <FilterSelect label="Módulo" value={mod} neutral={mod} onChange={setMod} options={data.modules.map((m) => [m.code, `${m.number ? m.number + " · " : ""}${m.name}`] as [string, string])} />
        <FilterSelect label="Tipo" value={onlySensitive} onChange={setOnlySensitive} options={[["", "Todos"], ["1", "Solo sensibles"]]} />
        <SearchInput label="Buscar permiso" value={q} onChange={setQ} placeholder="Registro o acción" />
      </FilterBar>
      {rows.length === 0 ? (
        <EmptyState icon="search" text="No hay permisos con ese filtro." />
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs text-muted">
              <tr>
                <th className="sticky left-0 z-10 min-w-[14rem] bg-bg px-4 py-3 uppercase tracking-wide">Permiso</th>
                {data.roles.map((r) => (
                  <th key={r.code} className="px-2 py-3 text-center font-semibold" title={r.description}>
                    <span className="block w-20 whitespace-normal leading-tight">{r.name}</span>
                    {r.is_medical && <span className="mt-1 block text-[10px] font-bold text-brand">Médico</span>}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((p) => (
                <tr key={p.code} className="border-b border-line last:border-0">
                  <th scope="row" className="sticky left-0 bg-surface px-4 py-2 text-left font-normal">
                    <span className="font-medium">{p.resource_label}</span> · {p.action_label}
                    {p.is_sensitive && <span className="ml-2"><Chip tone="bad">Sensible</Chip></span>}
                  </th>
                  {data.roles.map((r) => {
                    const has = p.roles.includes(r.code);
                    return (
                      <td key={r.code} className="px-2 py-2 text-center">
                        <input type="checkbox" checked={has} readOnly disabled aria-label={`${r.name}: ${p.resource_label} · ${p.action_label}${has ? " permitido" : " no permitido"}`} />
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

/** Fecha AAAA-MM-DD (hora de Lima) de una vigencia guardada, para el campo de fecha. */
function limaDate(v: string | null | undefined): string {
  return v ? new Date(v).toLocaleDateString("en-CA", { timeZone: "America/Lima" }) : "";
}

/**
 * «Editar acceso» de un usuario existente (SCR-USR-03): alcance por sede/área y vigencia (fecha de fin). El auditor
 * externo siempre necesita fecha de fin; si se le está cambiando el rol a auditor externo, el rol se guarda junto.
 */
export function EditAccessModal({
  member,
  roleCode,
  roleBase,
  onClose,
  onDone,
}: {
  member: { id: string; full_name: string; role_code: string; base_role_code?: string; role_name: string; expires_at?: string | null; scopes?: Scope[] };
  roleCode?: string;
  /** Rol base del rol nuevo (un rol personalizado se comporta como su rol base). */
  roleBase?: string;
  onClose: () => void;
  onDone: (msg: string) => void;
}) {
  const role = roleCode ? (roleBase ?? roleCode) : (member.base_role_code ?? member.role_code);
  const [value, setValue] = useState({
    site_ids: (member.scopes ?? []).filter((s) => s.type === "site" && s.id).map((s) => s.id as string),
    area_ids: (member.scopes ?? []).filter((s) => s.type === "area" && s.id).map((s) => s.id as string),
    expires_on: limaDate(member.expires_at),
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [detail, setDetail] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const auditor = role === "external_auditor";

  const save = async () => {
    setErrors({});
    setDetail(null);
    if (auditor && !value.expires_on) {
      setErrors({ expires_on: ["El auditor externo necesita una fecha de fin del acceso."] });
      return;
    }
    setBusy(true);
    try {
      await api(`/company/users/${member.id}`, {
        method: "PATCH",
        withCompany: true,
        body: { ...(roleCode && roleCode !== member.role_code ? { role_code: roleCode } : {}), site_ids: value.site_ids, area_ids: value.area_ids, expires_on: value.expires_on || null },
      });
      onDone(roleCode && roleCode !== member.role_code ? "Rol, alcance y vigencia actualizados." : "Alcance y vigencia actualizados.");
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setDetail(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title="Editar acceso" subtitle={`${member.full_name} · ${auditor && roleCode ? "Auditor externo" : member.role_name}`} icon="people" size="sm" onClose={onClose}
      footer={
        <div className="flex w-full justify-end gap-2">
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" loading={busy} onClick={save}>Guardar</Button>
        </div>
      }
    >
      <div className="space-y-3">
        {detail && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{detail}</p>}
        {roleCode && roleCode !== member.role_code && auditor && (
          <p className="rounded-lg bg-amber-soft px-3 py-2 text-sm text-amber-ink">Para darle el rol de auditor externo indica hasta cuándo tendrá acceso.</p>
        )}
        <ScopeFields value={value} onChange={setValue} errors={errors} auditor={auditor} />
      </div>
    </Modal>
  );
}
