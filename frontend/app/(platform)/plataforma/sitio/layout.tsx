// Sitio web (panel): navegación de secciones a la izquierda y el contenido a todo el ancho restante.

import { SiteSideNav } from "@/components/site/SitePanelTabs";

export default function SitioLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-1 gap-5 xl:grid-cols-[14.5rem_minmax(0,1fr)] xl:gap-5">
      <aside className="xl:sticky xl:top-[5rem] xl:self-start">
        <SiteSideNav />
      </aside>
      <div className="min-w-0">{children}</div>
    </div>
  );
}
