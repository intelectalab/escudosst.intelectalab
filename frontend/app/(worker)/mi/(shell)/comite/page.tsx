"use client";

// Elección del comité (M-COM, docs/07 §5.2 punto 7): la misma cédula secreta del módulo, con botones grandes.

import { useEffect, useState } from "react";
import { Ballot } from "@/components/modules/ComElection";
import { Alert, Loading, Title } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { api } from "@/lib/api";
import { fmtDate } from "@/lib/workerApp";

type Mine = { id: string; title: string; voting_closes_at: string | null; on_roll: boolean; has_voted: boolean; linked: boolean } | null;

export default function WorkerElectionPage() {
  const { reload } = useWorker();
  const [e, setE] = useState<Mine | undefined>(undefined);
  useEffect(() => {
    api<{ data: Mine }>("/com/my-election", { withCompany: true }).then((r) => setE(r.data)).catch(() => setE(null));
  }, []);

  if (e === undefined) return <Loading />;
  return (
    <>
      <Title sub={e ? `${e.title}${e.voting_closes_at ? ` · vota hasta el ${fmtDate(e.voting_closes_at, true)}` : ""}` : undefined}>Elección del comité</Title>
      {!e ? <Alert tone="na">La votación no está abierta ahora. Te avisaremos cuando abra.</Alert>
        : !e.on_roll ? <Alert tone="na">No figuras en el padrón de esta elección. Si crees que es un error, consulta con el área de SST.</Alert>
          : <Ballot election={{ id: e.id }} onVoted={() => void reload()} />}
    </>
  );
}
