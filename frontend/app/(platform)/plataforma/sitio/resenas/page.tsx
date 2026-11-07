"use client";

// Sitio web › Reseñas de Google (SCR-WEB-81): se conectan con el perfil de Google Business y se muestran tal cual
// (sin editar ni elegir cuáles). Solo se filtra por calificación mínima y cantidad.

import { FormEvent, useEffect, useState } from "react";
import { dt, HowItWorks, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Toast } from "@/components/ui/Toast";
import { api } from "@/lib/api";

type Source = { place_id: string; min_rating: number; max_to_show: number; cache_ttl_hours: number; status: "active" | "paused" | "error"; last_fetched_at: string | null; last_error: string | null };
type Res = { data: { source: Source | null; key_ready: boolean; snapshots: { author_name: string; rating: number; body: string | null; review_published_at: string | null }[] } };

export default function ResenasPage() {
  const { res, error, load } = usePanelList<Res>("/internal/site/reviews");
  const [f, setF] = useState({ place_id: "", min_rating: "4", max_to_show: "5", cache_ttl_hours: "24", active: false });
  const [toast, setToast] = useState<string | null>(null);
  const { message, saving, run, err } = useSave();
  const s = res?.data.source ?? null;

  useEffect(() => {
    if (s) setF({ place_id: s.place_id, min_rating: String(s.min_rating), max_to_show: String(s.max_to_show), cache_ttl_hours: String(s.cache_ttl_hours), active: s.status !== "paused" });
  }, [s]);

  async function save(e: FormEvent) {
    e.preventDefault();
    const r = await run(() =>
      api<{ data: { fetched: number | null } }>("/internal/site/reviews", {
        method: "PUT",
        body: { place_id: f.place_id.trim(), min_rating: Number(f.min_rating), max_to_show: Number(f.max_to_show), cache_ttl_hours: Number(f.cache_ttl_hours), active: f.active },
      }),
    );
    if (r) {
      setToast(r.data.fetched !== null ? `Guardado. Se leyeron ${r.data.fetched} reseñas de Google.` : "Guardado. Las reseñas no se muestran mientras esté en pausa.");
      load();
    }
  }
  async function refresh() {
    const r = await run(() => api<{ data: { fetched: number } }>("/internal/site/reviews/refresh", { method: "POST" }));
    if (r) {
      setToast(`Se leyeron ${r.data.fetched} reseñas de Google.`);
      load();
    }
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Reseñas de Google"]]}
        icon="star"
        title="Reseñas de Google"
        description="Muestra en el sitio las reseñas reales del perfil de Google de la empresa, con la atribución «Reseñas de Google». No se pueden editar ni elegir a mano."
      />
      <HowItWorks
        steps={[
          ["lock", "1. Llave de Google", "El equipo técnico carga la llave de la API de Google Places en el servidor (una sola vez)."],
          ["globe", "2. ID del lugar", "Copia el ID de tu perfil de Google (empieza con «ChIJ»). Búscalo en el «Place ID Finder» de Google."],
          ["star", "3. Actívala", "Elige la calificación mínima y cuántas mostrar. Se actualizan solas."],
        ]}
      />
      <section className="form-section space-y-3 text-sm">
        <h2 className="font-display text-base font-bold">¿Para qué sirve y qué necesito?</h2>
        <p className="text-muted">
          Muestra en el sitio las opiniones que tus clientes dejaron en el perfil de Google de la empresa (el que sale en Google Maps). Generan confianza porque son reales y llevan el
          sello «Reseñas de Google». Se ven en la <b>portada</b> y en <b>Casos de éxito</b>; si no hay reseñas, ese bloque simplemente no aparece.
        </p>
        <ol className="list-inside list-decimal space-y-2 text-muted">
          <li>
            <b className="text-fg">Tener un perfil de Google Business</b> con reseñas. Si aún no lo tienes, créalo en{" "}
            <a href="https://business.google.com/" target="_blank" rel="noopener" className="font-semibold text-brand-strong hover:underline">
              business.google.com
            </a>{" "}
            y pide a tus clientes que dejen su opinión.
          </li>
          <li>
            <b className="text-fg">La llave de Google (la hace el equipo técnico, una sola vez):</b> en Google Cloud se crea un proyecto, se activa «Places API (New)» y se genera una
            clave restringida solo a esa API. Esa clave se guarda en el servidor, nunca en este panel. Google puede cobrar si se pasa del uso gratuito; leer 5 reseñas una vez al día
            es un uso muy bajo.
          </li>
          <li>
            <b className="text-fg">El ID del lugar:</b> abre el{" "}
            <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener" className="font-semibold text-brand-strong hover:underline">
              buscador de Place ID de Google
            </a>
            , escribe el nombre de tu empresa como aparece en Google Maps y copia el código que empieza con «ChIJ». Pégalo abajo.
          </li>
          <li>
            <b className="text-fg">Elige qué mostrar y actívalo:</b> calificación mínima (por ejemplo, desde 4 estrellas) y cuántas. No se pueden editar ni elegir una por una: así lo
            exige Google y así son creíbles.
          </li>
        </ol>
      </section>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
      {res && !res.data.key_ready && (
        <p className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">
          <b>Pendiente:</b> aún no está la llave de Google en el servidor. Puedes guardar el ID del lugar; las reseñas se mostrarán cuando el equipo técnico cargue la llave y la actives.
        </p>
      )}
      {res && (
        <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
          <form onSubmit={save} noValidate className="form-section space-y-4">
            {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
            <div className="flex items-center justify-between gap-2">
              <h2 className="font-display text-base font-bold">Conexión</h2>
              {s && (
                <Chip tone={s.status === "active" ? "ok" : s.status === "error" ? "bad" : "na"} dot>
                  {s.status === "active" ? "Activa" : s.status === "error" ? "Con error" : "En pausa"}
                </Chip>
              )}
            </div>
            {s?.last_error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-xs text-bad">{s.last_error}</p>}
            <FormField label="ID del lugar en Google" hint="Empieza con «ChIJ». Es el identificador de tu perfil de Google Business." error={err("place_id")}>
              <input className="input font-mono text-xs" maxLength={200} value={f.place_id} onChange={(e) => setF({ ...f, place_id: e.target.value })} />
            </FormField>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <FormField label="Calificación mínima" hint="Solo se muestran desde esta cantidad de estrellas." error={err("min_rating")}>
                <select className="input" value={f.min_rating} onChange={(e) => setF({ ...f, min_rating: e.target.value })}>
                  {[1, 2, 3, 4, 5].map((n) => (
                    <option key={n} value={n}>
                      {n} {n === 1 ? "estrella" : "estrellas"}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField label="Cuántas mostrar" hint="Google entrega hasta 5." error={err("max_to_show")}>
                <input className="input" type="number" min={1} max={50} value={f.max_to_show} onChange={(e) => setF({ ...f, max_to_show: e.target.value })} />
              </FormField>
              <FormField label="Actualizar cada (horas)" hint="Cada cuánto se vuelven a leer." error={err("cache_ttl_hours")}>
                <input className="input" type="number" min={1} max={720} value={f.cache_ttl_hours} onChange={(e) => setF({ ...f, cache_ttl_hours: e.target.value })} />
              </FormField>
            </div>
            <SwitchRow label="Mostrar en el sitio" hint="Aparecen en la portada y en Casos de éxito. Si no hay reseñas vigentes, el bloque no se muestra." checked={f.active} onChange={(v) => setF({ ...f, active: v })} />
            {err("active") && <p className="text-xs font-medium text-bad">● {err("active")}</p>}
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" type="submit" icon="check" loading={saving}>
                Guardar
              </Button>
              {s && res.data.key_ready && (
                <Button icon="refresh" onClick={refresh} disabled={saving}>
                  Leer ahora
                </Button>
              )}
            </div>
            <p className="text-xs text-muted">Última lectura: {s?.last_fetched_at ? dt(s.last_fetched_at) : "nunca"}. Solo el Dueño puede cambiar esta conexión.</p>
          </form>
          <section className="form-section space-y-3">
            <h2 className="font-display text-base font-bold">Copia actual ({res.data.snapshots.length})</h2>
            {res.data.snapshots.length === 0 ? (
              <p className="text-sm text-muted">Aún no hay reseñas leídas.</p>
            ) : (
              <ul className="space-y-3">
                {res.data.snapshots.map((r, k) => (
                  <li key={k} className="rounded-xl bg-bg p-3 text-sm">
                    <span className="flex items-center justify-between gap-2">
                      <b>{r.author_name}</b>
                      <span className="text-amber" aria-label={`${r.rating} de 5`}>
                        {"★".repeat(r.rating)}
                        <span className="text-line">{"★".repeat(5 - r.rating)}</span>
                      </span>
                    </span>
                    {r.body && <span className="mt-1 line-clamp-3 block text-muted">{r.body}</span>}
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}
