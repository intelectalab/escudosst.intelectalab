"use client";

// Sitio web › SEO y redirecciones (SCR-WEB-27): redirecciones 301/302 con contador y sin bucles, códigos de Google
// (Search Console y Analytics) y vista del sitemap y del robots.txt que se generan solos.

import { FormEvent, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { usePlatformUser } from "@/components/PlatformContext";
import { LinkPicker, type LinkPage } from "@/components/site/LinkPicker";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Redirect = { id: string; source_path: string; target_url: string; status_code: 301 | 302; is_active: boolean; hit_count: number; last_hit_at: string | null; notes: string | null };
type Seo = { primary_domain: string | null; search_console_verification: string | null; ga4: string | null };
type SitePage = { path: string; title: string; group: string; in_google: boolean; reason: string | null; edit: string | null };


export default function SeoPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [rows, setRows] = useState<Redirect[] | null>(null);
  const [seo, setSeo] = useState<Seo | null>(null);
  const [pages, setPages] = useState<SitePage[] | null>(null);
  const [linkable, setLinkable] = useState<LinkPage[]>([]);
  const [pageFilter, setPageFilter] = useState<"all" | "in" | "out">("all");
  const [editing, setEditing] = useState<Redirect | "new" | null>(null);
  const [filter, setFilter] = useState<"all" | "active" | "off">("all");
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Redirect[] }>("/internal/site/redirects").then((r) => setRows(r.data));
    api<{ data: Seo }>("/internal/site/seo").then((r) => setSeo(r.data));
    api<{ data: SitePage[] }>("/internal/site/seo/pages").then((r) => setPages(r.data));
    api<{ pages: LinkPage[] }>("/internal/site/menu").then((r) => setLinkable(r.pages));
  }, []);
  useEffect(load, [load]);

  async function remove(r: Redirect) {
    if (!window.confirm(`¿Eliminar la redirección de ${r.source_path}? Esa ruta dejará de llevar a ${r.target_url}.`)) return;
    await api(`/internal/site/redirects/${r.id}`, { method: "DELETE" });
    setToast({ text: "Redirección eliminada.", tone: "ok" });
    load();
  }

  const list = (rows ?? []).filter((r) => (filter === "active" ? r.is_active : filter === "off" ? !r.is_active : true));
  const hits = (rows ?? []).reduce((a, r) => a + r.hit_count, 0);

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["SEO y redirecciones"]]}
        icon="search"
        title="SEO y redirecciones"
        description="Cómo encuentra Google el sitio: direcciones que cambiaron, el mapa del sitio y los códigos de Google."
        aside={
          owner && (
            <Button variant="primary" icon="plus" onClick={() => setEditing("new")}>
              Nueva redirección
            </Button>
          )
        }
      />

      <section className="grid grid-cols-1 gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm md:grid-cols-3">
        {(
          [
            ["check", "ok", "Lo automático", "El mapa del sitio se arma solo con cada página que publicas. Si cambias la dirección de una página publicada, la vieja lleva sola a la nueva. No tienes que hacer nada."],
            ["swap", "brand", "Lo que tú haces (pocas veces)", "Crear una redirección cuando quitas una página y quieres que quien llegue a la dirección vieja vaya a otra. Ej.: quitaste /sectores/retail → que lleve a /sectores."],
            ["search", "amber", "Una sola vez", "Pegar los códigos de Google (Search Console y Analytics) cuando el sitio esté publicado en su dominio. Te los da quien administre la cuenta de Google."],
          ] as const
        ).map(([icon, tone, t, d]) => (
          <div key={t} className="flex gap-3">
            <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl ${tone === "ok" ? "bg-ok-soft text-ok" : tone === "brand" ? "bg-brand-soft text-brand-strong" : "bg-amber-soft text-amber-ink"}`}>
              <Icon name={icon} size={17} />
            </span>
            <span>
              <b className="block text-sm">{t}</b>
              <span className="text-xs text-muted">{d}</span>
            </span>
          </div>
        ))}
      </section>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Redirecciones activas" value={rows ? rows.filter((r) => r.is_active).length : "–"} icon="swap" tone="brand" hint="Rutas que llevan a otra" active={filter === "active"} onClick={() => setFilter((f) => (f === "active" ? "all" : "active"))} />
        <StatCard label="Desactivadas" value={rows ? rows.filter((r) => !r.is_active).length : "–"} icon="pause" tone="na" hint="Guardadas, sin efecto" active={filter === "off"} onClick={() => setFilter((f) => (f === "off" ? "all" : "off"))} />
        <StatCard label="Páginas que salen en Google" value={pages ? `${pages.filter((p) => p.in_google).length} de ${pages.length}` : "–"} icon="globe" tone="ok" hint="Las demás aún no están publicadas" active={pageFilter === "in"} onClick={() => setPageFilter((f) => (f === "in" ? "all" : "in"))} />
      </div>

      <div className="grid grid-cols-1 items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_26rem]">
        <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
            <h2 className="flex items-center gap-2 font-display font-semibold">
              <Icon name="swap" size={17} className="text-brand" /> Redirecciones
            </h2>
            <span className="text-xs text-muted">{hits} visitas redirigidas en total</span>
          </div>
          <p className="border-b border-line bg-bg/60 px-5 py-2.5 text-xs text-muted">
            Cuando una página cambia de dirección, la anterior debe llevar a la nueva para no perder visitas ni posición en Google. Si cambias la dirección de una página publicada, la redirección se crea sola.
            <b className="text-fg"> 301</b> = cambio permanente (lo normal). <b className="text-fg">302</b> = temporal.
          </p>
          {rows === null ? (
            <p className="p-5 text-sm text-muted">Cargando…</p>
          ) : list.length === 0 ? (
            <p className="p-8 text-center text-sm text-muted">{rows.length ? "Ninguna en este filtro." : "Aún no hay redirecciones."}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase tracking-wide text-muted">
                  <tr className="border-b border-line">
                    <th className="px-5 py-2.5 font-semibold">Origen</th>
                    <th className="px-2 py-2.5" aria-label="lleva a" />
                    <th className="px-3 py-2.5 font-semibold">Destino</th>
                    <th className="px-3 py-2.5 font-semibold">Tipo</th>
                    <th className="px-3 py-2.5 text-right font-semibold">Visitas</th>
                    <th className="px-5 py-2.5" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {list.map((r) => (
                    <tr key={r.id} className={r.is_active ? "" : "opacity-55"}>
                      <td className="px-5 py-2.5">
                        <code className="rounded bg-bg px-1.5 py-0.5 font-mono text-[13px]">{r.source_path}</code>
                        {r.notes && <span className="mt-0.5 block text-xs text-muted">{r.notes}</span>}
                      </td>
                      <td className="px-2 text-muted">→</td>
                      <td className="max-w-[18rem] truncate px-3 py-2.5 font-mono text-[13px]" title={r.target_url}>
                        {r.target_url}
                      </td>
                      <td className="px-3 py-2.5">
                        {r.is_active ? <Chip tone={r.status_code === 301 ? "brand" : "amber"}>{r.status_code === 301 ? "301 permanente" : "302 temporal"}</Chip> : <Chip tone="na">Desactivada</Chip>}
                      </td>
                      <td className="px-3 py-2.5 text-right tabular-nums">
                        {r.hit_count}
                        {r.last_hit_at && <span className="block text-[11px] text-muted">{new Date(r.last_hit_at).toLocaleDateString("es-PE")}</span>}
                      </td>
                      <td className="whitespace-nowrap px-5 py-2.5 text-right">
                        {owner && (
                          <>
                            <button type="button" onClick={() => setEditing(r)} className="rounded-md px-2 py-1 text-xs font-semibold text-brand hover:bg-brand-soft">
                              Editar
                            </button>
                            <button type="button" onClick={() => remove(r)} className="rounded-md px-2 py-1 text-xs font-semibold text-bad hover:bg-bad-soft">
                              Eliminar
                            </button>
                          </>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <div className="space-y-5">
          {seo && <GoogleCard seo={seo} owner={owner} onSaved={(t) => (setToast({ text: t, tone: "ok" }), load())} />}

          <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
              <h2 className="flex items-center gap-2 font-display font-semibold">
                <Icon name="globe" size={17} className="text-ok" /> Páginas del sitio en Google
              </h2>
              <span className="flex gap-3 text-xs font-semibold">
                <a href="/sitemap.xml" target="_blank" rel="noopener" className="text-brand hover:underline" title="El archivo que lee Google">
                  sitemap.xml ↗
                </a>
                <a href="/robots.txt" target="_blank" rel="noopener" className="text-brand hover:underline" title="Lo que Google no debe revisar">
                  robots.txt ↗
                </a>
              </span>
            </div>
            <div className="flex gap-1 border-b border-line bg-bg/60 px-4 py-2">
              {(
                [
                  ["all", "Todas"],
                  ["in", "Salen en Google"],
                  ["out", "No salen"],
                ] as const
              ).map(([k, l]) => (
                <button key={k} type="button" onClick={() => setPageFilter(k)} className={`rounded-full px-3 py-1 text-xs font-semibold ${pageFilter === k ? "bg-brand text-white" : "text-muted hover:bg-surface"}`}>
                  {l}
                  {pages && ` (${k === "all" ? pages.length : pages.filter((p) => p.in_google === (k === "in")).length})`}
                </button>
              ))}
            </div>
            <div className="max-h-[32rem] overflow-y-auto">
              {pages === null ? (
                <p className="p-5 text-sm text-muted">Cargando…</p>
              ) : (
                [...new Set(pages.map((p) => p.group))].map((g) => {
                  const rowsG = pages.filter((p) => p.group === g && (pageFilter === "all" || p.in_google === (pageFilter === "in")));
                  if (!rowsG.length) return null;
                  return (
                    <div key={g}>
                      <p className="sticky top-0 bg-surface px-5 pb-1 pt-3 text-[10.5px] font-bold uppercase tracking-[0.12em] text-muted">{g}</p>
                      <ul className="divide-y divide-line">
                        {rowsG.map((p) => (
                          <li key={p.path} className="flex items-center gap-3 px-5 py-2">
                            <span className={`h-2 w-2 shrink-0 rounded-full ${p.in_google ? "bg-ok" : "bg-line"}`} aria-hidden="true" />
                            <span className="min-w-0 flex-1">
                              <span className="block truncate text-sm font-semibold">{p.title}</span>
                              <span className="block truncate font-mono text-[11px] text-muted">{p.path}</span>
                            </span>
                            {p.in_google ? (
                              <Chip tone="ok">En Google</Chip>
                            ) : (
                              <span className="text-right text-[11px] leading-tight text-muted">
                                {p.reason}
                                {p.edit && (
                                  <Link href={p.edit} className="block font-semibold text-brand hover:underline">
                                    Abrir →
                                  </Link>
                                )}
                              </span>
                            )}
                          </li>
                        ))}
                      </ul>
                    </div>
                  );
                })
              )}
            </div>
            <p className="border-t border-line px-5 py-2.5 text-xs text-muted">Una página empieza a salir en Google cuando la publicas. El panel, el inicio de sesión y los enlaces personales nunca salen.</p>
          </section>
        </div>
      </div>

      {editing && (
        <RedirectModal
          row={editing === "new" ? null : editing}
          pages={linkable}
          onClose={() => setEditing(null)}
          onDone={(t) => {
            setEditing(null);
            setToast({ text: t, tone: "ok" });
            load();
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function GoogleCard({ seo, owner, onSaved }: { seo: Seo; owner: boolean; onSaved: (t: string) => void }) {
  const [f, setF] = useState({ search_console_verification: seo.search_console_verification ?? "", ga4: seo.ga4 ?? "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const dirty = f.search_console_verification !== (seo.search_console_verification ?? "") || f.ga4 !== (seo.ga4 ?? "");

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api("/internal/site/seo", { method: "PATCH", body: { search_console_verification: f.search_console_verification || null, ga4: f.ga4 || null } });
      onSaved("Códigos de Google guardados. El sitio los usa en menos de un minuto.");
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? {});
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={save} className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm" noValidate>
      <h2 className="flex items-center gap-2 font-display font-semibold">
        <Icon name="search" size={17} className="text-brand" /> Google <span className="text-xs font-normal text-muted">(opcional)</span>
      </h2>
      <p className="rounded-lg bg-bg px-3 py-2 text-xs text-muted">
        <b className="text-fg">No es necesario para que el sitio funcione ni para que salga en Google.</b> Son dos herramientas gratuitas de Google para medir: <b className="text-fg">Search Console</b> muestra con qué búsquedas llegan y si Google encuentra errores; <b className="text-fg">Analytics</b> cuenta las visitas. Se llenan cuando el sitio ya esté en su dominio, si alguien del equipo va a revisar esas estadísticas.
      </p>
      <FormField
        label="Verificación de Search Console"
        error={errors.search_console_verification?.[0]}
        hint="En Search Console elige «Etiqueta HTML» y pega solo el código que está en content=&quot;…&quot;. Sirve para ver cómo aparece el sitio en Google."
      >
        <input className="input font-mono text-sm" maxLength={120} disabled={!owner} value={f.search_console_verification} onChange={(e) => setF({ ...f, search_console_verification: e.target.value.trim() })} placeholder="abc123XYZ…" />
      </FormField>
      <FormField label="ID de Google Analytics 4" error={errors.ga4?.[0]} hint="Empieza con G-. Solo se carga para quien acepta las cookies analíticas.">
        <input className="input font-mono text-sm" maxLength={30} disabled={!owner} value={f.ga4} onChange={(e) => setF({ ...f, ga4: e.target.value.trim().toUpperCase() })} placeholder="G-XXXXXXXXXX" />
      </FormField>
      <p className="text-xs text-muted">
        Dominio del sitio: <b className="text-fg">{seo.primary_domain ?? "sin definir"}</b> (se cambia en Contacto y WhatsApp). Es la dirección que va en el sitemap.
      </p>
      {owner ? (
        <Button type="submit" variant="primary" icon="check" loading={saving} disabled={!dirty}>
          Guardar
        </Button>
      ) : (
        <p className="text-xs text-muted">Solo un Dueño puede cambiar estos códigos.</p>
      )}
    </form>
  );
}

function RedirectModal({ row, pages, onClose, onDone }: { row: Redirect | null; pages: LinkPage[]; onClose: () => void; onDone: (t: string) => void }) {
  const [f, setF] = useState({ source_path: row?.source_path ?? "", target_url: row?.target_url ?? "/", status_code: String(row?.status_code ?? 301), is_active: row?.is_active ?? true, notes: row?.notes ?? "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const body = { ...f, status_code: Number(f.status_code), notes: f.notes || null };
      await api(row ? `/internal/site/redirects/${row.id}` : "/internal/site/redirects", { method: row ? "PATCH" : "POST", body });
      onDone(row ? "Redirección actualizada." : "Redirección creada. Funciona en menos de un minuto.");
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { source_path: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  return (
    <Modal
      title={row ? "Editar redirección" : "Nueva redirección"}
      icon="swap"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="redirect-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="redirect-form" onSubmit={save} className="space-y-4" noValidate>
        <p className="rounded-xl bg-brand-soft/60 px-4 py-3 text-sm text-brand-strong">
          Úsalo cuando una dirección <b>ya no existe</b> (la quitaste o la compartiste mal) y quieres que quien entre ahí llegue a otra página en vez de ver «página no encontrada».
        </p>
        <FormField label="1. Dirección vieja (la que ya no existe)" error={errors.source_path?.[0]} hint="Escribe lo que va después del dominio. Ej.: si era escudosst.com/precios, escribe /precios">
          <span className="flex items-center gap-1 font-mono text-sm">
            <span className="text-muted">sitio</span>
            <input className="input font-mono" maxLength={300} value={f.source_path} placeholder="/precios" onChange={(e) => setF({ ...f, source_path: e.target.value })} autoFocus />
          </span>
        </FormField>
        <FormField label="2. ¿A qué página debe llevar?" error={errors.target_url?.[0]} hint="Elige una página que exista. También puede ser otra web.">
          <LinkPicker value={f.target_url} pages={pages} invalid={!!errors.target_url} onChange={(url) => setF({ ...f, target_url: url })} />
        </FormField>
        <p className="text-sm font-semibold">3. ¿Es para siempre?</p>
        <div className="grid grid-cols-2 gap-2">
          {(
            [
              ["301", "Permanente (301)", "La dirección cambió para siempre. Google pasa el posicionamiento a la nueva."],
              ["302", "Temporal (302)", "Por un tiempo, por ejemplo una campaña. Google mantiene la original."],
            ] as const
          ).map(([v, t, d]) => (
            <button
              key={v}
              type="button"
              aria-pressed={f.status_code === v}
              onClick={() => setF({ ...f, status_code: v })}
              className={`rounded-xl border p-3 text-left text-sm transition-colors ${f.status_code === v ? "border-brand bg-brand-soft/60 ring-1 ring-brand" : "border-line hover:border-brand/50"}`}
            >
              <b className="block">{t}</b>
              <span className="text-xs text-muted">{d}</span>
            </button>
          ))}
        </div>
        <FormField label="Nota (opcional)" error={errors.notes?.[0]}>
          <input className="input" maxLength={300} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} placeholder="Por qué se creó" />
        </FormField>
        {row && (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="h-4 w-4 accent-brand" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} /> Activa
          </label>
        )}
      </form>
    </Modal>
  );
}
