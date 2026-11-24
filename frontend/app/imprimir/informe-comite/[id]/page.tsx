"use client";

// Informe anual del comité SST (M-COM §11) para la revisión por la dirección. ?anio=<año> elige el año (por defecto el actual).

import { useParams, useSearchParams } from "next/navigation";
import { Suspense } from "react";
import { CommitteeAnnualReport } from "@/components/modules/RecordReports";

function Report() {
  const { id } = useParams<{ id: string }>();
  const q = useSearchParams().get("anio");
  const year = q && /^\d{4}$/.test(q) ? Number(q) : new Date().getFullYear();
  return <CommitteeAnnualReport id={id} year={year} />;
}

export default function Imprimir() {
  return <Suspense fallback={<p className="p-8">Preparando el informe…</p>}><Report /></Suspense>;
}
