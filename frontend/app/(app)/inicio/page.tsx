"use client";

// Pantalla de inicio provisional. El panel real es el módulo DSH (fase 0).

import { useApp } from "@/components/AppContext";

export default function InicioPage() {
  const { user, company, modules } = useApp();
  const enabled = modules.filter((m) => m.mode === "enabled").length;
  const locked = modules.filter((m) => m.mode === "locked").length;

  return (
    <div className="max-w-4xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Hola, {user.full_name.split(" ")[0]}</h1>
        <p className="text-muted">Estás trabajando en {company.name}.</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Módulos habilitados" value={enabled} tone="ok" />
        <Stat label="Disponibles en otro plan" value={locked} tone="na" />
        <Stat label="Tu rol" value={company.role.name} tone="brand" />
      </div>

      <div className="rounded-xl border border-line bg-surface p-6 text-muted">
        El panel con indicadores llegará con el módulo <strong className="text-fg">Dashboard e indicadores (DSH)</strong>.
      </div>
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: string | number; tone: "ok" | "na" | "brand" }) {
  const tones = { ok: "bg-ok-soft text-ok", na: "bg-na-soft text-na", brand: "bg-brand-soft text-brand-strong" };
  return (
    <div className={`rounded-xl p-5 ${tones[tone]}`}>
      <p className="text-sm">{label}</p>
      <p className="font-display text-2xl font-semibold mt-1">{value}</p>
    </div>
  );
}
