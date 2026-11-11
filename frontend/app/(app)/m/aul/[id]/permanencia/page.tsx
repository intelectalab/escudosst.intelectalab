"use client";

// Tiempo de permanencia de un curso (M-AUL SCR-AUL-14).

import { useParams } from "next/navigation";
import { useApp } from "@/components/AppContext";
import { NoAccess, useAulAccess } from "@/components/modules/AulAdmin";
import { AulTimeReport } from "@/components/modules/AulTools";

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export default function AulTimePage() {
  const { id } = useParams<{ id: string }>();
  const { company } = useApp();
  const { ok } = useAulAccess();
  if (!ok || ["worker", "external_learner"].includes(company.role.code)) return <NoAccess text="Tu rol no ve los reportes del aula." />;
  if (!UUID.test(id)) return <NoAccess text="Ese curso no existe." />;
  return <AulTimeReport courseId={id} />;
}
