"use client";

// Tablero consolidado del grupo (SCR-DSH-04): una fila por empresa del usuario y una columna por indicador,
// con semáforo. Todo se toca: la empresa abre su inicio, cada celda abre la lista filtrada de esa empresa
// (cambia de empresa activa antes de ir) y los resúmenes de arriba filtran la tabla.

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import { recordsHref } from "@/lib/records";
import { useApp } from "@/components/AppContext";

type Link_ = { module: string | null; table: string | null; filters: Record<string, string> };
type Kpi = { code: string; name: string; module: string; locked: boolean; value?: number | null; label?: string; basis?: string; color?: "green" | "amber" | "red" | null; link: Link_ };
type Row = {
  company_id: string; name: string; ruc: string; role: string; is_active: boolean; workers: number; red: number; amber: number; alerts: number;
  alert_items: { module: string; tone: string; count: number; text: string; link: Link_ }[]; kpis: Kpi[];
};
type Group = { year: number; group: { name: string | null; type: string | null }; columns: { code: string; name: string }[]; companies: Row[] };

const hrefOf = (l: Link_) => (l.module && l.table ? recordsHref(l.module, l.table, { filters: l.filters }) : l.module ? `/m/${l.module.toLowerCase()}` : "/inicio");
const TONE = { green: "bg-ok-soft text-ok", amber: "bg-amber-soft text-amber-ink", red: "bg-bad-soft text-bad" } as const;
type Filter = "all" | "red" | "alerts" | "ok";

export function GroupDashboard() {
  const router = useRouter();
  const { company } = useApp();
  const now = new Date().getFullYear();
  const [year, setYear] = useState(now);
  const [data, setData] = useState<Group | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<Filter>("all");
  const [q, setQ] = useState("");
  const [going, setGoing] = useState<string | null>(null);

  useEffect(() => {
    setError(null);
    api<{ data: Group }>(`/dsh/group?year=${year}`, { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [year]);

  // Ir a algo de otra empresa: primero se cambia la empresa activa (queda registrado) y luego se abre la lista.
  async function go(row: Row, href: string) {
    if (row.is_active) return router.push(href);
    setGoing(row.company_id);
    try {
      await api("/me/switch-company", { method: "POST", body: { company_id: row.company_id } });
      session.setCompanyId(row.company_id);
      window.location.assign(href);
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.title) : "No pudimos cambiar de empresa.");
      setGoing(null);
    }
  }

  const rows = useMemo(() => {
    const all = data?.companies ?? [];
    return all.filter((r) => {
      if (q && !`${r.name} ${r.ruc}`.toLowerCase().includes(q.toLowerCase())) return false;
      if (filter === "red") return r.red > 0;
      if (filter === "alerts") return r.alerts > 0;
      if (filter === "ok") return r.red === 0 && r.alerts === 0;
      return true;
    });
  }, [data, filter, q]);

  const all = data?.companies ?? [];
  const tiles: { key: Filter; label: string; n: number; tone: string }[] = [
    { key: "all", label: "Empresas", n: all.length, tone: "text-fg" },
    { key: "red", label: "Con indicadores en rojo", n: all.filter((r) => r.red > 0).length, tone: "text-bad" },
    { key: "alerts", label: "Con pendientes para hoy", n: all.filter((r) => r.alerts > 0).length, tone: "text-amber-ink" },
    { key: "ok", label: "Sin rojos ni pendientes", n: all.filter((r) => r.red === 0 && r.alerts === 0).length, tone: "text-ok" },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm text-muted">
            <Link href="/inicio" className="text-brand hover:underline">Inicio de {company.name}</Link> › Grupo
          </p>
          <h1 className="text-2xl font-semibold">Tablero del grupo{data?.group.name ? ` · ${data.group.name}` : ""}</h1>
          <p className="text-muted">Tus empresas, ordenadas de mayor a menor riesgo. Toca una celda para ver sus registros.</p>
        </div>
        <div className="flex flex-wrap gap-3">
          <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
            Buscar empresa
            <input className="filter-control" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nombre o RUC" />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
            Año
            <select className="filter-control" value={year} onChange={(e) => setYear(Number(e.target.value))}>
              {[now, now - 1, now - 2].map((y) => (
                <option key={y} value={y}>{y}</option>
              ))}
            </select>
          </label>
        </div>
      </div>

      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}
      {!data && !error && <p className="text-muted">Calculando los indicadores de cada empresa…</p>}

      {data && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {tiles.map((t) => (
              <button
                key={t.key}
                onClick={() => setFilter(filter === t.key && t.key !== "all" ? "all" : t.key)}
                aria-pressed={filter === t.key}
                className={`rounded-2xl border bg-surface p-4 text-left shadow-sm transition hover:shadow-md ${filter === t.key ? "border-brand ring-2 ring-brand/30" : "border-line"}`}
              >
                <p className={`font-display text-3xl font-semibold ${t.tone}`}>{t.n}</p>
                <p className="text-sm text-muted">{t.label}</p>
              </button>
            ))}
          </div>

          {all.length <= 1 && (
            <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">
              Solo tienes acceso a una empresa. Cuando te inviten a otras empresas del grupo, aparecerán aquí lado a lado.
            </p>
          )}

          {rows.length === 0 ? (
            <p className="rounded-xl border border-line bg-surface p-6 text-muted">Ninguna empresa cumple este filtro.</p>
          ) : (
            <div className="overflow-x-auto rounded-2xl border border-line bg-surface shadow-sm">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-xs text-muted">
                    <th className="sticky left-0 z-10 bg-surface px-4 py-3">Empresa</th>
                    <th className="px-3 py-3">Pendientes hoy</th>
                    {data.columns.map((c) => (
                      <th key={c.code} className="min-w-[7.5rem] px-3 py-3 font-semibold">{c.name}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.company_id} className={`border-b border-line last:border-0 ${going === r.company_id ? "opacity-50" : ""}`}>
                      <th className="sticky left-0 z-10 bg-surface px-4 py-3 text-left font-normal">
                        <button onClick={() => go(r, "/inicio")} className="text-left font-semibold text-brand hover:underline">{r.name}</button>
                        <p className="text-xs text-muted">
                          RUC {r.ruc} · {r.workers} trabajadores{r.is_active ? " · empresa activa" : ""}
                        </p>
                      </th>
                      <td className="px-3 py-3">
                        {r.alerts === 0 ? (
                          <span className="text-xs text-muted">Nada urgente</span>
                        ) : (
                          <div className="flex flex-col gap-1">
                            {r.alert_items.map((a, i) => (
                              <button
                                key={i}
                                onClick={() => go(r, hrefOf(a.link))}
                                className={`rounded-md px-2 py-0.5 text-left text-xs font-medium hover:brightness-95 ${a.tone === "bad" ? "bg-bad-soft text-bad" : "bg-amber-soft text-amber-ink"}`}
                              >
                                <b>{a.count}</b> {a.text}
                              </button>
                            ))}
                          </div>
                        )}
                      </td>
                      {data.columns.map((c) => {
                        const k = r.kpis.find((x) => x.code === c.code);
                        if (!k || k.locked)
                          return (
                            <td key={c.code} className="px-3 py-3 text-xs text-muted" title="No está en el plan de esta empresa">
                              Fuera del plan
                            </td>
                          );
                        return (
                          <td key={c.code} className="px-3 py-3">
                            <button
                              onClick={() => go(r, hrefOf(k.link))}
                              title={`${k.basis ?? ""} Toca para ver los registros.`}
                              className={`rounded-lg px-2.5 py-1 font-semibold tabular-nums hover:brightness-95 ${k.color ? TONE[k.color] : "bg-na-soft text-muted"}`}
                            >
                              {k.label}
                            </button>
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
