"use client";

import { useParams } from "next/navigation";
import { TrainingProgramReport } from "@/components/modules/PlanningReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <TrainingProgramReport id={id} />;
}
