"use client";

import { useParams } from "next/navigation";
import { PetsReport } from "@/components/modules/PetsReport";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <PetsReport id={id} />;
}
