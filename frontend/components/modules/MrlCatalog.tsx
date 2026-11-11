"use client";

// Matriz de requisitos legales (M-MRL SCR-MRL-01/03): catálogo de normas (globales de EscudoSST y propias de la
// empresa) con filtros por tipo, sector, tema y origen; «Agregar norma propia»; y el asistente «Agregar desde el
// catálogo»: sector y tema → normas → vista previa de sus requisitos (marcar los que aplican, sumar uno propio) →
// responsable y próxima evaluación → resumen. Los requisitos elegidos se agregan a la matriz sin duplicarse.

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { ModuleRecords } from "@/components/records/ModuleRecords";
import { recordsHref } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { Modal } from "@/components/ui/Modal";
import { MrlNews } from "./MrlNews";
import { RefSelect } from "./RefSelect";
import { Card, Chip, type Tone } from "./Summary";
import { BTN_PRIMARY, SummaryRow, Wizard, errorText, type WizardStep } from "./Wizard";

type Norm = {
  id: string;
  norm_type: string;
  number: string;
  title: string;
  issuing_entity: string | null;
  published_on: string | null;
  status: string;
  summary: string | null;
  official_url: string | null;
  sectors: string[];
  topics: string[];
  is_company: boolean;
  requirements_count: number;
  added_count: number;
  review_count?: number;
  recently_changed: boolean;
};
type Meta = { sectors: string[]; topics: string[]; can_create_norm: boolean; can_add: boolean; evaluation_months: number };
type Template = { id: string; article: string | null; requirement: string; topic: string | null; evidence_expected: string | null; frequency: string | null; added: boolean };
type Preview = { id: string; norm_type: string; number: string; title: string; is_company: boolean; requirements: Template[] };

export const NORM_TYPES: Record<string, string> = {
  law: "Ley",
  supreme_decree: "D.S.",
  ministerial_resolution: "R.M.",
  technical_standard: "Norma técnica",
  ordinance: "Ordenanza",
  directive: "Directiva",
  other: "Otra",
};
const SECTORS: Record<string, string> = {
  general: "Todos los sectores",
  construccion: "Construcción",
  mineria: "Minería",
  industria: "Industria",
  comercio: "Comercio y servicios",
  salud: "Salud",
  agro: "Agroindustria",
  transporte: "Transporte",
};
const TOPICS: Record<string, string> = {
  gestion: "Sistema de gestión",
  registros: "Registros obligatorios",
  comite: "Comité de SST",
  capacitacion: "Capacitación",
  salud_ocupacional: "Salud ocupacional",
  eventos: "Accidentes e incidentes",
  ergonomia: "Ergonomía",
  agentes: "Agentes químicos y físicos",
  senalizacion: "Señalización",
  sctr: "SCTR",
  datos_personales: "Datos personales",
  inspeccion: "Inspección del trabajo",
  auditoria: "Auditoría",
  construccion: "Obras de construcción",
  mineria: "Seguridad minera",
  consumidor: "Consumidor",
};
const STATUS: Record<string, [string, Tone]> = { current: ["Vigente", "ok"], amended: ["Modificada", "warn"], repealed: ["Derogada", "bad"] };
const label = (map: Record<string, string>, v: string) => map[v] ?? v.charAt(0).toUpperCase() + v.slice(1).replace(/_/g, " ");
const normName = (n: { norm_type: string; number: string }) => `${NORM_TYPES[n.norm_type] ?? ""} ${n.number}`.trim();
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

function useCatalog(params: string) {
  const [norms, setNorms] = useState<Norm[] | null>(null);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Norm[]; meta: Meta }>(`/mrl/catalog${params ? `?${params}` : ""}`, { withCompany: true })
      .then((r) => {
        setNorms(r.data);
        setMeta(r.meta);
        setError(null);
      })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [params]);
  useEffect(load, [load]);
  return { norms, meta, error, load };
}

function qs(f: Record<string, string>) {
  return new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString();
}

// ─────────────── Asistente «Agregar desde el catálogo» ───────────────

type Custom = { norm_id: string; article: string; requirement: string };
type Data = {
  sector: string;
  topic: string;
  norm_ids: string[];
  norm_names: Record<string, string>;
  picked: string[];
  picked_text: Record<string, string>;
  custom: Custom[];
  responsible_user_id: string;
  responsible_label: string;
  next_evaluation_on: string;
  site_id: string;
  site_label: string;
};

function NormPicker({ d, set }: { d: Data; set: (p: Partial<Data>) => void }) {
  const { norms, error } = useCatalog(qs({ sector: d.sector, topic: d.topic }));
  if (error) return <p className="text-sm text-bad">{error}</p>;
  if (!norms) return <div className="h-32 animate-pulse rounded-xl bg-bg" />;
  if (norms.length === 0) return <p className="text-sm text-muted">No hay normas para ese sector y tema. Vuelve y cambia el filtro.</p>;
  const toggle = (n: Norm) => {
    const on = d.norm_ids.includes(n.id);
    set({ norm_ids: on ? d.norm_ids.filter((x) => x !== n.id) : [...d.norm_ids, n.id], norm_names: { ...d.norm_names, [n.id]: `${normName(n)} · ${n.title}` } });
  };
  return (
    <ul className="space-y-2">
      {norms.map((n) => {
        const on = d.norm_ids.includes(n.id);
        return (
          <li key={n.id}>
            <button type="button" role="checkbox" aria-checked={on} onClick={() => toggle(n)}
              className={`flex min-h-12 w-full items-start gap-3 rounded-xl border p-3 text-left text-sm ${on ? "border-brand bg-brand-soft/50 ring-1 ring-brand" : "border-line hover:border-brand/50"}`}>
              <span className={`mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded border-2 text-xs ${on ? "border-brand bg-brand text-white" : "border-line"}`}>{on ? "✓" : ""}</span>
              <span className="min-w-0 flex-1">
                <b className="block">{normName(n)} · {n.title}</b>
                <span className="block text-xs text-muted">
                  {n.requirements_count} requisito(s) en el catálogo{n.added_count ? ` · ${n.added_count} ya en tu matriz` : ""}{n.is_company ? " · norma propia" : ""}
                </span>
              </span>
              {n.status !== "current" && <Chip tone={STATUS[n.status]?.[1] ?? "na"}>{STATUS[n.status]?.[0] ?? n.status}</Chip>}
            </button>
          </li>
        );
      })}
    </ul>
  );
}

function RequirementPreview({ d, set }: { d: Data; set: (p: Partial<Data>) => void }) {
  const [list, setList] = useState<Preview[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const ids = d.norm_ids.join(",");
  useEffect(() => {
    api<{ data: Preview[] }>(`/mrl/catalog/requirements?norm_ids=${ids}`, { withCompany: true })
      .then((r) => setList(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [ids]);
  if (error) return <p className="text-sm text-bad">{error}</p>;
  if (!list) return <div className="h-40 animate-pulse rounded-xl bg-bg" />;
  const toggle = (t: Template) => {
    const on = d.picked.includes(t.id);
    set({ picked: on ? d.picked.filter((x) => x !== t.id) : [...d.picked, t.id], picked_text: { ...d.picked_text, [t.id]: t.requirement } });
  };
  const setCustom = (i: number, p: Partial<Custom>) => set({ custom: d.custom.map((c, j) => (j === i ? { ...c, ...p } : c)) });
  return (
    <div className="space-y-4">
      {list.map((n) => {
        const free = n.requirements.filter((t) => !t.added);
        return (
          <section key={n.id} className="rounded-xl border border-line p-3">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <b className="text-sm">{normName(n)} · {n.title}</b>
              {free.length > 0 && (
                <button type="button" className="text-xs font-semibold text-brand hover:underline"
                  onClick={() => set({ picked: [...new Set([...d.picked, ...free.map((t) => t.id)])], picked_text: { ...d.picked_text, ...Object.fromEntries(free.map((t) => [t.id, t.requirement])) } })}>
                  Marcar todos
                </button>
              )}
            </div>
            {n.requirements.length === 0 ? (
              <p className="text-xs text-muted">Esta norma no trae requisitos en el catálogo: agrega el tuyo abajo.</p>
            ) : (
              <ul className="space-y-1.5">
                {n.requirements.map((t) => {
                  const on = d.picked.includes(t.id);
                  return (
                    <li key={t.id}>
                      <button type="button" role="checkbox" aria-checked={on || t.added} disabled={t.added} onClick={() => toggle(t)}
                        className={`flex w-full items-start gap-3 rounded-lg border p-2.5 text-left text-sm disabled:opacity-60 ${on ? "border-brand bg-brand-soft/40" : "border-line hover:border-brand/50"}`}>
                        <span className={`mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded border-2 text-xs ${on || t.added ? "border-brand bg-brand text-white" : "border-line"}`}>{on || t.added ? "✓" : ""}</span>
                        <span className="min-w-0 flex-1">
                          {t.article && <b>{t.article}. </b>}
                          {t.requirement}
                          <span className="block text-xs text-muted">
                            {t.added ? "Ya está en tu matriz" : [t.evidence_expected && `Evidencia: ${t.evidence_expected}`, t.frequency && `Frecuencia: ${t.frequency}`].filter(Boolean).join(" · ")}
                          </span>
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </section>
        );
      })}
      <section className="space-y-2 rounded-xl border border-dashed border-line p-3">
        <p className="text-sm font-semibold">Requisito propio (opcional)</p>
        {d.custom.map((c, i) => (
          <div key={i} className="grid gap-2 sm:grid-cols-[minmax(0,12rem)_6rem_minmax(0,1fr)_auto]">
            <select className="input" aria-label="Norma" value={c.norm_id} onChange={(e) => setCustom(i, { norm_id: e.target.value })}>
              {list.map((n) => <option key={n.id} value={n.id}>{normName(n)}</option>)}
            </select>
            <input className="input" aria-label="Artículo" placeholder="Art." maxLength={40} value={c.article} onChange={(e) => setCustom(i, { article: e.target.value })} />
            <input className="input" aria-label="Requisito" placeholder="Qué exige la norma" maxLength={2000} value={c.requirement} onChange={(e) => setCustom(i, { requirement: e.target.value })} />
            <Button size="sm" variant="danger-ghost" onClick={() => set({ custom: d.custom.filter((_, j) => j !== i) })}>Quitar</Button>
          </div>
        ))}
        <Button size="sm" variant="ghost" icon="plus" onClick={() => set({ custom: [...d.custom, { norm_id: list[0]?.id ?? "", article: "", requirement: "" }] })}>Agregar requisito propio</Button>
      </section>
    </div>
  );
}

export function MrlAddWizard({ meta, onClose, onDone }: { meta: Meta; onClose: () => void; onDone: () => void }) {
  const next = new Date();
  next.setMonth(next.getMonth() + (meta.evaluation_months || 12));
  const initial: Data = {
    sector: "", topic: "", norm_ids: [], norm_names: {}, picked: [], picked_text: {}, custom: [],
    responsible_user_id: "", responsible_label: "", next_evaluation_on: iso(next), site_id: "", site_label: "",
  };
  const customOk = (d: Data) => d.custom.filter((c) => c.requirement.trim().length >= 5);
  const steps: WizardStep<Data>[] = [
    {
      title: "Sector y tema",
      hint: "Filtra el catálogo por la actividad de tu empresa. Las normas de «todos los sectores» aparecen siempre.",
      render: (d, set) => (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Sector">
            <select className="input" value={d.sector} onChange={(e) => set({ sector: e.target.value, norm_ids: [], picked: [] })}>
              <option value="">Todos</option>
              {meta.sectors.map((s) => <option key={s} value={s}>{label(SECTORS, s)}</option>)}
            </select>
          </FormField>
          <FormField label="Tema">
            <select className="input" value={d.topic} onChange={(e) => set({ topic: e.target.value, norm_ids: [], picked: [] })}>
              <option value="">Todos</option>
              {meta.topics.map((t) => <option key={t} value={t}>{label(TOPICS, t)}</option>)}
            </select>
          </FormField>
        </div>
      ),
    },
    {
      title: "Normas",
      hint: "Marca las normas que aplican a tu empresa.",
      validate: (d) => (d.norm_ids.length === 0 ? "Marca al menos una norma." : null),
      render: (d, set) => <NormPicker d={d} set={set} />,
    },
    {
      title: "Requisitos",
      hint: "Revisa lo que exige cada norma y marca lo que aplica. Lo que ya está en tu matriz no se duplica.",
      validate: (d) =>
        d.picked.length + customOk(d).length === 0
          ? "Marca al menos un requisito o escribe uno propio."
          : d.custom.some((c) => c.requirement.trim() && c.requirement.trim().length < 5)
            ? "El requisito propio debe tener al menos 5 caracteres."
            : null,
      render: (d, set) => <RequirementPreview d={d} set={set} />,
    },
    {
      title: "Responsable y evaluación",
      hint: `Cada requisito tiene un responsable y una próxima evaluación (por defecto en ${meta.evaluation_months} meses).`,
      validate: (d) => (!d.responsible_user_id ? "Elige al responsable." : !d.next_evaluation_on ? "Indica la próxima evaluación." : d.next_evaluation_on < iso(new Date()) ? "La próxima evaluación no puede ser pasada." : null),
      render: (d, set) => (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Responsable">
            <RefSelect module="MRL" table="legal_requirements" field="responsible_user_id" label="Responsable" value={d.responsible_user_id}
              onChange={(id, l) => set({ responsible_user_id: id, responsible_label: id ? l : "" })} />
          </FormField>
          <FormField label="Próxima evaluación">
            <input className="input" type="date" min={iso(new Date())} value={d.next_evaluation_on} onChange={(e) => set({ next_evaluation_on: e.target.value })} />
          </FormField>
          <div className="sm:col-span-2">
            <FormField label="Sede (opcional)">
              <RefSelect module="MRL" table="legal_requirements" field="site_id" label="Sede" placeholder="Toda la empresa" value={d.site_id}
                onChange={(id, l) => set({ site_id: id, site_label: id ? l : "" })} />
            </FormField>
          </div>
        </div>
      ),
    },
  ];
  return (
    <Wizard<Data>
      id="mrl-add"
      title="Agregar desde el catálogo"
      subtitle="Normas y requisitos legales que aplican a tu empresa"
      icon="scale"
      initial={initial}
      steps={steps}
      confirmLabel="Agregar a la matriz"
      onClose={onClose}
      summary={(d, goTo) => (
        <div>
          <SummaryRow label="Sector y tema" value={`${d.sector ? label(SECTORS, d.sector) : "Todos los sectores"} · ${d.topic ? label(TOPICS, d.topic) : "Todos los temas"}`} onEdit={() => goTo(0)} />
          <SummaryRow label="Normas" value={d.norm_ids.map((id) => d.norm_names[id]).filter(Boolean).join("; ")} onEdit={() => goTo(1)} />
          <SummaryRow label="Requisitos" value={`${d.picked.length} del catálogo${customOk(d).length ? ` + ${customOk(d).length} propio(s)` : ""}`} onEdit={() => goTo(2)} />
          <SummaryRow label="Responsable" value={d.responsible_label} onEdit={() => goTo(3)} />
          <SummaryRow label="Próxima evaluación" value={d.next_evaluation_on.split("-").reverse().join("/")} onEdit={() => goTo(3)} />
          <SummaryRow label="Sede" value={d.site_label || "Toda la empresa"} onEdit={() => goTo(3)} />
        </div>
      )}
      onConfirm={async (d) => {
        const r = await api<{ data: { created: number; skipped: number } }>("/mrl/catalog/add", {
          method: "POST",
          withCompany: true,
          body: {
            items: [
              ...d.picked.map((id) => ({ template_id: id })),
              ...customOk(d).map((c) => ({ legal_norm_id: c.norm_id, article: c.article.trim() || null, requirement: c.requirement.trim() })),
            ],
            responsible_user_id: d.responsible_user_id,
            next_evaluation_on: d.next_evaluation_on,
            site_id: d.site_id || null,
          },
        });
        onDone();
        return (
          <div className="space-y-3">
            <div className="rounded-xl border border-line p-4">
              <p className="text-sm text-muted">Matriz actualizada</p>
              <p className="font-display text-2xl font-bold">{r.data.created} requisito(s) agregado(s)</p>
              {r.data.skipped > 0 && <p className="text-sm text-muted">{r.data.skipped} ya estaban en la matriz y no se duplicaron.</p>}
            </div>
            <a className={BTN_PRIMARY} href={recordsHref("MRL", "legal_requirements")}>Ver la matriz</a>
          </div>
        );
      }}
    />
  );
}

// ─────────────── Norma propia ───────────────

function NormForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ norm_type: "ordinance", number: "", title: "", issuing_entity: "", published_on: "", sector: "", topic: "", summary: "", official_url: "" });
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const save = async () => {
    setBusy(true);
    setMsg(null);
    setErrors({});
    try {
      await api("/mrl/catalog/norms", {
        method: "POST",
        withCompany: true,
        body: { ...f, sectors: f.sector ? [f.sector] : [], topics: f.topic ? [f.topic] : [], published_on: f.published_on || null },
      });
      onSaved();
      onClose();
    } catch (e) {
      setErrors((e as ApiError).errors ?? {});
      setMsg(errorText(e));
    } finally {
      setBusy(false);
    }
  };
  const err = (k: string) => errors[k]?.[0];
  return (
    <Modal title="Agregar norma propia" subtitle="Solo la ve tu empresa (p. ej. una ordenanza municipal o un estándar del cliente)" icon="scale" onClose={onClose}
      footer={<><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Guardar norma</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Tipo" error={err("norm_type")}>
          <select className="input" value={f.norm_type} onChange={(e) => setF({ ...f, norm_type: e.target.value })}>
            {Object.entries(NORM_TYPES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <FormField label="Número" error={err("number")}>
          <input className="input" maxLength={60} value={f.number} onChange={(e) => setF({ ...f, number: e.target.value })} />
        </FormField>
        <div className="sm:col-span-2">
          <FormField label="Título" error={err("title")}>
            <input className="input" maxLength={300} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} />
          </FormField>
        </div>
        <FormField label="Entidad emisora">
          <input className="input" maxLength={150} value={f.issuing_entity} onChange={(e) => setF({ ...f, issuing_entity: e.target.value })} />
        </FormField>
        <FormField label="Publicada el" error={err("published_on")}>
          <input className="input" type="date" value={f.published_on} onChange={(e) => setF({ ...f, published_on: e.target.value })} />
        </FormField>
        <FormField label="Sector">
          <select className="input" value={f.sector} onChange={(e) => setF({ ...f, sector: e.target.value })}>
            <option value="">Todos los sectores</option>
            {Object.entries(SECTORS).filter(([v]) => v !== "general").map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <FormField label="Tema">
          <select className="input" value={f.topic} onChange={(e) => setF({ ...f, topic: e.target.value })}>
            <option value="">Sin tema</option>
            {Object.entries(TOPICS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <div className="sm:col-span-2">
          <FormField label="Enlace oficial (opcional)" error={err("official_url")}>
            <input className="input" inputMode="url" placeholder="https://" value={f.official_url} onChange={(e) => setF({ ...f, official_url: e.target.value })} />
          </FormField>
        </div>
        <div className="sm:col-span-2">
          <FormField label="Resumen (opcional)">
            <textarea className="input min-h-20" maxLength={2000} value={f.summary} onChange={(e) => setF({ ...f, summary: e.target.value })} />
          </FormField>
        </div>
      </div>
      {msg && <p className="mt-3 text-sm text-bad">{msg}</p>}
    </Modal>
  );
}

// ─────────────── Catálogo (SCR-MRL-01) ───────────────

export function MrlCatalog({ readOnly, onAdded }: { readOnly: boolean; onAdded?: () => void }) {
  const [f, setF] = useState({ q: "", type: "", sector: "", topic: "", origin: "" });
  const params = useMemo(() => qs(f), [f]);
  const { norms, meta, error, load } = useCatalog(params);
  const [wizard, setWizard] = useState(false);
  const [form, setForm] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const filtered = Object.values(f).some(Boolean);

  return (
    <Card
      title="Catálogo de normas"
      action={
        !readOnly && meta ? (
          <span className="flex flex-wrap gap-2">
            {meta.can_create_norm && <Button size="sm" icon="plus" onClick={() => setForm(true)}>Norma propia</Button>}
            {meta.can_add && <Button size="sm" variant="primary" icon="scale" onClick={() => setWizard(true)}>Agregar desde el catálogo</Button>}
          </span>
        ) : undefined
      }
    >
      <div className="space-y-3">
        <FilterBar count={norms ? `${norms.length} norma(s)` : undefined} onClear={filtered ? () => setF({ q: "", type: "", sector: "", topic: "", origin: "" }) : undefined}>
          <SearchInput label="Buscar norma" placeholder="Número o título" value={f.q} onChange={(q) => setF({ ...f, q })} />
          <FilterSelect label="Tipo" value={f.type} onChange={(type) => setF({ ...f, type })} options={[["", "Todos"], ...Object.entries(NORM_TYPES)]} />
          <FilterSelect label="Sector" value={f.sector} onChange={(sector) => setF({ ...f, sector })} options={[["", "Todos"], ...(meta?.sectors ?? []).map((s) => [s, label(SECTORS, s)] as [string, string])]} />
          <FilterSelect label="Tema" value={f.topic} onChange={(topic) => setF({ ...f, topic })} options={[["", "Todos"], ...(meta?.topics ?? []).map((t) => [t, label(TOPICS, t)] as [string, string])]} />
          <FilterSelect label="Origen" value={f.origin} onChange={(origin) => setF({ ...f, origin })} options={[["", "Todas"], ["global", "Catálogo EscudoSST"], ["company", "Propias de la empresa"]]} />
        </FilterBar>
        {error ? (
          <p className="text-sm text-muted">{error}</p>
        ) : !norms ? (
          <div className="h-40 animate-pulse rounded-lg bg-bg" />
        ) : norms.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted">No hay normas con esos filtros.</p>
        ) : (
          <ul className="divide-y divide-line">
            {norms.map((n) => {
              const [st, tone] = STATUS[n.status] ?? [n.status, "na" as Tone];
              const expanded = open === n.id;
              return (
                <li key={n.id} className="py-2.5">
                  <button type="button" className="flex w-full flex-wrap items-start gap-2 text-left" aria-expanded={expanded} onClick={() => setOpen(expanded ? null : n.id)}>
                    <span className="min-w-0 flex-1">
                      <b className="block text-sm">
                        {normName(n)} · {n.title}
                        {n.recently_changed && <span className="ml-2 rounded bg-brand-soft px-1.5 py-0.5 text-[11px] font-semibold text-brand-strong">Nueva o modificada</span>}
                      </b>
                      <span className="block text-xs text-muted">
                        {[n.issuing_entity, n.sectors.map((s) => label(SECTORS, s)).join(", "), n.topics.map((t) => label(TOPICS, t)).join(", ")].filter(Boolean).join(" · ")}
                      </span>
                    </span>
                    <span className="flex flex-wrap items-center gap-1.5">
                      {n.is_company && <Chip tone="na">Propia</Chip>}
                      <Chip tone={tone}>{st}</Chip>
                      {(n.review_count ?? 0) > 0 && <Chip tone="warn">{n.review_count} requiere(n) revisión</Chip>}
                      <span className="text-xs text-muted">{n.added_count} en la matriz</span>
                    </span>
                  </button>
                  {expanded && (
                    <div className="mt-2 space-y-1 rounded-lg bg-bg px-3 py-2 text-sm">
                      {n.summary && <p>{n.summary}</p>}
                      <p className="text-xs text-muted">
                        {n.requirements_count} requisito(s) en el catálogo{n.published_on ? ` · publicada el ${n.published_on.slice(0, 10).split("-").reverse().join("/")}` : ""}
                      </p>
                      <span className="flex flex-wrap gap-3">
                        {n.official_url && <a href={n.official_url} target="_blank" rel="noreferrer" className="text-xs font-semibold text-brand hover:underline">Ver texto oficial ↗</a>}
                        {n.added_count > 0 && <a href={recordsHref("MRL", "legal_requirements", { filters: { legal_norm_id: n.id } })} className="text-xs font-semibold text-brand hover:underline">Ver sus requisitos en la matriz</a>}
                      </span>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
        <p className="text-xs text-muted">El texto de los requisitos del catálogo es referencial y debe validarlo un especialista SST/legal.</p>
      </div>
      {wizard && meta && <MrlAddWizard meta={meta} onClose={() => setWizard(false)} onDone={() => { load(); onAdded?.(); }} />}
      {form && <NormForm onClose={() => setForm(false)} onSaved={load} />}
    </Card>
  );
}

/** Pantalla del módulo: catálogo de normas y la matriz (motor común), que se recarga al agregar requisitos. */
export function MrlModule({ readOnly }: { readOnly: boolean }) {
  const [version, setVersion] = useState(0);
  return (
    <div className="space-y-6">
      <MrlNews readOnly={readOnly} version={version} onChanged={() => setVersion((v) => v + 1)} />
      <MrlCatalog readOnly={readOnly} onAdded={() => setVersion((v) => v + 1)} />
      <ModuleRecords key={version} code="MRL" readOnly={readOnly} />
    </div>
  );
}
