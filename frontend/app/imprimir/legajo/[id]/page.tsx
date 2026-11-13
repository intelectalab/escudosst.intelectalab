"use client";

import { useParams } from "next/navigation";
import { LegajoReport } from "@/components/modules/LegajoReport";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <LegajoReport id={id} />;
}
