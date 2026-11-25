"use client";

// Botón del asistente sobre la lista de registros de ACC («Registrar evento»), PTR («Solicitar PETAR») y TRB
// («Cargar desde Excel»). Al terminar el asistente, la lista se vuelve a cargar. El botón solo aparece si el rol
// puede crear en la tabla (según el esquema del módulo) y el módulo no está en solo lectura.

import { useEffect, useState } from "react";
import { records } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { ModuleRecords } from "@/components/records/ModuleRecords";
import { AccWizard } from "./AccWizard";
import { PtrWizard } from "./PtrWizard";
import { TrbImport } from "./TrbImport";
import { ReportButton } from "./CtrExtras";
import { PtrBoard } from "./PtrBoard";
import { ActRecurrent } from "./ActRecurrent";

const ACTIONS: Record<string, { table: string; label: string; icon: string }> = {
  ACC: { table: "sst_events", label: "Registrar evento", icon: "alert" },
  PTR: { table: "work_permits", label: "Solicitar PETAR", icon: "hardhat" },
  TRB: { table: "workers", label: "Cargar desde Excel", icon: "download" },
};

export function hasModuleWizard(code: string) {
  return code in ACTIONS || code === "ACT";   // ACT: solo su botón de reporte
}

export function ModuleWizards({ code, readOnly }: { code: string; readOnly: boolean }) {
  const a = ACTIONS[code];
  const [can, setCan] = useState(false);
  const [open, setOpen] = useState(false);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    if (!a || readOnly) return;
    records.schema(code).then((s) => setCan(!!s.resources.find((r) => r.table === a.table)?.can.create)).catch(() => setCan(false));
  }, [a, code, readOnly]);

  const close = () => setOpen(false);
  const done = () => setVersion((v) => v + 1);

  return (
    <div className="space-y-4">
      {((a && can && !readOnly) || ["ACC", "ACT", "PTR"].includes(code)) && (
        <div className="flex flex-wrap items-center justify-end gap-2">
          {/* M-ACC §11: eventos agrupados por tipo, gravedad, sede, parte del cuerpo y agente. */}
          {code === "ACC" && <AccReport />}
          {code === "PTR" && <ReportButton path="/ptr/stats.xlsx" file={`permisos-${new Date().getFullYear()}.xlsx`} label="Estadísticas y suspensiones en Excel" />}
          {code === "ACT" && <ReportButton path="/act/report.xlsx" file={`reportes-${new Date().getFullYear()}.xlsx`} label="Reporte por área, tipo y tiempo de atención" />}
          {a && can && !readOnly && (
            <Button variant={code === "TRB" ? "secondary" : "primary"} icon={a.icon} onClick={() => setOpen(true)}>
              {a.label}
            </Button>
          )}
        </div>
      )}
      {/* M-PTR SCR-PTR-01: tablero de permisos activos por sede con su mapa. */}
      {code === "PTR" && <PtrBoard key={`b${version}`} />}
      {/* M-ACT §11: ranking de condiciones recurrentes. */}
      {code === "ACT" && <ActRecurrent key={`r${version}`} />}
      {open && code === "ACC" && <AccWizard onClose={close} onDone={done} />}
      {open && code === "PTR" && <PtrWizard onClose={close} onDone={done} />}
      {open && code === "TRB" && <TrbImport onClose={close} onDone={done} />}
      <ModuleRecords key={version} code={code} readOnly={readOnly} />
    </div>
  );
}

/** M-ACC §11: reporte agrupado del periodo que elige el usuario (por defecto, del 1 de enero a hoy). */
function AccReport() {
  const today = new Date().toISOString().slice(0, 10);
  const [p, setP] = useState({ from: `${today.slice(0, 4)}-01-01`, to: today });
  const ok = !!p.from && !!p.to && p.from <= p.to;
  return (
    <span className="inline-flex flex-wrap items-end gap-2 text-xs">
      <label>Desde<input type="date" className="input mt-1 !w-36" value={p.from} max={p.to || undefined} onChange={(e) => setP({ ...p, from: e.target.value })} /></label>
      <label>Hasta<input type="date" className="input mt-1 !w-36" value={p.to} min={p.from || undefined} onChange={(e) => setP({ ...p, to: e.target.value })} /></label>
      {ok ? <ReportButton path={`/acc/report.xlsx?from=${p.from}&to=${p.to}`} file={`eventos-${p.from}-${p.to}.xlsx`} label="Reporte de eventos en Excel" />
        : <span className="text-bad">La fecha «Desde» debe ser anterior o igual a «Hasta».</span>}
    </span>
  );
}
