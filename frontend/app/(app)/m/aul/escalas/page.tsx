"use client";

// Escalas de calificación del aula virtual (M-AUL SCR-AUL-10).

import { useApp } from "@/components/AppContext";
import { NoAccess, useAulAccess } from "@/components/modules/AulAdmin";
import { AulScales } from "@/components/modules/AulTools";

export default function AulScalesPage() {
  const { company } = useApp();
  const { ok, readOnly } = useAulAccess();
  if (!ok || ["worker", "external_learner"].includes(company.role.code)) return <NoAccess text="Tu rol no administra las escalas del aula." />;
  return <AulScales readOnly={readOnly} />;
}
