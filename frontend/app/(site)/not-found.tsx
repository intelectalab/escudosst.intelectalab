// Página 404 del sitio público. Avisa la ruta que no existe para el reporte de enlaces rotos del panel (M-WEB §11).

import Link from "next/link";
import { NotFoundBeacon } from "@/components/site/NotFoundBeacon";
import { PageHero } from "@/components/site/Sections";

export default function SiteNotFound() {
  return (
    <>
      <NotFoundBeacon />
      <PageHero eyebrow="Página no encontrada" title="Esta página no existe o cambió de dirección" text="Puede que el enlace esté mal escrito o que la página ya no esté publicada.">
        <div className="mt-6 flex flex-wrap gap-3">
          <Link href="/" className="site-btn site-btn-light">
            Ir al inicio
          </Link>
          <Link href="/planes" className="site-btn site-btn-ghost text-white">
            Ver planes
          </Link>
        </div>
      </PageHero>
    </>
  );
}
