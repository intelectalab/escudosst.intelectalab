"use client";

// Sitio web › Ventanas emergentes (SCR-WEB-72): una oferta útil (calculadora, autodiagnóstico, demo…) que aparece
// al intentar salir, al bajar un % de la página o tras unos segundos. Nunca en el Libro de Reclamaciones, políticas ni formularios.

import { FormEvent, useState } from "react";
import { act, day, Guide, MediaField, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState, PageHeader } from "@/components/ui/Display";
import { ChoiceCards, FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api } from "@/lib/api";

type Popup = {
  id: string;
  name: string;
  trigger_type: "exit_intent" | "scroll_percent" | "time_on_page";
  trigger_value: number | null;
  pages_json: string | string[];
  excluded_pages_json: string | string[];
  devices: "all" | "desktop" | "mobile";
  offer_type: string;
  title: string;
  body: string | null;
  cta_label: string | null;
  image_media_id: string | null;
  frequency_days: number;
  status: "draft" | "active" | "paused";
  starts_at: string | null;
  ends_at: string | null;
  views: number;
  clicks: number;
  closes: number;
};
type Res = { data: Popup[]; triggers: Record<string, string>; offers: Record<string, string> };

const list = (v: string | string[]) => (Array.isArray(v) ? v : (JSON.parse(v || "[]") as string[]));
const DEVICES = { all: "Todos", desktop: "Solo computadora", mobile: "Solo celular y tablet" };

export default function VentanasPage() {
  const { res, error, load } = usePanelList<Res>("/internal/site/popups");
  const [edit, setEdit] = useState<Popup | "new" | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [actError, setActError] = useState<string | null>(null);

  async function run(p: Popup, action: "activate" | "pause", msg: string) {
    setActError(null);
    if (await act(`/internal/site/popups/${p.id}/${action}`, setActError)) {
      setToast(msg);
      load();
    }
  }
  async function remove(p: Popup) {
    if (!confirm(`¿Eliminar «${p.name}»?`)) return;
    await api(`/internal/site/popups/${p.id}`, { method: "DELETE" });
    setToast("Ventana eliminada.");
    load();
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Ventanas emergentes"]]}
        icon="window"
        title="Ventanas emergentes"
        description="Una invitación útil que aparece en el sitio sin interrumpir: se cierra con un clic, se muestra como máximo una por visita y no vuelve a salir hasta pasados los días que elijas."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setEdit("new")}>
            Nueva ventana
          </Button>
        }
      />
      <Guide
        what="Un recuadro que aparece una sola vez en el sitio para invitar a algo útil (calcular una multa, agendar una demo, suscribirse). Ayuda a captar interesados sin molestar: se cierra con un clic y no vuelve a salir por los días que elijas."
        where="En las páginas del sitio público que elijas (nunca en el Libro de Reclamaciones, políticas ni formularios)."
        steps={["Elige la oferta y escribe un título corto.", "Elige cuándo aparece: al intentar salir, al bajar en la página o tras unos segundos.", "«Activar». Aquí ves cuántos la vieron, hicieron clic o la cerraron."]}
      />
      {(error || actError) && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error ?? actError}</p>}
      {!res ? (
        !error && <p className="text-sm text-muted">Cargando…</p>
      ) : res.data.length === 0 ? (
        <EmptyState icon="window" text="Aún no hay ventanas emergentes." />
      ) : (
        <ul className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {res.data.map((p) => (
            <li key={p.id} className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <b className="block font-display text-[15px]">{p.name}</b>
                  <span className="text-xs text-muted">
                    {res.offers[p.offer_type]} · {res.triggers[p.trigger_type]}
                    {p.trigger_type === "scroll_percent" ? ` (${p.trigger_value} %)` : p.trigger_type === "time_on_page" ? ` (${p.trigger_value} s)` : ""} · cada {p.frequency_days} días
                  </span>
                </div>
                <Chip tone={p.status === "active" ? "ok" : p.status === "paused" ? "amber" : "na"} dot>
                  {p.status === "active" ? "Activa" : p.status === "paused" ? "Pausada" : "Borrador"}
                </Chip>
              </div>
              <p className="rounded-xl bg-bg px-4 py-3 text-sm">
                <b>{p.title}</b>
                {p.body && <span className="block text-muted">{p.body}</span>}
              </p>
              <p className="text-xs text-muted">
                Páginas: {list(p.pages_json).length ? list(p.pages_json).join(", ") : "todas"}
                {list(p.excluded_pages_json).length ? ` · menos ${list(p.excluded_pages_json).join(", ")}` : ""} · {DEVICES[p.devices]}
                {p.ends_at ? ` · hasta ${day(p.ends_at)}` : ""}
              </p>
              <dl className="grid grid-cols-3 divide-x divide-line rounded-xl border border-line text-center text-sm">
                {(
                  [
                    ["Vistas", p.views],
                    ["Clics", p.clicks],
                    ["Cerradas", p.closes],
                  ] as const
                ).map(([k, v]) => (
                  <div key={k} className="px-2 py-2">
                    <dt className="text-xs text-muted">{k}</dt>
                    <dd className="font-display text-lg font-bold tabular-nums">{v}</dd>
                  </div>
                ))}
              </dl>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" icon="pen" onClick={() => setEdit(p)}>
                  Editar
                </Button>
                {p.status !== "active" ? (
                  <Button size="sm" variant="primary" icon="play" onClick={() => run(p, "activate", "Ventana activa.")}>
                    Activar
                  </Button>
                ) : (
                  <Button size="sm" icon="pause" onClick={() => run(p, "pause", "Ventana pausada.")}>
                    Pausar
                  </Button>
                )}
                <Button size="sm" variant="danger-ghost" onClick={() => remove(p)}>
                  Eliminar
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {edit && res && (
        <PopupModal
          p={edit === "new" ? null : edit}
          res={res}
          onClose={() => setEdit(null)}
          onSaved={() => {
            setEdit(null);
            setToast("Ventana guardada.");
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function PopupModal({ p, res, onClose, onSaved }: { p: Popup | null; res: Res; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    name: p?.name ?? "",
    trigger_type: p?.trigger_type ?? "exit_intent",
    trigger_value: p?.trigger_value != null ? String(p.trigger_value) : "",
    pages: p ? list(p.pages_json).join("\n") : "",
    excluded_pages: p ? list(p.excluded_pages_json).join("\n") : "",
    devices: p?.devices ?? "all",
    offer_type: p?.offer_type ?? "fine_calculator",
    title: p?.title ?? "",
    body: p?.body ?? "",
    cta_label: p?.cta_label ?? "",
    frequency_days: String(p?.frequency_days ?? 7),
    ends_at: p?.ends_at?.slice(0, 10) ?? "",
  });
  const [image, setImage] = useState<{ id: string | null; file: string | null }>({ id: p?.image_media_id ?? null, file: null });
  const { message, saving, run, err } = useSave();
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const lines = (v: string) => v.split(/\n|,/).map((x) => x.trim()).filter(Boolean);

  async function save(e: FormEvent) {
    e.preventDefault();
    const body = {
      ...f,
      trigger_value: f.trigger_type === "exit_intent" ? null : Number(f.trigger_value) || null,
      pages: lines(f.pages),
      excluded_pages: lines(f.excluded_pages),
      frequency_days: Number(f.frequency_days),
      body: f.body || null,
      cta_label: f.cta_label || null,
      image_media_id: image.id,
      starts_at: null,
      ends_at: f.ends_at ? `${f.ends_at}T23:59:00-05:00` : null,
    };
    const ok = await run(() => (p ? api(`/internal/site/popups/${p.id}`, { method: "PUT", body }) : api("/internal/site/popups", { method: "POST", body })));
    if (ok !== null) onSaved();
  }

  return (
    <Modal
      title={p ? "Editar ventana" : "Nueva ventana emergente"}
      icon="bell"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="popup-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="popup-form" onSubmit={save} noValidate className="space-y-4">
        {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Nombre interno" hint="Solo lo ve el equipo." error={err("name")}>
            <input className="input" maxLength={80} value={f.name} onChange={(e) => set("name", e.target.value)} />
          </FormField>
          <FormField label="Oferta" hint="A dónde lleva el botón." error={err("offer_type")}>
            <select className="input" value={f.offer_type} onChange={(e) => set("offer_type", e.target.value)}>
              {Object.entries(res.offers).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </FormField>
        </div>
        <fieldset className="space-y-3 rounded-xl border border-line p-4">
          <legend className="px-1 text-sm font-semibold">Lo que ve la persona</legend>
          <FormField label="Título" hint="Corto y útil. Ej.: ¿Cuánto podría costar una multa de SUNAFIL?" error={err("title")}>
            <input className="input" maxLength={80} value={f.title} onChange={(e) => set("title", e.target.value)} />
          </FormField>
          <FormField label="Texto (opcional)" hint="Una o dos frases. Sin promesas que no podamos cumplir." error={err("body")}>
            <textarea className="input min-h-[70px]" maxLength={300} value={f.body} onChange={(e) => set("body", e.target.value)} />
          </FormField>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="Texto del botón (opcional)" hint="Si lo dejas vacío dice «Ver más»." error={err("cta_label")}>
              <input className="input" maxLength={30} value={f.cta_label} onChange={(e) => set("cta_label", e.target.value)} />
            </FormField>
            <MediaField label="Imagen (opcional)" value={image.id} fileId={image.file} error={err("image_media_id")} onChange={(id, file) => setImage({ id, file })} />
          </div>
        </fieldset>
        <fieldset className="space-y-3 rounded-xl border border-line p-4">
          <legend className="px-1 text-sm font-semibold">Cuándo aparece</legend>
          <ChoiceCards
            label="Momento"
            columns={3}
            value={f.trigger_type}
            onChange={(v) => set("trigger_type", v)}
            options={Object.entries(res.triggers).map(([k, v]) => ({ value: k as Popup["trigger_type"], title: v }))}
          />
          {f.trigger_type !== "exit_intent" && (
            <FormField label={f.trigger_type === "scroll_percent" ? "Porcentaje de la página (1 a 100)" : "Segundos en la página (3 a 3600)"} error={err("trigger_value")}>
              <input className="input w-40" type="number" value={f.trigger_value} onChange={(e) => set("trigger_value", e.target.value)} />
            </FormField>
          )}
          <p className="text-xs text-muted">En celulares, «al intentar salir» no se puede detectar: ahí se usa el % de la página o el tiempo.</p>
        </fieldset>
        <fieldset className="space-y-3 rounded-xl border border-line p-4">
          <legend className="px-1 text-sm font-semibold">Dónde y cada cuánto</legend>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label="Solo en estas páginas (opcional)" hint="Una por línea. /sectores/* incluye todas las de sectores. Vacío = todas." error={err("pages") ?? err("pages.0")}>
              <textarea className="input min-h-[80px] font-mono text-xs" value={f.pages} placeholder={"/planes\n/sectores/*"} onChange={(e) => set("pages", e.target.value)} />
            </FormField>
            <FormField label="Nunca en estas (opcional)" hint="Además de las que nunca la muestran: Libro de Reclamaciones, políticas y formularios." error={err("excluded_pages") ?? err("excluded_pages.0")}>
              <textarea className="input min-h-[80px] font-mono text-xs" value={f.excluded_pages} placeholder="/demo" onChange={(e) => set("excluded_pages", e.target.value)} />
            </FormField>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <FormField label="Dispositivos" error={err("devices")}>
              <select className="input" value={f.devices} onChange={(e) => set("devices", e.target.value)}>
                {Object.entries(DEVICES).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="No repetir por (días)" hint="A la misma persona, después de verla o cerrarla." error={err("frequency_days")}>
              <input className="input" type="number" min={1} max={365} value={f.frequency_days} onChange={(e) => set("frequency_days", e.target.value)} />
            </FormField>
            <FormField label="Hasta (opcional)" hint="Después deja de mostrarse." error={err("ends_at")}>
              <input className="input" type="date" value={f.ends_at} onChange={(e) => set("ends_at", e.target.value)} />
            </FormField>
          </div>
        </fieldset>
      </form>
    </Modal>
  );
}
