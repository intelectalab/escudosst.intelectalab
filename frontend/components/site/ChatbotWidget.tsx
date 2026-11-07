"use client";

// Chatbot del sitio (SCR-WEB-59, RN-WEB-75 a 77): botón flotante encima del de WhatsApp (no lo tapa), panel de
// 380 px en escritorio y hoja completa en el celular. Respuestas guiadas (las que escribe el dueño), botones de
// acción, datos de contacto con privacidad y casilla comercial aparte, y valoración al cerrar. Esc cierra.

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { FormEvent, useEffect, useRef, useState } from "react";
import { type Card, ChatCard, type CardItem } from "@/components/site/ChatbotCards";
import { Icon } from "@/components/ui/Icon";
import { assetUrl } from "@/lib/site";
import { track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const STORE = "escudo.chat";

type Intent = { id: string; name: string; answer: string; cta_type: string; cta_url: string | null };
type Config = { result: string; greeting: string; is_open: boolean; offline_message: string | null; handoff_whatsapp: boolean; whatsapp_number: string | null; avatar_file_id: string | null; avatar_alt_text: string | null; intents: Intent[] };
type Msg = { from: "bot" | "me"; text: string; cta?: { type: string; url: string | null }; card?: Card | null };

/** Atajos según la página donde se abre el chat. */
function shortcuts(path: string): [string, string][] {
  const base: [string, string][] = [
    ["¿Cuánto cuesta?", "precios"],
    ["Quiero una demo", "demo"],
    ["Plantillas gratis", "recursos"],
  ];
  if (path.startsWith("/planes")) return [["¿Qué incluye cada plan?", "precios"], ["Quiero una cotización", "precios"], ["Quiero una demo", "demo"]];
  if (path.startsWith("/demo")) return [["¿Qué horarios hay?", "demo"], ["¿Cuánto cuesta?", "precios"], ["Hablar con alguien", "contacto"]];
  if (path.startsWith("/herramientas")) return [["¿Cuánto es una multa?", "multa"], ["¿Estoy cumpliendo la ley?", "autodiagnostico"], ["Quiero una demo", "demo"]];
  if (path.startsWith("/ayuda")) return [["Hablar con alguien", "contacto"], ["¿Está caído el sistema?", "estado"], ["Quiero una demo", "demo"]];
  return base;
}

const CTA: Record<string, [string, string]> = {
  demo: ["Agendar una demo", "/demo/agendar"],
  calculator: ["Abrir la calculadora", "/herramientas/calculadora-multas"],
  assessment: ["Hacer el autodiagnóstico", "/herramientas/autodiagnostico"],
};

async function post<T>(path: string, body: object): Promise<{ ok: boolean; status: number; data: T }> {
  const r = await fetch(`${API}/api/v1/public/chatbot/${path}`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify(body) });
  const j = await r.json().catch(() => ({}));
  return { ok: r.ok, status: r.status, data: (j.data ?? j) as T };
}

export function ChatbotWidget({ hasWhatsApp }: { hasWhatsApp: boolean }) {
  const pathname = usePathname();
  const router = useRouter();
  const [cfg, setCfg] = useState<Config | null>(null);
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState<Msg[]>([]);
  const [conv, setConv] = useState<{ id: string; session: string } | null>(null);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [askLead, setAskLead] = useState(false);
  const [lead, setLead] = useState({ full_name: "", email: "", phone: "", company_name: "", website: "" });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [leadState, setLeadState] = useState<"idle" | "sent">("idle");
  const [leadErr, setLeadErr] = useState<Record<string, string[]>>({});
  const [rated, setRated] = useState(false);
  const bottom = useRef<HTMLDivElement>(null);
  const panel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    fetch(`${API}/api/v1/public/chatbot`, { headers: { Accept: "application/json" } })
      .then((r) => r.json())
      .then((j) => j?.data?.result === "ok" && setCfg(j.data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    bottom.current?.scrollIntoView({ block: "end" });
  }, [msgs, askLead, open]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
      if (e.key === "Tab" && panel.current) {
        const f = panel.current.querySelectorAll<HTMLElement>("button, a, input, textarea, [tabindex]:not([tabindex='-1'])");
        if (!f.length) return;
        const first = f[0];
        const last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  // En el panel, la aplicación y los enlaces personales no se muestra.
  if (!cfg || /^\/(plataforma|inicio|m\/|cuenta|soporte|login|demo\/gestionar|boletin\/(confirmar|baja))/.test(pathname)) return null;

  async function ensureConv(): Promise<{ id: string; session: string } | null> {
    if (conv) return conv;
    try {
      const saved = JSON.parse(sessionStorage.getItem(STORE) ?? "null") as { id: string; session: string } | null;
      if (saved) return (setConv(saved), saved);
    } catch {
      /* nada */
    }
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    const session = [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
    const r = await post<{ conversation_id: string }>("start", { session, path: pathname });
    if (!r.ok) return null;
    const c = { id: r.data.conversation_id, session };
    sessionStorage.setItem(STORE, JSON.stringify(c));
    setConv(c);
    return c;
  }

  function openChat() {
    setOpen(true);
    if (!msgs.length) {
      setMsgs([{ from: "bot", text: cfg!.greeting }, ...(cfg!.is_open || !cfg!.offline_message ? [] : [{ from: "bot" as const, text: cfg!.offline_message }])]);
      track("chatbot_open");
    }
  }

  async function send(body: string, intentId?: string, ask?: string) {
    if (!body.trim() || busy) return;
    setBusy(true);
    setMsgs((m) => [...m, { from: "me", text: body }]);
    setText("");
    const c = await ensureConv();
    if (!c) {
      setMsgs((m) => [...m, { from: "bot", text: "No pude conectarme. Inténtalo en un momento o escríbenos por WhatsApp." }]);
      return setBusy(false);
    }
    let r = await post<{ reply: string; cta_type: string; cta_url: string | null; ask_lead: boolean; card: Card | null }>("message", { conversation_id: c.id, session: c.session, body, intent_id: intentId ?? null, ask: ask ?? null });
    if (r.status === 404) {
      sessionStorage.removeItem(STORE);
      setConv(null);
      const c2 = await ensureConv();
      if (c2) r = await post("message", { conversation_id: c2.id, session: c2.session, body, intent_id: intentId ?? null, ask: ask ?? null });
    }
    if (r.ok) {
      setMsgs((m) => [...m, { from: "bot", text: r.data.reply, card: r.data.card, cta: r.data.cta_type && r.data.cta_type !== "none" ? { type: r.data.cta_type, url: r.data.cta_url } : undefined }]);
      if (r.data.ask_lead && leadState === "idle") setAskLead(true);
    } else {
      setMsgs((m) => [...m, { from: "bot", text: r.status === 429 ? "Vamos muy rápido. Espera un momento, por favor." : "No pude responder. Inténtalo otra vez." }]);
    }
    setBusy(false);
  }

  async function pick(it: CardItem, term: string) {
    const c = conv;
    if (c && term && it.target_type && it.target_key) {
      post("learn", { conversation_id: c.id, session: c.session, term, target_type: it.target_type, target_key: it.target_key }).catch(() => {});
    }
    if (it.answer) {
      setMsgs((m) => [...m, { from: "me", text: it.title ?? "" }, { from: "bot", text: it.answer!, card: { type: "source", label: "Fuente: Preguntas frecuentes", href: "/preguntas-frecuentes" } }]);
    } else if (it.href) {
      setOpen(false);
      router.push(it.href);
    }
  }

  async function sendLead(e: FormEvent) {
    e.preventDefault();
    const c = await ensureConv();
    if (!c) return;
    const r = await post<{ errors?: Record<string, string[]> }>("lead", { conversation_id: c.id, session: c.session, ...lead, phone: lead.phone || null, company_name: lead.company_name || null, privacy_consent: privacy, marketing_consent: marketing });
    if (r.ok) {
      setLeadState("sent");
      setAskLead(false);
      track("chatbot_lead");
      setMsgs((m) => [...m, { from: "bot", text: "¡Gracias! Te escribiremos pronto." }]);
    } else setLeadErr((r.data as { errors?: Record<string, string[]> }).errors ?? { email: ["No se pudo enviar."] });
  }

  async function rate(n: number) {
    setRated(true);
    if (conv) await post("rate", { conversation_id: conv.id, session: conv.session, rating: n });
  }

  const wa = cfg.whatsapp_number ? `https://wa.me/${cfg.whatsapp_number}?text=${encodeURIComponent("Hola, vengo del chat del sitio de EscudoSST")}` : null;
  const onWhatsApp = () => conv && post("handoff", { conversation_id: conv.id, session: conv.session });
  const ctaLink = (c: { type: string; url: string | null }) => {
    if (c.type === "whatsapp") return wa ? (
      <a href={wa} target="_blank" rel="noopener noreferrer" onClick={onWhatsApp} className="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-[#22A35A] px-3 py-1.5 text-xs font-bold text-white">
        Escribir por WhatsApp
      </a>
    ) : null;
    const [label, href] = c.type === "link" ? ["Ver más", c.url ?? "/"] : (CTA[c.type] ?? [null, null]);
    if (!href) return null;
    return href.startsWith("/") ? (
      <Link href={href} onClick={() => setOpen(false)} className="mt-2 inline-flex rounded-lg bg-[var(--site-action)] px-3 py-1.5 text-xs font-bold text-white">
        {label}
      </Link>
    ) : (
      <a href={href} target="_blank" rel="noopener noreferrer" className="mt-2 inline-flex rounded-lg bg-[var(--site-action)] px-3 py-1.5 text-xs font-bold text-white">
        {label}
      </a>
    );
  };
  const avatar = cfg.avatar_file_id ? assetUrl(cfg.avatar_file_id) : null;

  return (
    <>
      {!open && (
        <button
          onClick={openChat}
          aria-label="Abrir el chat"
          className={`fixed right-5 z-40 grid h-14 w-14 place-items-center rounded-full bg-[var(--site-action)] text-white shadow-[0_10px_28px_-6px_rgba(31,127,209,.6)] transition-transform hover:-translate-y-0.5 sm:right-6 ${hasWhatsApp ? "bottom-[88px] sm:bottom-[96px]" : "bottom-5 sm:bottom-6"}`}
        >
          <Icon name="help" size={26} />
        </button>
      )}
      {open && (
        <div
          ref={panel}
          role="dialog"
          aria-modal="true"
          aria-label="Chat de EscudoSST"
          className="fixed inset-0 z-50 flex flex-col bg-white sm:inset-auto sm:bottom-6 sm:right-6 sm:h-[560px] sm:max-h-[calc(100vh-48px)] sm:w-[380px] sm:overflow-hidden sm:rounded-2xl sm:shadow-2xl"
        >
          <header className="flex items-center gap-3 bg-gradient-to-r from-[#0D3B6E] to-[#1F7FD1] px-4 py-3 text-white">
            <span className="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-white/15">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              {avatar ? <img src={avatar} alt={cfg.avatar_alt_text ?? ""} className="h-full w-full object-cover" /> : <Icon name="help" size={20} />}
            </span>
            <span className="min-w-0 flex-1">
              <b className="block font-display">Asistente de EscudoSST</b>
              <span className="flex items-center gap-1.5 text-xs text-white/80">
                <span className={`h-2 w-2 rounded-full ${cfg.is_open ? "bg-[#4ADE80]" : "bg-white/50"}`} /> {cfg.is_open ? "En horario de atención" : "Fuera de horario"}
              </span>
            </span>
            <button onClick={() => setOpen(false)} aria-label="Cerrar el chat" className="grid h-11 w-11 place-items-center rounded-lg hover:bg-white/10">
              <Icon name="close" size={20} />
            </button>
          </header>

          <div className="flex-1 space-y-3 overflow-y-auto bg-[#f5f8fc] p-4">
            {msgs.map((m, i) => (
              <div key={i} className={`flex ${m.from === "me" ? "justify-end" : "justify-start"}`}>
                <div className={`max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm ${m.from === "me" ? "rounded-br-md bg-[var(--site-action)] text-white" : "rounded-bl-md bg-white text-[var(--site-text)] shadow-sm"}`}>
                  <p className="whitespace-pre-line">{m.text}</p>
                  {m.card && <ChatCard card={m.card} whatsapp={wa} onGo={() => setOpen(false)} onAsk={(t) => send(t)} onPick={pick} />}
                  {m.cta && ctaLink(m.cta)}
                </div>
              </div>
            ))}
            {busy && <p className="text-xs text-[var(--site-text-2)]">Escribiendo…</p>}

            {msgs.length <= 2 && (
              <div className="flex flex-wrap gap-2">
                {shortcuts(pathname).map(([label, ask]) => (
                  <button key={label} onClick={() => send(label, undefined, ask)} className="rounded-full border border-[var(--site-action)]/30 bg-white px-3 py-1.5 text-xs font-semibold text-[var(--site-action)] hover:bg-[#EAF3FC]">
                    {label}
                  </button>
                ))}
                {cfg.intents.slice(0, Math.max(0, 4 - shortcuts(pathname).length)).map((it) => (
                  <button key={it.id} onClick={() => send(it.name, it.id)} className="rounded-full border border-[var(--site-action)]/30 bg-white px-3 py-1.5 text-xs font-semibold text-[var(--site-action)] hover:bg-[#EAF3FC]">
                    {it.name}
                  </button>
                ))}
              </div>
            )}

            {askLead && leadState === "idle" && (
              <form onSubmit={sendLead} className="relative space-y-2 rounded-2xl bg-white p-3 shadow-sm" noValidate>
                <p className="text-sm font-semibold text-[var(--site-navy-title)]">¿Te contactamos? Déjanos tus datos.</p>
                <input aria-hidden="true" tabIndex={-1} className="absolute -left-[9999px]" value={lead.website} onChange={(e) => setLead({ ...lead, website: e.target.value })} />
                {(
                  [
                    ["full_name", "Nombre", "name", "text"],
                    ["email", "Correo", "email", "email"],
                    ["phone", "Celular (opcional)", "tel", "tel"],
                    ["company_name", "Empresa (opcional)", "organization", "text"],
                  ] as const
                ).map(([k, ph, ac, type]) => (
                  <div key={k}>
                    <input className="input h-10 text-sm" type={type} placeholder={ph} autoComplete={ac} maxLength={160} value={lead[k]} onChange={(e) => setLead({ ...lead, [k]: e.target.value })} />
                    {leadErr[k] && <p className="mt-0.5 text-xs text-[#A3271F]">{leadErr[k][0]}</p>}
                  </div>
                ))}
                <label className="flex items-start gap-2 text-xs text-[var(--site-text-2)]">
                  <input type="checkbox" className="mt-0.5 accent-[var(--site-action)]" checked={privacy} onChange={(e) => setPrivacy(e.target.checked)} />
                  <span>
                    Acepto la{" "}
                    <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
                      Política de privacidad
                    </Link>
                    .
                  </span>
                </label>
                {leadErr.privacy_consent && <p className="text-xs text-[#A3271F]">{leadErr.privacy_consent[0]}</p>}
                <label className="flex items-start gap-2 text-xs text-[var(--site-text-2)]">
                  <input type="checkbox" className="mt-0.5 accent-[var(--site-action)]" checked={marketing} onChange={(e) => setMarketing(e.target.checked)} />
                  <span>Quiero recibir novedades y ofertas (opcional).</span>
                </label>
                <div className="flex gap-2">
                  <button type="submit" className="site-btn site-btn-primary min-h-10 flex-1 !py-2 text-sm">
                    Enviar
                  </button>
                  <button type="button" onClick={() => setAskLead(false)} className="rounded-lg px-3 text-xs font-semibold text-[var(--site-text-2)]">
                    Ahora no
                  </button>
                </div>
              </form>
            )}
            <div ref={bottom} />
          </div>

          <div className="border-t border-[#e3e8ef] bg-white p-3">
            {msgs.length > 3 && !rated && (
              <div className="mb-2 flex items-center justify-center gap-1 text-xs text-[var(--site-text-2)]">
                ¿Te ayudé?
                {[1, 2, 3, 4, 5].map((n) => (
                  <button key={n} onClick={() => rate(n)} aria-label={`${n} de 5`} className="grid h-8 w-8 place-items-center rounded-full text-[#F4B90F] hover:bg-[#FFF5D6]">
                    <Icon name="star" size={16} />
                  </button>
                ))}
              </div>
            )}
            {rated && <p className="mb-2 text-center text-xs text-[#17703e]">¡Gracias por tu opinión!</p>}
            <form
              onSubmit={(e) => {
                e.preventDefault();
                send(text);
              }}
              className="flex gap-2"
            >
              <label className="min-w-0 flex-1">
                <span className="sr-only">Escribe tu pregunta</span>
                <input className="input h-11" placeholder="Escribe tu pregunta" maxLength={500} value={text} onChange={(e) => setText(e.target.value)} />
              </label>
              <button type="submit" disabled={busy || !text.trim()} aria-label="Enviar" className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--site-action)] text-white disabled:opacity-50">
                <Icon name="chevron" size={18} />
              </button>
            </form>
            {cfg.handoff_whatsapp && wa && (
              <a href={wa} target="_blank" rel="noopener noreferrer" onClick={onWhatsApp} className="mt-2 block text-center text-xs font-semibold text-[#17703e] hover:underline">
                Prefiero hablar por WhatsApp
              </a>
            )}
          </div>
        </div>
      )}
    </>
  );
}
