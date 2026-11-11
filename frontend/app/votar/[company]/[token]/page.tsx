"use client";

// Votación del Comité SST con el enlace de un solo uso del elector (sin sesión, M-COM RN-COM-03).

import { useParams } from "next/navigation";
import { PublicBallotView } from "@/components/modules/ComVoteLinks";

export default function VotarConEnlace() {
  const { company, token } = useParams<{ company: string; token: string }>();
  return <PublicBallotView company={company} token={token} />;
}
