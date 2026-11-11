"use client";

// Recomendaciones de cursos del aula virtual (M-AUL SCR-AUL-15..18).

import { useApp } from "@/components/AppContext";
import { NoAccess, useAulAccess } from "@/components/modules/AulAdmin";
import { AulRecs } from "@/components/modules/AulRecs";

export default function AulRecsPage() {
  const { company } = useApp();
  const { ok, readOnly } = useAulAccess();
  if (!ok || ["worker", "external_learner"].includes(company.role.code)) return <NoAccess text="Tu rol no administra las recomendaciones del aula." />;
  return <AulRecs readOnly={readOnly} />;
}
