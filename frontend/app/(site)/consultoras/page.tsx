// SCR-WEB-08 · Para consultoras (se edita en el panel: Sitio web › Páginas).
import { pageMetadata, SitePageView } from "@/components/site/SitePageView";

export async function generateMetadata() {
  return pageMetadata("consultoras");
}
export default function ConsultorasPage() {
  return <SitePageView slug="consultoras" />;
}
