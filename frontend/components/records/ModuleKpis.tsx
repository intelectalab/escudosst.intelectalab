"use client";

// Fila de 4 tarjetas de indicadores arriba del módulo (prototipo). Cuenta con la API de la lista y, al tocar una
// tarjeta, abre la lista ya filtrada. Si una tabla no es visible para el rol, la tarjeta no se muestra.

import Link from "next/link";
import { useEffect, useState } from "react";
import { MODULE_KPIS, resolveDate, type Kpi } from "@/lib/moduleKpis";
import { records } from "@/lib/records";

const COLOR: Record<Kpi["color"], string> = { brand: "text-brand", ok: "text-ok", amber: "text-amber-ink", bad: "text-bad", orange: "text-[#F08A24]", teal: "text-[#1FB5A3]" };
const resolve = (f: Record<string, string> = {}) => Object.fromEntries(Object.entries(f).map(([k, v]) => [k, resolveDate(v)]));

export function ModuleKpis({ code }: { code: string }) {
  const kpis = MODULE_KPIS[code.toUpperCase()];
  const [values, setValues] = useState<(number | null)[] | null>(null);

  useEffect(() => {
    if (!kpis) return;
    setValues(null);
    const count = (table: string, filters?: Record<string, string>) =>
      records.list(code, table, { filters: resolve(filters) }).then((r) => r.meta.total).catch(() => null);
    Promise.all(
      kpis.map(async (k) => {
        const n = await count(k.table, k.filters);
        if (n === null || !k.of) return n;
        const d = await count(k.of.table, k.of.filters);
        return d ? Math.round((n / d) * 100) : null;
      }),
    ).then(setValues);
  }, [code, kpis]);

  if (!kpis) return null;
  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Indicadores del módulo">
      {kpis.map((k, i) => {
        const v = values?.[i];
        if (values && v === null) return null;
        const qs = new URLSearchParams({ t: k.table });
        Object.entries(resolve(k.filters)).forEach(([f, x]) => qs.set(`f[${f}]`, x));
        return (
          <Link key={k.label} href={`/m/${code.toLowerCase()}?${qs}`} className="rounded-xl border border-line bg-surface p-4 transition hover:border-brand/40" title="Ver los registros">
            <p className="text-sm text-muted">{k.label}</p>
            {values === null ? (
              <div className="mt-1 h-8 w-16 animate-pulse rounded bg-line/50" />
            ) : (
              <p className={`font-display text-3xl font-bold ${COLOR[k.color]}`}>{v}{k.of ? "%" : ""}</p>
            )}
            {k.note && <p className="text-xs text-muted">{k.note}</p>}
          </Link>
        );
      })}
    </div>
  );
}
