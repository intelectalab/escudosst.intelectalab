"use client";

import { useParams } from "next/navigation";
import { CertificateSheet } from "@/components/records/PrintSheets";

export default function ImprimirCertificado() {
  const { id } = useParams<{ id: string }>();
  return <CertificateSheet id={id} />;
}
