"use client";

// Pestañas de Catálogos globales: normas legales (con su catálogo de requisitos), peligros base, paquetes sectoriales y feriados.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { SECTORS } from "@/lib/accounts";
import { dateOnly, HAZARD_FAMILIES, type Hazard, type Holiday, type LegalNorm, NORM_STATUS, NORM_TYPES, type NormRequirement, PACK_ITEM_TYPES, PACK_STATUS, type SectorPack } from "@/lib/catalogs";

type Done = (msg: string) => void;
const sectorLabel = (s: string | null) => SECTORS.find(([v]) => v === s)?.[1] ?? s ?? "";

function useSave() {
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  async function run(fn: () => Promise<unknown>) {
    setSaving(true);
    setErrors({});
    try {
      await fn();
      return true;
    } catch (e) {
      setErrors(e instanceof ApiError ? (e.errors ?? { _: [e.message] }) : { _: ["No se pudo guardar."] });
      return false;
    } finally {
      setSaving(false);
    }
  }
  return { saving, errors, run };
}

function Footer({ onClose, form, saving, label = "Guardar" }: { onClose: () => void; form: string; saving: boolean; label?: string }) {
  return (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancelar
      </Button>
      <Button type="submit" form={form} icon="check" loading={saving}>
        {label}
      </Button>
    </>
  );
}

// ---------------------------------------------------------------- Normas

export function NormsTab({ norms, onChanged, canEdit = true }: { norms: LegalNorm[]; onChanged: Done; canEdit?: boolean }) {
  const [f, setF] = useState({ q: "", type: "", status: "" });
  const [edit, setEdit] = useState<LegalNorm | "new" | null>(null);
  const [reqs, setReqs] = useState<LegalNorm | null>(null);
  const list = norms.filter(
    (n) =>
      (!f.q || [n.number, n.title, n.issuing_entity ?? ""].some((v) => v.toLowerCase().includes(f.q.toLowerCase()))) &&
      (!f.type || n.norm_type === f.type) &&
      (!f.status || n.status === f.status),
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted">Cada norma trae sus requisitos de catálogo: las empresas los agregan a su matriz legal con «Agregar desde el catálogo».</p>
        {canEdit && (
          <Button icon="plus" onClick={() => setEdit("new")}>
            Nueva norma
          </Button>
        )}
      </div>
      <FilterBar count={`${list.length} de ${norms.length} normas`} onClear={f.q || f.type || f.status ? () => setF({ q: "", type: "", status: "" }) : undefined}>
        <SearchInput label="Buscar normas" placeholder="Número, título o entidad" value={f.q} onChange={(q) => setF({ ...f, q })} />
        <FilterSelect label="Tipo" value={f.type} onChange={(type) => setF({ ...f, type })} options={[["", "Todos los tipos"], ...Object.entries(NORM_TYPES)]} />
        <FilterSelect label="Estado" value={f.status} onChange={(status) => setF({ ...f, status })} options={[["", "Todos"], ...Object.entries(NORM_STATUS).map(([k, v]) => [k, v.label] as [string, string])]} />
      </FilterBar>
      {list.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState text="Ninguna norma coincide." onClear={() => setF({ q: "", type: "", status: "" })} />
        </div>
      ) : (
        <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          {list.map((n) => (
            <li key={n.id} className="flex flex-col gap-2 pb-3 hover:bg-bg/60 lg:flex-row lg:items-center lg:pb-0 lg:pr-4">
              <button
                type="button"
                onClick={() => canEdit && setEdit(n)}
                disabled={!canEdit}
                className="grid w-full flex-1 gap-2 px-4 py-3 text-left disabled:cursor-default sm:px-5 lg:grid-cols-[12rem_minmax(0,1fr)_auto] lg:items-center"
              >
                <span>
                  <span className="block text-xs text-muted">{NORM_TYPES[n.norm_type]}</span>
                  <span className="font-semibold">{n.number}</span>
                </span>
                <span className="min-w-0">
                  <span className="block truncate">{n.title}</span>
                  <span className="text-xs text-muted">
                    {n.issuing_entity ?? ""} {n.published_on && `· publicada el ${dateOnly(n.published_on)}`} {Number(n.parameters_count) > 0 && `· ${n.parameters_count} parámetros`}
                  </span>
                </span>
                <span className="flex flex-wrap gap-2">
                  {n.scope === "sector" && <Chip tone="brand">{sectorLabel(n.sector)}</Chip>}
                  <Chip tone={NORM_STATUS[n.status].tone} dot>
                    {NORM_STATUS[n.status].label}
                  </Chip>
                </span>
              </button>
              <span className="flex shrink-0 items-center gap-2 px-4 lg:px-0">
                <Button variant="soft" size="sm" icon="checklist" onClick={() => setReqs(n)}>
                  Requisitos ({Number(n.requirements_count ?? 0)})
                </Button>
                {Number(n.requirements_to_confirm ?? 0) > 0 && <Chip tone="amber">{n.requirements_to_confirm} por confirmar</Chip>}
              </span>
            </li>
          ))}
        </ul>
      )}
      {edit && <NormModal norm={edit === "new" ? null : edit} onClose={() => setEdit(null)} onDone={(m) => (setEdit(null), onChanged(m))} />}
      {reqs && <RequirementsModal norm={reqs} canEdit={canEdit} onClose={() => setReqs(null)} onChanged={onChanged} />}
    </div>
  );
}

type Flagged = { companies: number; requirements: number; notified: number };
const EMPTY_REQ = { article: "", requirement: "", topic: "", applicability: "", evidence_expected: "", frequency: "" };

/** Mensaje tras cambiar el catálogo: cuántos requisitos de las empresas quedaron «requiere revisión». */
function flaggedMsg(base: string, f?: Flagged) {
  return f && f.requirements > 0 ? `${base} ${f.requirements} requisito(s) de ${f.companies} empresa(s) quedaron «requiere revisión».` : base;
}

/** Requisitos del catálogo de una norma: ver, agregar, editar, confirmar y archivar. */
function RequirementsModal({ norm, canEdit, onClose, onChanged }: { norm: LegalNorm; canEdit: boolean; onClose: () => void; onChanged: Done }) {
  const [list, setList] = useState<NormRequirement[] | null>(null);
  const [failed, setFailed] = useState<string | null>(null);
  const [form, setForm] = useState<(typeof EMPTY_REQ & { id?: string }) | null>(null);
  const [confirming, setConfirming] = useState<NormRequirement | null>(null);
  const [who, setWho] = useState("");
  const [showArchived, setShowArchived] = useState(false);
  const { saving, errors, run } = useSave();

  const load = useCallback(() => {
    api<{ data: { requirements: NormRequirement[] } }>(`/internal/catalogs/legal-norms/${norm.id}/requirements`)
      .then((r) => setList(r.data.requirements))
      .catch((e) => setFailed(e instanceof ApiError ? e.message : "No pudimos cargar los requisitos."));
  }, [norm.id]);
  useEffect(load, [load]);

  const after = (msg: string, f?: Flagged) => {
    load();
    onChanged(flaggedMsg(msg, f));
  };

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!form) return;
    const { id, ...body } = form;
    let flagged: Flagged | undefined;
    const ok = await run(async () => {
      const r = await api<{ data: { flagged?: Flagged } }>(id ? `/internal/catalogs/norm-requirements/${id}` : `/internal/catalogs/legal-norms/${norm.id}/requirements`, {
        method: id ? "PATCH" : "POST",
        body,
      });
      flagged = r.data.flagged;
    });
    if (ok) {
      setForm(null);
      after(id ? "Requisito actualizado: queda «por confirmar»." : "Requisito agregado: queda «por confirmar».", flagged);
    }
  }

  async function action(r: NormRequirement, path: "archive" | "restore") {
    try {
      const res = await api<{ data: { flagged?: Flagged } }>(`/internal/catalogs/norm-requirements/${r.id}/${path}`, { method: "POST", body: {} });
      after(path === "archive" ? "Requisito archivado: ya no se ofrece a las empresas." : "Requisito restaurado.", res.data.flagged);
    } catch (e) {
      onChanged(e instanceof ApiError ? e.message : "No se pudo completar la acción.");
    }
  }

  async function confirm(e: FormEvent) {
    e.preventDefault();
    if (!confirming) return;
    const ok = await run(() => api(`/internal/catalogs/norm-requirements/${confirming.id}/confirm`, { method: "POST", body: { confirmed_by: who.trim() } }));
    if (ok) {
      setConfirming(null);
      setWho("");
      after("Requisito confirmado.");
    }
  }

  const active = (list ?? []).filter((r) => !r.archived);
  const archived = (list ?? []).filter((r) => r.archived);
  const field = (k: keyof typeof EMPTY_REQ, label: string, max: number, hint?: string) => (
    <FormField label={label} hint={hint} error={errors[k]?.[0]}>
      <input className="input" maxLength={max} value={form?.[k] ?? ""} onChange={(e) => form && setForm({ ...form, [k]: e.target.value })} />
    </FormField>
  );

  return (
    <Modal title={`Requisitos de ${NORM_TYPES[norm.norm_type] ?? ""} ${norm.number}`} subtitle={norm.title} icon="checklist" size="lg" onClose={onClose}>
      <div className="space-y-4">
        <p className="flex items-start gap-2 rounded-xl bg-amber-soft px-3 py-2 text-sm text-amber-ink">
          <Icon name="shield" size={16} className="mt-0.5 shrink-0" />
          El texto es referencial hasta que un especialista SST o legal lo confirme. Si agregas, cambias o archivas un requisito, los requisitos que las empresas tienen de esta norma quedan «requiere
          revisión».
        </p>
        {errors._ && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{errors._[0]}</p>}
        {canEdit && !form && (
          <div className="flex justify-end">
            <Button icon="plus" size="sm" onClick={() => setForm({ ...EMPTY_REQ })}>
              Nuevo requisito
            </Button>
          </div>
        )}
        {form && (
          <form onSubmit={save} className="space-y-3 rounded-xl border border-brand/30 bg-brand-soft/30 p-3" noValidate>
            <p className="font-semibold">{form.id ? "Editar requisito" : "Nuevo requisito"}</p>
            <div className="grid gap-3 sm:grid-cols-3">
              {field("article", "Artículo", 60)}
              {field("topic", "Tema", 60, "Ej.: capacitacion, comite.")}
              {field("frequency", "Frecuencia", 80, "Ej.: Anual, Por obra.")}
            </div>
            <FormField label="Requisito" error={errors.requirement?.[0]} counter={`${form.requirement.length}/1000`}>
              <textarea className="input min-h-20" maxLength={1000} value={form.requirement} onChange={(e) => setForm({ ...form, requirement: e.target.value })} />
            </FormField>
            <div className="grid gap-3 sm:grid-cols-2">
              {field("applicability", "A quién aplica", 300, "Sector, tamaño o actividad. Vacío: a todas.")}
              {field("evidence_expected", "Evidencia esperada", 300)}
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" size="sm" onClick={() => setForm(null)}>
                Cancelar
              </Button>
              <Button type="submit" size="sm" icon="check" loading={saving}>
                Guardar
              </Button>
            </div>
          </form>
        )}
        {confirming && (
          <form onSubmit={confirm} className="space-y-3 rounded-xl border border-ok/30 bg-ok-soft/40 p-3" noValidate>
            <p className="text-sm">
              Confirma el texto de <b>{confirming.article ?? "este requisito"}</b>. Hazlo solo con la revisión de un especialista SST o legal.
            </p>
            <FormField label="Especialista que lo validó" hint="Nombre y cargo. Queda en la bitácora." error={errors.confirmed_by?.[0]}>
              <input className="input" maxLength={120} value={who} onChange={(e) => setWho(e.target.value)} placeholder="Ej.: Ing. Rosa Salas, especialista SST" />
            </FormField>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" size="sm" onClick={() => setConfirming(null)}>
                Cancelar
              </Button>
              <Button type="submit" size="sm" icon="check" loading={saving}>
                Confirmar
              </Button>
            </div>
          </form>
        )}
        {failed ? (
          <p className="text-bad">{failed}</p>
        ) : !list ? (
          <div className="h-32 animate-pulse rounded-xl bg-line/40" />
        ) : active.length === 0 ? (
          <EmptyState icon="checklist" text="Esta norma aún no tiene requisitos en el catálogo." />
        ) : (
          <ul className="divide-y divide-line rounded-xl border border-line">
            {active.map((r) => (
              <li key={r.id} className="space-y-1.5 px-3 py-3">
                <div className="flex flex-wrap items-center gap-2">
                  {r.article && <Code>{r.article}</Code>}
                  {r.topic && <Chip tone="na">{r.topic}</Chip>}
                  {r.to_confirm ? (
                    <Chip tone="amber" dot>
                      Por confirmar
                    </Chip>
                  ) : (
                    <span title={`Confirmado por ${r.confirmed_by ?? ""}`}>
                      <Chip tone="ok" dot>
                        Confirmado
                      </Chip>
                    </span>
                  )}
                </div>
                <p className="text-sm">{r.requirement}</p>
                <p className="text-xs text-muted">
                  {[r.applicability && `Aplica a: ${r.applicability}`, r.evidence_expected && `Evidencia: ${r.evidence_expected}`, r.frequency && `Frecuencia: ${r.frequency}`].filter(Boolean).join(" · ")}
                </p>
                {canEdit && (
                  <div className="flex flex-wrap gap-2">
                    <Button
                      variant="secondary"
                      size="sm"
                      icon="pen"
                      onClick={() =>
                        setForm({
                          id: r.id,
                          article: r.article ?? "",
                          requirement: r.requirement,
                          topic: r.topic ?? "",
                          applicability: r.applicability ?? "",
                          evidence_expected: r.evidence_expected ?? "",
                          frequency: r.frequency ?? "",
                        })
                      }
                    >
                      Editar
                    </Button>
                    {r.to_confirm && (
                      <Button variant="soft" size="sm" icon="check" onClick={() => setConfirming(r)}>
                        Confirmar
                      </Button>
                    )}
                    <Button variant="danger-ghost" size="sm" icon="close" onClick={() => action(r, "archive")}>
                      Archivar
                    </Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
        {archived.length > 0 && (
          <div>
            <button type="button" onClick={() => setShowArchived(!showArchived)} className="inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline" aria-expanded={showArchived}>
              <Icon name="chevron" size={15} className={`transition-transform ${showArchived ? "rotate-90" : ""}`} />
              Archivados ({archived.length})
            </button>
            {showArchived && (
              <ul className="mt-2 space-y-2">
                {archived.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-bg px-3 py-2 text-sm text-muted">
                    <span className="min-w-0 flex-1">
                      {r.article ? `${r.article} · ` : ""}
                      {r.requirement}
                    </span>
                    {canEdit && (
                      <Button variant="ghost" size="sm" icon="refresh" onClick={() => action(r, "restore")}>
                        Restaurar
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

function NormModal({ norm, onClose, onDone }: { norm: LegalNorm | null; onClose: () => void; onDone: Done }) {
  const [form, setForm] = useState({
    norm_type: norm?.norm_type ?? "law",
    number: norm?.number ?? "",
    title: norm?.title ?? "",
    issuing_entity: norm?.issuing_entity ?? "",
    published_on: norm?.published_on?.slice(0, 10) ?? "",
    repealed_on: norm?.repealed_on?.slice(0, 10) ?? "",
    scope: norm?.scope ?? "general",
    sector: norm?.sector ?? "",
    summary: norm?.summary ?? "",
    official_url: norm?.official_url ?? "",
    status: norm?.status ?? "current",
  });
  const { saving, errors, run } = useSave();
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));
  const nul = (v: string) => (v.trim() === "" ? null : v.trim());

  async function submit(e: FormEvent) {
    e.preventDefault();
    const body = { ...form, issuing_entity: nul(form.issuing_entity), published_on: nul(form.published_on), repealed_on: nul(form.repealed_on), sector: form.scope === "sector" ? nul(form.sector) : null, summary: nul(form.summary), official_url: nul(form.official_url) };
    const ok = await run(() => api(norm ? `/internal/catalogs/legal-norms/${norm.id}` : "/internal/catalogs/legal-norms", { method: norm ? "PATCH" : "POST", body }));
    if (ok) onDone(norm ? "Norma actualizada." : "Norma agregada.");
  }

  return (
    <Modal title={norm ? `${NORM_TYPES[norm.norm_type]} ${norm.number}` : "Nueva norma"} subtitle="Normas legales" icon="file" onClose={onClose} footer={<Footer onClose={onClose} form="norm-form" saving={saving} />}>
      <form id="norm-form" onSubmit={submit} className="space-y-4" noValidate>
        {errors._ && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{errors._[0]}</p>}
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Tipo">
            <select className="input" value={form.norm_type} onChange={(e) => set("norm_type", e.target.value)}>
              {Object.entries(NORM_TYPES).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Número" error={errors.number?.[0]}>
            <input className="input" maxLength={40} placeholder="Ej.: 005-2012-TR" value={form.number} onChange={(e) => set("number", e.target.value)} />
          </FormField>
          <FormField label="Estado">
            <select className="input" value={form.status} onChange={(e) => set("status", e.target.value)}>
              {Object.entries(NORM_STATUS).map(([v, s]) => (
                <option key={v} value={v}>
                  {s.label}
                </option>
              ))}
            </select>
          </FormField>
        </div>
        <FormField label="Título" error={errors.title?.[0]}>
          <input className="input" maxLength={300} value={form.title} onChange={(e) => set("title", e.target.value)} />
        </FormField>
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Entidad que la emite">
            <input className="input" maxLength={120} placeholder="Ej.: MTPE" value={form.issuing_entity} onChange={(e) => set("issuing_entity", e.target.value)} />
          </FormField>
          <FormField label="Publicada el">
            <input className="input" type="date" value={form.published_on} onChange={(e) => set("published_on", e.target.value)} />
          </FormField>
          {form.status === "repealed" && (
            <FormField label="Derogada el" error={errors.repealed_on?.[0]}>
              <input className="input" type="date" value={form.repealed_on} onChange={(e) => set("repealed_on", e.target.value)} />
            </FormField>
          )}
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Alcance">
            <select className="input" value={form.scope} onChange={(e) => set("scope", e.target.value)}>
              <option value="general">General (todos los sectores)</option>
              <option value="sector">De un sector</option>
            </select>
          </FormField>
          {form.scope === "sector" && (
            <FormField label="Sector" error={errors.sector?.[0]}>
              <select className="input" value={form.sector} onChange={(e) => set("sector", e.target.value)}>
                <option value="">Elige…</option>
                {SECTORS.map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
            </FormField>
          )}
        </div>
        <FormField label="Enlace oficial (El Peruano u otro)" error={errors.official_url?.[0]}>
          <input className="input" type="url" maxLength={300} placeholder="https://" value={form.official_url} onChange={(e) => set("official_url", e.target.value)} />
        </FormField>
        <FormField label="Resumen" counter={`${form.summary.length}/1000`}>
          <textarea className="input min-h-20" maxLength={1000} value={form.summary} onChange={(e) => set("summary", e.target.value)} />
        </FormField>
      </form>
    </Modal>
  );
}

// ---------------------------------------------------------------- Peligros base

export function HazardsTab({ hazards, onChanged }: { hazards: Hazard[]; onChanged: Done }) {
  const [f, setF] = useState({ q: "", family: "", active: "yes" });
  const [edit, setEdit] = useState<Hazard | "new" | null>(null);
  const list = hazards.filter(
    (h) =>
      (!f.q || [h.code, h.hazard, h.risk].some((v) => v.toLowerCase().includes(f.q.toLowerCase()))) &&
      (!f.family || h.hazard_family === f.family) &&
      (!f.active || (f.active === "yes") === h.is_active),
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted">Base que las empresas usan al armar su matriz IPERC. Cada empresa puede agregar los suyos.</p>
        <Button icon="plus" onClick={() => setEdit("new")}>
          Nuevo peligro
        </Button>
      </div>
      <FilterBar count={`${list.length} de ${hazards.length} peligros`} onClear={f.q || f.family || f.active !== "yes" ? () => setF({ q: "", family: "", active: "yes" }) : undefined}>
        <SearchInput label="Buscar peligros" placeholder="Código, peligro o riesgo" value={f.q} onChange={(q) => setF({ ...f, q })} />
        <FilterSelect label="Familia" value={f.family} onChange={(family) => setF({ ...f, family })} options={[["", "Todas"], ...Object.entries(HAZARD_FAMILIES)]} />
        <FilterSelect
          label="Estado"
          value={f.active}
          neutral="yes"
          onChange={(active) => setF({ ...f, active })}
          options={[
            ["yes", "Activos"],
            ["no", "Desactivados"],
            ["", "Todos"],
          ]}
        />
      </FilterBar>
      {list.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState text="Ningún peligro coincide." onClear={() => setF({ q: "", family: "", active: "yes" })} />
        </div>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {list.map((h) => (
            <li key={h.id}>
              <button type="button" onClick={() => setEdit(h)} className={`h-full w-full rounded-2xl border border-line bg-surface p-4 text-left shadow-sm hover:border-brand/40 ${h.is_active ? "" : "opacity-60"}`}>
                <span className="flex flex-wrap items-center gap-2">
                  <Code>{h.code}</Code>
                  <Chip tone="brand">{HAZARD_FAMILIES[h.hazard_family]}</Chip>
                  {!h.is_active && <Chip tone="na">Desactivado</Chip>}
                  {h.sector && <Chip tone="amber">{sectorLabel(h.sector)}</Chip>}
                </span>
                <span className="mt-2 block font-medium">{h.hazard}</span>
                <span className="block text-sm text-muted">Riesgo: {h.risk}</span>
                {h.typical_controls.length > 0 && <span className="mt-1 block truncate text-xs text-muted">Controles: {h.typical_controls.join(" · ")}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
      {edit && <HazardModal hazard={edit === "new" ? null : edit} onClose={() => setEdit(null)} onDone={(m) => (setEdit(null), onChanged(m))} />}
    </div>
  );
}

function HazardModal({ hazard, onClose, onDone }: { hazard: Hazard | null; onClose: () => void; onDone: Done }) {
  const [form, setForm] = useState({
    code: hazard?.code ?? "",
    hazard_family: hazard?.hazard_family ?? "mechanical",
    hazard: hazard?.hazard ?? "",
    risk: hazard?.risk ?? "",
    possible_consequence: hazard?.possible_consequence ?? "",
    controls: (hazard?.typical_controls ?? []).join("\n"),
    sector: hazard?.sector ?? "",
    is_active: hazard?.is_active ?? true,
  });
  const { saving, errors, run } = useSave();

  async function submit(e: FormEvent) {
    e.preventDefault();
    const body = {
      code: form.code,
      hazard_family: form.hazard_family,
      hazard: form.hazard,
      risk: form.risk,
      possible_consequence: form.possible_consequence.trim() || null,
      typical_controls: form.controls.split("\n").map((c) => c.trim()).filter(Boolean),
      sector: form.sector || null,
      is_active: form.is_active,
    };
    const ok = await run(() => api(hazard ? `/internal/catalogs/hazards/${hazard.id}` : "/internal/catalogs/hazards", { method: hazard ? "PATCH" : "POST", body }));
    if (ok) onDone(hazard ? "Peligro actualizado." : "Peligro agregado.");
  }

  return (
    <Modal title={hazard ? `Peligro ${hazard.code}` : "Nuevo peligro"} subtitle="Peligros base" icon="shield" onClose={onClose} footer={<Footer onClose={onClose} form="hazard-form" saving={saving} />}>
      <form id="hazard-form" onSubmit={submit} className="space-y-4" noValidate>
        {errors._ && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{errors._[0]}</p>}
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Código" error={errors.code?.[0]}>
            <input className="input uppercase" maxLength={20} disabled={!!hazard} placeholder="Ej.: MEC-07" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          </FormField>
          <FormField label="Familia">
            <select className="input" value={form.hazard_family} onChange={(e) => setForm({ ...form, hazard_family: e.target.value })}>
              {Object.entries(HAZARD_FAMILIES).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Sector" hint="Vacío: aplica a todos.">
            <select className="input" value={form.sector} onChange={(e) => setForm({ ...form, sector: e.target.value })}>
              <option value="">Todos</option>
              {SECTORS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
        </div>
        <FormField label="Peligro" error={errors.hazard?.[0]}>
          <input className="input" maxLength={200} value={form.hazard} onChange={(e) => setForm({ ...form, hazard: e.target.value })} />
        </FormField>
        <FormField label="Riesgo" error={errors.risk?.[0]}>
          <input className="input" maxLength={200} value={form.risk} onChange={(e) => setForm({ ...form, risk: e.target.value })} />
        </FormField>
        <FormField label="Consecuencia posible">
          <input className="input" maxLength={300} value={form.possible_consequence} onChange={(e) => setForm({ ...form, possible_consequence: e.target.value })} />
        </FormField>
        <FormField label="Controles típicos" hint="Uno por línea.">
          <textarea className="input min-h-24" value={form.controls} onChange={(e) => setForm({ ...form, controls: e.target.value })} />
        </FormField>
        <SwitchRow label="Activo" hint="Si lo desactivas, ya no se ofrece al armar nuevas matrices. Las que ya lo usan no cambian." checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
      </form>
    </Modal>
  );
}

// ---------------------------------------------------------------- Paquetes sectoriales

export function PacksTab({ packs, onChanged }: { packs: SectorPack[]; onChanged: Done }) {
  const [open, setOpen] = useState<string | null>(null);

  async function setStatus(p: SectorPack, status: SectorPack["status"]) {
    try {
      await api(`/internal/catalogs/sector-packs/${p.id}`, { method: "PATCH", body: { status } });
      onChanged(`${p.name}: ${PACK_STATUS[status].label.toLowerCase()}.`);
    } catch (e) {
      onChanged(e instanceof ApiError ? e.message : "No se pudo cambiar el estado.");
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted">Plantillas por sector que las empresas con el complemento «Paquetes sectoriales» cargan de una vez: peligros, inspecciones, permisos, capacitaciones y más.</p>
      <ul className="grid gap-4 md:grid-cols-2">
        {packs.map((p) => {
          const byType = p.items.reduce<Record<string, string[]>>((acc, i) => ({ ...acc, [i.item_type]: [...(acc[i.item_type] ?? []), i.name] }), {});
          const expanded = open === p.id;
          return (
            <li key={p.id} className="rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-display text-lg font-semibold">{p.name}</p>
                  <p className="text-xs text-muted">
                    <Code>{p.code}</Code> · versión {p.version_number} · {p.items.length} elementos
                  </p>
                </div>
                <Chip tone={PACK_STATUS[p.status].tone} dot>
                  {PACK_STATUS[p.status].label}
                </Chip>
              </div>
              {p.description && <p className="mt-2 text-sm text-muted">{p.description}</p>}
              <div className="mt-3 flex flex-wrap gap-1.5">
                {Object.entries(byType).map(([t, names]) => (
                  <span key={t} className="rounded-full bg-bg px-2.5 py-1 text-xs font-semibold">
                    {PACK_ITEM_TYPES[t] ?? t}: {names.length}
                  </span>
                ))}
              </div>
              <button type="button" onClick={() => setOpen(expanded ? null : p.id)} className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline" aria-expanded={expanded}>
                <Icon name="chevron" size={15} className={`transition-transform ${expanded ? "rotate-90" : ""}`} />
                {expanded ? "Ocultar contenido" : "Ver contenido"}
              </button>
              {expanded && (
                <div className="mt-2 space-y-2 rounded-xl border border-line p-3 text-sm">
                  {Object.entries(byType).map(([t, names]) => (
                    <div key={t}>
                      <p className="text-xs font-bold uppercase tracking-wide text-muted">{PACK_ITEM_TYPES[t] ?? t}</p>
                      <p>{names.join(" · ")}</p>
                    </div>
                  ))}
                </div>
              )}
              <div className="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
                {p.status !== "published" && (
                  <Button variant="soft" size="sm" icon="check" onClick={() => setStatus(p, "published")}>
                    Publicar
                  </Button>
                )}
                {p.status === "published" && (
                  <Button variant="secondary" size="sm" icon="pen" onClick={() => setStatus(p, "draft")}>
                    Pasar a borrador
                  </Button>
                )}
                {p.status !== "retired" && (
                  <Button variant="danger-ghost" size="sm" icon="close" onClick={() => setStatus(p, "retired")}>
                    Retirar
                  </Button>
                )}
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

// ---------------------------------------------------------------- Feriados

export function HolidaysTab({ holidays, onChanged }: { holidays: Holiday[]; onChanged: Done }) {
  const years = [...new Set(holidays.map((h) => h.holiday_on.slice(0, 4)))].sort();
  const thisYear = String(new Date().getFullYear());
  const [year, setYear] = useState(years.includes(thisYear) ? thisYear : (years[years.length - 1] ?? thisYear));
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState({ holiday_on: "", name: "" });
  const { saving, errors, run } = useSave();
  const list = holidays.filter((h) => h.holiday_on.startsWith(year));

  async function add(e: FormEvent) {
    e.preventDefault();
    const ok = await run(() => api("/internal/catalogs/holidays", { method: "POST", body: form }));
    if (ok) {
      setAdding(false);
      setForm({ holiday_on: "", name: "" });
      onChanged("Feriado agregado.");
    }
  }
  async function patch(h: Holiday, body: Record<string, unknown>, msg: string) {
    await api(`/internal/catalogs/holidays/${h.id}`, { method: "PATCH", body }).catch(() => undefined);
    onChanged(msg);
  }
  async function remove(h: Holiday) {
    await api(`/internal/catalogs/holidays/${h.id}`, { method: "DELETE" }).catch(() => undefined);
    onChanged(`Se quitó ${h.name}.`);
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted">Se usan para contar días hábiles (plazos de reclamos, investigaciones y avisos).</p>
        <div className="flex gap-2">
          <select className="filter-control w-28" aria-label="Año" value={year} onChange={(e) => setYear(e.target.value)}>
            {(years.length ? years : [thisYear]).map((y) => (
              <option key={y}>{y}</option>
            ))}
          </select>
          <Button icon="plus" onClick={() => setAdding(true)}>
            Agregar feriado
          </Button>
        </div>
      </div>
      {list.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState icon="clock" text={`No hay feriados cargados para ${year}.`} />
        </div>
      ) : (
        <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          {list.map((h) => (
            <li key={h.id} className="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
              <span className="w-28 font-semibold tabular-nums">{dateOnly(h.holiday_on)}</span>
              <span className="min-w-0 flex-1">{h.name}</span>
              {h.to_confirm ? (
                <button type="button" onClick={() => patch(h, { to_confirm: false }, `${h.name} confirmado.`)} title="Marcar como confirmado (por ejemplo, tras el decreto del año)">
                  <Chip tone="amber" dot>
                    A confirmar
                  </Chip>
                </button>
              ) : (
                <Chip tone="ok" dot>
                  Confirmado
                </Chip>
              )}
              <Button variant="danger-ghost" size="sm" icon="close" onClick={() => remove(h)} aria-label={`Quitar ${h.name}`}>
                <span className="hidden sm:inline">Quitar</span>
              </Button>
            </li>
          ))}
        </ul>
      )}
      {adding && (
        <Modal title="Agregar feriado" subtitle="Feriado nacional" icon="clock" size="sm" onClose={() => setAdding(false)} footer={<Footer onClose={() => setAdding(false)} form="holiday-form" saving={saving} label="Agregar" />}>
          <form id="holiday-form" onSubmit={add} className="space-y-4" noValidate>
            <FormField label="Fecha" error={errors.holiday_on?.[0]}>
              <input className="input" type="date" value={form.holiday_on} onChange={(e) => setForm({ ...form, holiday_on: e.target.value })} />
            </FormField>
            <FormField label="Nombre" error={errors.name?.[0]}>
              <input className="input" maxLength={120} placeholder="Ej.: Día no laborable decretado" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </FormField>
          </form>
        </Modal>
      )}
    </div>
  );
}
