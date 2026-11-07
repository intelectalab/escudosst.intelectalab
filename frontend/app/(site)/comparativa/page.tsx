// SCR-WEB-31 · «EscudoSST vs Excel y papel» (se edita en el panel: Sitio web › Páginas, tipo «Comparativa», dirección comparativa).
import { pageMetadata, SitePageView } from "@/components/site/SitePageView";

export async function generateMetadata() {
  return pageMetadata("comparativa");
}
export default function ComparativaPage() {
  return <SitePageView slug="comparativa" />;
}
