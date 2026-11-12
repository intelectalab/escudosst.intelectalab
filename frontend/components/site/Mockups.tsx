// Maquetas visuales de pantallas para el sitio (docs/07 §1.4): datos ficticios, siempre rotulados «Ejemplo».
// Son HTML/SVG propios, livianos y nítidos en cualquier tamaño; no son capturas reales ni de empresas reales.

function Example() {
  return <span className="rounded-md bg-[var(--site-amber)]/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[#6b5003]">Ejemplo</span>;
}

function Window({ title, children, className = "" }: { title: string; children: React.ReactNode; className?: string }) {
  return (
    <div className={`overflow-hidden rounded-2xl border border-[var(--site-border)] bg-white shadow-[0_24px_60px_-20px_rgba(13,59,110,.45)] ${className}`} aria-hidden="true">
      <div className="flex items-center gap-1.5 border-b border-[var(--site-border)] bg-[var(--site-surface)] px-4 py-2.5">
        <span className="h-2.5 w-2.5 rounded-full bg-[#E2453C]/70" />
        <span className="h-2.5 w-2.5 rounded-full bg-[#F4B90F]/80" />
        <span className="h-2.5 w-2.5 rounded-full bg-[#22A35A]/70" />
        <span className="ml-3 truncate text-xs font-semibold text-[var(--site-text-2)]">{title}</span>
        <span className="ml-auto">
          <Example />
        </span>
      </div>
      <div className="p-4 sm:p-5">{children}</div>
    </div>
  );
}

const chip = {
  ok: "bg-[#22A35A]/12 text-[#187A42]",
  warn: "bg-[#F4B90F]/20 text-[#6b5003]",
  bad: "bg-[#E2453C]/12 text-[#B3261E]",
};

/** Héroe: tablero de cumplimiento con anillo, vencimientos y avance mensual. */
export function DashboardMock() {
  const pct = 78;
  const r = 42;
  const c = 2 * Math.PI * r;
  return (
    <Window title="Panel SST · Constructora Andes (ficticia)">
      <div className="grid-cols-1 grid gap-4 sm:grid-cols-[auto_1fr]">
        <div className="flex flex-col items-center justify-center rounded-xl bg-[var(--site-surface)] p-4">
          <svg width="112" height="112" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r={r} fill="none" stroke="#E3EAF3" strokeWidth="10" />
            <circle cx="50" cy="50" r={r} fill="none" stroke="#1FB5A3" strokeWidth="10" strokeLinecap="round" strokeDasharray={`${(pct / 100) * c} ${c}`} transform="rotate(-90 50 50)" />
            <text x="50" y="49" textAnchor="middle" fontSize="20" fontWeight="700" fill="#00254D">
              {pct}%
            </text>
            <text x="50" y="64" textAnchor="middle" fontSize="8" fill="#636d7a">
              cumplimiento
            </text>
          </svg>
          <p className="mt-1 text-[11px] font-semibold text-[var(--site-text-2)]">Línea base Ley 29783</p>
        </div>
        <div className="space-y-2">
          <p className="text-xs font-bold uppercase tracking-wider text-[var(--site-text-2)]">Próximos vencimientos</p>
          {[
            ["EMO periódico · 4 trabajadores", "en 5 días", chip.warn],
            ["SCTR · contratista Obras Sur", "vencido", chip.bad],
            ["Extintores · Almacén", "en 18 días", chip.ok],
            ["Capacitación en altura", "en 9 días", chip.warn],
          ].map(([t, d, cls]) => (
            <div key={t} className="flex items-center justify-between gap-2 rounded-lg border border-[var(--site-border)] px-3 py-2 text-xs">
              <span className="truncate font-medium text-[var(--site-text)]">{t}</span>
              <span className={`shrink-0 rounded-md px-2 py-0.5 font-bold ${cls}`}>{d}</span>
            </div>
          ))}
        </div>
      </div>
      <div className="mt-4 flex items-end gap-1.5 rounded-xl bg-[var(--site-surface)] p-3">
        {[40, 55, 48, 62, 70, 66, 78].map((h, i) => (
          <span key={i} className="flex-1 rounded-t-[4px] bg-[#1b6fb7]" style={{ height: `${h * 0.7}px`, opacity: 0.45 + i * 0.08 }} />
        ))}
        <span className="ml-2 self-center text-[11px] font-semibold text-[var(--site-text-2)]">Inspecciones cerradas por mes</span>
      </div>
    </Window>
  );
}

/** Modo Inspección SUNAFIL: expediente por trabajador con lo encontrado y lo faltante. */
export function SunafilMock() {
  return (
    <Window title="Expediente SUNAFIL · J. Quispe (ficticio)">
      <div className="mb-3 flex items-center justify-between">
        <div>
          <p className="text-sm font-bold text-[var(--site-navy-title)]">Periodo: enero – octubre</p>
          <p className="text-xs text-[var(--site-text-2)]">9 de 11 evidencias encontradas</p>
        </div>
        <span className="rounded-lg bg-[#1b6fb7] px-3 py-1.5 text-xs font-bold text-white">Compartir enlace</span>
      </div>
      <div className="mb-3 h-2 overflow-hidden rounded-full bg-[#E3EAF3]">
        <div className="h-full w-[82%] rounded-full bg-[#22A35A]" />
      </div>
      <ul className="space-y-1.5 text-xs">
        {[
          ["Inducción SST", true],
          ["Entrega de EPP con firma", true],
          ["EMO vigente (aptitud)", true],
          ["Capacitación trabajo en altura", false],
          ["IPERC de su puesto", true],
          ["Registro de entrega de RISST", false],
        ].map(([t, ok]) => (
          <li key={String(t)} className="flex items-center justify-between rounded-lg border border-[var(--site-border)] px-3 py-1.5">
            <span>{t}</span>
            <span className={`rounded-md px-2 py-0.5 font-bold ${ok ? chip.ok : chip.bad}`}>{ok ? "Encontrado" : "Falta · corregir"}</span>
          </li>
        ))}
      </ul>
    </Window>
  );
}

/** Fotocheck SST con QR: estado habilitado según requisitos. */
export function FotocheckMock() {
  const cells = "1110111010011101101110010111011100101101011101101011101001110111";
  return (
    <div className="relative mx-auto w-full max-w-sm" aria-hidden="true">
      <div className="absolute -inset-6 -z-10 rounded-[32px] bg-gradient-to-br from-[#1FB5A3]/25 to-[#1b6fb7]/20 blur-2xl" />
      <div className="overflow-hidden rounded-2xl bg-white shadow-[0_24px_60px_-20px_rgba(13,59,110,.45)]">
        <div className="site-stripe h-2" />
        <div className="flex items-center justify-between bg-[var(--site-navy)] px-5 py-3 text-white">
          <span className="font-display font-bold">Fotocheck SST</span>
          <Example />
        </div>
        <div className="grid grid-cols-[1fr_auto] gap-4 p-5">
          <div className="space-y-1.5">
            <div className="grid h-14 w-14 place-items-center rounded-full bg-[var(--site-surface)] font-display text-lg font-bold text-[var(--site-navy)]">RM</div>
            <p className="font-display font-bold text-[var(--site-navy-title)]">Rosa Mendoza</p>
            <p className="text-xs text-[var(--site-text-2)]">Operaria de planta · ficticia</p>
            <span className={`inline-block rounded-md px-2 py-0.5 text-xs font-bold ${chip.ok}`}>Habilitada</span>
          </div>
          <div className="grid h-28 w-28 grid-cols-8 gap-[2px] rounded-lg border border-[var(--site-border)] p-2">
            {cells.split("").map((v, i) => (
              <span key={i} className={v === "1" ? "rounded-[1px] bg-[var(--site-navy)]" : ""} />
            ))}
          </div>
        </div>
        <ul className="grid grid-cols-3 gap-px border-t border-[var(--site-border)] bg-[var(--site-border)] text-center text-[11px]">
          {["Inducción", "EMO apto", "SCTR"].map((t) => (
            <li key={t} className="bg-white px-2 py-2 font-semibold text-[#187A42]">
              ✓ {t}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

/** Reporte sin app: conversación por WhatsApp que se vuelve un reporte de condición subestándar. */
export function ReportMock() {
  return (
    <div className="mx-auto w-full max-w-[300px] rounded-[36px] border-[10px] border-[#1d2633] bg-[#ECE5DD] shadow-[0_24px_60px_-20px_rgba(13,59,110,.5)]" aria-hidden="true">
      <div className="flex items-center gap-2 rounded-t-[26px] bg-[#075E54] px-4 py-3 text-white">
        <span className="grid h-8 w-8 place-items-center rounded-full bg-white/20 text-xs font-bold">SST</span>
        <span className="text-sm font-semibold">Reportes SST</span>
        <span className="ml-auto">
          <Example />
        </span>
      </div>
      <div className="space-y-2 p-3 text-[12px]">
        <p className="ml-auto max-w-[80%] rounded-lg rounded-tr-none bg-[#DCF8C6] px-3 py-2">Hay un cable pelado junto a la escalera del almacén 2</p>
        <p className="max-w-[85%] rounded-lg rounded-tl-none bg-white px-3 py-2">
          Gracias. ¿Puedes enviar una foto? Registramos tu reporte como <b>condición subestándar</b>.
        </p>
        <div className="ml-auto h-20 w-36 rounded-lg bg-gradient-to-br from-[#9aa7b6] to-[#5d6b7c]" />
        <p className="max-w-[85%] rounded-lg rounded-tl-none bg-white px-3 py-2">
          Listo: reporte <b>N.° 0142</b>. El jefe SST ya fue notificado.
        </p>
      </div>
    </div>
  );
}

/** Ilustraciones de los pasos de «Cómo funciona» (SVG propios con la paleta del sitio). */
export function StepArt({ step }: { step: number }) {
  const common = { width: 120, height: 96, viewBox: "0 0 120 96", "aria-hidden": true } as const;
  switch (step) {
    case 1:
      return (
        <svg {...common}>
          <rect x="18" y="14" width="84" height="68" rx="10" fill="#F7FAFE" stroke="#0D3B6E" strokeOpacity=".15" />
          <rect x="30" y="28" width="60" height="10" rx="5" fill="#1b6fb7" opacity=".25" />
          <rect x="30" y="46" width="44" height="10" rx="5" fill="#0D3B6E" opacity=".15" />
          <rect x="30" y="62" width="34" height="10" rx="5" fill="#1b6fb7" />
          <circle cx="92" cy="70" r="14" fill="#22A35A" />
          <path d="M85 70l5 5 9-10" stroke="#fff" strokeWidth="3" fill="none" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );
    case 2:
      return (
        <svg {...common}>
          <circle cx="60" cy="48" r="32" fill="#F7FAFE" stroke="#0D3B6E" strokeOpacity=".15" />
          <circle cx="60" cy="48" r="24" fill="none" stroke="#E3EAF3" strokeWidth="8" />
          <circle cx="60" cy="48" r="24" fill="none" stroke="#1FB5A3" strokeWidth="8" strokeDasharray="95 151" transform="rotate(-90 60 48)" strokeLinecap="round" />
          <text x="60" y="53" textAnchor="middle" fontSize="14" fontWeight="700" fill="#00254D">
            63%
          </text>
        </svg>
      );
    case 3:
      return (
        <svg {...common}>
          <rect x="14" y="18" width="40" height="60" rx="8" fill="#F7FAFE" stroke="#0D3B6E" strokeOpacity=".15" />
          <rect x="66" y="18" width="40" height="28" rx="8" fill="#1b6fb7" opacity=".18" />
          <rect x="66" y="50" width="40" height="28" rx="8" fill="#F4B90F" opacity=".35" />
          <path d="M24 34h20M24 46h20M24 58h12" stroke="#0D3B6E" strokeOpacity=".4" strokeWidth="4" strokeLinecap="round" />
        </svg>
      );
    case 4:
      return (
        <svg {...common}>
          <rect x="38" y="10" width="44" height="76" rx="10" fill="#0D3B6E" />
          <rect x="44" y="20" width="32" height="50" rx="4" fill="#F7FAFE" />
          <path d="M50 34h20M50 44h20M50 54h12" stroke="#1b6fb7" strokeWidth="4" strokeLinecap="round" />
          <circle cx="92" cy="26" r="10" fill="#F4B90F" />
          <path d="M92 21v6M92 30v.5" stroke="#0D3B6E" strokeWidth="3" strokeLinecap="round" />
        </svg>
      );
    default:
      return (
        <svg {...common}>
          <path d="M60 8l38 14v24c0 22-16 36-38 42-22-6-38-20-38-42V22z" fill="#0D3B6E" />
          <path d="M60 18l28 10v18c0 16-12 27-28 32-16-5-28-16-28-32V28z" fill="#1b6fb7" />
          <path d="M47 48l9 9 18-20" stroke="#fff" strokeWidth="6" fill="none" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );
  }
}
