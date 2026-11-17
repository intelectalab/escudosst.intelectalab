"use client";

import { useParams } from "next/navigation";
import { PpeDeliveryReport } from "@/components/modules/RecordReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <PpeDeliveryReport id={id} />;
}
