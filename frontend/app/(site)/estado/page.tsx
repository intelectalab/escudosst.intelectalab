// Estado del servicio (SCR-WEB-38, RN-WEB-53): estado general (el peor componente), componentes con color y texto,
// incidentes abiertos con su línea de tiempo, mantenimientos programados, historial y avisos por correo.
// Nunca muestra datos de clientes. Si la API no responde, lo dice en vez de mostrar «todo operativo».

import type { Metadata } from "next";
import { StatusSubscribe } from "@/components/site/StatusSubscribe";
import { Icon } from "@/components/ui/Icon";
import { getStatus, IMPACT_LABEL, INCIDENT_LABEL, OVERALL_TEXT, STATUS_LABEL, STATUS_TONE, when } from "@/lib/trust";

export const metadata: Metadata = { title: "Estado del servicio · EscudoSST", description: "Disponibilidad de EscudoSST en tiempo real, incidentes y mantenimientos programados." };
export const revalidate = 30;

export default async function EstadoPage() {
  const s = await getStatus();
  const now = Date.now();
  const scheduled = s?.incidents.filter((i) => i.impact === "maintenance" && i.status !== "resolved" && new Date(i.started_at).getTime() > now) ?? [];
  const open = s?.incidents.filter((i) => i.status !== "resolved" && !scheduled.includes(i)) ?? [];
  const past = s?.incidents.filter((i) => i.status === "resolved") ?? [];
  const tone = s ? STATUS_TONE[s.overall_status] : null;
  const name = (code: string) => s?.components.find((c) => c.code === code)?.name ?? code;

  const incidentCard = (i: NonNullable<typeof s>["incidents"][number]) => (
    <li key={i.id} className="site-card min-w-0 p-5">
      <div className="flex flex-wrap items-center gap-2">
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${i.status === "resolved" ? "bg-[#E8F7EE] text-[#17703e]" : i.impact === "maintenance" ? "bg-[#EAF3FC] text-[#176bb3]" : "bg-[#FDECEB] text-[#a3261f]"}`}>
          {i.impact === "maintenance" ? "Mantenimiento" : `Impacto ${IMPACT_LABEL[i.impact].toLowerCase()}`}
        </span>
        <span className="text-xs text-[var(--site-text-2)]">
          {when(i.started_at)}
          {i.resolved_at && ` → ${when(i.resolved_at)}`}
        </span>
      </div>
      <h3 className="mt-2 font-display text-[17px] font-bold text-[var(--site-navy-title)]">{i.title}</h3>
      {i.components.length > 0 && <p className="text-xs text-[var(--site-text-2)]">Afecta a: {i.components.map(name).join(", ")}</p>}
      <ol className="mt-3 space-y-3 border-l-2 border-[#d5dde7] pl-4">
        {i.updates.map((u, k) => (
          <li key={k} className="relative">
            <span className="absolute -left-[23px] top-1 h-3 w-3 rounded-full border-2 border-white bg-[#1b6fb7]" aria-hidden="true" />
            <p className="text-sm">
              <b className="text-[var(--site-navy-title)]">{INCIDENT_LABEL[u.status] ?? u.status}</b> <span className="text-xs text-[var(--site-text-2)]">· {when(u.published_at)}</span>
            </p>
            <p className="text-sm text-[var(--site-text)]">{u.message}</p>
          </li>
        ))}
      </ol>
    </li>
  );

  return (
    <section className="px-4 py-10 sm:py-14 lg:px-8">
      <div className="mx-auto max-w-[1000px] space-y-8">
        <div>
          <p className="site-eyebrow">Estado del servicio</p>
          <h1 className="mt-2 font-display text-[30px] font-bold text-[var(--site-navy-title)] sm:text-[40px]">¿Funciona EscudoSST?</h1>
        </div>

        {!s || !tone ? (
          <div className="site-card flex items-center gap-3 border-l-4 border-[#F4B90F] p-5">
            <Icon name="bell" size={22} className="text-[#B88700]" />
            <p className="text-[var(--site-text)]">No pudimos leer el estado en este momento. Si no puedes entrar a la aplicación, escríbenos.</p>
          </div>
        ) : (
          <>
            <div className={`flex items-center gap-4 rounded-2xl p-5 sm:p-6 ${tone.soft}`} role="status">
              <span className={`grid h-12 w-12 shrink-0 place-items-center rounded-full text-white ${tone.dot}`}>
                <Icon name={tone.icon} size={24} />
              </span>
              <div className="min-w-0">
                <p className={`font-display text-xl font-bold ${tone.text}`}>{OVERALL_TEXT[s.overall_status]}</p>
                <p className="text-sm text-[var(--site-text-2)]">Se actualiza cada 30 segundos.</p>
              </div>
            </div>

            <ul className="site-card divide-y divide-[var(--site-border)]">
              {s.components.map((c) => {
                const t = STATUS_TONE[c.current_status];
                return (
                  <li key={c.code} className="flex items-center gap-3 px-5 py-4">
                    <span className="min-w-0 flex-1">
                      <b className="block text-[var(--site-navy-title)]">{c.name}</b>
                      {c.description && <span className="text-xs text-[var(--site-text-2)]">{c.description}</span>}
                    </span>
                    <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ${t.soft} ${t.text}`}>
                      <span className={`h-2 w-2 rounded-full ${t.dot}`} aria-hidden="true" />
                      {STATUS_LABEL[c.current_status]}
                    </span>
                  </li>
                );
              })}
            </ul>

            {open.length > 0 && (
              <div>
                <h2 className="font-display text-[22px] font-bold text-[var(--site-navy-title)]">En curso</h2>
                <ul className="mt-3 space-y-3">{open.map(incidentCard)}</ul>
              </div>
            )}
            {scheduled.length > 0 && (
              <div>
                <h2 className="font-display text-[22px] font-bold text-[var(--site-navy-title)]">Mantenimientos programados</h2>
                <ul className="mt-3 space-y-3">{scheduled.map(incidentCard)}</ul>
              </div>
            )}
            <div>
              <h2 className="font-display text-[22px] font-bold text-[var(--site-navy-title)]">Historial reciente</h2>
              {past.length === 0 ? <p className="site-card mt-3 p-5 text-[var(--site-text-2)]">Sin incidentes en los últimos días.</p> : <ul className="mt-3 space-y-3">{past.map(incidentCard)}</ul>}
            </div>
          </>
        )}

        <div className="site-card p-5 sm:p-6">
          <p className="font-display text-lg font-bold text-[var(--site-navy-title)]">Recibe avisos por correo</p>
          <p className="mb-3 text-sm text-[var(--site-text-2)]">Te escribimos solo cuando hay una interrupción o un mantenimiento programado.</p>
          <StatusSubscribe />
        </div>
        <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--site-text-2)]">
          {(Object.keys(STATUS_LABEL) as (keyof typeof STATUS_LABEL)[]).map((k) => (
            <span key={k} className="inline-flex items-center gap-1.5">
              <span className={`h-2 w-2 rounded-full ${STATUS_TONE[k].dot}`} aria-hidden="true" /> {STATUS_LABEL[k]}
            </span>
          ))}
        </p>
      </div>
    </section>
  );
}
