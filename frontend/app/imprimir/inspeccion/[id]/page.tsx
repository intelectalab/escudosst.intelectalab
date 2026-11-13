"use client";

import { useParams } from "next/navigation";
import { InspectionReport } from "@/components/modules/FieldReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <InspectionReport id={id} />;
}
