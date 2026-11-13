"use client";

import { useParams } from "next/navigation";
import { AtsReport } from "@/components/modules/FieldReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <AtsReport id={id} />;
}
