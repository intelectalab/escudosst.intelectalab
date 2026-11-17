"use client";

import { useParams } from "next/navigation";
import { ElectionCallReport } from "@/components/modules/RecordReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <ElectionCallReport id={id} />;
}
