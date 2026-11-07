"use client";

// Explorador de módulos del sitio: filtra por grupo (chips que se presionan) y por plan, y busca por nombre.

import { useMemo, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import type { PublicModule } from "@/lib/site";

const GROUP_ICON: Record<number, string> = { 1: "grid", 2: "tag", 3: "shield", 4: "eye", 5: "heart", 6: "history", 7: "building", 8: "star" };
const PLAN_CHIP: Record<string, string> = {
  E: "bg-[#22A35A]/12 text-[#187A42]",
  P: "bg-[#1F7FD1]/12 text-[#176BB3]",
  C: "bg-[#0D3B6E]/10 text-[#0D3B6E]",
  "+": "bg-[#F4B90F]/20 text-[#8A6704]",
  K: "bg-[#F4B90F]/20 text-[#8A6704]",
};

export function ModuleExplorer({ modules, groups, planNames }: { modules: PublicModule[]; groups: Record<number, string>; planNames: Record<string, string> }) {
  const [group, setGroup] = useState<number | null>(null);
  const [plan, setPlan] = useState<string>("");
  const [q, setQ] = useState("");

  const label = (m: PublicModule) => (m.is_addon ? "Complemento" : m.plan_code === "K" ? "Plan Consultora" : m.from_plan ? `Desde ${planNames[m.from_plan] ?? m.from_plan}` : "Incluido");
  const chip = (m: PublicModule) => PLAN_CHIP[m.is_addon ? "+" : m.plan_code === "K" ? "K" : (m.from_plan ?? "E")];
  const rank: Record<string, number> = { E: 1, P: 2, C: 3 };

  const filtered = useMemo(
    () =>
      modules.filter(
        (m) =>
          (group === null || m.group_number === group) &&
          (!plan || (plan === "+" ? m.is_addon : !m.is_addon && m.from_plan && rank[m.from_plan] <= rank[plan])) &&
          (!q || `${m.name} ${m.solves ?? ""}`.toLowerCase().includes(q.toLowerCase())),
      ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [modules, group, plan, q],
  );

  const shownGroups = Object.keys(groups)
    .map(Number)
    .filter((g) => filtered.some((m) => m.group_number === g));

  return (
    <div className="space-y-8">
      <div className="site-card space-y-4 p-4 sm:p-5">
        <div className="flex flex-col gap-3 md:flex-row md:items-center">
          <label className="relative flex-1">
            <span className="sr-only">Buscar módulo</span>
            <Icon name="search" size={18} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--site-text-2)]" />
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Busca: IPERC, EPP, capacitaciones, accidentes…"
              className="h-12 w-full rounded-[10px] border border-[#D5DCE5] bg-white pl-11 pr-4 text-[15px] outline-none focus:border-[var(--site-action)] focus:shadow-[0_0_0_3px_rgba(31,127,209,.15)]"
            />
          </label>
          <div className="grid grid-cols-3 gap-1 rounded-[10px] bg-[var(--site-surface)] p-1 sm:flex" role="group" aria-label="Filtrar por plan">
            {[
              ["", "Todos"],
              ["E", planNames.E ?? "Esencial"],
              ["P", planNames.P ?? "Profesional"],
              ["C", planNames.C ?? "Corporativo"],
              ["+", "Complementos"],
            ].map(([v, l]) => (
              <button
                key={v}
                type="button"
                aria-pressed={plan === v}
                onClick={() => setPlan(v)}
                className={`flex-1 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${plan === v ? "bg-white text-[var(--site-action)] shadow-sm" : "text-[var(--site-text-2)] hover:text-[var(--site-navy)]"}`}
              >
                {l}
              </button>
            ))}
          </div>
        </div>
        <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrar por área">
          {Object.entries(groups).map(([n, name]) => {
            const on = group === Number(n);
            return (
              <button
                key={n}
                type="button"
                aria-pressed={on}
                onClick={() => setGroup(on ? null : Number(n))}
                className={`inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-semibold transition-all ${on ? "border-transparent bg-[var(--site-navy)] text-white shadow-md" : "border-[var(--site-border)] bg-white text-[var(--site-navy)] hover:-translate-y-0.5 hover:shadow-sm"}`}
              >
                <Icon name={GROUP_ICON[Number(n)] ?? "grid"} size={15} />
                {name}
              </button>
            );
          })}
        </div>
        <p className="text-sm text-[var(--site-text-2)]" aria-live="polite">
          {filtered.length} {filtered.length === 1 ? "módulo" : "módulos"}
          {plan && plan !== "+" ? ` incluidos en el plan ${planNames[plan]}` : ""}
        </p>
      </div>

      {shownGroups.length === 0 && <p className="py-10 text-center text-[var(--site-text-2)]">No encontramos módulos con esa búsqueda.</p>}

      {shownGroups.map((g) => (
        <section key={g} className="space-y-4">
          <h2 className="flex items-center gap-3 font-display text-xl font-bold text-[var(--site-navy-title)]">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-[#1F7FD1] to-[#0D3B6E] text-white">
              <Icon name={GROUP_ICON[g] ?? "grid"} size={18} />
            </span>
            {groups[g]}
          </h2>
          <ul className="grid-cols-1 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {filtered
              .filter((m) => m.group_number === g)
              .map((m) => (
                <li key={m.code} className="site-card site-card-hover flex flex-col p-5">
                  <div className="flex items-start justify-between gap-3">
                    <h3 className="font-display font-bold text-[var(--site-navy-title)]">{m.name}</h3>
                    <span className={`shrink-0 rounded-md px-2 py-0.5 text-[11px] font-bold ${chip(m)}`}>{label(m)}</span>
                  </div>
                  {m.solves && <p className="mt-2 text-sm leading-relaxed text-[var(--site-text-2)]">{m.solves}</p>}
                </li>
              ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
