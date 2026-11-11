"use client";

import { useParams } from "next/navigation";
import { InvReportSheet } from "@/components/modules/InvReport";

export default function ImprimirInvestigacion() {
  const { id } = useParams<{ id: string }>();
  return <InvReportSheet id={id} />;
}
