"use client";

import { useParams } from "next/navigation";
import { PlanReport } from "@/components/modules/PlanningReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <PlanReport id={id} />;
}
