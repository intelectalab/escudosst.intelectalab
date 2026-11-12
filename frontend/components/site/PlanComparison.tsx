"use client";

// Comparativa módulo × plan (SCR-WEB-06). En escritorio, tabla; en el celular, tarjetas por plan con
// acordeón por grupo de módulos (docs/07 §1.4). Los datos salen de la configuración de planes.

import { useState } from "react";
import { Icon } from "@/components/ui/Icon";
import type { Pricing } from "@/lib/site";

const HIDE = ["TEN", "USR", "AUD", "SUS", "WEB"];

export function PlanComparison({ pricing, groups }: { pricing: Pricing; groups: Record<number, string> }) {
  const plans = pricing.plans.filter((p) => ["E", "P", "C"].includes(p.code));
  const rows = pricing.comparison.filter((m) => !m.is_addon && !HIDE.includes(m.module_code.trim()));
  const groupNums = [...new Set(rows.map((r) => r.group_number))];
  const [openGroup, setOpenGroup] = useState<number | null>(groupNums[0] ?? null);
  const [mobilePlan, setMobilePlan] = useState(plans[1]?.code ?? plans[0]?.code);

  const Mark = ({ yes }: { yes: boolean }) =>
    yes ? (
      <span className="mx-auto grid h-6 w-6 place-items-center rounded-full bg-[#22A35A]/15 text-[#187A42]" role="img" aria-label="Incluido">
        <Icon name="check" size={14} />
      </span>
    ) : (
      <span className="mx-auto block h-0.5 w-3 rounded bg-[#D5DCE5]" role="img" aria-label="No incluido" />
    );

  return (
    <>
      {/* Escritorio */}
      <div className="site-card hidden overflow-hidden md:block">
        <table className="w-full text-sm">
          <thead className="sticky top-[72px] z-10 bg-[var(--site-navy)] text-white">
            <tr>
              <th className="px-5 py-4 text-left font-display text-base">Módulo</th>
              {plans.map((p) => (
                <th key={p.code} className="w-36 px-3 py-4 text-center font-display text-base">
                  {p.name}
                  {p.code === "P" && <span className="mt-1 block text-[10px] font-bold uppercase tracking-wider text-[var(--site-amber)]">Recomendado</span>}
                </th>
              ))}
            </tr>
          </thead>
          {groupNums.map((g) => (
            <tbody key={g} className="divide-y divide-[var(--site-border)]">
              <tr className="bg-[var(--site-surface)]">
                <th colSpan={plans.length + 1} className="px-5 py-2.5 text-left text-xs font-bold uppercase tracking-wider text-[var(--site-navy)]">
                  {groups[g]}
                </th>
              </tr>
              {rows
                .filter((r) => r.group_number === g)
                .map((r) => (
                  <tr key={r.module_code} className="hover:bg-[var(--site-surface)]/60">
                    <td className="px-5 py-2.5">{r.name}</td>
                    {plans.map((p) => (
                      <td key={p.code} className="px-3 py-2.5 text-center">
                        <Mark yes={!!r.plans[p.code]} />
                      </td>
                    ))}
                  </tr>
                ))}
            </tbody>
          ))}
        </table>
      </div>

      {/* Celular: un plan a la vez, grupos en acordeón */}
      <div className="space-y-3 md:hidden">
        <div className="flex rounded-[10px] bg-[var(--site-surface)] p-1" role="tablist" aria-label="Plan">
          {plans.map((p) => (
            <button
              key={p.code}
              role="tab"
              aria-selected={mobilePlan === p.code}
              onClick={() => setMobilePlan(p.code)}
              className={`flex-1 rounded-lg px-2 py-2.5 text-sm font-bold ${mobilePlan === p.code ? "bg-white text-[var(--site-action)] shadow-sm" : "text-[var(--site-text-2)]"}`}
            >
              {p.name}
            </button>
          ))}
        </div>
        {groupNums.map((g) => {
          const items = rows.filter((r) => r.group_number === g);
          const included = items.filter((r) => r.plans[mobilePlan ?? ""]).length;
          const open = openGroup === g;
          return (
            <div key={g} className="site-card overflow-hidden">
              <button type="button" aria-expanded={open} onClick={() => setOpenGroup(open ? null : g)} className="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left">
                <span className="font-semibold text-[var(--site-navy)]">{groups[g]}</span>
                <span className="flex items-center gap-2 text-xs text-[var(--site-text-2)]">
                  {included}/{items.length}
                  <Icon name="chevron" size={16} className={`transition-transform ${open ? "-rotate-90" : "rotate-90"}`} />
                </span>
              </button>
              {open && (
                <ul className="divide-y divide-[var(--site-border)] border-t border-[var(--site-border)]">
                  {items.map((r) => (
                    <li key={r.module_code} className={`flex items-center justify-between gap-3 px-4 py-2.5 text-sm ${r.plans[mobilePlan ?? ""] ? "" : "text-[var(--site-text-2)]"}`}>
                      {r.name}
                      <span className="shrink-0">
                        <Mark yes={!!r.plans[mobilePlan ?? ""]} />
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          );
        })}
      </div>
    </>
  );
}
