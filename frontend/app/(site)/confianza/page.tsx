// Centro de confianza (SCR-WEB-37, RN-WEB-52): solo medidas reales e implementadas, subencargados activos con su
// base de transferencia, políticas vigentes y derechos ARCO. Si algo está «por validar», se dice.

import type { Metadata } from "next";
import Link from "next/link";
import { PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { getSite } from "@/lib/site";
import { countryName, getTrust } from "@/lib/trust";

export const metadata: Metadata = {
  title: "Centro de confianza · EscudoSST",
  description: "Cómo protegemos tus datos en EscudoSST: seguridad, copias de respaldo, base médica separada, Ley 29733 y lista de subencargados.",
};

// Cada punto describe algo que el sistema ya hace (revisado en el código). No agregar medidas que no existan.
const MEASURES: [string, string, string, string][] = [
  ["lock", "Acceso con doble verificación", "Además de la contraseña, al ingresar pedimos un código que llega a tu correo. La sesión se cierra sola tras 30 minutos sin uso.", "from-[#EAF3FC] text-[#1b6fb7]"],
  ["building", "Cada empresa ve solo lo suyo", "La base de datos separa la información por empresa: un usuario no puede ver datos de otra empresa aunque lo intente.", "from-[#E8F7EE] text-[#22A35A]"],
  ["heart", "Datos médicos en una base aparte", "Los datos de salud ocupacional se guardan en una base de datos distinta de la principal, con su propia copia de respaldo y su propia llave de cifrado.", "from-[#FDECEB] text-[#C8372F]"],
  ["database", "Copias de respaldo cifradas", "Hacemos una copia diaria de las bases de datos, cifrada, y la guardamos 30 días.", "from-[#FFF5D6] text-[#B88700]"],
  ["history", "Registro de cambios", "Las acciones importantes quedan registradas con quién las hizo y cuándo.", "from-[#EEEAFB] text-[#5B47C9]"],
  ["globe", "Conexión cifrada", "El sitio y la aplicación funcionan solo con conexión segura (HTTPS).", "from-[#E5F7F4] text-[#16897b]"],
];

export default async function ConfianzaPage() {
  const [t, site] = await Promise.all([getTrust(), getSite()]);
  const s = site.settings;
  const contact = s.support_email ?? s.contact_email;
  const pending = t.subprocessors.some((x) => x.to_validate);

  return (
    <>
      <PageHero eyebrow="Centro de confianza" title="Así cuidamos tus datos" text="Tu información de SST y la de tus trabajadores es sensible. Aquí te contamos, sin letra pequeña, qué hacemos para protegerla.">
        <Link href="/estado" className="site-btn site-btn-light mt-7 inline-flex min-h-11">
          Ver el estado del servicio
        </Link>
      </PageHero>

      <section className="px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-14">
          <div>
            <h2 className="font-display text-[26px] font-bold text-[var(--site-navy-title)]">Seguridad</h2>
            <ul className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              {MEASURES.map(([icon, title, text, tone]) => (
                <li key={title} className="site-card min-w-0 p-6">
                  <span className={`grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br to-white ${tone}`}>
                    <Icon name={icon} size={22} />
                  </span>
                  <h3 className="mt-4 font-display text-[18px] font-bold text-[var(--site-navy-title)]">{title}</h3>
                  <p className="mt-1.5 text-[var(--site-text-2)]">{text}</p>
                </li>
              ))}
            </ul>
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div className="site-card min-w-0 p-6 sm:p-8">
              <h2 className="font-display text-[22px] font-bold text-[var(--site-navy-title)]">Ley 29733 y tus derechos</h2>
              <p className="mt-2 text-[var(--site-text-2)]">
                Tratamos los datos personales según la Ley de Protección de Datos Personales (Ley 29733) y su reglamento. Puedes pedir acceso, rectificación, cancelación u oposición (derechos ARCO) sobre tus datos.
              </p>
              {contact && (
                <p className="mt-3 text-[var(--site-text)]">
                  Escríbenos a{" "}
                  <a href={`mailto:${contact}`} className="font-semibold text-[var(--site-action)] underline">
                    {contact}
                  </a>
                  .
                </p>
              )}
              <p className="mt-3 text-sm text-[var(--site-text-2)]">
                {s.legal_name} · RUC {s.ruc}
              </p>
            </div>
            <div className="site-card min-w-0 p-6 sm:p-8">
              <h2 className="font-display text-[22px] font-bold text-[var(--site-navy-title)]">Políticas vigentes</h2>
              {t.documents.length === 0 ? (
                <p className="mt-2 text-[var(--site-text-2)]">Estamos terminando de publicar nuestras políticas.</p>
              ) : (
                <ul className="mt-3 space-y-1">
                  {t.documents.map((d) => (
                    <li key={d.slug}>
                      <Link href={`/legal/${d.slug}`} className="flex items-center gap-2 rounded-lg px-2 py-2 font-semibold text-[var(--site-action)] hover:bg-[#EAF3FC]">
                        <Icon name="file" size={16} /> <span className="min-w-0 flex-1">{d.title}</span>
                        {d.version && <span className="text-xs font-normal text-[var(--site-text-2)]">v{d.version}</span>}
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>

          <div>
            <h2 className="font-display text-[26px] font-bold text-[var(--site-navy-title)]">Subencargados</h2>
            <p className="mt-2 max-w-3xl text-[var(--site-text-2)]">Proveedores que tratan datos por encargo nuestro para que EscudoSST funcione. Si cambiamos esta lista, avisamos a los administradores de cada cuenta.</p>
            {pending && (
              <p className="mt-3 max-w-3xl rounded-xl border border-[#F4B90F]/40 bg-[#FFF8E1] px-4 py-3 text-sm text-[#5C4500]">
                Los datos marcados «por validar» están en revisión legal y pueden cambiar.
              </p>
            )}
            {t.subprocessors.length === 0 ? (
              <p className="site-card mt-4 p-6 text-[var(--site-text-2)]">Estamos actualizando esta lista.</p>
            ) : (
              <ul className="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                {t.subprocessors.map((x) => (
                  <li key={x.name} className="site-card min-w-0 p-5">
                    <div className="flex flex-wrap items-center gap-2">
                      <b className="font-display text-[17px] text-[var(--site-navy-title)]">{x.name}</b>
                      <span className="rounded-full bg-[#EAF3FC] px-2.5 py-0.5 text-xs font-semibold text-[#1b6fb7]">{countryName(x.country)}</span>
                      {x.to_validate && <span className="rounded-full bg-[#FFF5D6] px-2.5 py-0.5 text-xs font-semibold text-[#8a6a00]">Por validar</span>}
                    </div>
                    <dl className="mt-3 space-y-2 text-sm">
                      <div>
                        <dt className="font-semibold text-[var(--site-text-2)]">Para qué</dt>
                        <dd>{x.purpose}</dd>
                      </div>
                      <div>
                        <dt className="font-semibold text-[var(--site-text-2)]">Qué datos</dt>
                        <dd>{x.data_categories}</dd>
                      </div>
                      <div>
                        <dt className="font-semibold text-[var(--site-text-2)]">Base de la transferencia</dt>
                        <dd>{x.transfer_basis}</dd>
                      </div>
                    </dl>
                    {x.website && (
                      <a href={x.website} target="_blank" rel="noopener noreferrer" className="mt-3 inline-block text-sm font-semibold text-[var(--site-action)] hover:underline">
                        Sitio del proveedor →
                      </a>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </section>
    </>
  );
}
