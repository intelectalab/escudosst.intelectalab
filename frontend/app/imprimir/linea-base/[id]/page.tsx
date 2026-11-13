"use client";

import { useParams } from "next/navigation";
import { BaselineReport } from "@/components/modules/PlanningReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <BaselineReport id={id} />;
}
