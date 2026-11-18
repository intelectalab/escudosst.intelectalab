"use client";

import { useParams } from "next/navigation";
import { DrillReport } from "@/components/modules/DrillReport";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <DrillReport id={id} />;
}
