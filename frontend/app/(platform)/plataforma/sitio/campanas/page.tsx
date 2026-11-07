"use client";

// Sitio web › Campañas (SCR-WEB-70): campañas de anuncios con su landing /l/…, el formulario que se muestra,
// los UTM para medirla, la vigencia y sus métricas (visitas, conversiones e interesados).

import Link from "next/link";
import { FormEvent, useState } from "react";
import { act, day, Guide, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api } from "@/lib/api";

type Campaign = {
  id: string;
  name: string;
  channel: string;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  landing_page_id: string | null;
  landing_slug: string | null;
  landing_title: string | null;
  landing_status: string | null;
  form_type: string | null;
  starts_on: string | null;
  ends_on: string | null;
  status: "draft" | "active" | "paused" | "finished";
  goal: string | null;
  visits: number;
  conversions: number;
  leads: number;
};
type Res = { data: Campaign[]; landings: { id: string; slug: string; title: string; status: string }[]; channels: Record<string, string>; forms: Record<string, string> };

const STATUS: Record<Campaign["status"], [string, ChipTone]> = { draft: ["Borrador", "na"], active: ["Activa", "ok"], paused: ["Pausada", "amber"], finished: ["Terminada", "na"] };
const SITE = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");

function campaignUrl(c: Pick<Campaign, "landing_slug" | "utm_source" | "utm_medium" | "utm_campaign">) {
  if (!c.landing_slug) return null;
  const q = new URLSearchParams();
  if (c.utm_source) q.set("utm_source", c.utm_source);
  if (c.utm_medium) q.set("utm_medium", c.utm_medium);
  if (c.utm_campaign) q.set("utm_campaign", c.utm_campaign);
  return `${SITE}/${c.landing_slug}${q.size ? `?${q}` : ""}`;
}

export default function CampanasPage() {
  const { res, error, load } = usePanelList<Res>("/internal/site/campaigns");
  const [edit, setEdit] = useState<Campaign | "new" | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [actError, setActError] = useState<string | null>(null);

  async function run(c: Campaign, action: "activate" | "pause" | "finish", msg: string) {
    setActError(null);
    if (await act(`/internal/site/campaigns/${c.id}/${action}`, setActError)) {
      setToast(msg);
      load();
    }
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Campañas"]]}
        icon="megaphone"
        title="Campañas"
        description="Para anuncios en Google, Meta, LinkedIn o correo: cada campaña tiene su página de llegada (landing), el formulario que se muestra y sus enlaces medibles."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setEdit("new")}>
            Nueva campaña
          </Button>
        }
      />
      <Guide
        what="Para anuncios pagados (Google, Facebook, LinkedIn) o un correo masivo que tú envías. Cada campaña tiene su propia página de llegada y aquí ves cuántas visitas, formularios e interesados trajo. No envía correos ni publica anuncios: solo te da el enlace para pegarlo en tu anuncio."
        where="En escudosst.intelectalab.com/l/… solo mientras la campaña está activa y en sus fechas."
        steps={["Crea la campaña y, en el mismo formulario, su página de llegada («Crear landing»).", "Escribe y publica la landing en Páginas; luego «Activar» la campaña.", "Copia el enlace y pégalo en tu anuncio. Al terminar, la landing avisa que la campaña acabó."]}
      />
      {(error || actError) && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error ?? actError}</p>}
      {!res ? (
        !error && <p className="text-sm text-muted">Cargando…</p>
      ) : res.data.length === 0 ? (
        <EmptyState icon="megaphone" text="Aún no hay campañas. Crea la primera con «Nueva campaña»." />
      ) : (
        <ul className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {res.data.map((c) => {
            const url = campaignUrl(c);
            return (
              <li key={c.id} className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <b className="block font-display text-[15px]">{c.name}</b>
                    <span className="text-xs text-muted">
                      {res.channels[c.channel]} · {c.form_type ? res.forms[c.form_type] : "sin formulario"} · {c.starts_on ? day(c.starts_on) : "desde ya"} → {c.ends_on ? day(c.ends_on) : "sin fin"}
                    </span>
                  </div>
                  <Chip tone={STATUS[c.status][1]} dot>
                    {STATUS[c.status][0]}
                  </Chip>
                </div>
                <dl className="grid grid-cols-3 divide-x divide-line rounded-xl border border-line text-center text-sm">
                  {(
                    [
                      ["Visitas", c.visits],
                      ["Formularios", c.conversions],
                      ["Interesados", c.leads],
                    ] as const
                  ).map(([k, v]) => (
                    <div key={k} className="px-2 py-2">
                      <dt className="text-xs text-muted">{k}</dt>
                      <dd className="font-display text-lg font-bold tabular-nums">{v}</dd>
                    </div>
                  ))}
                </dl>
                {url ? (
                  <div className="flex items-center gap-2 rounded-xl bg-bg px-3 py-2">
                    <code className="min-w-0 flex-1 truncate text-xs">{url}</code>
                    <Button size="sm" variant="ghost" icon="file" onClick={() => navigator.clipboard.writeText(url).then(() => setToast("Enlace copiado."))}>
                      Copiar
                    </Button>
                  </div>
                ) : (
                  <p className="text-xs text-amber-ink">Falta elegir la landing.</p>
                )}
                {c.landing_status && c.landing_status !== "published" && <p className="text-xs text-amber-ink">La landing «{c.landing_title}» aún no está publicada.</p>}
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" icon="pen" onClick={() => setEdit(c)}>
                    Editar
                  </Button>
                  {c.status !== "active" && c.status !== "finished" && (
                    <Button size="sm" variant="primary" icon="play" onClick={() => run(c, "activate", "Campaña activa.")}>
                      Activar
                    </Button>
                  )}
                  {c.status === "active" && (
                    <Button size="sm" icon="pause" onClick={() => run(c, "pause", "Campaña pausada.")}>
                      Pausar
                    </Button>
                  )}
                  {c.status !== "finished" && (
                    <Button size="sm" variant="ghost" onClick={() => run(c, "finish", "Campaña terminada.")}>
                      Terminar
                    </Button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
      {edit && res && (
        <CampaignModal
          c={edit === "new" ? null : edit}
          res={res}
          onClose={() => setEdit(null)}
          onSaved={() => {
            setEdit(null);
            setToast("Campaña guardada.");
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function CampaignModal({ c, res, onClose, onSaved }: { c: Campaign | null; res: Res; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    name: c?.name ?? "",
    channel: c?.channel ?? "google_ads",
    utm_source: c?.utm_source ?? "",
    utm_medium: c?.utm_medium ?? "",
    utm_campaign: c?.utm_campaign ?? "",
    landing_page_id: c?.landing_page_id ?? "",
    form_type: c?.form_type ?? "demo",
    starts_on: c?.starts_on ?? "",
    ends_on: c?.ends_on ?? "",
    goal: c?.goal ?? "",
  });
  const { message, saving, run, err, setMessage } = useSave();
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const [landings, setLandings] = useState(res.landings);
  const [creatingLanding, setCreatingLanding] = useState(false);
  const landing = landings.find((l) => l.id === f.landing_page_id);
  const slugify = (t: string) =>
    t
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 60);

  /** Crea la página de llegada con un encabezado y un botón final; luego se completa y publica en Páginas. */
  async function createLanding() {
    const base = f.utm_campaign || slugify(f.name);
    if (!base) {
      setMessage("Escribe primero el nombre de la campaña: con él se arma la dirección de la landing.");
      return;
    }
    setCreatingLanding(true);
    const r = await run(() =>
      api<{ data: { id: string } }>("/internal/site/pages", {
        method: "POST",
        body: {
          page_type: "campaign",
          slug: `l/${base}`,
          title: f.name || base,
          blocks: [
            { type: "hero", eyebrow: "", title: f.name || "Ordena la SST de tu empresa", text: "" },
            { type: "cta", title: "¿Hablamos?", text: "", button: f.form_type === "demo" ? "demo" : "trial" },
          ],
        },
      }),
    );
    setCreatingLanding(false);
    if (r) {
      setLandings([...landings, { id: r.data.id, slug: `l/${base}`, title: f.name || base, status: "draft" }]);
      set("landing_page_id", r.data.id);
    }
  }
  const preview = campaignUrl({ landing_slug: landing?.slug ?? null, utm_source: f.utm_source, utm_medium: f.utm_medium, utm_campaign: f.utm_campaign });

  async function save(e: FormEvent) {
    e.preventDefault();
    const body = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === "" ? null : v]));
    const ok = await run(() => (c ? api(`/internal/site/campaigns/${c.id}`, { method: "PUT", body }) : api("/internal/site/campaigns", { method: "POST", body })));
    if (ok !== null) onSaved();
  }

  return (
    <Modal
      title={c ? "Editar campaña" : "Nueva campaña"}
      icon="tag"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="campaign-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="campaign-form" onSubmit={save} noValidate className="space-y-4">
        {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Nombre" hint="Solo lo ve el equipo. Ej.: Construcción · noviembre." error={err("name")}>
            <input className="input" maxLength={120} value={f.name} onChange={(e) => set("name", e.target.value)} />
          </FormField>
          <FormField label="Canal" hint="Dónde sale el anuncio." error={err("channel")}>
            <select className="input" value={f.channel} onChange={(e) => set("channel", e.target.value)}>
              {Object.entries(res.channels).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Página de llegada (landing)" hint="A donde llega quien hace clic en tu anuncio." error={err("landing_page_id")}>
            <select className="input" value={f.landing_page_id} onChange={(e) => set("landing_page_id", e.target.value)}>
              <option value="">Elige una</option>
              {landings.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.title} (/{l.slug}){l.status !== "published" ? " · sin publicar" : ""}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Formulario que se muestra" hint="Lo que la persona puede hacer en la landing." error={err("form_type")}>
            <select className="input" value={f.form_type} onChange={(e) => set("form_type", e.target.value)}>
              {Object.entries(res.forms).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </FormField>
        </div>
        <div className="flex flex-wrap items-center gap-3 rounded-lg bg-bg px-4 py-3 text-sm">
          {landing ? (
            <>
              <span className="min-w-0 flex-1 text-muted">
                Landing: <b className="text-fg">/{landing.slug}</b> · {landing.status === "published" ? "publicada" : "en borrador: complétala y publícala antes de activar la campaña"}
              </span>
              <Link href={`/plataforma/sitio/paginas/${landing.id}`} target="_blank" className="font-semibold text-brand-strong hover:underline">
                Editar la landing
              </Link>
            </>
          ) : (
            <>
              <span className="min-w-0 flex-1 text-muted">¿Aún no tienes la página de llegada? Créala aquí con el nombre de la campaña.</span>
              <Button size="sm" icon="plus" loading={creatingLanding} onClick={createLanding}>
                Crear landing
              </Button>
            </>
          )}
        </div>
        <fieldset className="space-y-3 rounded-xl border border-line p-4">
          <legend className="px-1 text-sm font-semibold">Para medirla (UTM)</legend>
          <p className="text-xs text-muted">Se agregan al enlace. Solo minúsculas, números y guiones. El nombre de campaña es el que une visitas e interesados.</p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <FormField label="Origen (utm_source)" hint="Ej.: google, facebook" error={err("utm_source")}>
              <input className="input" maxLength={100} value={f.utm_source} onChange={(e) => set("utm_source", e.target.value.toLowerCase())} />
            </FormField>
            <FormField label="Medio (utm_medium)" hint="Ej.: cpc, email" error={err("utm_medium")}>
              <input className="input" maxLength={100} value={f.utm_medium} onChange={(e) => set("utm_medium", e.target.value.toLowerCase())} />
            </FormField>
            <FormField label="Campaña (utm_campaign)" hint="Ej.: sst-construccion-2026" error={err("utm_campaign")}>
              <input className="input" maxLength={100} value={f.utm_campaign} onChange={(e) => set("utm_campaign", e.target.value.toLowerCase())} />
            </FormField>
          </div>
          {preview && (
            <p className="flex items-center gap-2 break-all rounded-lg bg-bg px-3 py-2 text-xs">
              <Icon name="globe" size={14} className="shrink-0 text-muted" /> {preview}
            </p>
          )}
        </fieldset>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Desde (opcional)" hint="Antes de esta fecha la landing no se muestra." error={err("starts_on")}>
            <input className="input" type="date" value={f.starts_on} onChange={(e) => set("starts_on", e.target.value)} />
          </FormField>
          <FormField label="Hasta (opcional)" hint="Después, la landing avisa que la campaña terminó." error={err("ends_on")}>
            <input className="input" type="date" value={f.ends_on} onChange={(e) => set("ends_on", e.target.value)} />
          </FormField>
        </div>
        <FormField label="Objetivo (opcional)" hint="Para el equipo. Ej.: 20 demos agendadas en el mes." error={err("goal")}>
          <input className="input" maxLength={300} value={f.goal} onChange={(e) => set("goal", e.target.value)} />
        </FormField>
      </form>
    </Modal>
  );
}
