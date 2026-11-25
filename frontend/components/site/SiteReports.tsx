"use client";

// Reportes del sitio que pide M-WEB §11 y RN-WEB-10, todos agregados (sin identificar personas):
//  - consentimientos de cookies por categoría (última elección de cada visitante en el periodo);
//  - Core Web Vitals medidos en el navegador de quienes aceptaron cookies analíticas, frente a los umbrales parametrizados;
//  - redirecciones con más uso y enlaces rotos (404 que registra el sitio);
//  - biblioteca de medios: archivos sin uso, imágenes sin texto alternativo y tamaño total.

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, Kpi } from "@/components/ui/Display";
import { api, ApiError } from "@/lib/api";

function Box({ title, hint, aside, children }: { title: string; hint?: string; aside?: React.ReactNode; children: React.ReactNode }) {
  return (
    <section className="min-w-0 rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 className="font-display text-lg font-semibold">{title}</h2>
          {hint && <p className="text-sm text-muted">{hint}</p>}
        </div>
        {aside}
      </div>
      {children}
    </section>
  );
}

const pct = (n: number, total: number) => (total ? `${Math.round((n * 100) / total)} %` : "0 %");
const fmtDate = (s: string | null) => (s ? new Date(s).toLocaleDateString("es-PE", { day: "numeric", month: "short", year: "numeric" }) : "—");
const fmtSize = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

// ---------------------------------------------------------------- Cookies

type Consents = {
  days: number;
  choices: number;
  totals: { visitors: number; accept_all: number; reject_all: number; custom: number; withdraw: number; preferences: number; analytics: number; marketing: number };
  versions: { version: string; n: number }[];
};

export function CookieConsentReport({ days }: { days: number }) {
  const [d, setD] = useState<Consents | null>(null);
  useEffect(() => {
    api<{ data: Consents }>(`/internal/site/reports/cookie-consents?days=${days}`).then((r) => setD(r.data)).catch(() => setD(null));
  }, [days]);
  if (!d) return null;
  const t = d.totals;
  return (
    <Box title="Consentimientos de cookies" hint="Última elección de cada visitante en el periodo. Solo conteos: no se identifica a nadie.">
      <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Kpi label="Elecciones" value={t.visitors} />
        <Kpi label="Aceptaron todo" value={`${t.accept_all} · ${pct(t.accept_all, t.visitors)}`} />
        <Kpi label="Rechazaron todo" value={`${t.reject_all} · ${pct(t.reject_all, t.visitors)}`} />
        <Kpi label="Eligieron categorías" value={t.custom} />
      </dl>
      <ul className="mt-4 divide-y divide-line text-sm">
        {(
          [
            ["Necesarias", t.visitors, "Siempre activas"],
            ["Preferencias", t.preferences, null],
            ["Analíticas (métricas propias y Google Analytics)", t.analytics, null],
            ["Marketing", t.marketing, null],
          ] as [string, number, string | null][]
        ).map(([l, n, note]) => (
          <li key={l} className="flex items-center gap-2 py-2">
            <span className="min-w-0 flex-1">{l}</span>
            {note ? <span className="text-xs text-muted">{note}</span> : <b className="tabular-nums">{n}</b>}
            {!note && <span className="w-12 text-right text-xs text-muted">{pct(n, t.visitors)}</span>}
          </li>
        ))}
      </ul>
      <p className="mt-3 text-xs text-muted">
        {d.choices} cambios de preferencia registrados · {t.withdraw} retiraron su consentimiento
        {d.versions.length > 0 && ` · por versión de la política: ${d.versions.map((v) => `${v.version} (${v.n})`).join(", ")}`}
      </p>
    </Box>
  );
}

// ---------------------------------------------------------------- Core Web Vitals

type Vitals = {
  days: number;
  device: string;
  targets: Record<"LCP" | "INP" | "CLS", number>;
  failing: number;
  pages: { path: string; passes: boolean; metrics: Partial<Record<"LCP" | "INP" | "CLS", { p75: number; samples: number; passes: boolean }>> }[];
};

const METRIC_INFO: Record<"LCP" | "INP" | "CLS", [string, (v: number) => string]> = {
  LCP: ["Carga del contenido principal", (v) => `${(v / 1000).toFixed(1)} s`],
  INP: ["Respuesta al tocar o hacer clic", (v) => `${Math.round(v)} ms`],
  CLS: ["Estabilidad (saltos de la página)", (v) => v.toFixed(2)],
};

export function WebVitalsReport() {
  const [device, setDevice] = useState("mobile");
  const [days, setDays] = useState(30);
  const [d, setD] = useState<Vitals | null>(null);
  useEffect(() => {
    api<{ data: Vitals }>(`/internal/site/reports/web-vitals?days=${days}&device=${device}`).then((r) => setD(r.data)).catch(() => setD(null));
  }, [days, device]);
  return (
    <Box
      title="Rendimiento (Core Web Vitals)"
      hint="Medido en el navegador de visitantes reales que aceptaron cookies analíticas. Se compara el percentil 75 con los umbrales de los parámetros (a confirmar)."
      aside={
        <div className="flex gap-2">
          <select aria-label="Dispositivo" value={device} onChange={(e) => setDevice(e.target.value)} className="rounded-lg border border-line bg-surface px-2 py-1 text-sm">
            <option value="mobile">Celular</option>
            <option value="desktop">Computadora</option>
            <option value="tablet">Tablet</option>
          </select>
          <select aria-label="Periodo" value={days} onChange={(e) => setDays(Number(e.target.value))} className="rounded-lg border border-line bg-surface px-2 py-1 text-sm">
            <option value={7}>7 días</option>
            <option value={30}>30 días</option>
            <option value={90}>90 días</option>
          </select>
        </div>
      }
    >
      {!d ? null : d.pages.length === 0 ? (
        <p className="text-sm text-muted">Todavía no hay mediciones en este periodo y dispositivo.</p>
      ) : (
        <>
          <p className="mb-3 text-sm">
            Umbrales: {(["LCP", "INP", "CLS"] as const).map((m) => `${METRIC_INFO[m][0].toLowerCase()} ≤ ${METRIC_INFO[m][1](d.targets[m])}`).join(" · ")}.{" "}
            {d.failing > 0 ? <b className="text-bad">{d.failing} página(s) no cumplen.</b> : <b className="text-ok">Todas las páginas medidas cumplen.</b>}
          </p>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[34rem] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-xs text-muted">
                  <th className="py-2 pr-2 font-semibold">Página</th>
                  {(["LCP", "INP", "CLS"] as const).map((m) => (
                    <th key={m} className="py-2 pr-2 font-semibold" title={METRIC_INFO[m][0]}>
                      {METRIC_INFO[m][0]}
                    </th>
                  ))}
                  <th className="py-2 font-semibold">Resultado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {d.pages.map((p) => (
                  <tr key={p.path}>
                    <td className="max-w-[16rem] truncate py-2 pr-2 font-medium">{p.path}</td>
                    {(["LCP", "INP", "CLS"] as const).map((m) => {
                      const v = p.metrics[m];
                      return (
                        <td key={m} className={`py-2 pr-2 tabular-nums ${v && !v.passes ? "font-bold text-bad" : ""}`}>
                          {v ? `${METRIC_INFO[m][1](v.p75)} ` : "—"}
                          {v && <span className="text-xs font-normal text-muted">({v.samples})</span>}
                        </td>
                      );
                    })}
                    <td className="py-2">{p.passes ? <Chip tone="ok">Cumple</Chip> : <Chip tone="bad">No cumple</Chip>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Box>
  );
}

// ---------------------------------------------------------------- Enlaces rotos y redirecciones

type Links = {
  redirects: { id: string; source_path: string; target_url: string; status_code: number; is_active: boolean; hit_count: number; last_hit_at: string | null }[];
  broken: { id: string; path: string; hit_count: number; first_seen_at: string; last_seen_at: string; last_referrer_host: string | null; internal_referrer: boolean; has_redirect: boolean }[];
  totals: { open: number; hits: number; internal: number };
};

export function BrokenLinksReport({ owner, onRedirect, version }: { owner: boolean; onRedirect?: (path: string) => void; version?: number }) {
  const [d, setD] = useState<Links | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Links }>("/internal/site/reports/links").then((r) => setD(r.data)).catch(() => setD(null));
  }, []);
  useEffect(load, [load, version]);

  async function resolve(id: string) {
    setErr(null);
    try {
      await api(`/internal/site/reports/links/${id}/resolve`, { method: "POST" });
      load();
    } catch (e) {
      setErr(e instanceof ApiError ? (e.detail ?? e.message) : "No se pudo marcar.");
    }
  }

  if (!d) return null;
  return (
    <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
      <Box
        title="Enlaces rotos"
        hint="Direcciones del sitio que alguien abrió y no existen (404). Solo se guarda la ruta y el sitio que enlazó, nunca datos de la persona."
      >
        <dl className="mb-3 grid grid-cols-3 gap-2">
          <Kpi label="Rutas rotas" value={d.totals.open} />
          <Kpi label="Visitas a 404" value={d.totals.hits} />
          <Kpi label="Desde el propio sitio" value={d.totals.internal} />
        </dl>
        {err && <p className="mb-2 text-sm text-bad">{err}</p>}
        {d.broken.length === 0 ? (
          <p className="text-sm text-muted">No hay enlaces rotos pendientes.</p>
        ) : (
          <ul className="divide-y divide-line text-sm">
            {d.broken.map((b) => (
              <li key={b.id} className="flex flex-wrap items-center gap-2 py-2">
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{b.path}</span>
                  <span className="block text-xs text-muted">
                    {b.hit_count} visita(s) · última {fmtDate(b.last_seen_at)}
                    {b.last_referrer_host && ` · enlazada desde ${b.last_referrer_host}`}
                  </span>
                </span>
                {b.internal_referrer && <Chip tone="bad">Enlace interno</Chip>}
                {owner && !b.has_redirect && onRedirect && (
                  <Button variant="secondary" size="sm" onClick={() => onRedirect(b.path)}>
                    Redirigir
                  </Button>
                )}
                {owner && (
                  <Button variant="ghost" size="sm" onClick={() => resolve(b.id)}>
                    Ya está resuelto
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Box>
      <Box title="Redirecciones con más uso" hint="Cuántas veces llevó cada redirección a su destino.">
        {d.redirects.length === 0 ? (
          <p className="text-sm text-muted">Ninguna redirección se ha usado todavía.</p>
        ) : (
          <ul className="divide-y divide-line text-sm">
            {d.redirects.map((r) => (
              <li key={r.id} className="flex items-center gap-2 py-2">
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{r.source_path}</span>
                  <span className="block truncate text-xs text-muted">→ {r.target_url}</span>
                </span>
                {!r.is_active && <Chip tone="na">Inactiva</Chip>}
                <b className="tabular-nums">{r.hit_count}</b>
              </li>
            ))}
          </ul>
        )}
      </Box>
    </div>
  );
}

// ---------------------------------------------------------------- Biblioteca de medios

type Media = {
  files: number;
  size_bytes: number;
  by_kind: { kind: string; files: number; size_bytes: number }[];
  unused: { id: string; kind: string; title: string; folder: string | null; size_bytes: number; created_at: string }[];
  unused_size_bytes: number;
  missing_alt: { id: string; kind: string; title: string }[];
};

const KINDS: Record<string, string> = { image: "Imágenes", video: "Videos", document: "Documentos", icon: "Íconos" };

export function MediaReport({ version }: { version?: number }) {
  const [d, setD] = useState<Media | null>(null);
  const [open, setOpen] = useState(false);
  useEffect(() => {
    api<{ data: Media }>("/internal/site/reports/media").then((r) => setD(r.data)).catch(() => setD(null));
  }, [version]);
  if (!d) return null;
  return (
    <Box
      title="Reporte de la biblioteca"
      hint="Tamaño total, archivos que no se usan en ninguna parte del sitio e imágenes sin texto alternativo (debe ser cero)."
      aside={
        d.unused.length > 0 && (
          <Button variant="ghost" size="sm" onClick={() => setOpen((o) => !o)}>
            {open ? "Ocultar archivos sin uso" : "Ver archivos sin uso"}
          </Button>
        )
      }
    >
      <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Kpi label="Archivos" value={d.files} />
        <Kpi label="Tamaño total" value={fmtSize(d.size_bytes)} />
        <Kpi label="Sin uso" value={`${d.unused.length} · ${fmtSize(d.unused_size_bytes)}`} />
        <Kpi label="Sin texto alternativo" value={d.missing_alt.length} />
      </dl>
      {d.by_kind.length > 0 && (
        <p className="mt-3 text-xs text-muted">{d.by_kind.map((k) => `${KINDS[k.kind] ?? k.kind}: ${k.files} (${fmtSize(k.size_bytes)})`).join(" · ")}</p>
      )}
      {d.missing_alt.length > 0 && (
        <p className="mt-3 text-sm text-bad">
          Sin texto alternativo: {d.missing_alt.map((m) => m.title).join(", ")}. Agrégalo: lo leen los lectores de pantalla y Google.
        </p>
      )}
      {open && (
        <ul className="mt-3 divide-y divide-line text-sm">
          {d.unused.map((u) => (
            <li key={u.id} className="flex items-center gap-2 py-2">
              <span className="min-w-0 flex-1 truncate">{u.title}</span>
              <span className="text-xs text-muted">{KINDS[u.kind] ?? u.kind}{u.folder ? ` · ${u.folder}` : ""}</span>
              <span className="w-16 text-right text-xs tabular-nums text-muted">{fmtSize(u.size_bytes)}</span>
            </li>
          ))}
        </ul>
      )}
    </Box>
  );
}
