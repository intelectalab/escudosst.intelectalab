"use client";

import { useParams } from "next/navigation";
import { VoteCredentialsSheet } from "@/components/modules/ComVoteLinks";

export default function ImprimirCredencialesVoto() {
  const { id } = useParams<{ id: string }>();
  return <VoteCredentialsSheet id={id} />;
}
