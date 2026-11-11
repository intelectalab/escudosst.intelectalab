"use client";

// «Por calificar» del aula virtual (M-AUL SCR-AUL-06 y 13): evaluaciones prácticas y preguntas abiertas.

import { useApp } from "@/components/AppContext";
import { NoAccess, useAulAccess } from "@/components/modules/AulAdmin";
import { AulGrading } from "@/components/modules/AulTools";

export default function AulGradingPage() {
  const { company } = useApp();
  const { ok, readOnly } = useAulAccess();
  if (!ok || ["worker", "external_learner"].includes(company.role.code)) return <NoAccess text="Tu rol no califica evaluaciones del aula." />;
  return <AulGrading readOnly={readOnly} />;
}
