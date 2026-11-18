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
          {code === "ACC" && <ReportButton path="/acc/report.xlsx" file={`eventos-${new Date().getFullYear()}.xlsx`} label="Reporte de eventos en Excel" />}
          {code === "PTR" && <ReportButton path="/ptr/stats.xlsx" file={`permisos-${new Date().getFullYear()}.xlsx`} label="Estadísticas y suspensiones en Excel" />}
          {code === "ACT" && <ReportButton path="/act/report.xlsx" file={`reportes-${new Date().getFullYear()}.xlsx`} label="Reporte por área, tipo y tiempo de atención" />}
          {a && can && !readOnly && (
            <Button variant={code === "TRB" ? "secondary" : "primary"} icon={a.icon} onClick={() => setOpen(true)}>
              {a.label}
            </Button>
          )}
        </div>
      )}
      {open && code === "ACC" && <AccWizard onClose={close} onDone={done} />}
      {open && code === "PTR" && <PtrWizard onClose={close} onDone={done} />}
      {open && code === "TRB" && <TrbImport onClose={close} onDone={done} />}
      <ModuleRecords key={version} code={code} readOnly={readOnly} />
    </div>
  );
}
