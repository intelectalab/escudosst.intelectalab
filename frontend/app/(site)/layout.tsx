// Estructura del sitio web público (M-WEB): barra de anuncio, cabecera, contenido, pie y WhatsApp flotante.
// Se arma en el servidor con los datos que el dueño edita en el panel (Sitio web → Marca y ajustes).

import Link from "next/link";
import { CookieBanner } from "@/components/site/CookieBanner";
import { SiteFooter } from "@/components/site/SiteFooter";
import { SiteHeader } from "@/components/site/SiteHeader";
import { WhatsAppFloat } from "@/components/site/WhatsAppFloat";
import { assetUrl, contactHref, getSite, whatsappLink } from "@/lib/site";

export default async function SiteLayout({ children }: { children: React.ReactNode }) {
  const site = await getSite();
  const s = site.settings;
  const bar = s.announcement_bar;

  return (
    <div className="site flex min-h-screen flex-col">
      <a href="#contenido" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:font-semibold">
        Saltar al contenido
      </a>
      {bar?.text && (
        <div className="bg-[var(--site-navy)] text-white">
          <div className="mx-auto flex max-w-[1360px] items-center justify-center gap-3 px-4 py-2 text-center text-sm">
            <span className="hidden h-2 w-2 shrink-0 rounded-full bg-[var(--site-amber)] sm:inline-block" aria-hidden="true" />
            <span>{bar.text}</span>
            {bar.link && (
              <Link href={bar.link} className="shrink-0 font-bold text-[var(--site-amber)] underline-offset-2 hover:underline">
                Ver más →
              </Link>
            )}
          </div>
        </div>
      )}
      <SiteHeader
        trialHref={contactHref(s, "trial")}
        demoHref={contactHref(s, "demo")}
        blogUrl={s.blog_url}
        menu={site.menu}
        logoUrl={assetUrl(s.logo_file_id)}
        brandName={s.trade_name || "EscudoSST"}
      />
      <main id="contenido" className="flex-1">
        {children}
      </main>
      <SiteFooter site={site} />
      <WhatsAppFloat href={whatsappLink(s)} />
      <CookieBanner />
    </div>
  );
}
