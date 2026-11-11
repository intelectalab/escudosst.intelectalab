"use client";

// Banco de preguntas del aula virtual (M-AUL SCR-AUL-09).

import { useApp } from "@/components/AppContext";
import { NoAccess, useAulAccess } from "@/components/modules/AulAdmin";
import { AulBank } from "@/components/modules/AulBank";

export default function AulBankPage() {
  const { company } = useApp();
  const { ok, readOnly } = useAulAccess();
  if (!ok || ["worker", "external_learner"].includes(company.role.code)) return <NoAccess text="Tu rol no administra el banco de preguntas." />;
  return <AulBank readOnly={readOnly} />;
}
