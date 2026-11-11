"use client";

// Portería (M-ING SCR-ING-01/02/03): pantalla para el operador de la garita en tablet o celular.

import Link from "next/link";
import { useApp } from "@/components/AppContext";
import { IngGate } from "@/components/modules/IngGate";

export default function GaritaPage() {
  const { modules } = useApp();
  const m = modules.find((x) => x.code === "ING");
  if (!m || !m.role_can_use || m.mode !== "enabled") {
    return (
      <div className="space-y-3 rounded-xl border border-line bg-surface p-8">
        <h1 className="text-xl font-semibold">Portería no disponible</h1>
        <p className="text-muted">
          {m?.mode === "locked" ? "El módulo Ingreso por QR no está en el plan de tu empresa." : m?.mode === "read_only" ? "El módulo Ingreso por QR está en solo lectura." : "Tu rol no tiene acceso a la portería."}
        </p>
        <Link href="/inicio" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">Volver al inicio</Link>
      </div>
    );
  }
  return <IngGate />;
}
