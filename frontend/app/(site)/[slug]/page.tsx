// Otras páginas creadas en el panel (tipo «Página»), en la raíz del sitio: /{slug}.
// Las rutas fijas del sitio (/planes, /demo, …) tienen prioridad sobre esta.
import { pageMetadata, SitePageView } from "@/components/site/SitePageView";

type P = { params: Promise<{ slug: string }> };
export async function generateMetadata({ params }: P) {
  return pageMetadata((await params).slug);
}
export default async function LandingPage({ params }: P) {
  return <SitePageView slug={(await params).slug} />;
}
