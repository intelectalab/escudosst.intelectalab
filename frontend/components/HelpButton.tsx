"use client";

// Ayuda contextual (SCR-WEB-34; prototipo: m-web-extras.js wxAyudaContextual): botón «Ayuda» en la cabecera que abre
// un panel lateral (hoja completa en el celular) con los artículos de la pantalla abierta, los más consultados y un
// buscador; todo se lee dentro del panel. Al pie: ver todos los temas y «Escribir al equipo» (la consulta llega al
// panel de la plataforma; la respuesta se ve aquí, en «Mis consultas»). Solo viaja el código de la pantalla.

import { usePathname } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { HelpBody } from "@/components/site/HelpBody";
import { HelpFeedback } from "@/components/site/HelpFeedback";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import type { HelpArticle, HelpCategory, HelpListItem } from "@/lib/help";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

/** Código SCR-* de la pantalla abierta: cada módulo usa su pantalla principal SCR-<COD>-01. */
export function screenCodeFor(path: string): string | null {
  if (path === "/inicio") return "SCR-DSH-01";
  if (path.startsWith("/cuenta")) return "SCR-USR-05";
  if (path.startsWith("/soporte")) return "SCR-USR-07";
  const m = path.match(/^\/m\/([a-z0-9]{3})/);
  return m ? `SCR-${m[1].toUpperCase()}-01` : null;
}

async function publicGet<T>(path: string): Promise<T | null> {
  try {
    const r = await fetch(`${API}/api/v1/public/${path}`, { headers: { Accept: "application/json" } });
    return r.ok ? ((await r.json()) as { data: T }).data : null;
  } catch {
    return null;
  }
}

type Ticket = { id: string; code: string; topic: string; subject: string; message: string; status: "open" | "answered" | "closed"; reply: string | null; replied_at: string | null; created_at: string };
type View = { k: "home" } | { k: "article"; a: HelpArticle } | { k: "topics" } | { k: "topic"; c: HelpCategory } | { k: "search"; q: string } | { k: "write" } | { k: "mine" };
const TOPICS: [string, string][] = [["question", "Tengo una duda"], ["problem", "Algo no funciona"], ["suggestion", "Una sugerencia"], ["billing", "Mi plan o facturación"]];
const ST: Record<Ticket["status"], [string, string]> = { open: ["Enviada", "bg-amber-soft text-amber-ink"], answered: ["Respondida", "bg-ok-soft text-ok"], closed: ["Cerrada", "bg-bg text-muted"] };
const fmt = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d));

export function HelpButton({ screenCode, screenName }: { screenCode?: string; screenName?: string } = {}) {
  const pathname = usePathname();
  const code = screenCode ?? screenCodeFor(pathname);
  const [open, setOpen] = useState(false);
  const [view, setView] = useState<View>({ k: "home" });
  const [here, setHere] = useState<HelpListItem[] | null>(null);
  const [popular, setPopular] = useState<HelpListItem[]>([]);
  const [list, setList] = useState<HelpListItem[] | null>(null);
  const [cats, setCats] = useState<HelpCategory[] | null>(null);
  const [q, setQ] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;
    setView({ k: "home" });
    setHere(null);
    (code ? api<{ data: HelpListItem[] }>(`/me/help?screen_code=${code}`).then((r) => r.data).catch(() => []) : Promise.resolve([])).then(setHere);
    publicGet<HelpListItem[]>("help/articles").then((all) => setPopular((all ?? []).filter((a) => a.screen_code !== code).slice(0, 3)));
  }, [open, code]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  async function openArticle(slug: string) {
    setBusy(true);
    const a = await publicGet<HelpArticle>(`help/articles/${slug}`);
    setBusy(false);
    if (a) setView({ k: "article", a });
  }
  async function search(e: FormEvent) {
    e.preventDefault();
    const term = q.trim();
    if (!term) return;
    setList(null);
    setView({ k: "search", q: term });
    setList((await publicGet<HelpListItem[]>(`help/articles?q=${encodeURIComponent(term)}`)) ?? []);
  }
  async function showTopics() {
    setView({ k: "topics" });
    if (!cats) setCats((await publicGet<HelpCategory[]>("help/categories")) ?? []);
  }
  async function showTopic(c: HelpCategory) {
    setList(null);
    setView({ k: "topic", c });
    setList((await publicGet<HelpListItem[]>(`help/articles?categoria=${c.slug}`)) ?? []);
  }

  const title = view.k === "article" ? view.a.title : view.k === "topics" ? "Todos los temas" : view.k === "topic" ? view.c.name : view.k === "search" ? "Resultados" : view.k === "write" ? "Escribir al equipo" : view.k === "mine" ? "Mis consultas" : "Ayuda";
  const back = view.k === "home" ? null : () => (view.k === "topic" ? setView({ k: "topics" }) : setView({ k: "home" }));

  const articles = (rows: HelpListItem[] | null, empty: string) =>
    rows === null ? (
      <div className="h-24 animate-pulse rounded-lg bg-line/40" />
    ) : rows.length === 0 ? (
      <p className="rounded-lg border border-dashed border-line px-4 py-3 text-sm text-muted">{empty}</p>
    ) : (
      <ul className="divide-y divide-line overflow-hidden rounded-lg border border-line">
        {rows.map((a) => (
          <li key={a.slug}>
            <button onClick={() => openArticle(a.slug)} className="flex w-full items-center gap-3 bg-surface px-3 py-2.5 text-left hover:bg-bg">
              <Icon name={a.video_url ? "play" : "file"} size={17} className="shrink-0 text-brand" />
              <span className="min-w-0 flex-1">
                <b className="block text-sm">{a.title}</b>
                <span className="block truncate text-xs text-muted">{a.excerpt}</span>
              </span>
              <Icon name="chevron" size={15} className="shrink-0 text-muted" />
            </button>
          </li>
        ))}
      </ul>
    );

  return (
    <>
      <button onClick={() => setOpen(true)} className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-bg" aria-haspopup="dialog" title="Ayuda para esta pantalla">
        <Icon name="help" size={17} /> <span className="hidden lg:inline">Ayuda</span>
      </button>
      {open && (
        <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Ayuda">
          <button className="absolute inset-0 bg-[#00254d]/25 md:bg-[#00254d]/10" aria-label="Cerrar ayuda" onClick={() => setOpen(false)} />
          <aside className="relative flex h-full w-full flex-col bg-surface shadow-2xl md:w-[440px] md:border-l md:border-line">
            <header className="flex items-start gap-2 border-b border-line px-4 py-3">
              {back && (
                <button onClick={back} className="grid h-9 w-9 shrink-0 place-items-center rounded-lg hover:bg-bg" aria-label="Volver">
                  <Icon name="back" size={18} />
                </button>
              )}
              <div className="min-w-0 flex-1">
                <p className="truncate font-display text-lg font-semibold">{title}</p>
                {view.k === "home" && <p className="text-xs text-muted">Artículos del centro de ayuda para esta pantalla</p>}
              </div>
              <button onClick={() => setOpen(false)} className="grid h-9 w-9 shrink-0 place-items-center rounded-lg hover:bg-bg" aria-label="Cerrar">
                <Icon name="close" size={18} />
              </button>
            </header>

            <div className="flex-1 space-y-4 overflow-y-auto p-4">
              {busy && <div className="h-24 animate-pulse rounded-lg bg-line/40" />}
              {!busy && view.k === "home" && (
                <>
                  {screenName && <p className="text-xs text-muted">Pantalla actual: <b className="text-fg">{screenName}</b></p>}
                  <form onSubmit={search} role="search" className="flex gap-2">
                    <label className="relative min-w-0 flex-1">
                      <span className="sr-only">Buscar en la ayuda</span>
                      <Icon name="search" size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
                      <input className="input pl-9" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100} placeholder="Buscar en la ayuda" />
                    </label>
                    <button type="submit" className="rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-strong">Buscar</button>
                  </form>
                  <section className="space-y-2">
                    <h3 className="text-xs font-bold uppercase tracking-wide text-muted">Para esta pantalla</h3>
                    {articles(here, "Aún no hay artículos para esta pantalla. Revisa los más consultados o busca en la ayuda.")}
                  </section>
                  {popular.length > 0 && (
                    <section className="space-y-2">
                      <h3 className="text-xs font-bold uppercase tracking-wide text-muted">Más consultados</h3>
                      {articles(popular, "")}
                    </section>
                  )}
                </>
              )}
              {!busy && view.k === "article" && (
                <>
                  <HelpBody body={view.a.body} compact />
                  {view.a.video_url && (
                    <a href={view.a.video_url} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand hover:underline">
                      <Icon name="play" size={15} /> Ver el video
                    </a>
                  )}
                  <HelpFeedback slug={view.a.slug} compact />
                </>
              )}
              {!busy && view.k === "search" && articles(list, `No encontramos artículos sobre «${view.q}». Prueba con otras palabras o escribe al equipo.`)}
              {!busy && view.k === "topics" && (cats === null ? <div className="h-24 animate-pulse rounded-lg bg-line/40" /> : (
                <ul className="divide-y divide-line overflow-hidden rounded-lg border border-line">
                  {cats.map((c) => (
                    <li key={c.slug}>
                      <button onClick={() => showTopic(c)} className="flex w-full items-center gap-3 bg-surface px-3 py-2.5 text-left hover:bg-bg">
                        <Icon name={c.icon ?? "book"} size={17} className="shrink-0 text-brand" />
                        <span className="min-w-0 flex-1"><b className="block text-sm">{c.name}</b>{c.description && <span className="block truncate text-xs text-muted">{c.description}</span>}</span>
                        <span className="text-xs text-muted">{c.articles_count}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              ))}
              {!busy && view.k === "topic" && articles(list, "Este tema todavía no tiene artículos.")}
              {view.k === "write" && <WriteForm page={pathname} onSent={() => setView({ k: "mine" })} />}
              {view.k === "mine" && <MyTickets />}
            </div>

            <footer className="grid grid-cols-2 gap-2 border-t border-line p-3">
              <button onClick={showTopics} className="flex items-center justify-center gap-2 rounded-lg border border-line px-3 py-2.5 text-sm font-semibold hover:bg-bg">
                <Icon name="book" size={16} /> Todos los temas
              </button>
              <button onClick={() => setView({ k: "write" })} className="flex items-center justify-center gap-2 rounded-lg bg-brand px-3 py-2.5 text-sm font-semibold text-white hover:bg-brand-strong">
                <Icon name="chat" size={16} /> Escribir al equipo
              </button>
              <button onClick={() => setView({ k: "mine" })} className="col-span-2 text-center text-xs font-semibold text-brand hover:underline">
                Ver mis consultas
              </button>
            </footer>
          </aside>
        </div>
      )}
    </>
  );
}

/** Formulario para escribir al equipo de EscudoSST. */
function WriteForm({ page, onSent }: { page: string; onSent: () => void }) {
  const [topic, setTopic] = useState("question");
  const [subject, setSubject] = useState("");
  const [message, setMessage] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [sending, setSending] = useState(false);
  async function send(e: FormEvent) {
    e.preventDefault();
    setSending(true);
    setErrors({});
    try {
      await api("/me/support-tickets", { method: "POST", withCompany: true, body: { topic, subject, message, page } });
      onSent();
    } catch (err) {
      setErrors((err as ApiError).errors ?? { message: [(err as ApiError).detail ?? "No se pudo enviar."] });
    } finally {
      setSending(false);
    }
  }
  return (
    <form onSubmit={send} className="space-y-3">
      <p className="text-sm text-muted">Tu consulta llega al equipo de EscudoSST. Te respondemos aquí, en «Mis consultas». No escribas datos médicos.</p>
      <label className="block space-y-1 text-sm font-semibold">Tema
        <select className="input" value={topic} onChange={(e) => setTopic(e.target.value)}>
          {TOPICS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </label>
      <label className="block space-y-1 text-sm font-semibold">Asunto
        <input className="input" value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={120} placeholder="Ej.: ¿Cómo cargo a mis trabajadores desde Excel?" />
        {errors.subject && <span className="block text-xs font-normal text-bad">{errors.subject[0]}</span>}
      </label>
      <label className="block space-y-1 text-sm font-semibold">Tu consulta
        <textarea className="input min-h-[8rem] py-2" value={message} onChange={(e) => setMessage(e.target.value)} maxLength={4000} />
        {errors.message && <span className="block text-xs font-normal text-bad">{errors.message[0]}</span>}
      </label>
      <button type="submit" disabled={sending} className="w-full rounded-lg bg-brand py-2.5 text-sm font-semibold text-white hover:bg-brand-strong disabled:opacity-60">
        {sending ? "Enviando…" : "Enviar consulta"}
      </button>
    </form>
  );
}

/** Consultas que la persona envió en la empresa activa, con la respuesta del equipo. */
function MyTickets() {
  const [rows, setRows] = useState<Ticket[] | null>(null);
  useEffect(() => {
    api<{ data: Ticket[] }>("/me/support-tickets", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows([]));
  }, []);
  if (rows === null) return <div className="h-24 animate-pulse rounded-lg bg-line/40" />;
  if (rows.length === 0) return <p className="rounded-lg border border-dashed border-line px-4 py-3 text-sm text-muted">Aún no has escrito al equipo.</p>;
  return (
    <ul className="space-y-2">
      {rows.map((t) => (
        <li key={t.id} className="space-y-2 rounded-lg border border-line p-3">
          <div className="flex items-center gap-2 text-xs">
            <span className="font-semibold text-muted">{t.code}</span>
            <span className={`rounded-full px-2 py-0.5 font-semibold ${ST[t.status][1]}`}>{ST[t.status][0]}</span>
            <span className="ml-auto text-muted">{fmt(t.created_at)}</span>
          </div>
          <p className="text-sm font-semibold">{t.subject}</p>
          <p className="whitespace-pre-line text-sm text-muted">{t.message}</p>
          {t.reply && (
            <div className="rounded-lg bg-brand-soft/60 p-3 text-sm">
              <p className="mb-1 text-xs font-bold text-brand-strong">Respuesta del equipo{t.replied_at ? ` · ${fmt(t.replied_at)}` : ""}</p>
              <p className="whitespace-pre-line">{t.reply}</p>
            </div>
          )}
        </li>
      ))}
    </ul>
  );
}
