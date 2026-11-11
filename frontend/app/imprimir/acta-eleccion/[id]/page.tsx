"use client";

import { useParams } from "next/navigation";
import { ElectionMinutesSheet } from "@/components/modules/ComPrint";

export default function ImprimirActaEleccion() {
  const { id } = useParams<{ id: string }>();
  return <ElectionMinutesSheet id={id} />;
}
