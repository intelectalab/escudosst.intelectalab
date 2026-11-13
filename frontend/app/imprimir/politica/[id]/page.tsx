"use client";

import { useParams } from "next/navigation";
import { PolicyReport } from "@/components/modules/PlanningReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <PolicyReport id={id} />;
}
