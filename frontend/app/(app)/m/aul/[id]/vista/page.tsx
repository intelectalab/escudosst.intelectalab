"use client";

// «Ver como participante» (M-AUL SCR-AUL-04): el curso como lo ve quien aprende, sin matrícula ni avance.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useApp } from "@/components/AppContext";
import { CoursePlayer } from "@/components/addons/AulLearner";
import { AulCrumbs, NoAccess, useAulAccess } from "@/components/modules/AulAdmin";

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export default function AulPreviewPage() {
  const { id } = useParams<{ id: string }>();
  const { company } = useApp();
  const { ok } = useAulAccess();
  if (!ok || ["worker", "external_learner"].includes(company.role.code)) return <NoAccess text="Tu rol no administra cursos del aula." />;
  if (!UUID.test(id)) return <NoAccess text="Ese curso no existe." />;
  return (
    <div className="space-y-3">
      <AulCrumbs items={[{ label: "Curso", href: `/m/aul/${id}` }, { label: "Ver como participante" }]} />
      <Link href={`/m/aul/${id}`} className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand">‹ Volver al curso</Link>
      <CoursePlayer id={id} preview />
    </div>
  );
}
