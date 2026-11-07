"use client";

// Tarjetas de planes con el conmutador Mensual / Anual (SCR-WEB-06). El precio configurado es mensual;
// el anual se calcula con el descuento anual del panel (si es 0, solo se muestra el total del año, sin «descuento»).

import { useState } from "react";
import { PlanCards } from "@/components/site/Sections";
import type { BillingPeriod, PublicPlan, SiteSettings } from "@/lib/site";

export function PlanPricing({ plans, s, discount = 0, compact = false }: { plans: PublicPlan[]; s: SiteSettings; discount?: number; compact?: boolean }) {
  const [period, setPeriod] = useState<BillingPeriod>("monthly");
  // El conmutador solo tiene sentido si algún plan tiene precio y se vende también al año.
  const canToggle = plans.some((p) => p.price && p.billing_periods.includes("annual"));
  const options: [BillingPeriod, string][] = [
    ["monthly", "Mensual"],
    ["annual", "Anual"],
  ];

  return (
    <div className="space-y-6">
      {canToggle && (
        <div className="flex flex-col items-center gap-2">
          <div role="radiogroup" aria-label="Periodo de pago" className="inline-flex rounded-full border border-[var(--site-border)] bg-white p-1 shadow-sm">
            {options.map(([v, label]) => (
              <button
                key={v}
                type="button"
                role="radio"
                aria-checked={period === v}
                onClick={() => setPeriod(v)}
                className={`rounded-full px-5 py-2 text-sm font-bold transition ${
                  period === v ? "bg-[var(--site-navy)] text-white shadow" : "text-[var(--site-text-2)] hover:text-[var(--site-navy)]"
                }`}
              >
                {label}
                {v === "annual" && discount > 0 && <span className={`ml-1.5 text-xs ${period === v ? "text-[var(--site-amber)]" : "text-[#167A42]"}`}>−{discount}%</span>}
              </button>
            ))}
          </div>
          <p className="text-sm text-[var(--site-text-2)]">{period === "annual" ? "Pagas el año completo en un solo pago." : "Pagas mes a mes."} Precios en soles.</p>
        </div>
      )}
      <PlanCards
        plans={plans.map((p) => (p.billing_periods.includes(period) ? p : { ...p, price: null }))}
        s={s}
        compact={compact}
        period={period}
        discount={discount}
      />
    </div>
  );
}
