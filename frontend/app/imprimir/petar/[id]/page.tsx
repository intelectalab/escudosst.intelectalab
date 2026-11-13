"use client";

import { useParams } from "next/navigation";
import { PermitReport } from "@/components/modules/FieldReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <PermitReport id={id} />;
}
