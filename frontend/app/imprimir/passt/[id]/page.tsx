"use client";

import { useParams } from "next/navigation";
import { ProgramReport } from "@/components/modules/PlanningReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <ProgramReport id={id} />;
}
