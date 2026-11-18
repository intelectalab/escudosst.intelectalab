"use client";

import { useParams } from "next/navigation";
import { RisstReport } from "@/components/modules/PlanningReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <RisstReport id={id} />;
}
