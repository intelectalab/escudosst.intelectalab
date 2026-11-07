"use client";

// Editor de una página por bloques (SCR-WEB-20): bloques ordenables, vista previa (escritorio o celular), SEO y
// mensaje de WhatsApp de la página. Guardar = borrador; Publicar = lo que se ve en el sitio.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { MediaPicker } from "@/components/platform/MediaKit";
import { PageBlocks } from "@/components/site/PageBlocks";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { type Block, BLOCK_TYPES, CTA_BUTTONS, PAGE_TYPES, type PageType } from "@/lib/pages";
import { assetUrl, type PublicModule } from "@/lib/site";

type Page = {
  id: string;
  slug: string;
  page_type: PageType;
  title: string;
  summary: string | null;
  blocks: Block[];
  status: "draft" | "published" | "archived";
  meta_title: string | null;
  meta_description: string | null;
  noindex: boolean;
  whatsapp_message: string | null;
  sort_order: number;
  version: number;
  published_at: string | null;
};

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export default function EditPage() {
  const { id } = useParams<{ id: string }>();
  const [p, setP] = useState<Page | null>(null);
  const [modules, setModules] = useState<PublicModule[]>([]);
  const [view, setView] = useState<"edit" | "desktop" | "mobile">("edit");
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => api<{ data: Page }>(`/internal/site/pages/${id}`).then((r) => setP(r.data)), [id]);
  useEffect(() => {
    load();
    fetch(`${API_URL}/api/v1/public/modules`)
      .then((r) => r.json())
      .then((j) => setModules(j.data ?? []))
      .catch(() => undefined);
  }, [load]);

  const set = <K extends keyof Page>(k: K, v: Page[K]) => {
    setP((x) => (x ? { ...x, [k]: v } : x));
    setDirty(true);
  };
  const setBlock = (i: number, b: Block) => set("blocks", p!.blocks.map((x, k) => (k === i ? b : x)));
  const move = (i: number, d: -1 | 1) => {
    const l = [...p!.blocks];
    [l[i], l[i + d]] = [l[i + d], l[i]];
    set("blocks", l);
  };

  async function save(): Promise<boolean> {
    if (!p) return false;
    setBusy("save");
    setErrors({});
    try {
      const r = await api<{ data: { version: number } }>(`/internal/site/pages/${id}`, {
        method: "PATCH",
        body: { slug: p.slug, title: p.title, summary: p.summary, blocks: p.blocks, meta_title: p.meta_title, meta_description: p.meta_description, noindex: p.noindex, whatsapp_message: p.whatsapp_message, sort_order: p.sort_order, version: p.version },
      });
      setP((x) => (x ? { ...x, version: r.data.version } : x));
      setDirty(false);
      return true;
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        setToast({ text: e.message, tone: "bad" });
      }
      return false;
    } finally {
      setBusy(null);
    }
  }

  async function act(action: "publish" | "archive") {
    if (action === "publish" && dirty && !(await save())) return;
    setBusy(action);
    try {
      await api(`/internal/site/pages/${id}/${action}`, { method: "POST" });
      await load();
      setToast({ text: action === "publish" ? "Publicada. El sitio la muestra en menos de un minuto." : "Se quitó del sitio. Puedes volver a publicarla cuando quieras.", tone: "ok" });
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        setToast({ text: e.message, tone: "bad" });
      }
    } finally {
      setBusy(null);
    }
  }

  if (!p) return <p className="p-6 text-sm text-muted">Cargando…</p>;
  const type = PAGE_TYPES[p.page_type];
  const err = (k: string) => errors[k]?.[0];

  return (
    <div className="w-full space-y-5">
      <PageHeader crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Páginas", "/plataforma/sitio/paginas"], [p.title]]} icon={type.icon} title={p.title} description={`${type.label} · /${p.slug}`} />

      <div className="sticky top-0 z-20 -mx-1 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface/95 p-3 shadow-sm backdrop-blur">
        <div className="flex flex-wrap items-center gap-2">
          <Chip tone={p.status === "published" ? "ok" : p.status === "draft" ? "amber" : "na"} dot>
            {p.status === "published" ? "Publicada" : p.status === "draft" ? "Borrador" : "Quitada del sitio"}
          </Chip>
          {dirty && <Chip tone="amber">Sin guardar</Chip>}
          <div className="flex rounded-lg bg-bg p-1" role="tablist">
            {(
              [
                ["edit", "Editar", "pen"],
                ["desktop", "Vista previa", "eye"],
                ["mobile", "Celular", "people"],
              ] as const
            ).map(([k, l, ic]) => (
              <button key={k} role="tab" aria-selected={view === k} onClick={() => setView(k)} className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold ${view === k ? "bg-surface text-brand-strong shadow-sm" : "text-muted"}`}>
                <Icon name={ic} size={14} /> {l}
              </button>
            ))}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {p.status === "published" && (
            <>
              <a href={`/${p.slug}`} target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-3 text-sm font-semibold hover:border-brand">
                <Icon name="globe" size={15} /> Ver en el sitio
              </a>
              <Button variant="ghost" loading={busy === "archive"} onClick={() => act("archive")}>
                Quitar del sitio
              </Button>
            </>
          )}
          <Button variant="secondary" icon="check" loading={busy === "save"} disabled={!dirty} onClick={save}>
            Guardar borrador
          </Button>
          <Button variant="primary" icon="globe" loading={busy === "publish"} onClick={() => act("publish")}>
            {p.status === "published" ? "Publicar cambios" : "Publicar"}
          </Button>
        </div>
      </div>
      {err("blocks") && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{err("blocks")}</p>}

      {view !== "edit" ? (
        <div className="space-y-3">
          <p className="flex items-center justify-center gap-2 text-sm text-muted">
            <Icon name="eye" size={15} /> Solo es una muestra: los botones y enlaces no funcionan aquí.{view === "mobile" && " Usa la rueda del mouse para bajar."}
          </p>
          {view === "mobile" ? (
            <div className="mx-auto w-[390px] max-w-full rounded-[2.75rem] bg-[#0D3B6E] p-3 shadow-2xl">
              <div className="relative overflow-hidden rounded-[2.1rem] bg-white">
                <div className="flex items-center justify-between bg-white px-6 pb-1.5 pt-2.5 text-[11px] font-bold text-[#0D3B6E]">
                  <span>9:41</span>
                  <span className="h-5 w-24 rounded-full bg-[#0D3B6E]" aria-hidden="true" />
                  <span>5G</span>
                </div>
                <div className="site h-[min(680px,68vh)] select-none overflow-y-auto overscroll-contain [scrollbar-width:thin]">
                  <div className="pointer-events-none">
                    <PageBlocks blocks={p.blocks} modules={modules} />
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div className="site pointer-events-none select-none overflow-hidden rounded-2xl border border-line bg-white shadow-lg">
              <PageBlocks blocks={p.blocks} modules={modules} />
            </div>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
          <div className="space-y-3">
            {p.blocks.map((b, i) => (
              <BlockEditor key={i} b={b} i={i} total={p.blocks.length} modules={modules} errors={errors} onChange={(nb) => setBlock(i, nb)} onMove={(d) => move(i, d)} onRemove={() => set("blocks", p.blocks.filter((_, k) => k !== i))} />
            ))}
            <AddBlock onAdd={(b) => set("blocks", [...p.blocks, b])} />
          </div>

          <aside className="space-y-4 xl:sticky xl:top-24 xl:self-start">
            <section className="form-section space-y-3">
              <h3 className="font-display text-[15px] font-semibold">Datos de la página</h3>
              <FormField label="Título" error={err("title")}>
                <input className="input" maxLength={120} value={p.title} onChange={(e) => set("title", e.target.value)} />
              </FormField>
              <FormField label="Dirección" error={err("slug")}>
                <input className="input font-mono text-sm" maxLength={120} value={p.slug} onChange={(e) => set("slug", e.target.value.toLowerCase().trim())} />
              </FormField>
              <FormField label="Resumen" hint="Se ve en los listados (por ejemplo, en /sectores).">
                <textarea className="input min-h-[70px]" maxLength={300} value={p.summary ?? ""} onChange={(e) => set("summary", e.target.value)} />
              </FormField>
            </section>
            <section className="form-section space-y-3">
              <h3 className="font-display text-[15px] font-semibold">Google (SEO)</h3>
              <FormField label="Título en Google" counter={`${(p.meta_title ?? "").length}/70`} error={err("meta_title")}>
                <input className="input" maxLength={70} value={p.meta_title ?? ""} onChange={(e) => set("meta_title", e.target.value)} />
              </FormField>
              <FormField label="Descripción en Google" counter={`${(p.meta_description ?? "").length}/170`} error={err("meta_description")}>
                <textarea className="input min-h-[70px]" maxLength={170} value={p.meta_description ?? ""} onChange={(e) => set("meta_description", e.target.value)} />
              </FormField>
              <div className="rounded-xl border border-line bg-white p-3 text-sm">
                <p className="truncate text-[#1a0dab]">{p.meta_title || p.title}</p>
                <p className="truncate text-xs text-[#006621]">escudosst.com/{p.slug}</p>
                <p className="line-clamp-2 text-xs text-[#545454]">{p.meta_description || p.summary}</p>
              </div>
              <SwitchRow label="Ocultar de Google" hint="La página se ve, pero Google no la muestra en búsquedas." checked={p.noindex} onChange={(v) => set("noindex", v)} />
            </section>
            <section className="form-section space-y-3">
              <h3 className="font-display text-[15px] font-semibold">WhatsApp de esta página</h3>
              <FormField label="Mensaje predefinido (opcional)" hint="Es el texto que le llega al equipo cuando alguien toca el botón verde de WhatsApp en esta página (ej.: «Hola, me interesa EscudoSST para construcción»). Si está vacío, se usa el mensaje general. El botón solo aparece si hay un número en «Contacto y WhatsApp».">
                <input className="input" maxLength={300} value={p.whatsapp_message ?? ""} onChange={(e) => set("whatsapp_message", e.target.value)} />
              </FormField>
            </section>
          </aside>
        </div>
      )}
      <p className="text-sm">
        <Link href="/plataforma/sitio/paginas" className="font-semibold text-brand hover:underline">
          ← Todas las páginas
        </Link>
      </p>
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function AddBlock({ onAdd }: { onAdd: (b: Block) => void }) {
  const [open, setOpen] = useState(false);
  if (!open)
    return (
      <Button variant="secondary" icon="plus" onClick={() => setOpen(true)}>
        Agregar bloque
      </Button>
    );
  return (
    <div className="rounded-2xl border border-dashed border-brand/40 bg-brand-soft/30 p-4">
      <p className="mb-3 text-sm font-semibold">¿Qué bloque agregas?</p>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {BLOCK_TYPES.map((t) => (
          <button
            key={t.type}
            type="button"
            onClick={() => {
              onAdd(t.empty());
              setOpen(false);
            }}
            className="flex items-start gap-3 rounded-xl border border-line bg-surface p-3 text-left hover:border-brand/50"
          >
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-soft text-brand-strong">
              <Icon name={t.icon} size={16} />
            </span>
            <span>
              <b className="block text-sm">{t.label}</b>
              <span className="text-xs text-muted">{t.help}</span>
            </span>
          </button>
        ))}
      </div>
      <Button variant="ghost" className="mt-2" onClick={() => setOpen(false)}>
        Cancelar
      </Button>
    </div>
  );
}

function BlockEditor({
  b,
  i,
  total,
  modules,
  errors,
  onChange,
  onMove,
  onRemove,
}: {
  b: Block;
  i: number;
  total: number;
  modules: PublicModule[];
  errors: Record<string, string[]>;
  onChange: (b: Block) => void;
  onMove: (d: -1 | 1) => void;
  onRemove: () => void;
}) {
  const meta = BLOCK_TYPES.find((t) => t.type === b.type)!;
  const err = (f: string) => errors[`blocks.${i}.${f}`]?.[0];
  const field = (f: string, label: string, max: number, long = false, hint?: string) => {
    const v = ((b as Record<string, unknown>)[f] as string) ?? "";
    return (
      <FormField label={label} error={err(f)} hint={hint} counter={long ? `${v.length}/${max}` : undefined}>
        {long ? (
          <textarea className="input min-h-[90px]" maxLength={max} value={v} onChange={(e) => onChange({ ...b, [f]: e.target.value } as Block)} />
        ) : (
          <input className="input" maxLength={max} value={v} onChange={(e) => onChange({ ...b, [f]: e.target.value } as Block)} />
        )}
      </FormField>
    );
  };
  const list = (items: string[], placeholder: string) => (
    <div className="space-y-2">
      {items.map((it, k) => (
        <div key={k} className="flex gap-2">
          <input className="input" maxLength={200} placeholder={placeholder} value={it} onChange={(e) => onChange({ ...b, items: items.map((x, j) => (j === k ? e.target.value : x)) } as Block)} />
          <button type="button" onClick={() => onChange({ ...b, items: items.filter((_, j) => j !== k) } as Block)} className="grid h-11 w-11 shrink-0 place-items-center rounded-lg text-bad hover:bg-bad-soft" aria-label="Quitar">
            <Icon name="close" size={15} />
          </button>
        </div>
      ))}
      <Button variant="ghost" icon="plus" onClick={() => onChange({ ...b, items: [...items, ""] } as Block)}>
        Agregar
      </Button>
    </div>
  );

  return (
    <section className="form-section space-y-3">
      <div className="flex items-center gap-2">
        <span className="grid h-8 w-8 place-items-center rounded-lg bg-brand text-white">
          <Icon name={meta.icon} size={15} />
        </span>
        <b className="flex-1 text-sm">{meta.label}</b>
        <button type="button" disabled={i === 0} onClick={() => onMove(-1)} className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-bg disabled:opacity-30" aria-label="Subir">
          <Icon name="chevron" size={15} className="-rotate-90" />
        </button>
        <button type="button" disabled={i === total - 1} onClick={() => onMove(1)} className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-bg disabled:opacity-30" aria-label="Bajar">
          <Icon name="chevron" size={15} className="rotate-90" />
        </button>
        <button type="button" onClick={onRemove} className="grid h-9 w-9 place-items-center rounded-lg text-bad hover:bg-bad-soft" aria-label="Quitar bloque">
          <Icon name="close" size={15} />
        </button>
      </div>

      {b.type === "hero" && (
        <>
          {field("eyebrow", "Etiqueta (opcional)", 60)}
          {field("title", "Título", 120)}
          {field("text", "Frase", 400, true)}
        </>
      )}
      {b.type === "text" && (
        <>
          {field("title", "Título", 120)}
          {field("body", "Texto", 3000, true)}
        </>
      )}
      {(b.type === "bullets" || b.type === "norms") && (
        <>
          {field("title", "Título", 120)}
          {b.type === "norms" && <p className="text-xs text-amber-ink">En el sitio se rotula «Referencial · a validar con un especialista». Cita solo normas de la documentación (docs/11).</p>}
          {list(b.items, b.type === "norms" ? "D.S. 011-2019-TR…" : "Un punto")}
        </>
      )}
      {b.type === "modules" && (
        <>
          {field("title", "Título", 120)}
          {err("items") && <p className="text-xs font-medium text-bad">● {err("items")}</p>}
          <p className="text-xs text-muted">
            La letra indica desde qué plan viene cada módulo: <b>E</b> Esencial, <b>P</b> Profesional, <b>C</b> Corporativo, <b>K</b> Consultora y <b>+</b> complemento. En la página, cada tarjeta lo dice completo (míralo en «Vista previa»).
          </p>
          <div className="flex flex-wrap gap-1.5">
            {modules.map((m) => {
              const on = b.items.includes(m.code);
              const plan = m.is_addon ? "+" : m.plan_code === "K" ? "K" : (m.from_plan ?? "");
              return (
                <button
                  key={m.code}
                  type="button"
                  onClick={() => onChange({ ...b, items: on ? b.items.filter((c) => c !== m.code) : [...b.items, m.code] })}
                  className={`inline-flex items-center gap-1.5 rounded-full border py-1 pl-1 pr-3 text-xs font-semibold ${on ? "border-brand bg-brand text-white" : "border-line text-muted hover:border-brand/50"}`}
                >
                  <span className={`grid h-5 min-w-5 place-items-center rounded-full px-1 text-[10px] font-bold ${on ? "bg-white/25 text-white" : m.is_addon ? "bg-amber-soft text-amber-ink" : "bg-brand-soft text-brand-strong"}`} title={m.is_addon ? "Complemento" : `Desde el plan ${plan}`}>
                    {plan}
                  </span>
                  {m.name}
                </button>
              );
            })}
          </div>
        </>
      )}
      {b.type === "image" && <ImageBlockEditor b={b} error={err("media_id")} field={field} onChange={onChange} />}
      {b.type === "video" && (
        <>
          {field("title", "Título (opcional)", 120)}
          {field("url", "Enlace del video", 250, false, "YouTube o Vimeo, por ejemplo https://www.youtube.com/watch?v=…")}
        </>
      )}
      {b.type === "faq" && (
        <>
          {field("title", "Título", 120)}
          <div className="space-y-3">
            {b.items.map((f, k) => (
              <div key={k} className="space-y-2 rounded-xl bg-bg/60 p-3">
                <input className="input" maxLength={200} placeholder="Pregunta" value={f.q} onChange={(e) => onChange({ ...b, items: b.items.map((x, j) => (j === k ? { ...x, q: e.target.value } : x)) })} />
                <textarea className="input min-h-[60px]" maxLength={1000} placeholder="Respuesta" value={f.a} onChange={(e) => onChange({ ...b, items: b.items.map((x, j) => (j === k ? { ...x, a: e.target.value } : x)) })} />
                <Button variant="danger-ghost" onClick={() => onChange({ ...b, items: b.items.filter((_, j) => j !== k) })}>
                  Quitar pregunta
                </Button>
              </div>
            ))}
            <Button variant="ghost" icon="plus" onClick={() => onChange({ ...b, items: [...b.items, { q: "", a: "" }] })}>
              Agregar pregunta
            </Button>
          </div>
        </>
      )}
      {b.type === "cta" && (
        <>
          {field("title", "Título", 120)}
          {field("text", "Texto (opcional)", 300)}
          <FormField label="Botón">
            <select className="input" value={b.button} onChange={(e) => onChange({ ...b, button: e.target.value as "trial" | "demo" | "consultancy" })}>
              {Object.entries(CTA_BUTTONS).map(([k, [label]]) => (
                <option key={k} value={k}>
                  {label}
                </option>
              ))}
            </select>
          </FormField>
        </>
      )}
    </section>
  );
}

/** Bloque «Imagen»: elige una imagen de la biblioteca de medios (su texto alternativo viene de la biblioteca). */
function ImageBlockEditor({
  b,
  error,
  field,
  onChange,
}: {
  b: Extract<Block, { type: "image" }>;
  error?: string;
  field: (f: string, label: string, max: number, long?: boolean, hint?: string) => React.ReactNode;
  onChange: (b: Block) => void;
}) {
  const [picking, setPicking] = useState(false);
  return (
    <>
      <div className="flex flex-wrap items-center gap-3">
        <span className="h-24 w-36 shrink-0 overflow-hidden rounded-xl border border-line bg-bg">
          {b.file_id ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={assetUrl(b.file_id) ?? ""} alt={b.alt ?? ""} className="h-full w-full object-cover" />
          ) : (
            <span className="grid h-full place-items-center text-xs text-muted">Sin imagen</span>
          )}
        </span>
        <span className="space-y-1">
          <Button size="sm" variant="soft" icon="eye" onClick={() => setPicking(true)}>
            {b.file_id ? "Cambiar imagen" : "Elegir de la biblioteca"}
          </Button>
          {b.alt && <span className="block max-w-xs text-xs text-muted">Descripción: {b.alt}</span>}
          {error && <span className="block text-xs font-medium text-bad">● {error}</span>}
        </span>
      </div>
      {field("title", "Título (opcional)", 120)}
      {field("caption", "Leyenda (opcional)", 200)}
      {picking && (
        <MediaPicker
          kind="image"
          onClose={() => setPicking(false)}
          onPick={([m]) => {
            setPicking(false);
            onChange({ ...b, media_id: m.id, file_id: m.file_id, alt: m.alt_text, width: m.width, height: m.height });
          }}
        />
      )}
    </>
  );
}
