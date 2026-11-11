"use client";

// Parámetros legales con vigencia: cada parámetro muestra su valor vigente, si un especialista ya lo confirmó
// y su historial de versiones. Cambiar un valor crea una versión nueva desde una fecha; nunca se sobrescribe.
// Las listas de familias de peligro (p. ej. prohibidas para menores) se eligen con casillas, se ve dónde usa el sistema
// cada parámetro y qué empresas tienen un valor propio. Solo el Dueño cambia valores; Soporte solo los ve.

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/accounts";
import { dateOnly, FAMILY_LIST_PARAMS, HAZARD_FAMILIES, type Hazard, type LegalParameter, PARAM_AREAS, type ParamOverride, VALUE_TYPES } from "@/lib/catalogs";

const isFamilyList = (p: LegalParameter) => FAMILY_LIST_PARAMS.includes(p.key);

/** Familias guardadas en el valor JSON del parámetro. */
function parseFamilies(value: string): string[] {
  try {
    const v: unknown = JSON.parse(value);
    return Array.isArray(v) ? v.filter((x): x is string => typeof x === "string") : [];
  } catch {
    return [];
  }
}

/** Valor legible: familias con su nombre en castellano; el resto, tal cual con su unidad. */
function readable(p: LegalParameter, value = p.value): string {
  if (isFamilyList(p)) {
    const f = parseFamilies(value);
    return f.length ? f.map((x) => HAZARD_FAMILIES[x] ?? x).join(", ") : "Ninguna";
  }
  return `${value} ${p.unit ?? ""}`.trim();
}

const EMPTY = { q: "", state: "", area: "" };

export function ParametersTab({
  params,
  onChanged,
  initialState = "",
  onGoHolidays,
  hazards,
  canEdit,
}: {
  params: LegalParameter[];
  onChanged: (msg: string) => void;
  initialState?: string;
  onGoHolidays: () => void;
  hazards: Hazard[];
  canEdit: boolean;
}) {
  const [f, setF] = useState({ ...EMPTY, state: initialState });
  const [open, setOpen] = useState<string | null>(null);
  const [edit, setEdit] = useState<LegalParameter | null>(null);
  const [confirm, setConfirm] = useState<LegalParameter | null>(null);

  // Un grupo por parámetro: la versión vigente arriba y el resto como historial.
  const groups = useMemo(() => {
    const byKey = new Map<string, LegalParameter[]>();
    params.forEach((p) => byKey.set(p.key, [...(byKey.get(p.key) ?? []), p]));
    return [...byKey.values()].map((versions) => ({ current: versions.find((v) => v.is_current) ?? versions[0], versions }));
  }, [params]);

  const filtered = groups.filter(({ current: c, versions }) => {
    const t = f.q.trim().toLowerCase();
    return (
      (!t || [c.description, c.key, c.norm_number ?? "", c.legal_reference ?? ""].some((v) => v.toLowerCase().includes(t))) &&
      (!f.area || c.area === f.area) &&
      (!f.state ||
        (f.state === "to_confirm"
          ? c.kind === "legal" && c.to_confirm
          : f.state === "pending"
            ? c.kind === "pending"
            : f.state === "internal"
              ? c.kind === "internal"
              : f.state === "confirmed"
                ? !c.to_confirm
                : versions.some((v) => !v.is_current && v.valid_from > c.valid_from)))
    );
  });

  return (
    <div className="space-y-4">
      <p className="flex items-start gap-2 rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">
        <Icon name="shield" size={16} className="mt-0.5 shrink-0" />
        Los plazos y cantidades que usa el sistema están aquí, no en el código. Los <b className="mx-1">legales</b> los confirma un especialista SST o legal; los
        <b className="mx-1">internos</b> son decisiones de EscudoSST. Cambiar un valor crea una versión nueva desde una fecha: el historial se conserva.
      </p>

      <FilterBar count={`${filtered.length} de ${groups.length} parámetros`} onClear={f.q || f.state ? () => setF(EMPTY) : undefined}>
        <SearchInput label="Buscar parámetros" placeholder="Qué regula, norma o artículo" value={f.q} onChange={(q) => setF({ ...f, q })} />
        <FilterSelect
          label="Estado"
          value={f.state}
          onChange={(state) => setF({ ...f, state })}
          options={[
            ["", "Todos"],
            ["to_confirm", "Legales a confirmar"],
            ["pending", "Por definir"],
            ["internal", "Valores internos"],
            ["confirmed", "Confirmados"],
            ["future", "Con cambio programado"],
          ]}
        />
        <FilterSelect
          label="Área"
          value={f.area}
          onChange={(area) => setF({ ...f, area })}
          options={[["", "Todas las áreas"], ...(Object.entries(PARAM_AREAS) as [string, string][])]}
        />
      </FilterBar>

      {filtered.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState text="Ningún parámetro coincide con los filtros." onClear={() => setF(EMPTY)} />
        </div>
      ) : (
        <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          {filtered.map(({ current: c, versions }) => {
            const future = versions.filter((v) => v.valid_from > c.valid_from);
            const expanded = open === c.key;
            return (
              <li key={c.key} className="px-4 py-3.5 sm:px-5">
                <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_10rem_auto] lg:items-center">
                  <div className="min-w-0">
                    <p className="font-medium">{c.description}</p>
                    <p className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted">
                      <Code>{c.key}</Code>
                      {c.norm_number && <span>Norma {c.norm_number}</span>}
                      {c.legal_reference && <span>· {c.legal_reference}</span>}
                    </p>
                  </div>
                  <div>
                    <p className="font-display text-xl font-bold tabular-nums">
                      {c.kind === "pending" ? (
                        <span className="text-base text-bad">Por definir</span>
                      ) : c.kind === "reference" ? (
                        <span className="text-base">Tabla de feriados</span>
                      ) : isFamilyList(c) ? (
                        <span className="flex flex-wrap gap-1">
                          {parseFamilies(c.value).length === 0 ? (
                            <span className="text-base text-muted">Ninguna</span>
                          ) : (
                            parseFamilies(c.value).map((x) => (
                              <span key={x} className="rounded-full bg-bad-soft px-2 py-0.5 font-sans text-xs font-semibold text-bad">
                                {HAZARD_FAMILIES[x] ?? x}
                              </span>
                            ))
                          )}
                        </span>
                      ) : c.value.startsWith("{") ? (
                        <span className="text-base">Tabla configurada</span>
                      ) : (
                        <>
                          {c.value} <span className="text-sm font-normal text-muted">{c.unit ?? ""}</span>
                        </>
                      )}
                    </p>
                    <p className="text-xs text-muted">Desde {dateOnly(c.valid_from)}</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                    <Chip tone="na">{PARAM_AREAS[c.area]}</Chip>
                    {c.kind === "pending" ? (
                      <Chip tone="bad" dot>
                        Falta el valor
                      </Chip>
                    ) : c.kind === "reference" ? null : c.kind === "internal" && c.to_confirm ? (
                      <span title="Valor de EscudoSST, no exigido por ley: no requiere especialista">
                        <Chip tone="brand" dot>
                          Valor interno
                        </Chip>
                      </span>
                    ) : c.to_confirm ? (
                      <Chip tone="amber" dot>
                        A confirmar
                      </Chip>
                    ) : (
                      <span title={`Confirmado por ${c.confirmed_by} el ${formatDate(c.confirmed_at)}`}>
                        <Chip tone="ok" dot>
                          Confirmado
                        </Chip>
                      </span>
                    )}
                    {future.length > 0 && <Chip tone="brand">Cambia el {dateOnly(future[future.length - 1].valid_from)}</Chip>}
                  </div>
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                  {c.kind === "reference" ? (
                    <Button variant="soft" size="sm" icon="clock" onClick={onGoHolidays}>
                      Ir a Feriados
                    </Button>
                  ) : !canEdit ? (
                    <span className="text-xs text-muted">Solo el Dueño de la plataforma cambia este valor.</span>
                  ) : (
                    <>
                      {c.to_confirm && c.kind !== "pending" && (
                        <Button variant="soft" size="sm" icon="check" onClick={() => setConfirm(c)}>
                          {c.kind === "internal" ? "Marcar como revisado" : "Confirmar valor"}
                        </Button>
                      )}
                      <Button variant={c.kind === "pending" ? "primary" : "secondary"} size="sm" icon="pen" onClick={() => setEdit(c)}>
                        {c.kind === "pending" ? "Registrar valor" : "Nueva versión"}
                      </Button>
                    </>
                  )}
                  <button type="button" onClick={() => setOpen(expanded ? null : c.key)} className="inline-flex items-center gap-1 px-2 text-sm font-semibold text-brand hover:underline" aria-expanded={expanded}>
                    <Icon name="chevron" size={15} className={`transition-transform ${expanded ? "rotate-90" : ""}`} />
                    Historial ({versions.length})
                  </button>
                  {!c.to_confirm && <span className="text-xs text-muted">{c.kind === "internal" ? "Revisado" : "Validado"} por {c.confirmed_by}</span>}
                </div>
                {expanded && c.used_in.length > 0 && (
                  <div className="mt-3 rounded-xl bg-bg px-3 py-2 text-sm">
                    <p className="text-xs font-bold uppercase tracking-wide text-muted">Dónde se usa</p>
                    <ul className="mt-1 list-disc space-y-0.5 pl-5">
                      {c.used_in.map((u) => (
                        <li key={u}>{u}</li>
                      ))}
                    </ul>
                  </div>
                )}
                {expanded && (
                  <ol className="mt-3 space-y-1.5 rounded-xl border border-line p-3 text-sm">
                    {versions.map((v) => (
                      <li key={v.id} className={`flex flex-wrap items-center justify-between gap-2 ${v.is_current ? "font-semibold" : "text-muted"}`}>
                        <span>
                          {readable(v)} · del {dateOnly(v.valid_from)} {v.valid_to ? `al ${dateOnly(v.valid_to)}` : "en adelante"}
                        </span>
                        <span className="flex gap-1.5">
                          {v.is_current && <Chip tone="ok">Vigente hoy</Chip>}
                          {v.valid_from > c.valid_from && <Chip tone="brand">Programada</Chip>}
                          {v.to_confirm && <Chip tone="amber">A confirmar</Chip>}
                        </span>
                      </li>
                    ))}
                  </ol>
                )}
                {expanded && c.kind !== "reference" && c.kind !== "pending" && <CompanyValues param={c} hazards={hazards} canEdit={canEdit} onChanged={onChanged} />}
              </li>
            );
          })}
        </ul>
      )}

      {edit && <VersionModal param={edit} hazards={hazards} onClose={() => setEdit(null)} onDone={(m) => (setEdit(null), onChanged(m))} />}
      {confirm && <ConfirmModal param={confirm} onClose={() => setConfirm(null)} onDone={(m) => (setConfirm(null), onChanged(m))} />}
    </div>
  );
}

/** Casillas con las familias de peligro del catálogo base (y cuántos peligros base activos tiene cada una). */
function FamilyPicker({ value, onChange, hazards }: { value: string[]; onChange: (v: string[]) => void; hazards: Hazard[] }) {
  const counts = hazards.reduce<Record<string, number>>((acc, h) => (h.is_active ? { ...acc, [h.hazard_family]: (acc[h.hazard_family] ?? 0) + 1 } : acc), {});
  const families = [...new Set([...Object.keys(HAZARD_FAMILIES), ...hazards.map((h) => h.hazard_family)])].sort(
    (a, b) => (counts[b] ?? 0) - (counts[a] ?? 0) || (HAZARD_FAMILIES[a] ?? a).localeCompare(HAZARD_FAMILIES[b] ?? b),
  );
  const toggle = (f: string) => onChange(value.includes(f) ? value.filter((x) => x !== f) : [...value, f]);
  return (
    <fieldset>
      <legend className="sr-only">Familias de peligro</legend>
      <div className="grid gap-2 sm:grid-cols-2">
        {families.map((f) => {
          const on = value.includes(f);
          return (
            <label key={f} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm ${on ? "border-bad/50 bg-bad-soft/60" : "border-line hover:border-brand/40"}`}>
              <input type="checkbox" className="h-4 w-4" checked={on} onChange={() => toggle(f)} />
              <span className="flex-1 font-medium">{HAZARD_FAMILIES[f] ?? f}</span>
              <span className="text-xs text-muted">{counts[f] ?? 0} peligros base</span>
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

function VersionModal({ param: p, hazards, onClose, onDone }: { param: LegalParameter; hazards: Hazard[]; onClose: () => void; onDone: (m: string) => void }) {
  const [form, setForm] = useState({ value: p.value, valid_from: "", legal_reference: p.legal_reference ?? "", reason: "" });
  const families = isFamilyList(p);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(`/internal/catalogs/legal-parameters/${p.id}/versions`, { method: "POST", body: { ...form, legal_reference: form.legal_reference.trim() || null } });
      onDone(`Nueva versión guardada: rige desde el ${dateOnly(form.valid_from)}.`);
    } catch (err) {
      setErrors(err instanceof ApiError ? (err.errors ?? { value: [err.message] }) : { value: ["No se pudo guardar."] });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Nueva versión del parámetro"
      subtitle={p.description}
      icon="pen"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="param-version" icon="check" loading={saving}>
            Guardar versión
          </Button>
        </>
      }
    >
      <form id="param-version" onSubmit={submit} className="space-y-4" noValidate>
        <p className="rounded-lg bg-bg px-3 py-2 text-sm">
          Hoy rige <b>{readable(p)}</b> desde el {dateOnly(p.valid_from)}. La versión actual se cierra el día anterior a la nueva fecha; antes de esa fecha, las empresas siguen usando el valor
          actual.
        </p>
        {families && (
          <FormField label="Familias de peligro prohibidas" hint="Un menor no se asigna a un puesto cuyo IPERC tenga peligros de estas familias." error={errors.value?.[0]}>
            <FamilyPicker value={parseFamilies(form.value)} hazards={hazards} onChange={(v) => setForm({ ...form, value: JSON.stringify(v) })} />
          </FormField>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          {!families && (
            <FormField label={`Nuevo valor (${VALUE_TYPES[p.value_type] ?? p.value_type})`} error={errors.value?.[0]}>
              <input className="input tabular-nums" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
            </FormField>
          )}
          <FormField label="Rige desde" error={errors.valid_from?.[0]}>
            <input className="input" type="date" value={form.valid_from} onChange={(e) => setForm({ ...form, valid_from: e.target.value })} />
          </FormField>
        </div>
        <FormField label="Referencia legal" hint="Norma y artículo que lo establecen." error={errors.legal_reference?.[0]}>
          <input className="input" maxLength={200} value={form.legal_reference} onChange={(e) => setForm({ ...form, legal_reference: e.target.value })} />
        </FormField>
        <FormField label="Motivo del cambio" counter={`${form.reason.length}/300`} error={errors.reason?.[0]}>
          <textarea className="input min-h-20" maxLength={300} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
        </FormField>
        <p className="text-xs text-muted">La nueva versión queda «a confirmar» hasta que un especialista la valide.</p>
      </form>
    </Modal>
  );
}

function ConfirmModal({ param: p, onClose, onDone }: { param: LegalParameter; onClose: () => void; onDone: (m: string) => void }) {
  const [who, setWho] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api(`/internal/catalogs/legal-parameters/${p.id}/confirm`, { method: "POST", body: { confirmed_by: who.trim() } });
      onDone("Valor confirmado.");
    } catch (err) {
      setError(err instanceof ApiError ? (err.errors?.confirmed_by?.[0] ?? err.message) : "No se pudo confirmar.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={p.kind === "internal" ? "Marcar valor como revisado" : "Confirmar valor legal"}
      subtitle={p.description}
      icon="check"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="param-confirm" icon="check" loading={saving}>
            Confirmar
          </Button>
        </>
      }
    >
      <form id="param-confirm" onSubmit={submit} className="space-y-4" noValidate>
        <p className="text-sm">
          {p.kind === "internal" ? (
            <>
              Es un valor de EscudoSST (no lo exige la ley). Marca que el equipo revisó <b>{readable(p)}</b> y está de acuerdo.
            </>
          ) : (
            <>
              Confirma que <b>{readable(p)}</b> es correcto según {p.legal_reference ?? "la norma"}. Hazlo solo con la revisión de un especialista SST o legal.
            </>
          )}
        </p>
        <FormField label={p.kind === "internal" ? "Quién lo revisó" : "Especialista que lo validó"} hint="Nombre y cargo. Queda en la bitácora." error={error ?? undefined}>
          <input className="input" maxLength={120} placeholder="Ej.: Ing. Rosa Salas, especialista SST" value={who} onChange={(e) => setWho(e.target.value)} />
        </FormField>
      </form>
    </Modal>
  );
}

type CompanyData = { overrides: ParamOverride[]; companies: { id: string; name: string }[] };

/** Empresas con valor propio para el parámetro: reemplaza al global solo para ellas, desde hoy. */
function CompanyValues({ param: p, hazards, canEdit, onChanged }: { param: LegalParameter; hazards: Hazard[]; canEdit: boolean; onChanged: (m: string) => void }) {
  const [data, setData] = useState<CompanyData | null>(null);
  const [failed, setFailed] = useState(false);
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState({ company: "", value: p.value, reason: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const families = isFamilyList(p);

  const load = useCallback(() => {
    api<{ data: CompanyData }>(`/internal/catalogs/legal-parameters/${p.id}/companies`)
      .then((r) => setData(r.data))
      .catch(() => setFailed(true));
  }, [p.id]);
  useEffect(load, [load]);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!form.company) {
      setErrors({ company: ["Elige la empresa."] });
      return;
    }
    setSaving(true);
    setErrors({});
    try {
      await api(`/internal/catalogs/legal-parameters/${p.id}/companies/${form.company}`, { method: "PUT", body: { value: form.value, reason: form.reason.trim() } });
      setAdding(false);
      setForm({ company: "", value: p.value, reason: "" });
      load();
      onChanged("Valor propio de la empresa guardado: rige desde hoy.");
    } catch (err) {
      setErrors(err instanceof ApiError ? (err.errors ?? { value: [err.message] }) : { value: ["No se pudo guardar."] });
    } finally {
      setSaving(false);
    }
  }

  async function remove(o: ParamOverride) {
    try {
      await api(`/internal/catalogs/legal-parameters/${p.id}/companies/${o.company_id}`, { method: "DELETE", body: { reason: "Vuelve al valor general" } });
      load();
      onChanged(`${o.company_name} vuelve a usar el valor general.`);
    } catch (err) {
      onChanged(err instanceof ApiError ? err.message : "No se pudo quitar el valor propio.");
    }
  }

  return (
    <div className="mt-3 rounded-xl border border-line p-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs font-bold uppercase tracking-wide text-muted">Valores propios de empresas</p>
        {canEdit && data && !adding && (
          <Button variant="ghost" size="sm" icon="plus" onClick={() => setAdding(true)}>
            Valor para una empresa
          </Button>
        )}
      </div>
      {failed ? (
        <p className="mt-1 text-bad">No pudimos cargar los valores de las empresas.</p>
      ) : !data ? (
        <div className="mt-2 h-8 animate-pulse rounded bg-line/40" />
      ) : data.overrides.length === 0 ? (
        <p className="mt-1 text-muted">Todas las empresas usan el valor general.</p>
      ) : (
        <ul className="mt-2 space-y-1.5">
          {data.overrides.map((o) => (
            <li key={o.company_id} className="flex flex-wrap items-center justify-between gap-2">
              <span>
                <b>{o.company_name}</b>: {readable(p, o.value)} <span className="text-xs text-muted">· desde el {dateOnly(o.valid_from)}</span>
              </span>
              {canEdit && (
                <Button variant="danger-ghost" size="sm" icon="close" onClick={() => remove(o)}>
                  Usar el general
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
      {adding && data && (
        <form onSubmit={save} className="mt-3 space-y-3 border-t border-line pt-3" noValidate>
          <FormField label="Empresa" error={errors.company?.[0]}>
            <select className="input" value={form.company} onChange={(e) => setForm({ ...form, company: e.target.value })}>
              <option value="">Elige…</option>
              {data.companies.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </FormField>
          {families ? (
            <FormField label="Familias prohibidas para esta empresa" error={errors.value?.[0]}>
              <FamilyPicker value={parseFamilies(form.value)} hazards={hazards} onChange={(v) => setForm({ ...form, value: JSON.stringify(v) })} />
            </FormField>
          ) : (
            <FormField label={`Valor (${VALUE_TYPES[p.value_type] ?? p.value_type})`} error={errors.value?.[0]}>
              <input className="input tabular-nums" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
            </FormField>
          )}
          <FormField label="Motivo" hint="Por ejemplo, una política interna más estricta. Queda en la bitácora." error={errors.reason?.[0]}>
            <input className="input" maxLength={300} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
          </FormField>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" size="sm" onClick={() => setAdding(false)}>
              Cancelar
            </Button>
            <Button type="submit" size="sm" icon="check" loading={saving}>
              Guardar
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}
