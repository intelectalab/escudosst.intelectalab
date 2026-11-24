"use client";

// Ejecución presupuestal SST (M-PRE §11) imprimible: totales, ejecución por categoría, sede y actividad y detalle por línea.
// ?budget_id=<id> elige el presupuesto (por defecto el aprobado más reciente). Se guarda como PDF desde el diálogo de impresión.

import { useSearchParams } from "next/navigation";
import { Suspense } from "react";
import { BudgetExecutionReport } from "@/components/modules/PreExecution";

function Report() {
  const b = useSearchParams().get("budget_id");
  return <BudgetExecutionReport budgetId={b} />;
}

export default function Imprimir() {
  return <Suspense fallback={<p className="p-8">Preparando el informe…</p>}><Report /></Suspense>;
}
