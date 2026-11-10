"use client";

// Pestañas de módulos de la portada: al tocar una pestaña cambian la imagen (una maqueta de la pantalla) y la lista
// de lo que hace ese módulo. Se manejan con el teclado (flechas izquierda/derecha) como pestañas accesibles.

import Link from "next/link";
import { useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";

type Tab = { code: string; label: string; icon: string; title: string; bullets: string[]; art: React.ReactNode };

const pill = (tone: "bad" | "warn" | "ok", text: string) => {
  const c = { bad: "bg-[#FDECEB] text-[#a3261f]", warn: "bg-[#FFF6DB] text-[#7a5300]", ok: "bg-[#E8F7EE] text-[#17703e]" }[tone];
  return <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold ${c}`}>{text}</span>;
};

function Screen({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-[var(--site-border)]">
      <div className="flex items-center gap-2 border-b border-[var(--site-border)] bg-[var(--site-surface)] px-4 py-2.5">
        <span className="flex gap-1" aria-hidden="true">
          <i className="h-2 w-2 rounded-full bg-[#F26B5B]" />
          <i className="h-2 w-2 rounded-full bg-[#F4B90F]" />
          <i className="h-2 w-2 rounded-full bg-[#22A35A]" />
        </span>
        <span className="truncate text-xs font-semibold text-[var(--site-text-2)]">{title}</span>
      </div>
      <div className="space-y-2 p-4 text-[13px] text-[var(--site-navy-title)]">{children}</div>
    </div>
  );
}

const Row = ({ children }: { children: React.ReactNode }) => (
  <div className="flex items-center justify-between gap-3 rounded-lg bg-[var(--site-surface)] px-3 py-2">{children}</div>
);

const TABS: Tab[] = [
  {
    code: "IPC",
    label: "IPERC",
    icon: "shield",
    title: "Peligros y riesgos de cada puesto, con sus controles",
    bullets: [
      "Matriz por área o puesto con el nivel de riesgo antes y después de los controles",
      "Cada control con responsable y fecha",
      "Versión vigente siempre a la vista, con su historial",
      "Aviso cuando toca la revisión anual",
    ],
    art: (
      <Screen title="Matriz IPERC · Almacén">
        <Row><span className="truncate">Caída al mismo nivel</span>{pill("warn", "Moderado")}</Row>
        <Row><span className="truncate">Atrapamiento con montacargas</span>{pill("bad", "Importante")}</Row>
        <Row><span className="truncate">Sobreesfuerzo al levantar cajas</span>{pill("warn", "Moderado")}</Row>
        <Row><span className="truncate">Corte con cúter</span>{pill("ok", "Tolerable")}</Row>
        <p className="pt-1 text-xs text-[var(--site-text-2)]">Versión vigente · revisión en 4 meses</p>
      </Screen>
    ),
  },
  {
    code: "INS",
    label: "Inspecciones",
    icon: "checklist",
    title: "Inspecciones desde el celular, con fotos y seguimiento",
    bullets: [
      "Listas de verificación desde la web o el celular",
      "Hallazgos con foto, responsable y fecha límite",
      "Acción correctiva con un toque, hasta cerrarla",
    ],
    art: (
      <Screen title="Inspección de extintores · Planta">
        <Row><span>Señalización visible</span><span className="font-bold text-[#17703e]">✓ Cumple</span></Row>
        <Row><span>Presión en zona verde</span><span className="font-bold text-[#17703e]">✓ Cumple</span></Row>
        <Row><span>Fecha de recarga vigente</span><span className="font-bold text-[#a3261f]">✗ Hallazgo</span></Row>
        <Row><span className="truncate">Acción: recargar extintor E-04</span>{pill("warn", "Vence en 5 días")}</Row>
      </Screen>
    ),
  },
  {
    code: "CAP",
    label: "Capacitaciones",
    icon: "people",
    title: "Capacitaciones con asistencia y certificados verificables",
    bullets: [
      "Programa anual y sesiones con su asistencia",
      "Certificados con QR que se verifican en línea",
      "Aviso antes de que venza una capacitación",
    ],
    art: (
      <Screen title="Programa anual de capacitación">
        <Row><span className="truncate">Trabajo en altura</span><span className="font-bold">18/20 asistieron</span></Row>
        <Row><span className="truncate">Uso de extintores</span><span className="font-bold">25/25 asistieron</span></Row>
        <Row><span className="truncate">Primeros auxilios</span>{pill("warn", "Programada")}</Row>
        <div className="flex items-center gap-3 rounded-lg border border-dashed border-[var(--site-border)] px-3 py-2">
          <span className="grid h-9 w-9 shrink-0 grid-cols-3 gap-0.5 rounded bg-[var(--site-navy-title)] p-1" aria-hidden="true">
            {[1, 0, 1, 0, 1, 0, 1, 1, 0].map((on, k) => <i key={k} className={on ? "bg-white" : ""} />)}
          </span>
          <span className="text-xs">Certificado con QR · verificable en línea</span>
        </div>
      </Screen>
    ),
  },
  {
    code: "EPP",
    label: "EPP",
    icon: "tag",
    title: "Entregas de EPP firmadas y a tiempo",
    bullets: [
      "Entregas por trabajador con su firma",
      "Stock por almacén y reposición",
      "Qué equipos toca cambiar y a quién",
    ],
    art: (
      <Screen title="Entregas de EPP">
        <Row><span className="truncate">Casco · Ana Lima</span>{pill("ok", "Firmado")}</Row>
        <Row><span className="truncate">Guantes de nitrilo · Luis Rey</span>{pill("ok", "Firmado")}</Row>
        <Row><span className="truncate">Respirador · Carla Soto</span>{pill("bad", "Toca cambiar")}</Row>
        <Row><span className="truncate">Stock de lentes</span>{pill("warn", "Quedan 6")}</Row>
      </Screen>
    ),
  },
  {
    code: "ACC",
    label: "Accidentes",
    icon: "history",
    title: "Accidentes e incidentes, del reporte a la mejora",
    bullets: [
      "Registro de accidentes, incidentes y enfermedades",
      "Investigación de causas y acciones correctivas",
      "Índices de frecuencia y gravedad al día",
    ],
    art: (
      <Screen title="Estadísticas de seguridad">
        <div className="grid grid-cols-3 gap-2">
          {[["Accidentes", "2"], ["Incidentes", "7"], ["Días sin accidentes", "41"]].map(([k, v]) => (
            <div key={k} className="rounded-lg bg-[var(--site-surface)] p-2 text-center">
              <p className="font-display text-xl font-bold">{v}</p>
              <p className="text-[10px] text-[var(--site-text-2)]">{k}</p>
            </div>
          ))}
        </div>
        <div className="flex h-20 items-end gap-1.5 rounded-lg bg-[var(--site-surface)] p-2" aria-hidden="true">
          {[30, 55, 20, 40, 15, 25, 10, 18, 8, 12, 6, 9].map((h, k) => (
            <i key={k} className="flex-1 rounded-t bg-[#1F7FD1]/70" style={{ height: `${h + 20}%` }} />
          ))}
        </div>
        <p className="text-xs text-[var(--site-text-2)]">Índice de frecuencia por mes</p>
      </Screen>
    ),
  },
];

export function ModuleTabs({ plans }: { plans: Record<string, string | undefined> }) {
  const [active, setActive] = useState(0);
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  const tab = TABS[active];

  function onKey(e: React.KeyboardEvent, i: number) {
    if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") return;
    e.preventDefault();
    const next = (i + (e.key === "ArrowRight" ? 1 : -1) + TABS.length) % TABS.length;
    setActive(next);
    refs.current[next]?.focus();
  }

  return (
    <div className="space-y-8">
      <div role="tablist" aria-label="Módulos de EscudoSST" className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:justify-center sm:px-0">
        {TABS.map((t, i) => (
          <button
            key={t.code}
            ref={(el) => {
              refs.current[i] = el;
            }}
            role="tab"
            id={`modtab-${t.code}`}
            aria-selected={i === active}
            aria-controls={`modpanel-${t.code}`}
            tabIndex={i === active ? 0 : -1}
            onClick={() => setActive(i)}
            onKeyDown={(e) => onKey(e, i)}
            className={`flex shrink-0 items-center gap-2 rounded-full px-4 py-2.5 text-sm font-bold transition ${
              i === active
                ? "bg-[var(--site-navy)] text-white shadow-md"
                : "bg-white text-[var(--site-navy-title)] ring-1 ring-[var(--site-border)] hover:ring-[var(--site-action)]"
            }`}
          >
            <Icon name={t.icon} size={16} /> {t.label}
          </button>
        ))}
      </div>

      <div
        role="tabpanel"
        id={`modpanel-${tab.code}`}
        aria-labelledby={`modtab-${tab.code}`}
        className="grid grid-cols-1 items-center gap-8 lg:grid-cols-[1fr_1.1fr] lg:gap-14"
      >
        <div key={`txt-${tab.code}`} className="space-y-4 motion-safe:animate-[fadein_.35s_ease]">
          <h3 className="font-display text-[24px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[28px]">{tab.title}</h3>
          <ul className="space-y-3">
            {tab.bullets.map((b) => (
              <li key={b} className="flex items-start gap-3 text-[16px] text-[var(--site-text)]">
                <span className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#22A35A] text-white">
                  <Icon name="check" size={13} />
                </span>
                {b}
              </li>
            ))}
          </ul>
          <p className="flex flex-wrap items-center gap-3 pt-1 text-sm text-[var(--site-text-2)]">
            {plans[tab.code] && <span className="rounded-full bg-[var(--site-surface)] px-3 py-1 font-semibold">Desde el plan {plans[tab.code]}</span>}
            <Link href="/producto" className="font-bold text-[var(--site-action)] hover:underline">
              Ver todos los módulos →
            </Link>
          </p>
        </div>
        <div key={`art-${tab.code}`} className="motion-safe:animate-[fadein_.35s_ease]">
          {tab.art}
        </div>
      </div>
      <style>{`@keyframes fadein { from { opacity: 0; transform: translateY(6px) } to { opacity: 1; transform: none } }`}</style>
    </div>
  );
}
