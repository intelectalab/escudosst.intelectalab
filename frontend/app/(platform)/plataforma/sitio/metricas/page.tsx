"use client";

// Sitio web › Resumen y métricas (SCR-WEB-19 y 50): visitas, visitantes, conversión, fuentes, páginas, conversiones
// por botón o formulario, campañas y embudo. Métricas propias y agregadas: solo de quienes aceptaron cookies
// analíticas. Exportar (CSV para Excel) es solo del Dueño y queda en la bitácora.

import Link from "next/link";
import { useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { BarList, ColumnChart, SERIES_COLORS } from "@/components/ui/Charts";
import { PageHeader, StatCard } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { api, API_URL } from "@/lib/api";
import { session } from "@/lib/session";

type Data = {
  from: string;
  to: string;
  totals: { visits: number; visitors: number; bounces: number };
  days: { day: string; visits: number; visitors: number }[];
  sources: { source: string; visits: number }[];
  devices: { device: string; visits: number }[];
  pages: { path: string; visits: number; visitors: number; bounces: number }[];
  conversions: { event_type: string; element_key: string; n: number }[];
  campaigns: { campaign: string; source: string | null; visitors: number; conversions: number }[];
  funnel: { visit: number; cta: number; form: number; lead: number };
  leads_month: number;
  demos_pending: number;
  conversion_rate: number;
  ids: { ga4?: string | null; gtm?: string | null; meta_pixel?: string | null; search_console_verification?: string | null };
};

const SOURCES: Record<string, string> = { organic: "Google y buscadores", direct: "Directo", social: "Redes sociales", referral: "Otros sitios", campaign: "Campañas (UTM)", email: "Correo" };
const DEVICES: Record<string, string> = { desktop: "Computadora", mobile: "Celular", tablet: "Tablet" };
const EVENTS: Record<string, string> = {
  cta_click: "Clic en botón",
  form_view: "Vio un formulario",
  form_submit: "Envió un formulario",
  whatsapp_click: "Clic en WhatsApp",
  phone_click: "Clic en teléfono",
  demo_booked: "Agendó una demo",
  calculator_used: "Usó la calculadora",
  assessment_completed: "Hizo el autodiagnóstico",
  resource_download: "Descargó un recurso",
  chatbot_open: "Abrió el chatbot",
  chatbot_lead: "Dejó datos en el chatbot",
  campaign_view: "Vio una campaña",
};
const KEYS: Record<string, string> = { trial: "Prueba gratis", demo: "Demo", quote: "Cotización", contact: "Contacto", partner: "Partners", boletin: "Boletín", multas: "Calculadora de multas", autodiagnostico: "Autodiagnóstico", "calculadora-correo": "Resultado por correo" };

const fmtDay = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString("es-PE", { day: "numeric", month: "short" });

export default function MetricasSitioPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [days, setDays] = useState(30);
  const [device, setDevice] = useState("");
  const [d, setD] = useState<Data | null>(null);

  useEffect(() => {
    setD(null);
    api<{ data: Data }>(`/internal/site/analytics?days=${days}${device ? `&device=${device}` : ""}`).then((r) => setD(r.data));
  }, [days, device]);

  async function exportCsv() {
    const res = await fetch(`${API_URL}/api/v1/internal/site/analytics/export?days=${days}`, { headers: { Authorization: `Bearer ${session.getToken()}` } });
    if (!res.ok) return;
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement("a");
    a.href = url;
    a.download = `metricas-sitio-${days}-dias.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  // Más de 31 días: se agrupa por semana para que el gráfico se lea.
  const series = (() => {
    if (!d) return [];
    const map = new Map(d.days.map((x) => [x.day, x]));
    const all: { day: string; visits: number; visitors: number }[] = [];
    for (let t = new Date(`${d.from}T12:00:00`); t <= new Date(`${d.to}T12:00:00`); t.setDate(t.getDate() + 1)) {
      const k = t.toISOString().slice(0, 10);
      all.push(map.get(k) ?? { day: k, visits: 0, visitors: 0 });
    }
    if (all.length <= 31) return all.map((x) => ({ key: x.day, label: fmtDay(x.day), value: x.visits, display: String(x.visits), detail: `${fmtDay(x.day)} · ${x.visits} visitas · ${x.visitors} visitantes` }));
    const weeks: typeof all[] = [];
    all.forEach((x, i) => (i % 7 === 0 ? weeks.push([x]) : weeks[weeks.length - 1].push(x)));
    return weeks.map((w) => {
      const v = w.reduce((s, x) => s + x.visits, 0);
      return { key: w[0].day, label: fmtDay(w[0].day), value: v, display: String(v), detail: `Semana del ${fmtDay(w[0].day)} · ${v} visitas` };
    });
  })();

  const empty = d && d.totals.visits === 0 && d.conversions.length === 0;
  const pct = (a: number, b: number) => (b ? `${Math.round((a * 100) / b)}%` : "—");

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Resumen y métricas"]]}
        icon="grid"
        title="Resumen y métricas"
        description="Cuánta gente visita el sitio, de dónde llega y qué hace. Son métricas propias: no dependen de Google y solo cuentan a quienes aceptaron las cookies analíticas."
        aside={
          owner && (
            <button onClick={exportCsv} className="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-semibold hover:bg-bg">
              <Icon name="file" size={16} /> Exportar a Excel (CSV)
            </button>
          )
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="inline-flex rounded-xl border border-line bg-surface p-1 text-sm font-semibold">
          {[7, 30, 90, 365].map((n) => (
            <button key={n} onClick={() => setDays(n)} className={`rounded-lg px-3 py-1.5 ${days === n ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>
              {n === 365 ? "1 año" : `${n} días`}
            </button>
          ))}
        </div>
        <select className="input h-10 w-auto" value={device} onChange={(e) => setDevice(e.target.value)} aria-label="Dispositivo">
          <option value="">Todos los dispositivos</option>
          {Object.entries(DEVICES).map(([k, l]) => (
            <option key={k} value={k}>
              {l}
            </option>
          ))}
        </select>
      </div>

      <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Visitas" value={d ? d.totals.visits : "–"} icon="eye" tone="brand" hint={d ? `${d.totals.visitors} visitantes` : ""} />
        <StatCard label="Conversión" value={d ? `${d.conversion_rate}%` : "–"} icon="star" tone="ok" hint="Visitantes que dejaron datos" />
        <StatCard label="Interesados del mes" value={d ? d.leads_month : "–"} icon="people" tone="amber" hint="Todos los formularios" />
        <StatCard label="Demos por atender" value={d ? d.demos_pending : "–"} icon="clock" tone="bad" hint="Solicitudes nuevas" />
      </div>

      {empty && (
        <div className="rounded-2xl border border-brand/15 bg-gradient-to-r from-brand-soft/70 to-surface p-4 text-sm">
          <p className="font-semibold text-brand-strong">Aún no hay datos en este periodo</p>
          <p className="mt-1 text-muted">Las visitas empiezan a contarse cuando el sitio esté publicado y los visitantes acepten las cookies analíticas. Mientras tanto verás los números en cero.</p>
        </div>
      )}

      {!d ? (
        <div className="h-64 animate-pulse rounded-2xl bg-line/40" />
      ) : (
        <>
          <Panel title="Visitas" hint={days > 31 ? "Por semana" : "Por día"}>
            <div className="-mx-1 overflow-x-auto px-1 pb-1">
              <div style={{ minWidth: Math.max(280, series.length * 34) }}>
                <ColumnChart label="Visitas al sitio" data={series} />
              </div>
            </div>
            {series.length > 10 && <p className="mt-1 text-xs text-muted sm:hidden">Desliza el gráfico hacia los lados para ver todos los días.</p>}
          </Panel>

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <Panel title="¿De dónde llegan?" hint="Visitas por fuente">
              {d.sources.length ? (
                <BarList label="Visitas por fuente" data={d.sources.map((s, i) => ({ key: s.source, label: SOURCES[s.source] ?? s.source, value: s.visits, display: `${s.visits} · ${pct(s.visits, d.totals.visits)}`, color: SERIES_COLORS[i % SERIES_COLORS.length] }))} />
              ) : (
                <Empty />
              )}
            </Panel>
            <Panel title="Embudo" hint="Visitantes que avanzan de un paso al siguiente">
              <ol className="space-y-2">
                {(
                  [
                    ["Visitaron el sitio", d.funnel.visit],
                    ["Hicieron clic en un botón (demo, prueba, WhatsApp, chatbot)", d.funnel.cta],
                    ["Abrieron un formulario", d.funnel.form],
                    ["Dejaron sus datos", d.funnel.lead],
                  ] as [string, number][]
                ).map(([l, n], i) => (
                  <li key={l} className="rounded-xl bg-bg p-3">
                    <div className="flex items-center justify-between gap-2 text-sm">
                      <span>{l}</span>
                      <b>
                        {n} <span className="font-normal text-muted">{i > 0 ? `(${pct(n, d.funnel.visit)})` : ""}</span>
                      </b>
                    </div>
                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-line/60">
                      <div className="h-full rounded-full bg-brand" style={{ width: d.funnel.visit ? `${Math.max(2, (n * 100) / d.funnel.visit)}%` : "0%" }} />
                    </div>
                  </li>
                ))}
              </ol>
              <p className="mt-3 text-xs text-muted">Las oportunidades y ventas se miden en el CRM, no aquí.</p>
            </Panel>
          </div>

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <Panel title="Páginas más vistas">
              {d.pages.length ? (
                <div className="-mx-1 overflow-x-auto">
                  <table className="w-full min-w-[420px] text-sm">
                    <thead className="text-left text-xs text-muted">
                      <tr>
                        <th className="px-1 py-2 font-semibold">Página</th>
                        <th className="px-1 py-2 text-right font-semibold">Visitas</th>
                        <th className="px-1 py-2 text-right font-semibold">Visitantes</th>
                        <th className="px-1 py-2 text-right font-semibold">Rebote</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {d.pages.map((p) => (
                        <tr key={p.path}>
                          <td className="max-w-[220px] truncate px-1 py-2 font-mono text-xs">
                            <a href={p.path} target="_blank" rel="noopener" className="hover:text-brand hover:underline">
                              {p.path}
                            </a>
                          </td>
                          <td className="px-1 py-2 text-right tabular-nums">{p.visits}</td>
                          <td className="px-1 py-2 text-right tabular-nums">{p.visitors}</td>
                          <td className="px-1 py-2 text-right tabular-nums">{pct(p.bounces, p.visits)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  <p className="mt-2 px-1 text-xs text-muted">Rebote: visitantes que vieron solo esa página y se fueron.</p>
                </div>
              ) : (
                <Empty />
              )}
            </Panel>
            <Panel title="Qué hicieron" hint="Clics y formularios en el periodo">
              {d.conversions.length ? (
                <BarList
                  label="Acciones de los visitantes"
                  data={d.conversions.map((c) => ({
                    key: `${c.event_type}-${c.element_key}`,
                    label: `${EVENTS[c.event_type] ?? c.event_type}${c.element_key ? ` · ${KEYS[c.element_key] ?? c.element_key}` : ""}`,
                    value: c.n,
                    display: String(c.n),
                  }))}
                />
              ) : (
                <Empty />
              )}
            </Panel>
          </div>

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <Panel title="Campañas" hint="Enlaces con utm_campaign">
              {d.campaigns.length ? (
                <ul className="divide-y divide-line text-sm">
                  {d.campaigns.map((c) => (
                    <li key={c.campaign} className="flex items-center gap-2 py-2">
                      <span className="min-w-0 flex-1 truncate font-medium">{c.campaign}</span>
                      {c.source && <span className="text-xs text-muted">{c.source}</span>}
                      <b className="tabular-nums">{c.visitors}</b>
                      <span className="text-xs text-muted">visitantes · {c.conversions} conv.</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted">
                  Sin campañas en el periodo. Para medir una, agrega <code className="rounded bg-bg px-1">?utm_source=…&utm_campaign=…</code> al enlace que compartes.
                </p>
              )}
            </Panel>
            <Panel title="Dispositivos y herramientas externas">
              {d.devices.length > 0 && (
                <BarList label="Visitas por dispositivo" data={d.devices.map((x, i) => ({ key: x.device, label: DEVICES[x.device] ?? x.device, value: x.visits, display: pct(x.visits, d.totals.visits), color: SERIES_COLORS[(i + 3) % SERIES_COLORS.length] }))} />
              )}
              <ul className="mt-4 space-y-2 text-sm">
                {(
                  [
                    ["Google Analytics 4", d.ids.ga4],
                    ["Google Search Console", d.ids.search_console_verification],
                  ] as [string, string | null | undefined][]
                ).map(([l, v]) => (
                  <li key={l} className="flex items-center gap-2">
                    <span className={`h-2 w-2 rounded-full ${v ? "bg-ok" : "bg-line"}`} />
                    <span className="flex-1">{l}</span>
                    <span className="text-xs text-muted">{v ? "Configurado" : "Sin configurar"}</span>
                  </li>
                ))}
              </ul>
              <Link href="/plataforma/sitio/seo" className="mt-2 inline-block text-xs font-semibold text-brand hover:underline">
                Configurar en SEO y redirecciones →
              </Link>
            </Panel>
          </div>
        </>
      )}
    </div>
  );
}

function Panel({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
  return (
    <section className="min-w-0 rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
      <div className="mb-4">
        <h2 className="font-display text-lg font-semibold">{title}</h2>
        {hint && <p className="text-sm text-muted">{hint}</p>}
      </div>
      {children}
    </section>
  );
}

function Empty() {
  return <p className="text-sm text-muted">Sin datos en este periodo.</p>;
}
