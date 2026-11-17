"use client";

import { useParams } from "next/navigation";
import { PetsField } from "@/components/modules/PetsReport";

export default function VerPets() {
  const { id } = useParams<{ id: string }>();
  return <PetsField procedureId={id} />;
}
