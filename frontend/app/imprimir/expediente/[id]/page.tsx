"use client";

import { useParams } from "next/navigation";
import { DossierSheet } from "@/components/modules/SunPrint";

export default function ImprimirExpediente() {
  const { id } = useParams<{ id: string }>();
  return <DossierSheet id={id} />;
}
