"use client";

// Panel de la consultora (M-CON SCR-CON-01, docs/07 §5.1): se abre desde el menú en cualquier empresa de la consultora
// o de sus clientes; la API valida que el usuario sea administrador o consultor de la consultora.

import { ConsultancyPanel } from "@/components/modules/ConPanel";

export default function ConsultoraPage() {
  return (
    <div className="space-y-4">
      <div className="space-y-1">
        <nav aria-label="Migas de pan" className="text-xs text-muted">
          EscudoSST › <span className="text-fg">Panel de la consultora</span>
        </nav>
        <h1 className="font-display text-2xl font-bold leading-tight">Panel de la consultora</h1>
      </div>
      <ConsultancyPanel />
    </div>
  );
}
