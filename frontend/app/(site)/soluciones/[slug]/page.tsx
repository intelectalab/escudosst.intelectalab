// SCR-WEB-03 · Página de un diferenciador (se edita en el panel: Sitio web › Páginas).
import { pageMetadata, SitePageView } from "@/components/site/SitePageView";

type P = { params: Promise<{ slug: string }> };
export async function generateMetadata({ params }: P) {
  return pageMetadata(`soluciones/${(await params).slug}`);
}
export default async function SolutionPage({ params }: P) {
  return <SitePageView slug={`soluciones/${(await params).slug}`} />;
}
