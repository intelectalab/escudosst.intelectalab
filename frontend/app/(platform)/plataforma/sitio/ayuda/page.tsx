"use client";

// Sitio web › Centro de ayuda (SCR-WEB-45): categorías (temas) y artículos que se ven en /ayuda y, si tienen código
// de pantalla, en el botón «Ayuda» dentro de la aplicación. Soporte redacta; solo el Dueño publica o elimina.

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { HelpBody } from "@/components/site/HelpBody";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { APP_SCREENS } from "@/lib/help";

type Category = { id: string; name: string; slug: string; icon: string | null; description: string | null; sort_order: number; status: "active" | "hidden"; articles: number; published: number };
type Article = {
  id: string;
  category_id: string;
  title: string;
  slug: string;
  body: string;
  video_url: string | null;
  screen_code: string | null;
  related_article_ids: string[];
  status: "draft" | "published" | "archived";
  published_at: string | null;
  helpful_count: number;
  not_helpful_count: number;
  sort_order: number;
  seo_title: string | null;
  seo_description: string | null;
  comments: number;
};

const STATUS = { draft: ["Borrador", "amber"], published: ["Publicado", "ok"], archived: ["Quitado del sitio", "na"] } as const;

const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.");

export default function AyudaPanelPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [data, setData] = useState<{ categories: Category[]; articles: Article[] } | null>(null);
  const [icons, setIcons] = useState<string[]>([]);
  const [cat, setCat] = useState<string>("all");
  const [filter, setFilter] = useState<"all" | "published" | "draft" | "low">("all");
  const [q, setQ] = useState("");
  const [editCat, setEditCat] = useState<Category | "new" | null>(null);
  const [editArt, setEditArt] = useState<Article | "new" | null>(null);
  const [opinions, setOpinions] = useState<Article | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: { categories: Category[]; articles: Article[] }; icons: string[] }>("/internal/site/help").then((r) => {
      setData(r.data);
      setIcons(r.icons);
    });
  }, []);
  useEffect(load, [load]);

  async function act(a: Article, action: "publish" | "archive" | "delete") {
    if (action === "delete" && !window.confirm(`¿Eliminar «${a.title}»? No se puede deshacer.`)) return;
    try {
      await api(action === "delete" ? `/internal/site/help/articles/${a.id}` : `/internal/site/help/articles/${a.id}/${action}`, { method: action === "delete" ? "DELETE" : "POST" });
      setToast({ text: action === "publish" ? "Publicado: ya se ve en /ayuda." : action === "archive" ? "Quitado del sitio." : "Artículo eliminado.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: errText(e), tone: "bad" });
    }
  }

  const cats = data?.categories ?? [];
  const all = data?.articles ?? [];
  const catName = (id: string) => cats.find((c) => c.id === id)?.name ?? "";
  const isLow = (a: Article) => a.helpful_count + a.not_helpful_count >= 3 && a.not_helpful_count > a.helpful_count;
  const list = all.filter(
    (a) =>
      (cat === "all" || a.category_id === cat) &&
      (filter === "published" ? a.status === "published" : filter === "draft" ? a.status === "draft" : filter === "low" ? isLow(a) : true) &&
      (!q.trim() || a.title.toLowerCase().includes(q.trim().toLowerCase())),
  );
  const toggle = (f: typeof filter) => setFilter((x) => (x === f ? "all" : f));
  const votes = all.reduce((s, a) => s + a.helpful_count + a.not_helpful_count, 0);
  const helpful = all.reduce((s, a) => s + a.helpful_count, 0);

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Centro de ayuda"]]}
        icon="help"
        title="Centro de ayuda"
        description="Guías paso a paso para tus clientes. Se ven en /ayuda y, si les pones el código de una pantalla, también en el botón «Ayuda» de esa pantalla dentro del sistema."
        aside={
          <div className="flex flex-wrap gap-2">
            <Button icon="plus" onClick={() => setEditCat("new")}>
              Nuevo tema
            </Button>
            <Button variant="primary" icon="plus" onClick={() => setEditArt("new")} disabled={!cats.length}>
              Nuevo artículo
            </Button>
          </div>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Publicados" value={data ? all.filter((a) => a.status === "published").length : "–"} icon="globe" tone="ok" hint="Se ven en /ayuda" active={filter === "published"} onClick={() => toggle("published")} />
        <StatCard label="Borradores" value={data ? all.filter((a) => a.status === "draft").length : "–"} icon="pen" tone="amber" hint="Falta publicarlos" active={filter === "draft"} onClick={() => toggle("draft")} />
        <StatCard label="Por mejorar" value={data ? all.filter(isLow).length : "–"} icon="bell" tone="bad" hint="Más «No» que «Sí»" active={filter === "low"} onClick={() => toggle("low")} />
        <StatCard label="Les sirvió" value={data ? (votes ? `${Math.round((helpful / votes) * 100)}%` : "–") : "–"} icon="check" tone="brand" hint={`${votes} voto${votes === 1 ? "" : "s"} en total`} />
      </div>

      <div className="rounded-2xl border border-brand/15 bg-gradient-to-r from-brand-soft/70 to-surface p-4 text-sm">
        <p className="font-semibold text-brand-strong">Cómo funciona</p>
        <ul className="mt-1 grid grid-cols-1 gap-x-6 gap-y-1 text-muted md:grid-cols-3">
          <li>
            <b className="text-fg">1. Temas:</b> agrupan los artículos (ej. «Primeros pasos», «Usuarios»). Un tema oculto esconde todos sus artículos.
          </li>
          <li>
            <b className="text-fg">2. Artículos:</b> se escriben como texto simple. Se guardan como borrador y el Dueño los publica.
          </li>
          <li>
            <b className="text-fg">3. «¿Te sirvió?»:</b> la gente vota al final. Revisa los de «Por mejorar» y sus comentarios.
          </li>
        </ul>
      </div>

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-[300px_minmax(0,1fr)]">
        <section className="min-w-0 rounded-2xl border border-line bg-surface p-3 shadow-sm xl:self-start">
          <p className="px-2 pb-2 text-xs font-bold uppercase tracking-wide text-muted">Temas</p>
          <ul className="flex gap-2 overflow-x-auto pb-1 xl:flex-col xl:overflow-visible xl:pb-0">
            <li className="shrink-0">
              <button onClick={() => setCat("all")} className={`flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold ${cat === "all" ? "bg-brand text-white" : "hover:bg-bg"}`}>
                <Icon name="grid" size={16} /> Todos <span className="ml-auto text-xs opacity-80">{all.length}</span>
              </button>
            </li>
            {cats.map((c) => (
              <li key={c.id} className="group flex shrink-0 items-center gap-1">
                <button onClick={() => setCat(c.id)} className={`flex min-w-0 flex-1 items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold ${cat === c.id ? "bg-brand text-white" : "hover:bg-bg"}`}>
                  <Icon name={c.icon ?? "book"} size={16} />
                  <span className="truncate">{c.name}</span>
                  {c.status === "hidden" && <span className={`rounded px-1 text-[10px] ${cat === c.id ? "bg-white/20" : "bg-na-soft text-muted"}`}>oculto</span>}
                  <span className="ml-auto text-xs opacity-80">{c.articles}</span>
                </button>
                <button onClick={() => setEditCat(c)} className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-muted hover:bg-bg hover:text-brand" aria-label={`Editar tema ${c.name}`}>
                  <Icon name="pen" size={14} />
                </button>
              </li>
            ))}
          </ul>
          {data && !cats.length && <p className="px-2 py-3 text-sm text-muted">Crea el primer tema con «Nuevo tema».</p>}
        </section>

        <section className="min-w-0 space-y-3">
          <label className="relative block max-w-md">
            <span className="sr-only">Buscar artículo</span>
            <Icon name="search" size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
            <input className="input pl-9" placeholder="Buscar por título" value={q} onChange={(e) => setQ(e.target.value)} />
          </label>
          {data === null ? (
            <div className="h-48 animate-pulse rounded-2xl bg-line/40" />
          ) : list.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-line bg-surface p-10 text-center">
              <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-brand-soft text-brand-strong">
                <Icon name="book" size={22} />
              </span>
              <p className="mt-3 font-semibold">{all.length ? "Ninguno en este filtro" : "Todavía no hay artículos"}</p>
              <p className="mt-1 text-sm text-muted">{cats.length ? "Crea uno con «Nuevo artículo»." : "Primero crea un tema, luego sus artículos."}</p>
            </div>
          ) : (
            <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
              {list.map((a) => {
                const total = a.helpful_count + a.not_helpful_count;
                return (
                  <li key={a.id} className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
                    <div className="p-4">
                      <span className="flex flex-wrap gap-1.5">
                        <Chip tone={STATUS[a.status][1]} dot>
                          {STATUS[a.status][0]}
                        </Chip>
                        {a.screen_code && <Chip tone="brand">En la app · {APP_SCREENS[a.screen_code] ?? a.screen_code}</Chip>}
                        {a.video_url && <Chip tone="bad">Video</Chip>}
                        {isLow(a) && <Chip tone="bad">Por mejorar</Chip>}
                      </span>
                      <b className="mt-1.5 block font-display text-[15px] leading-snug">{a.title}</b>
                      <span className="block text-xs text-muted">{catName(a.category_id)}</span>
                      <div className="mt-3 flex items-center gap-2 text-xs">
                        <span className="h-2 flex-1 overflow-hidden rounded-full bg-bad-soft">
                          <span className="block h-full rounded-full bg-ok" style={{ width: total ? `${(a.helpful_count / total) * 100}%` : "0%" }} />
                        </span>
                        <span className="shrink-0 text-muted">
                          {total ? `${a.helpful_count} sí · ${a.not_helpful_count} no` : "Sin votos aún"}
                        </span>
                      </div>
                    </div>
                    <div className="mt-auto flex flex-wrap gap-1 border-t border-line bg-bg/50 px-3 py-2">
                      <Button size="sm" variant="ghost" icon="pen" onClick={() => setEditArt(a)}>
                        Editar
                      </Button>
                      {(total > 0 || a.comments > 0) && (
                        <Button size="sm" variant="ghost" icon="heart" onClick={() => setOpinions(a)}>
                          Opiniones{a.comments ? ` (${a.comments})` : ""}
                        </Button>
                      )}
                      {a.status === "published" && (
                        <a href={`/ayuda/${a.slug}`} target="_blank" rel="noopener" className="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-sm font-semibold text-brand hover:bg-brand-soft">
                          <Icon name="globe" size={14} /> Ver
                        </a>
                      )}
                      {owner && a.status !== "published" && (
                        <Button size="sm" variant="ghost" icon="globe" onClick={() => act(a, "publish")}>
                          Publicar
                        </Button>
                      )}
                      {owner && a.status === "published" && (
                        <Button size="sm" variant="ghost" onClick={() => act(a, "archive")}>
                          Quitar del sitio
                        </Button>
                      )}
                      {owner && a.status !== "published" && (
                        <Button size="sm" variant="ghost" onClick={() => act(a, "delete")}>
                          Eliminar
                        </Button>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      </div>

      {editCat && (
        <CategoryModal
          row={editCat === "new" ? null : editCat}
          icons={icons}
          owner={owner}
          onClose={() => setEditCat(null)}
          onDone={(t) => {
            setEditCat(null);
            setToast({ text: t, tone: "ok" });
            load();
          }}
        />
      )}
      {editArt && (
        <ArticleModal
          row={editArt === "new" ? null : editArt}
          cats={cats}
          articles={all}
          defaultCat={cat !== "all" ? cat : cats[0]?.id}
          onClose={() => setEditArt(null)}
          onDone={(t) => {
            setEditArt(null);
            setToast({ text: t, tone: "ok" });
            load();
          }}
        />
      )}
      {opinions && <OpinionsModal a={opinions} onClose={() => setOpinions(null)} />}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function CategoryModal({ row, icons, owner, onClose, onDone }: { row: Category | null; icons: string[]; owner: boolean; onClose: () => void; onDone: (t: string) => void }) {
  const [f, setF] = useState({ name: row?.name ?? "", slug: row?.slug ?? "", icon: row?.icon ?? "book", description: row?.description ?? "", status: row?.status ?? "active", sort_order: row?.sort_order ?? 0 });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(row ? `/internal/site/help/categories/${row.id}` : "/internal/site/help/categories", { method: row ? "PATCH" : "POST", body: { ...f, slug: f.slug || null } });
      onDone(row ? "Tema guardado." : "Tema creado.");
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { name: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  async function remove() {
    if (!row || !window.confirm(`¿Eliminar el tema «${row.name}»?`)) return;
    try {
      await api(`/internal/site/help/categories/${row.id}`, { method: "DELETE" });
      onDone("Tema eliminado.");
    } catch (x) {
      setErrors({ name: [errText(x)] });
    }
  }

  return (
    <Modal
      title={row ? "Editar tema" : "Nuevo tema"}
      icon="book"
      onClose={onClose}
      footer={
        <>
          {row && owner && (
            <Button variant="ghost" className="mr-auto" onClick={remove}>
              Eliminar
            </Button>
          )}
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="help-cat-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="help-cat-form" onSubmit={save} className="space-y-4" noValidate>
        <FormField label="Nombre" error={errors.name?.[0]}>
          <input className="input" maxLength={80} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Primeros pasos" autoFocus />
        </FormField>
        <FormField label="Descripción (opcional)" error={errors.description?.[0]} hint="Una línea que explique qué encuentra la persona.">
          <input className="input" maxLength={200} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="Crea tu cuenta y configura tu empresa" />
        </FormField>
        <div>
          <p className="text-sm font-semibold">Icono</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {icons.map((i) => (
              <button
                key={i}
                type="button"
                onClick={() => setF({ ...f, icon: i })}
                aria-pressed={f.icon === i}
                aria-label={`Icono ${i}`}
                className={`grid h-10 w-10 place-items-center rounded-xl border ${f.icon === i ? "border-brand bg-brand-soft text-brand-strong" : "border-line hover:border-brand/50"}`}
              >
                <Icon name={i} size={18} />
              </button>
            ))}
          </div>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="¿Se ve en el sitio?">
            <select className="input" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value as "active" | "hidden" })}>
              <option value="active">Sí, visible</option>
              <option value="hidden">No, oculto (esconde sus artículos)</option>
            </select>
          </FormField>
          <FormField label="Orden" hint="Los números menores salen primero.">
            <input className="input" type="number" min={0} max={999} value={f.sort_order} onChange={(e) => setF({ ...f, sort_order: Number(e.target.value) || 0 })} />
          </FormField>
        </div>
        <FormField label="Dirección (opcional)" error={errors.slug?.[0]} hint="Si la dejas vacía, se arma con el nombre: /ayuda?categoria=…">
          <input className="input font-mono text-sm" maxLength={80} value={f.slug} onChange={(e) => setF({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "-") })} />
        </FormField>
      </form>
    </Modal>
  );
}

function ArticleModal({ row, cats, articles, defaultCat, onClose, onDone }: { row: Article | null; cats: Category[]; articles: Article[]; defaultCat?: string; onClose: () => void; onDone: (t: string) => void }) {
  const [f, setF] = useState({
    category_id: row?.category_id ?? defaultCat ?? "",
    title: row?.title ?? "",
    slug: row?.slug ?? "",
    body: row?.body ?? "",
    video_url: row?.video_url ?? "",
    screen_code: row?.screen_code ?? "",
    related: row?.related_article_ids ?? [],
    seo_title: row?.seo_title ?? "",
    seo_description: row?.seo_description ?? "",
    sort_order: row?.sort_order ?? 0,
  });
  const [tab, setTab] = useState<"write" | "preview">("write");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const others = useMemo(() => articles.filter((a) => a.id !== row?.id), [articles, row]);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    const body = { ...f, slug: f.slug || null, video_url: f.video_url || null, screen_code: f.screen_code || null };
    try {
      await api(row ? `/internal/site/help/articles/${row.id}` : "/internal/site/help/articles", { method: row ? "PATCH" : "POST", body });
      onDone(row ? (row.status === "published" ? "Guardado: los cambios ya se ven en el sitio." : "Artículo guardado.") : "Artículo creado como borrador. Publícalo cuando esté listo.");
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { title: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  const toggleRel = (id: string) => setF({ ...f, related: f.related.includes(id) ? f.related.filter((x) => x !== id) : f.related.length >= 6 ? f.related : [...f.related, id] });

  return (
    <Modal
      title={row ? "Editar artículo" : "Nuevo artículo"}
      icon="file"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="help-art-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="help-art-form" onSubmit={save} className="space-y-4" noValidate>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_220px]">
          <FormField label="Título" error={errors.title?.[0]}>
            <input className="input" maxLength={140} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder="Cómo invitar a tu equipo" autoFocus />
          </FormField>
          <FormField label="Tema" error={errors.category_id?.[0]}>
            <select className="input" value={f.category_id} onChange={(e) => setF({ ...f, category_id: e.target.value })}>
              {cats.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </FormField>
        </div>

        <div>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm font-semibold">Contenido</p>
            <div className="inline-flex rounded-lg border border-line p-0.5 text-xs font-semibold">
              {(["write", "preview"] as const).map((t) => (
                <button key={t} type="button" onClick={() => setTab(t)} className={`rounded-md px-3 py-1 ${tab === t ? "bg-brand text-white" : "text-muted"}`}>
                  {t === "write" ? "Escribir" : "Vista previa"}
                </button>
              ))}
            </div>
          </div>
          {tab === "write" ? (
            <textarea
              className="input mt-2 min-h-64 font-mono text-[13px] leading-relaxed"
              maxLength={20000}
              value={f.body}
              onChange={(e) => setF({ ...f, body: e.target.value })}
              placeholder={"Explica en pocas líneas qué logra la persona.\n\n## Pasos\n\n- Entra a Usuarios.\n- Pulsa «Invitar».\n- Escribe el correo y elige el rol."}
            />
          ) : (
            <div className="mt-2 min-h-64 rounded-xl border border-line bg-white p-4">{f.body.trim() ? <HelpBody body={f.body} compact /> : <p className="text-sm text-muted">Escribe algo para ver cómo queda.</p>}</div>
          )}
          {errors.body ? (
            <p className="mt-1 text-xs font-medium text-bad">● {errors.body[0]}</p>
          ) : (
            <p className="mt-1 text-xs text-muted">
              Deja una <b>línea vacía</b> entre párrafos. Empieza con <code className="rounded bg-bg px-1">## </code> para un subtítulo y con <code className="rounded bg-bg px-1">- </code> para cada punto de una lista.
            </p>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Video (opcional)" error={errors.video_url?.[0]} hint="Enlace de YouTube o Vimeo. Solo se reproduce si la persona aceptó las cookies.">
            <input className="input" maxLength={300} value={f.video_url} onChange={(e) => setF({ ...f, video_url: e.target.value })} placeholder="https://www.youtube.com/watch?v=…" />
          </FormField>
          <FormField label="Mostrar en la pantalla de la app (opcional)" error={errors.screen_code?.[0]} hint="Sale en el botón «Ayuda» de esa pantalla.">
            <select className="input" value={f.screen_code} onChange={(e) => setF({ ...f, screen_code: e.target.value })}>
              <option value="">Ninguna (solo en /ayuda)</option>
              {Object.entries(APP_SCREENS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l} ({k})
                </option>
              ))}
              {f.screen_code && !APP_SCREENS[f.screen_code] && <option value={f.screen_code}>{f.screen_code}</option>}
            </select>
          </FormField>
        </div>

        {others.length > 0 && (
          <div className="rounded-xl border border-line p-3">
            <p className="text-sm font-semibold">Artículos relacionados (hasta 6)</p>
            <p className="text-xs text-muted">Salen al costado del artículo. Los que estén en borrador no se muestran hasta publicarse.</p>
            {errors.related && <p className="mt-1 text-xs font-medium text-bad">● {errors.related[0]}</p>}
            <div className="mt-2 flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
              {others.map((a) => (
                <button
                  key={a.id}
                  type="button"
                  onClick={() => toggleRel(a.id)}
                  aria-pressed={f.related.includes(a.id)}
                  className={`max-w-full truncate rounded-full border px-3 py-1 text-xs font-semibold ${f.related.includes(a.id) ? "border-brand bg-brand-soft text-brand-strong" : "border-line text-muted hover:border-brand/50"}`}
                >
                  {f.related.includes(a.id) && "✓ "}
                  {a.title}
                </button>
              ))}
            </div>
          </div>
        )}

        <details className="rounded-xl border border-line p-3">
          <summary className="cursor-pointer text-sm font-semibold">Google y dirección (opcional)</summary>
          <p className="mt-2 rounded-lg bg-bg px-3 py-2 text-xs text-muted">
            <b className="text-fg">Puedes dejarlo vacío.</b> La <b className="text-fg">dirección</b> es el final del enlace (escudosst.com/ayuda/<i>dirección</i>); si la cambias en un artículo publicado, el enlace viejo lleva solo al nuevo. El <b className="text-fg">título</b> y la{" "}
            <b className="text-fg">descripción en Google</b> son lo que aparece en los resultados de búsqueda.
          </p>
          <div className="mt-3 space-y-3">
            <FormField label="Dirección" error={errors.slug?.[0]} hint="Si la dejas vacía, se arma con el título.">
              <input className="input font-mono text-sm" maxLength={80} value={f.slug} onChange={(e) => setF({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "-") })} />
            </FormField>
            <FormField label="Título en Google" counter={`${f.seo_title.length}/70`}>
              <input className="input" maxLength={70} value={f.seo_title} onChange={(e) => setF({ ...f, seo_title: e.target.value })} />
            </FormField>
            <FormField label="Descripción en Google" counter={`${f.seo_description.length}/170`}>
              <textarea className="input min-h-16" maxLength={170} value={f.seo_description} onChange={(e) => setF({ ...f, seo_description: e.target.value })} />
            </FormField>
            <FormField label="Orden dentro del tema" hint="Los números menores salen primero.">
              <input className="input w-32" type="number" min={0} max={999} value={f.sort_order} onChange={(e) => setF({ ...f, sort_order: Number(e.target.value) || 0 })} />
            </FormField>
          </div>
        </details>
      </form>
    </Modal>
  );
}

function OpinionsModal({ a, onClose }: { a: Article; onClose: () => void }) {
  const [d, setD] = useState<{ helpful: number; not_helpful: number; comments: { was_helpful: boolean; comment: string; created_at: string }[] } | null>(null);
  useEffect(() => {
    api<{ data: NonNullable<typeof d> }>(`/internal/site/help/articles/${a.id}/feedback`).then((r) => setD(r.data));
  }, [a.id]);
  return (
    <Modal title="Opiniones" subtitle={a.title} icon="heart" onClose={onClose} footer={<Button onClick={onClose}>Cerrar</Button>}>
      {!d ? (
        <div className="h-32 animate-pulse rounded-xl bg-line/40" />
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <div className="rounded-xl bg-ok-soft p-3 text-center">
              <b className="block text-2xl text-ok">{d.helpful}</b>
              <span className="text-xs text-muted">Les sirvió</span>
            </div>
            <div className="rounded-xl bg-bad-soft p-3 text-center">
              <b className="block text-2xl text-bad">{d.not_helpful}</b>
              <span className="text-xs text-muted">No del todo</span>
            </div>
          </div>
          {d.comments.length === 0 ? (
            <p className="text-sm text-muted">Nadie dejó comentarios todavía.</p>
          ) : (
            <ul className="space-y-2">
              {d.comments.map((c, i) => (
                <li key={i} className="rounded-xl border border-line p-3 text-sm">
                  <span className="flex items-center justify-between gap-2 text-xs">
                    <Chip tone={c.was_helpful ? "ok" : "bad"}>{c.was_helpful ? "Le sirvió" : "No del todo"}</Chip>
                    <span className="text-muted">{new Date(c.created_at).toLocaleDateString("es-PE", { timeZone: "America/Lima" })}</span>
                  </span>
                  <p className="mt-2 whitespace-pre-line">{c.comment}</p>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Modal>
  );
}
