"use client";

import { useParams } from "next/navigation";
import { RegisterReport } from "@/components/modules/RegisterReport";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <RegisterReport id={id} />;
}
