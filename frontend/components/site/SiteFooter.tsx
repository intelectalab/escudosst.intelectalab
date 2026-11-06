// Pie del sitio (docs/07 §1.5, corrección 10): contacto, enlaces, políticas, Libro de Reclamaciones,
// otros productos y datos legales de GRUPO DE SOLUCIONES CREATIVAS SAC.

import Link from "next/link";
import { CookiePrefsLink } from "@/components/site/CookieBanner";
import { SiteLogo } from "@/components/site/SiteLogo";
import { Icon } from "@/components/ui/Icon";
import type { SiteData } from "@/lib/site";
import { assetUrl, whatsappLink } from "@/lib/site";
import { WhatsAppIcon } from "./WhatsAppFloat";

const SOCIAL_LABELS: Record<string, string> = { facebook: "Facebook", instagram: "Instagram", linkedin: "LinkedIn", tiktok: "TikTok", youtube: "YouTube", x: "X" };

export function SiteFooter({ site }: { site: SiteData }) {
  const s = site.settings;
  const wa = whatsappLink(s);
  const email = s.contact_email;
  const year = new Date().getFullYear();
  const social = Object.entries(s.social_links ?? {}).filter(([, v]) => v);

  return (
    <footer id="contacto" className="scroll-mt-20 bg-[var(--site-navy)] text-white">
      <div className="site-stripe h-1.5" aria-hidden="true" />
      <div className="grid-cols-1 mx-auto grid max-w-[1360px] gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-4 lg:px-8">
        <div className="space-y-4">
          <SiteLogo src={assetUrl(s.logo_dark_file_id)} alt={s.trade_name || "EscudoSST"} light />
          <p className="text-sm text-white/70">Gestión de seguridad y salud en el trabajo para empresas peruanas, pensada para la Ley 29783.</p>
          {social.length > 0 && (
            <ul className="flex flex-wrap gap-2">
              {social.map(([k, url]) => (
                <li key={k}>
                  <a href={url} target="_blank" rel="noopener" className="rounded-full bg-white/10 px-3 py-1.5 text-xs font-semibold hover:bg-white/20">
                    {SOCIAL_LABELS[k] ?? k}
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          <h2 className="mb-3 text-sm font-bold uppercase tracking-wider text-white/60">Producto</h2>
          <ul className="space-y-2 text-sm">
            {[
              ["/producto", "Producto y módulos"],
              ["/como-funciona", "Cómo funciona"],
              ["/planes", "Planes"],
              ["/complementos", "Complementos"],
              ["/preguntas-frecuentes", "Preguntas frecuentes"],
              ["/login", "Iniciar sesión"],
            ].map(([href, label]) => (
              <li key={href}>
                <Link href={href} className="text-white/85 hover:text-white hover:underline">
                  {label}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <h2 className="mb-3 text-sm font-bold uppercase tracking-wider text-white/60">Contacto</h2>
          <ul className="space-y-2.5 text-sm text-white/85">
            {wa && (
              <li>
                <a href={wa} target="_blank" rel="noopener" className="inline-flex items-center gap-2 hover:text-white">
                  <WhatsAppIcon size={18} /> WhatsApp
                </a>
              </li>
            )}
            {email && (
              <li>
                <a href={`mailto:${email}`} className="inline-flex items-center gap-2 hover:text-white">
                  <Icon name="mail" size={16} /> {email}
                </a>
              </li>
            )}
            {s.contact_phone && <li>Teléfono: {s.contact_phone}</li>}
            {s.support_email && (
              <li>
                Soporte para clientes:{" "}
                <a href={`mailto:${s.support_email}`} className="hover:text-white hover:underline">
                  {s.support_email}
                </a>
              </li>
            )}
            {!wa && !email && !s.contact_phone && !s.support_email && <li className="text-white/60">Datos de contacto por publicar.</li>}
          </ul>
        </div>

        <div>
          <h2 className="mb-3 text-sm font-bold uppercase tracking-wider text-white/60">Legal</h2>
          <ul className="space-y-2 text-sm text-white/85">
            <li>
              <Link
                href="/libro-de-reclamaciones"
                className="mb-1 inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 font-bold text-[var(--site-navy-title)] shadow-sm transition hover:-translate-y-0.5"
              >
                <Icon name="file" size={18} className="text-[var(--site-red)]" />
                Libro de Reclamaciones
              </Link>
            </li>
            {[
              ["terminos-y-condiciones", "Términos y condiciones"],
              ["politica-de-privacidad", "Privacidad y datos personales"],
              ["politica-de-cookies", "Política de cookies"],
              ["encargo-de-tratamiento", "Encargo de tratamiento de datos"],
              ["acuerdo-de-nivel-de-servicio", "Nivel de servicio"],
            ].map(([slug, label]) => (
              <li key={slug}>
                <Link href={`/legal/${slug}`} className="text-white/85 hover:text-white hover:underline">
                  {label}
                </Link>
              </li>
            ))}
            <li>
              <CookiePrefsLink />
            </li>
          </ul>
        </div>
      </div>

      {site.products.length > 0 && (
        <div className="border-t border-white/10">
          <div className="mx-auto max-w-[1360px] px-4 py-6 lg:px-8">
            <p className="mb-2 text-xs font-bold uppercase tracking-wider text-white/50">Otros productos de {s.legal_name}</p>
            <ul className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-white/75">
              {site.products.map((p) => (
                <li key={p.name}>{p.url ? <a href={p.url} target="_blank" rel="noopener" className="hover:text-white">{p.name}</a> : p.name}</li>
              ))}
            </ul>
          </div>
        </div>
      )}

      <div className="border-t border-white/10">
        <div className="mx-auto flex max-w-[1360px] flex-col gap-1 px-4 py-5 text-xs text-white/55 sm:flex-row sm:justify-between lg:px-8">
          <span>
            © {year} {s.legal_name} · RUC {s.ruc}
            {s.legal_address ? ` · ${s.legal_address}` : ""}
          </span>
          <span>{s.trade_name} es una marca de {s.legal_name}.</span>
        </div>
      </div>
    </footer>
  );
}
