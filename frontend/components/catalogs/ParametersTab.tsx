"use client";

// Parámetros legales con vigencia: cada parámetro muestra su valor vigente, si un especialista ya lo confirmó
// y su historial de versiones. Cambiar un valor crea una versión nueva desde una fecha; nunca se sobrescribe.

import { FormEvent, useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/accounts";
import { dateOnly, type LegalParameter, PARAM_AREAS, VALUE_TYPES } from "@/lib/catalogs";

const EMPTY = { q: "", state: "", area: "" };

export function ParametersTab({
  params,
  onChanged,
  initialState = "",
  onGoHolidays,
}: {
  params: LegalParameter[];
  onChanged: (msg: string) => void;
  initialState?: string;
  onGoHolidays: () => void;
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
                {expanded && (
                  <ol className="mt-3 space-y-1.5 rounded-xl border border-line p-3 text-sm">
                    {versions.map((v) => (
                      <li key={v.id} className={`flex flex-wrap items-center justify-between gap-2 ${v.is_current ? "font-semibold" : "text-muted"}`}>
                        <span>
                          {v.value} {v.unit ?? ""} · del {dateOnly(v.valid_from)} {v.valid_to ? `al ${dateOnly(v.valid_to)}` : "en adelante"}
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
              </li>
            );
          })}
        </ul>
      )}

      {edit && <VersionModal param={edit} onClose={() => setEdit(null)} onDone={(m) => (setEdit(null), onChanged(m))} />}
      {confirm && <ConfirmModal param={confirm} onClose={() => setConfirm(null)} onDone={(m) => (setConfirm(null), onChanged(m))} />}
    </div>
  );
}

function VersionModal({ param: p, onClose, onDone }: { param: LegalParameter; onClose: () => void; onDone: (m: string) => void }) {
  const [form, setForm] = useState({ value: p.value, valid_from: "", legal_reference: p.legal_reference ?? "", reason: "" });
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
          Hoy rige <b>{p.value} {p.unit ?? ""}</b> desde el {dateOnly(p.valid_from)}. La versión actual se cierra el día anterior a la nueva fecha; antes de esa fecha, las empresas siguen usando el valor
          actual.
        </p>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label={`Nuevo valor (${VALUE_TYPES[p.value_type] ?? p.value_type})`} error={errors.value?.[0]}>
            <input className="input tabular-nums" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
          </FormField>
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
              Es un valor de EscudoSST (no lo exige la ley). Marca que el equipo revisó <b>{p.value} {p.unit ?? ""}</b> y está de acuerdo.
            </>
          ) : (
            <>
              Confirma que <b>{p.value} {p.unit ?? ""}</b> es correcto según {p.legal_reference ?? "la norma"}. Hazlo solo con la revisión de un especialista SST o legal.
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
