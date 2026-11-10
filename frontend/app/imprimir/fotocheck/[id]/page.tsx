"use client";

import { useParams } from "next/navigation";
import { CredentialSheet } from "@/components/records/PrintSheets";

export default function ImprimirFotocheck() {
  const { id } = useParams<{ id: string }>();
  return <CredentialSheet id={id} />;
}
