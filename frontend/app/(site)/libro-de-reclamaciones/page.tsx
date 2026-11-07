// SCR-WEB-17 · Libro de Reclamaciones virtual de EscudoSST (Ley 29571 y D.S. 011-2011-PCM).
// Los datos del proveedor salen de Sitio web › Marca y ajustes; el plazo, del parámetro legal vigente.

import type { Metadata } from "next";
import Link from "next/link";
import { ComplaintForm, type Provider } from "@/components/site/ComplaintForms";
import { Icon } from "@/components/ui/Icon";

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export const metadata: Metadata = {
  title: "Libro de Reclamaciones · EscudoSST",
  description: "Libro de Reclamaciones virtual de EscudoSST. Registra tu reclamo o queja y consulta su estado.",
};

async function info(): Promise<{ provider: Provider; response_days: number | null; ready: boolean }> {
  try {
    const res = await fetch(`${API}/api/v1/public/complaints/info`, { cache: "no-store" });
    return ((await res.json()) as { data: { provider: Provider; response_days: number | null; ready: boolean } }).data;
  } catch {
    return { provider: { legal_name: null, trade_name: null, ruc: null, legal_address: null }, response_days: null, ready: false };
  }
}

export default async function LibroPage() {
  const { provider, response_days } = await info();
  const date = new Intl.DateTimeFormat("es-PE", { dateStyle: "long", timeZone: "America/Lima" }).format(new Date());

  return (
    <section className="bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 sm:py-16 lg:px-8">
      <div className="mx-auto grid max-w-[1200px] grid-cols-1 gap-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,8fr)] lg:gap-12">
        <div className="space-y-6">
          <div className="space-y-3">
            <p className="site-eyebrow">Libro de Reclamaciones</p>
            <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[40px]">Hoja de reclamación</h1>
            <p className="text-[16px] leading-relaxed text-[var(--site-text-2)]">
              Conforme al Código de Protección y Defensa del Consumidor, contamos con un Libro de Reclamaciones virtual a tu disposición.
            </p>
          </div>
          <div className="rounded-2xl border border-[var(--site-border)] bg-white p-5 text-sm shadow-sm">
            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-[var(--site-text-2)]">Proveedor</p>
            <dl className="space-y-2">
              <div>
                <dt className="text-[var(--site-text-2)]">Razón social</dt>
                <dd className="font-semibold text-[var(--site-navy-title)]">{provider.legal_name ?? "—"}</dd>
              </div>
              <div>
                <dt className="text-[var(--site-text-2)]">RUC</dt>
                <dd className="font-semibold text-[var(--site-navy-title)]">{provider.ruc ?? "—"}</dd>
              </div>
              <div>
                <dt className="text-[var(--site-text-2)]">Domicilio</dt>
                <dd className="font-semibold text-[var(--site-navy-title)]">{provider.legal_address ?? "Por publicar"}</dd>
              </div>
              <div>
                <dt className="text-[var(--site-text-2)]">Fecha</dt>
                <dd className="font-semibold text-[var(--site-navy-title)]">{date}</dd>
              </div>
            </dl>
          </div>
          <Link href="/libro-de-reclamaciones/seguimiento" className="flex items-center gap-3 rounded-2xl border border-[var(--site-border)] bg-white p-4 text-sm font-semibold text-[var(--site-navy-title)] shadow-sm transition hover:-translate-y-0.5">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--site-surface)] text-[var(--site-action)]">
              <Icon name="search" size={18} />
            </span>
            ¿Ya registraste una hoja? Consulta su estado
            <Icon name="chevron" size={16} className="ml-auto" />
          </Link>
        </div>
        <ComplaintForm provider={provider} responseDays={response_days} />
      </div>
    </section>
  );
}
