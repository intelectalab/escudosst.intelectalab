"use client";

import { useParams } from "next/navigation";
import { EventNotificationsReport } from "@/components/modules/EventNotificationsReport";

export default function Imprimir() {
  const { id } = useParams<{ id: string }>();
  return <EventNotificationsReport id={id} />;
}
