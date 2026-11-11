"use client";

// Curso del aula virtual por dentro (M-AUL SCR-AUL-04..08). La lista de cursos sigue en /m/aul (página del módulo);
// quien aprende abre sus cursos en /aula/<matrícula>.

import { useParams } from "next/navigation";
import { useApp } from "@/components/AppContext";
import { AulCourse } from "@/components/modules/AulCourse";
import { NoAccess, useAulAccess } from "@/components/modules/AulAdmin";

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export default function AulCoursePage() {
  const { id } = useParams<{ id: string }>();
  const { company } = useApp();
  const { ok, readOnly } = useAulAccess();
  if (!ok || ["worker", "external_learner"].includes(company.role.code)) return <NoAccess text="Tu rol no administra cursos del aula. Tus cursos están en «Mis cursos»." />;
  if (!UUID.test(id)) return <NoAccess text="Ese curso no existe." />;
  return <AulCourse id={id} readOnly={readOnly} />;
}
