"use client";

import { useParams } from "next/navigation";
import { MeetingMinutesSheet } from "@/components/modules/ComPrint";

export default function ImprimirActaReunion() {
  const { id } = useParams<{ id: string }>();
  return <MeetingMinutesSheet id={id} />;
}
