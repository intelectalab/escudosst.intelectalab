"use client";

// SCR-SUS-01 · Planes y módulos (docs/modulos/M-SUS.md §6). Todo está conectado a la API:
// - Tarjetas de plan → «Editar plan» (datos, límites, precio y módulos).
// - «Módulos por plan»: matriz con filtros; cada interruptor actualiza plan_modules y recalcula las empresas.
// - «Complementos»: lista con filtros y «Editar».

import { useCallback, useEffect, useMemo, useState } from "react";
import { EditAddonModal } from "@/components/sus/EditAddonModal";
import { EditPlanModal } from "@/components/sus/EditPlanModal";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState, StatCard } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Switch } from "@/components/ui/Switch";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { CATALOG_GROUPS, formatLimit, formatPrice, MATRIX_PLANS, MODULE_KIND, periodsText, PLAN_ACCENT } from "@/lib/plans";
import type { Addon, PlanMatrix, PlanMatrixModule, PlanSummary } from "@/lib/types";

type Pending = { module: PlanMatrixModule; plan: PlanSummary; included: boolean };
type ToastState = { message: string; tone: "ok" | "bad" } | null;

const EMPTY_FILTERS = { q: "", group: "", kind: "plan", plan: "", inclusion: "" };

export default function PlanesPage() {
  const [matrix, setMatrix] = useState<PlanMatrix | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [toast, setToast] = useState<ToastState>(null);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [pending, setPending] = useState<Pending | null>(null);
  const [editPlan, setEditPlan] = useState<PlanSummary | null>(null);
  const [editAddon, setEditAddon] = useState<Addon | null>(null);
  const [tab, setTab] = useState<"modulos" | "complementos">("modulos");
  const [f, setF] = useState(EMPTY_FILTERS);
  const [af, setAf] = useState({ q: "", status: "", v1: "" });

  const load = useCallback(
    () =>
      api<PlanMatrix>("/internal/plans")
        .then((m) => {
          setMatrix(m);
          setLoadError(null);
        })
        .catch((e) => setLoadError(e instanceof Error ? e.message : "No se pudo cargar.")),
    [],
  );

  useEffect(() => {
    load();
  }, [load]);

  const closeToast = useCallback(() => setToast(null), []);

  const matrixPlans = useMemo(() => (matrix?.plans ?? []).filter((p) => p.code !== "K"), [matrix]);
  const consultora = useMemo(() => (matrix?.plans ?? []).find((p) => p.code === "K") ?? null, [matrix]);
  const planByCode = useMemo(() => new Map((matrix?.plans ?? []).map((p) => [p.code, p])), [matrix]);

  // ---- Filtros de la matriz ----
  const filtered = useMemo(() => {
    if (!matrix) return [];
    const text = f.q.trim().toLowerCase();
    return matrix.modules.filter((m) => {
      if (text && !`${m.code} ${m.name}`.toLowerCase().includes(text)) return false;
      if (f.group && m.group_number !== Number(f.group)) return false;
      if (f.kind === "plan" && !m.assignable) return false;
      if (f.kind === "+" && m.plan_code !== "+") return false;
      if (f.kind === "other" && (m.assignable || m.plan_code === "+")) return false;
      if (f.plan && f.inclusion) {
        const has = m.assignable && m.plans[f.plan as "E" | "P" | "C"] !== null;
        if (f.inclusion === "in" && !has) return false;
        if (f.inclusion === "out" && (has || !m.assignable)) return false;
      }
      return true;
    });
  }, [matrix, f]);

  const groups = useMemo(
    () =>
      Object.entries(CATALOG_GROUPS)
        .map(([n, title]) => ({ n, title, rows: filtered.filter((m) => m.group_number === Number(n)) }))
        .filter((g) => g.rows.length > 0),
    [filtered],
  );

  const filtersActive = JSON.stringify(f) !== JSON.stringify(EMPTY_FILTERS);

  // ---- Filtros de complementos ----
  const addons = useMemo(() => {
    if (!matrix) return [];
    const text = af.q.trim().toLowerCase();
    return matrix.addons.filter(
      (a) =>
        (!text || `${a.code} ${a.name} ${a.description ?? ""}`.toLowerCase().includes(text)) &&
        (!af.status || (af.status === "active") === a.is_active) &&
        (!af.v1 || (af.v1 === "yes") === a.available_in_v1),
    );
  }, [matrix, af]);

  // ---- Cambio de módulo en un plan ----
  function cascade(p: Pending): PlanSummary[] {
    const idx = MATRIX_PLANS.indexOf(p.plan.code as "E" | "P" | "C");
    const codes = p.included ? MATRIX_PLANS.slice(idx) : MATRIX_PLANS.slice(0, idx + 1);
    return codes.filter((c) => (p.module.plans[c] !== null) !== p.included).map((c) => planByCode.get(c)!).filter(Boolean);
  }

  async function applyToggle(p: Pending) {
    setPending(null);
    setBusyKey(`${p.module.code}-${p.plan.code}`);
    try {
      const res = await api<{ changed: { plan: string; included: boolean }[]; companies_refreshed: number; matrix: PlanMatrix }>(
        `/internal/plans/${p.plan.code}/modules/${p.module.code}`,
        { method: "PUT", body: { included: p.included } },
      );
      setMatrix(res.matrix);
      const names = res.changed.map((c) => planByCode.get(c.plan as PlanSummary["code"])?.name ?? c.plan).join(" y ");
      setToast({
        tone: "ok",
        message:
          `${p.module.name}: ${p.included ? "incluido en" : "quitado de"} ${names}. ` +
          (res.companies_refreshed === 1 ? "1 empresa actualizada." : `${res.companies_refreshed} empresas actualizadas.`),
      });
    } catch (e) {
      setToast({ tone: "bad", message: e instanceof ApiError ? e.message : "No se pudo guardar el cambio." });
    } finally {
      setBusyKey(null);
    }
  }

  async function saved(message: string) {
    setEditPlan(null);
    setEditAddon(null);
    await load();
    setToast({ tone: "ok", message });
  }

  if (loadError && !matrix)
    return (
      <div className="mx-auto max-w-md rounded-2xl border border-line bg-surface p-10 text-center shadow-sm">
        <p className="font-semibold text-bad">{loadError}</p>
        <Button variant="secondary" className="mt-4" onClick={load}>
          Reintentar
        </Button>
      </div>
    );
  if (!matrix) return <PageSkeleton />;

  const totalAssignable = matrix.modules.filter((m) => m.assignable).length;
  const totalCompanies = matrixPlans.reduce((n, p) => n + p.companies_count, 0);

  return (
    <div className="mx-auto w-full max-w-[1600px] space-y-8">
      {/* Encabezado */}
      <header className="space-y-4">
        <nav className="flex items-center gap-1.5 text-sm text-muted" aria-label="Ruta">
          <span>Panel de la plataforma</span>
          <span aria-hidden="true">›</span>
          <span className="font-semibold text-fg">Planes y módulos</span>
        </nav>
        <div className="flex flex-wrap items-start justify-between gap-6">
          <div className="flex gap-4">
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand text-white shadow-md shadow-brand/30">
              <Icon name="tag" size={24} />
            </span>
            <div>
              <h1 className="text-2xl font-bold tracking-tight">Planes y módulos</h1>
              <p className="mt-1 max-w-2xl text-muted">
                Qué incluye cada plan. Los cambios se aplican al momento.
              </p>
            </div>
          </div>
        </div>
      </header>

      {/* Resumen: cada tarjeta lleva a lo que cuenta */}
      <section aria-label="Resumen" className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard
          label="Módulos en planes"
          value={totalAssignable}
          hint="Ver la matriz por plan"
          icon="grid"
          active={tab === "modulos"}
          onClick={() => {
            setTab("modulos");
            document.getElementById("contenido")?.scrollIntoView({ behavior: "smooth" });
          }}
        />
        <StatCard
          label="Complementos"
          value={matrix.addons.length}
          hint="Se contratan aparte"
          icon="box"
          tone="amber"
          active={tab === "complementos"}
          onClick={() => {
            setTab("complementos");
            document.getElementById("contenido")?.scrollIntoView({ behavior: "smooth" });
          }}
        />
        <StatCard
          label="Empresas suscritas"
          value={totalCompanies}
          hint="Ver en Cuentas"
          icon="building"
          tone="ok"
          onClick={() => (window.location.href = "/plataforma/cuentas")}
        />
      </section>

      {/* Planes para empresas */}
      <section aria-labelledby="planes-empresa" className="space-y-4">
        <SectionTitle id="planes-empresa" title="Planes para empresas" hint="Acumulativos: Corporativo incluye todo Profesional, y Profesional incluye todo Esencial." />
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {matrixPlans.map((p, i) => (
            <PlanCard key={p.code} plan={p} level={i + 1} onEdit={() => setEditPlan(p)} />
          ))}
        </div>
      </section>

      {/* Plan Consultora */}
      {consultora && (
        <section aria-labelledby="plan-consultora" className="relative overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          <div className="absolute inset-y-0 left-0 w-1.5" style={{ background: PLAN_ACCENT.K.bar }} />
          <div className="flex flex-col gap-4 p-5 pl-7 sm:p-6 sm:pl-8 lg:flex-row lg:items-center">
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl" style={{ background: PLAN_ACCENT.K.soft, color: PLAN_ACCENT.K.text }}>
              <Icon name="building" size={24} />
            </span>
            <div className="min-w-0 flex-1 space-y-1.5">
              <div className="flex flex-wrap items-center gap-2">
                <h2 id="plan-consultora" className="font-display text-lg font-semibold">
                  Plan {consultora.name}
                </h2>
                <Chip tone={consultora.is_active ? "ok" : "na"} dot>
                  {consultora.is_active ? "Activo" : "Inactivo"}
                </Chip>
                <Chip tone="amber">Plan especial</Chip>
              </div>
              <p className="max-w-2xl text-sm text-muted">
                Para consultoras SST que gestionan varias empresas: panel multi-cliente y marca blanca. Cada empresa cliente conserva su propio plan.
              </p>
            </div>
            <dl className="grid grid-cols-3 divide-x divide-line rounded-xl border border-line text-sm lg:w-[27rem]">
              <MiniStat label="Precio" value={formatPrice(consultora.price_reference)} sub={consultora.price_reference !== null ? "por empresa" : undefined} />
              <MiniStat label="Consultoras" value={String(consultora.companies_count)} />
              <MiniStat label="Máx. empresas" value={formatLimit(consultora.limits.max_companies)} />
            </dl>
            <Button variant="soft" icon="pen" onClick={() => setEditPlan(consultora)}>
              Editar plan
            </Button>
          </div>
        </section>
      )}

      {/* Pestañas */}
      <section id="contenido" className="scroll-mt-24 space-y-4">
        <div role="tablist" aria-label="Contenido de los planes" className="flex w-full overflow-x-auto rounded-xl border border-line bg-surface p-1 shadow-sm sm:inline-flex sm:w-auto">
          {(
            [
              ["modulos", "Módulos", totalAssignable, "grid"],
              ["complementos", "Complementos", matrix.addons.length, "box"],
            ] as const
          ).map(([k, label, n, icon]) => (
            <button
              key={k}
              role="tab"
              aria-selected={tab === k}
              onClick={() => setTab(k)}
              className={`inline-flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition-colors sm:flex-none ${tab === k ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"}`}
            >
              <Icon name={icon} size={16} />
              {label}
              <span className={`rounded-full px-2 py-0.5 text-xs ${tab === k ? "bg-white/20" : "bg-bg"}`}>{n}</span>
            </button>
          ))}
        </div>

        {tab === "modulos" && (
          <div className="space-y-4">
            <FilterBar count={`${filtered.length} de ${matrix.modules.length} módulos`} onClear={filtersActive ? () => setF(EMPTY_FILTERS) : undefined}>
              <SearchInput label="Buscar módulo" placeholder="Nombre o código" value={f.q} onChange={(v) => setF({ ...f, q: v })} />
              <FilterSelect label="Grupo" value={f.group} onChange={(v) => setF({ ...f, group: v })} options={[["", "Todos los grupos"], ...Object.entries(CATALOG_GROUPS)]} />
              <FilterSelect
                label="Tipo"
                neutral="plan"
                value={f.kind}
                onChange={(v) => setF({ ...f, kind: v })}
                options={[
                  ["plan", "Módulos de planes"],
                  ["+", "Complementos"],
                  ["other", "Internos y Consultora"],
                  ["", "Todos los tipos"],
                ]}
              />
              <FilterSelect label="Plan" value={f.plan} onChange={(v) => setF({ ...f, plan: v, inclusion: v ? f.inclusion || "in" : "" })} options={[["", "Cualquier plan"], ...matrixPlans.map((p) => [p.code, p.name] as [string, string])]} />
              {f.plan && (
                <FilterSelect
                  label="Inclusión"
                  value={f.inclusion}
                  onChange={(v) => setF({ ...f, inclusion: v })}
                  options={[
                    ["in", "Incluidos"],
                    ["out", "No incluidos"],
                  ]}
                />
              )}
            </FilterBar>

            {/* Celular: cada módulo con sus 3 interruptores */}
            <div className="space-y-4 md:hidden">
              {groups.map((g) => (
                <div key={g.n} className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
                  <p className="border-b border-line bg-bg/60 px-4 py-2 text-xs font-bold uppercase tracking-wider text-muted">
                    {g.title} · {g.rows.length}
                  </p>
                  <ul className="divide-y divide-line">
                    {g.rows.map((m) => (
                      <li key={m.code} className="space-y-3 px-4 py-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Code>{m.code}</Code>
                          <span className="font-medium">{m.name}</span>
                          {m.core && <Chip tone="brand">Núcleo</Chip>}
                        </div>
                        {m.assignable ? (
                          <div className="grid grid-cols-3 gap-2">
                            {matrixPlans.map((p) => {
                              const state = m.plans[p.code as "E" | "P" | "C"];
                              const key = `${m.code}-${p.code}`;
                              return (
                                <div key={p.code} className="flex flex-col items-center gap-1 rounded-lg bg-bg px-2 py-2">
                                  <span className="inline-flex items-center gap-1 text-xs font-semibold">
                                    <span className="h-2 w-2 rounded-full" style={{ background: PLAN_ACCENT[p.code].bar }} />
                                    {p.name}
                                  </span>
                                  <Switch
                                    checked={state !== null}
                                    disabled={(m.core && state !== null) || (busyKey !== null && busyKey !== key)}
                                    busy={busyKey === key}
                                    label={`${m.name} en ${p.name}`}
                                    onChange={(v) => setPending({ module: m, plan: p, included: v })}
                                  />
                                </div>
                              );
                            })}
                          </div>
                        ) : (
                          <Chip tone={m.plan_code === "+" ? "amber" : "na"}>{MODULE_KIND[m.plan_code] ?? m.plan_code}</Chip>
                        )}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
              {filtered.length === 0 && (
                <div className="rounded-2xl border border-line bg-surface">
                  <EmptyState text="Ningún módulo coincide con los filtros." onClear={() => setF(EMPTY_FILTERS)} />
                </div>
              )}
            </div>

            {/* Tablet y escritorio: tabla comparativa */}
            <div className="hidden overflow-hidden rounded-2xl border border-line bg-surface shadow-sm md:block">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[700px] text-sm">
                  <thead>
                    <tr className="border-b border-line bg-bg/70 text-left">
                      <th className="px-5 py-3.5 text-xs font-bold uppercase tracking-wide text-muted">Módulo</th>
                      {matrixPlans.map((p) => (
                        <th key={p.code} className="w-40 px-2 py-3 text-center">
                          <span className="inline-flex items-center gap-1.5 font-semibold">
                            <span className="h-2.5 w-2.5 rounded-full" style={{ background: PLAN_ACCENT[p.code].bar }} />
                            {p.name}
                          </span>
                          <span className="block text-xs font-normal text-muted">{p.modules_count} módulos</span>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {groups.map((g) => (
                      <GroupRows key={g.n} title={g.title} count={g.rows.length} cols={matrixPlans.length + 1}>
                        {g.rows.map((m) => (
                          <tr key={m.code} className="border-b border-line transition-colors last:border-0 hover:bg-brand-soft/40">
                            <td className="px-5 py-3">
                              <div className="flex flex-wrap items-center gap-2">
                                <Code>{m.code}</Code>
                                <span className="font-medium">{m.name}</span>
                                {m.core && (
                                  <Chip tone="brand">
                                    <Icon name="lock" size={11} /> Núcleo
                                  </Chip>
                                )}
                              </div>
                            </td>
                            {m.assignable ? (
                              matrixPlans.map((p) => {
                                const state = m.plans[p.code as "E" | "P" | "C"];
                                const key = `${m.code}-${p.code}`;
                                return (
                                  <td key={p.code} className="px-2 py-3 text-center">
                                    <div className="flex flex-col items-center gap-1">
                                      <Switch
                                        checked={state !== null}
                                        disabled={(m.core && state !== null) || (busyKey !== null && busyKey !== key)}
                                        busy={busyKey === key}
                                        label={`${m.name} en ${p.name}`}
                                        onChange={(v) => setPending({ module: m, plan: p, included: v })}
                                      />
                                      {state === "inherited" && <span className="text-[10px] font-medium text-muted">heredado</span>}
                                    </div>
                                  </td>
                                );
                              })
                            ) : (
                              <td colSpan={matrixPlans.length} className="px-2 py-3 text-center">
                                <Chip tone={m.plan_code === "+" ? "amber" : "na"}>{MODULE_KIND[m.plan_code] ?? m.plan_code}</Chip>
                              </td>
                            )}
                          </tr>
                        ))}
                      </GroupRows>
                    ))}
                  </tbody>
                </table>
              </div>
              {filtered.length === 0 && <EmptyState text="Ningún módulo coincide con los filtros." onClear={() => setF(EMPTY_FILTERS)} />}
            </div>
          </div>
        )}

        {tab === "complementos" && (
          <div className="space-y-4">
            <p className="text-sm text-muted">Se suman a cualquier plan y se contratan en la suscripción de cada empresa (requieren un plan base activo, RN-SUS-05).</p>
            <FilterBar count={`${addons.length} de ${matrix.addons.length} complementos`} onClear={af.q || af.status || af.v1 ? () => setAf({ q: "", status: "", v1: "" }) : undefined}>
              <SearchInput label="Buscar complemento" placeholder="Nombre o código" value={af.q} onChange={(v) => setAf({ ...af, q: v })} />
              <FilterSelect
                label="Estado"
                value={af.status}
                onChange={(v) => setAf({ ...af, status: v })}
                options={[
                  ["", "Todos los estados"],
                  ["active", "Activos"],
                  ["inactive", "Inactivos"],
                ]}
              />
              <FilterSelect
                label="Disponibilidad"
                value={af.v1}
                onChange={(v) => setAf({ ...af, v1: v })}
                options={[
                  ["", "Cualquier versión"],
                  ["yes", "Versión 1"],
                  ["no", "Más adelante"],
                ]}
              />
            </FilterBar>

            <div className="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
              {addons.map((a) => (
                <article key={a.code} className="flex flex-col gap-4 rounded-2xl border border-line bg-surface p-5 shadow-sm transition-shadow hover:shadow-md">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-soft text-amber-ink">
                        <Icon name="box" size={20} />
                      </span>
                      <div className="min-w-0">
                        <h3 className="font-semibold leading-tight">{a.name}</h3>
                        <p className="text-xs text-muted">Complemento {a.code}</p>
                      </div>
                    </div>
                    <Chip tone={a.is_active ? "ok" : "na"} dot>
                      {a.is_active ? "Activo" : "Inactivo"}
                    </Chip>
                  </div>
                  {a.description && <p className="text-sm text-muted">{a.description}</p>}
                  <dl className="mt-auto grid grid-cols-3 divide-x divide-line rounded-xl border border-line text-sm">
                    <MiniStat label="Precio /mes" value={formatPrice(a.price_reference)} />
                    <MiniStat label="Versión" value={a.available_in_v1 ? "1" : "Después"} />
                    <MiniStat label="Suscripciones" value={String(a.subscriptions_count)} />
                  </dl>
                  <Button variant="soft" icon="pen" full onClick={() => setEditAddon(a)}>
                    Editar complemento
                  </Button>
                </article>
              ))}
            </div>
            {addons.length === 0 && (
              <div className="rounded-2xl border border-line bg-surface">
                <EmptyState text="Ningún complemento coincide con los filtros." onClear={() => setAf({ q: "", status: "", v1: "" })} />
              </div>
            )}
          </div>
        )}
      </section>

      {editPlan && (
        <EditPlanModal
          plan={editPlan}
          matrix={matrix}
          busyKey={busyKey}
          onClose={() => setEditPlan(null)}
          onSaved={saved}
          onToggleModule={(module, plan, included) => setPending({ module, plan, included })}
        />
      )}
      {editAddon && <EditAddonModal addon={editAddon} onClose={() => setEditAddon(null)} onSaved={saved} />}
      {pending && <ConfirmToggle pending={pending} affected={cascade(pending)} onCancel={() => setPending(null)} onConfirm={() => applyToggle(pending)} />}
      {toast && <Toast message={toast.message} tone={toast.tone} onClose={closeToast} />}
    </div>
  );
}

// ---------------------------------------------------------------- Componentes de la página

function PlanCard({ plan: p, level, onEdit }: { plan: PlanSummary; level: number; onEdit: () => void }) {
  const accent = PLAN_ACCENT[p.code];
  return (
    <article className="group flex flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-lg">
      <div className="relative px-6 pb-5 pt-6" style={{ background: `linear-gradient(180deg, ${accent.soft}, transparent)` }}>
        <div className="absolute inset-x-0 top-0 h-1.5" style={{ background: accent.bar }} />
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl font-display text-lg font-bold text-white shadow-sm" style={{ background: accent.bar }}>
              {p.code}
            </span>
            <div className="min-w-0">
              <h3 className="truncate font-display text-lg font-semibold leading-tight">{p.name}</h3>
              <p className="text-xs text-muted">Nivel {level} de 3</p>
            </div>
          </div>
          <div className="flex shrink-0 flex-col items-end gap-1">
            <Chip tone={p.is_active ? "ok" : "na"} dot>
              {p.is_active ? "Activo" : "Inactivo"}
            </Chip>
            {!p.is_public && <Chip tone="na">Oculto en la web</Chip>}
          </div>
        </div>
        <p className="mt-5 flex items-baseline gap-1">
          <span className="font-display text-3xl font-bold tracking-tight" style={{ color: accent.text }}>
            {formatPrice(p.price_reference)}
          </span>
          {p.price_reference !== null && <span className="text-sm text-muted">/mes</span>}
        </p>
        {p.description && <p className="mt-2 line-clamp-2 min-h-[2.5rem] text-sm text-muted">{p.description}</p>}
      </div>
      <dl className="mx-6 grid grid-cols-2 divide-x divide-y divide-line overflow-hidden rounded-xl border border-line text-sm">
        <MiniStat label="Módulos" value={String(p.modules_count)} icon="grid" />
        <MiniStat label="Empresas" value={String(p.companies_count)} icon="building" />
        <MiniStat label="Trabajadores" value={formatLimit(p.limits.max_workers)} icon="people" />
        <MiniStat label="Cobro" value={periodsText(p)} icon="clock" />
      </dl>
      <div className="mt-auto p-6 pt-5">
        <Button variant="soft" icon="pen" full onClick={onEdit}>
          Editar plan
        </Button>
      </div>
    </article>
  );
}

function ConfirmToggle({ pending, affected, onCancel, onConfirm }: { pending: Pending; affected: PlanSummary[]; onCancel: () => void; onConfirm: () => void }) {
  const companies = affected.reduce((n, p) => n + p.companies_count, 0);
  const names = affected.map((p) => p.name).join(", ");
  return (
    <Modal
      title={`${pending.included ? "Incluir" : "Quitar"} ${pending.module.name}`}
      onClose={onCancel}
      size="sm"
      z={60}
      footer={
        <>
          <Button variant="secondary" onClick={onCancel}>
            Cancelar
          </Button>
          <Button variant={pending.included ? "primary" : "danger"} icon={pending.included ? "check" : undefined} onClick={onConfirm}>
            {pending.included ? "Incluir" : "Quitar del plan"}
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-sm">
        <p>
          {pending.included ? "Se incluirá en: " : "Se quitará de: "}
          <b>{names}</b>
          {affected.length > 1 && <span className="text-muted"> (los planes son acumulativos)</span>}.
        </p>
        <p className={`rounded-xl px-4 py-3 ${pending.included ? "bg-brand-soft text-brand-strong" : "bg-amber-soft text-amber-ink"}`}>
          {companies === 0
            ? "Ninguna empresa tiene hoy estos planes."
            : pending.included
              ? `${companies === 1 ? "1 empresa lo verá" : `${companies} empresas lo verán`} activo al momento.`
              : `${companies === 1 ? "1 empresa lo verá" : `${companies} empresas lo verán`} en solo lectura si ya lo usaban. Sus datos no se borran.`}
        </p>
      </div>
    </Modal>
  );
}

function SectionTitle({ id, title, hint }: { id: string; title: string; hint: string }) {
  return (
    <div>
      <h2 id={id} className="text-lg font-semibold">
        {title}
      </h2>
      <p className="text-sm text-muted">{hint}</p>
    </div>
  );
}

function GroupRows({ title, count, cols, children }: { title: string; count: number; cols: number; children: React.ReactNode }) {
  return (
    <>
      <tr className="border-b border-line bg-bg/50">
        <td colSpan={cols} className="px-5 py-2 text-xs font-bold uppercase tracking-wider text-muted">
          {title} <span className="ml-1 rounded-full bg-surface px-2 py-0.5 font-semibold normal-case">{count}</span>
        </td>
      </tr>
      {children}
    </>
  );
}

function MiniStat({ label, value, sub, icon }: { label: string; value: string; sub?: string; icon?: string }) {
  return (
    <div className="min-w-0 px-3 py-2.5">
      <dt className="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
        {icon && <Icon name={icon} size={12} />}
        {label}
      </dt>
      <dd className="truncate font-semibold">
        {value}
        {sub && <span className="ml-1 text-xs font-normal text-muted">{sub}</span>}
      </dd>
    </div>
  );
}

function PageSkeleton() {
  return (
    <div className="mx-auto w-full max-w-[1600px] animate-pulse space-y-6" aria-label="Cargando planes">
      <div className="h-12 w-72 rounded-xl bg-line/60" />
      <div className="grid gap-5 md:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-80 rounded-2xl bg-line/50" />
        ))}
      </div>
      <div className="h-64 rounded-2xl bg-line/40" />
    </div>
  );
}
