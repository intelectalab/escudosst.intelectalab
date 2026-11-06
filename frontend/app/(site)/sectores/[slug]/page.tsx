// SCR-WEB-04 · Página por sector (se edita en el panel: Sitio web › Páginas).
import { pageMetadata, SitePageView } from "@/components/site/SitePageView";

type P = { params: Promise<{ slug: string }> };
export async function generateMetadata({ params }: P) {
  return pageMetadata(`sectores/${(await params).slug}`);
}
export default async function SectorPage({ params }: P) {
  return <SitePageView slug={`sectores/${(await params).slug}`} />;
}
