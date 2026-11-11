"use client";

// Portería (M-ING SCR-ING-01/02/03): pantalla para el operador de la garita en tablet o celular. Solo para quien opera
// la garita: módulo habilitado y permiso de registrar ingresos (ing.access_events.create, M-ING §4).

import Link from "next/link";
import { useEffect, useState } from "react";
import { useApp } from "@/components/AppContext";
import { IngGate } from "@/components/modules/IngGate";
import { api } from "@/lib/api";
import type { PermissionsResponse } from "@/lib/types";

export default function GaritaPage() {
  const { modules, company } = useApp();
  const m = modules.find((x) => x.code === "ING");
  const usable = !!m && m.role_can_use && m.mode === "enabled";
  const [operator, setOperator] = useState<boolean | null>(null);
  useEffect(() => {
    if (!usable) return;
    let live = true;
    api<PermissionsResponse>("/me/permissions", { withCompany: true })
      .then((r) => live && setOperator(r.data.includes("ing.access_events.create")))
      .catch(() => live && setOperator(false));
    return () => {
      live = false;
    };
  }, [usable, company.company_id]);

  if (usable && operator === null) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;
  if (!usable || !operator) {
    return (
      <div className="space-y-3 rounded-xl border border-line bg-surface p-8">
        <h1 className="text-xl font-semibold">Portería no disponible</h1>
        <p className="text-muted">
          {m?.mode === "locked" ? "El módulo Ingreso por QR no está en el plan de tu empresa."
            : m?.mode === "read_only" ? "El módulo Ingreso por QR está en solo lectura."
              : usable ? "La portería es para quien opera la garita: tu rol puede consultar los ingresos en el módulo Ingreso por QR, pero no registrarlos."
                : "Tu rol no tiene acceso a la portería."}
        </p>
        <div className="flex flex-wrap gap-2">
          {usable && <Link href="/m/ing" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">Ver ingresos</Link>}
          <Link href="/inicio" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">Volver al inicio</Link>
        </div>
      </div>
    );
  }
  return <IngGate />;
}
