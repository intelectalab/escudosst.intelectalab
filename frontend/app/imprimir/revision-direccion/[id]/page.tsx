"use client";

import { useParams } from "next/navigation";
import { ReviewReport } from "@/components/modules/RecordReports";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <ReviewReport id={id} />;
}
