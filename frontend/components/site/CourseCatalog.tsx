"use client";

// Catálogo público de cursos de una empresa (VTA): tarjetas con búsqueda y filtro por categoría (SCR-VTA-12) y
// solicitud de compra para una persona o cupos para una empresa (SCR-VTA-16). El pago es manual: el pedido queda
// pendiente y la empresa lo confirma al recibir el pago; aquí no se piden datos de tarjeta.

import Link from "next/link";
import { FormEvent, useMemo, useState } from "react";
import { Alert, Check, Done, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { type CatalogOffer, MODALITY, soles } from "@/lib/courseCatalog";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
type Errors = Record<string, string[]>;

/** El catálogo existe pero no se puede mostrar (VTA desactivado) o la API no respondió. */
export function CatalogUnavailable({ reason }: { reason: "unavailable" | "error" }) {
  return (
    <section className="px-4 py-20 lg:px-8">
      <div className="site-card mx-auto max-w-lg p-10 text-center">
        <Icon name={reason === "error" ? "alert" : "clock"} size={30} className="mx-auto text-[var(--site-text-2)]" />
        <h1 className="mt-4 font-display text-2xl font-bold text-[var(--site-navy-title)]">
          {reason === "error" ? "No pudimos cargar el catálogo" : "Catálogo no disponible"}
        </h1>
        <p className="mt-2 text-[var(--site-text-2)]">
          {reason === "error" ? "Inténtalo de nuevo en unos minutos." : "Este catálogo de cursos no está disponible por ahora."}
        </p>
      </div>
    </section>
  );
}

export function OfferCard({ catalog, o }: { catalog: string; o: CatalogOffer }) {
  const soldOut = o.seats_left !== null && o.seats_left <= 0;
  return (
    <Link href={`/cursos/${catalog}/${o.slug}`} className="site-card group flex flex-col gap-3 p-6 transition-shadow hover:shadow-lg">
      <span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--site-surface)] text-[var(--site-action)]">
        <Icon name="graduation" size={22} />
      </span>
      {o.category && <span className="text-xs font-semibold uppercase tracking-wide text-[var(--site-text-2)]">{o.category}</span>}
      <b className="font-display text-lg leading-snug text-[var(--site-navy-title)] group-hover:underline">{o.title}</b>
      {o.short_description && <p className="line-clamp-3 text-sm text-[var(--site-text-2)]">{o.short_description}</p>}
      <span className="text-sm text-[var(--site-text-2)]">
        {[o.hours ? `${o.hours} horas` : null, MODALITY[o.delivery_mode], o.certificate ? "Certificado verificable" : null].filter(Boolean).join(" · ")}
      </span>
      <div className="mt-auto flex items-end justify-between gap-3 pt-2">
        <span>
          {o.sale_price !== null && o.sale_price < o.list_price && <s className="mr-2 text-sm text-[var(--site-text-2)]">{soles(o.list_price)}</s>}
          <b className="font-display text-xl text-[var(--site-navy-title)]">{soles(o.price)}</b>
        </span>
        {soldOut ? (
          <span className="text-xs font-semibold text-[var(--site-red)]">Sin cupos</span>
        ) : (
          o.seats_left !== null && <span className="text-xs text-[var(--site-text-2)]">Quedan {o.seats_left} cupos</span>
        )}
      </div>
    </Link>
  );
}

export function CatalogGrid({ catalog, offers }: { catalog: string; offers: CatalogOffer[] }) {
  const [q, setQ] = useState("");
  const [cat, setCat] = useState<string | null>(null);
  const cats = useMemo(() => Array.from(new Set(offers.map((o) => o.category).filter((c): c is string => !!c))).sort(), [offers]);
  const norm = (s: string) => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  const list = offers.filter((o) => (!cat || o.category === cat) && (!q.trim() || norm(`${o.title} ${o.short_description ?? ""}`).includes(norm(q.trim()))));

  if (!offers.length)
    return <p className="site-card p-8 text-center text-[var(--site-text-2)]">Por ahora no hay cursos a la venta. Vuelve pronto.</p>;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <label className="relative block sm:w-80">
          <span className="sr-only">Buscar curso</span>
          <Icon name="search" size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--site-text-2)]" />
          <input className="input !pl-9" placeholder="Buscar curso" value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
        {cats.length > 1 && (
          <div className="flex flex-wrap gap-2" role="group" aria-label="Categoría">
            {[null, ...cats].map((c) => (
              <button
                key={c ?? "all"}
                type="button"
                aria-pressed={cat === c}
                onClick={() => setCat(c)}
                className={`rounded-full border px-4 py-1.5 text-sm font-semibold ${cat === c ? "border-[var(--site-action)] bg-[var(--site-action)] text-white" : "border-[var(--site-border)] bg-white text-[var(--site-navy-title)]"}`}
              >
                {c ?? "Todos"}
              </button>
            ))}
          </div>
        )}
      </div>
      {list.length ? (
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {list.map((o) => (
            <OfferCard key={o.slug} catalog={catalog} o={o} />
          ))}
        </div>
      ) : (
        <p className="site-card p-8 text-center text-[var(--site-text-2)]">Ningún curso coincide con tu búsqueda.</p>
      )}
    </div>
  );
}

type Offer = { slug: string; price: number; allows_company_purchase: boolean; min_company_seats: number | null; seats_left: number | null };
type Placed = { order_number: string; total: number; expires_at: string | null; payment_instructions: string | null };

const onlyDigits = (v: string, max: number) => v.replace(/\D/g, "").slice(0, max);

export function CoursePurchaseForm({ catalog, company, offer }: { catalog: string; company: string; offer: Offer }) {
  const minSeats = Math.max(2, offer.min_company_seats ?? 2);
  const [buyer, setBuyer] = useState<"person" | "company">("person");
  const [f, setF] = useState({ document_type: "DNI", document_number: "", first_name: "", last_name: "", legal_name: "", ruc: "", email: "", phone: "", seats: String(minSeats), website: "" });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [key] = useState(() => (typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : undefined));
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [placed, setPlaced] = useState<Placed | null>(null);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];
  const isCompany = buyer === "company";
  const qty = isCompany ? Math.max(1, Number(f.seats) || 0) : 1;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    const body = isCompany
      ? { buyer_type: "company", legal_name: f.legal_name, ruc: f.ruc, email: f.email, phone: f.phone || null, seats: qty }
      : { buyer_type: "person", document_type: f.document_type, document_number: f.document_number, first_name: f.first_name, last_name: f.last_name, email: f.email, phone: f.phone || null };
    try {
      const res = await fetch(`${API}/api/v1/public/courses/${encodeURIComponent(catalog)}/${encodeURIComponent(offer.slug)}/order`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ ...body, privacy_consent: privacy, marketing_consent: marketing, idempotency_key: key, website: f.website || undefined }),
      });
      const j = await res.json().catch(() => ({}));
      if (res.ok) {
        setPlaced(j.data as Placed);
        setErrors({});
      } else if (res.status === 429) {
        setMessage("Hiciste varios intentos seguidos. Espera unos minutos e inténtalo otra vez.");
      } else {
        setErrors((j.errors ?? {}) as Errors);
        setMessage(j.errors ? "Revisa los campos marcados." : (j.detail ?? "No pudimos registrar tu pedido. Inténtalo otra vez."));
      }
    } catch {
      setMessage("No hay conexión. Revisa tu internet e inténtalo otra vez.");
    }
    setSaving(false);
  }

  if (placed)
    return (
      <Done icon="receipt" title="Recibimos tu pedido">
        <p>
          Pedido <b className="font-mono text-[var(--site-navy-title)]">{placed.order_number}</b> por <b className="text-[var(--site-navy-title)]">{soles(placed.total)}</b>, pendiente de pago.
        </p>
        {placed.payment_instructions ? (
          <div className="rounded-xl bg-[var(--site-surface)] p-4 text-left">
            <b className="mb-1 block text-[var(--site-navy-title)]">Cómo pagar</b>
            <p className="whitespace-pre-line">{placed.payment_instructions}</p>
            <p className="mt-2 text-xs">Al pagar, indica tu número de pedido.</p>
          </div>
        ) : (
          <p>
            {company} se comunicará contigo por correo o teléfono para indicarte cómo pagar (Yape, Plin o transferencia). Ten a mano tu número de pedido.
          </p>
        )}
        <p>{isCompany ? "Cuando se confirme el pago, recibirás tus cupos para asignarlos a tus trabajadores." : "Cuando se confirme el pago, te matricularemos y te llegará el acceso al curso por correo."}</p>
        {placed.expires_at && (
          <p className="text-xs">
            El pedido reserva tu cupo hasta el {new Intl.DateTimeFormat("es-PE", { dateStyle: "long", timeZone: "America/Lima" }).format(new Date(placed.expires_at))}.
          </p>
        )}
      </Done>
    );

  return (
    <form onSubmit={submit} noValidate className="space-y-4">
      <Honeypot value={f.website} onChange={(v) => set("website", v)} />
      {offer.allows_company_purchase && (
        <div className="grid grid-cols-2 gap-2" role="group" aria-label="¿Para quién es la compra?">
          {(["person", "company"] as const).map((b) => (
            <button
              key={b}
              type="button"
              aria-pressed={buyer === b}
              onClick={() => { setBuyer(b); setErrors({}); }}
              className={`rounded-xl border px-3 py-2.5 text-sm font-semibold ${buyer === b ? "border-[var(--site-action)] bg-[var(--site-surface)] text-[var(--site-navy-title)]" : "border-[var(--site-border)] bg-white text-[var(--site-text-2)]"}`}
            >
              {b === "person" ? "Para mí" : "Cupos para mi empresa"}
            </button>
          ))}
        </div>
      )}
      <Alert text={message} />
      {isCompany ? (
        <>
          <Field label="Razón social" error={err("legal_name")}>
            <input className="input" maxLength={160} autoComplete="organization" value={f.legal_name} onChange={(e) => set("legal_name", e.target.value)} aria-invalid={!!err("legal_name")} />
          </Field>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="RUC" hint="Se emitirá factura." error={err("ruc")}>
              <input className="input" inputMode="numeric" value={f.ruc} onChange={(e) => set("ruc", onlyDigits(e.target.value, 11))} aria-invalid={!!err("ruc")} />
            </Field>
            <Field label="Cupos" hint={offer.min_company_seats ? `Desde ${offer.min_company_seats} cupos.` : undefined} error={err("seats")}>
              <input className="input" type="number" min={offer.min_company_seats ?? 1} max={500} value={f.seats} onChange={(e) => set("seats", onlyDigits(e.target.value, 3))} aria-invalid={!!err("seats")} />
            </Field>
          </div>
        </>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Nombres" error={err("first_name")}>
              <input className="input" maxLength={80} autoComplete="given-name" value={f.first_name} onChange={(e) => set("first_name", e.target.value)} aria-invalid={!!err("first_name")} />
            </Field>
            <Field label="Apellidos" error={err("last_name")}>
              <input className="input" maxLength={80} autoComplete="family-name" value={f.last_name} onChange={(e) => set("last_name", e.target.value)} aria-invalid={!!err("last_name")} />
            </Field>
          </div>
          <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4">
            <Field label="Documento" error={err("document_type")}>
              <select className="input" value={f.document_type} onChange={(e) => set("document_type", e.target.value)}>
                <option value="DNI">DNI</option>
                <option value="CE">Carné de extranjería</option>
                <option value="PTP">PTP</option>
                <option value="PASSPORT">Pasaporte</option>
              </select>
            </Field>
            <Field label="Número" error={err("document_number")}>
              <input className="input" maxLength={20} value={f.document_number} onChange={(e) => set("document_number", e.target.value.replace(/[^A-Za-z0-9]/g, ""))} aria-invalid={!!err("document_number")} />
            </Field>
          </div>
        </>
      )}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Correo" hint={isCompany ? undefined : "Aquí te llega el acceso al curso."} error={err("email")}>
          <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} onChange={(e) => set("email", e.target.value)} aria-invalid={!!err("email")} />
        </Field>
        <Field label="Celular" optional error={err("phone")}>
          <input className="input" type="tel" maxLength={20} autoComplete="tel" value={f.phone} onChange={(e) => set("phone", e.target.value)} />
        </Field>
      </div>
      <div className="flex items-center justify-between rounded-xl bg-[var(--site-surface)] px-4 py-3 text-sm">
        <span className="text-[var(--site-text-2)]">{isCompany ? `${qty} cupos × ${soles(offer.price)}` : "Total"}</span>
        <b className="font-display text-lg text-[var(--site-navy-title)]">{soles(offer.price * qty)}</b>
      </div>
      <Check checked={privacy} onChange={setPrivacy} error={err("privacy_consent")}>
        Acepto que {company} use mis datos para atender mi compra, matricularme y emitir el comprobante.
      </Check>
      <Check checked={marketing} onChange={setMarketing}>
        Quiero recibir novedades y cursos de {company} (opcional).
      </Check>
      <Submit saving={saving}>Solicitar compra</Submit>
      <p className="text-center text-xs text-[var(--site-text-2)]">
        <Icon name="lock" size={12} className="mr-1 inline" />
        No te pedimos datos de tarjeta. El pago se coordina con {company}.
      </p>
    </form>
  );
}
