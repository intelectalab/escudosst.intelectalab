"use client";

// Semáforo de requisitos SST de trabajadores (GET /trb/workers/requirements): inducción, EMO apto vigente (solo el
// estado), SCTR, capacitación y EPP de su puesto. Lo usan la ficha 360°, el PETAR y el registro de eventos.

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Chip, type Tone } from "./Summary";

export type Check = { code: string; label: string; status: "ok" | "warn" | "bad" | "na"; detail: string };
export type WorkerReq = {
  worker_id: string;
  name: string;
  document_last4: string | null;
  position: string | null;
  site: string | null;
  area: string | null;
  worker_status: string;
  enabled: boolean;
  checks: Check[];
};

export function useRequirements(ids: string[]) {
  const key = [...new Set(ids.filter(Boolean))].sort().join(",");
  const [data, setData] = useState<Record<string, WorkerReq>>({});
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!key) return;
    let cancel = false;
    api<{ data: WorkerReq[] }>(`/trb/workers/requirements?ids=${key}`, { withCompany: true })
      .then((r) => !cancel && setData(Object.fromEntries(r.data.map((w) => [w.worker_id, w]))))
      .catch((e) => !cancel && setError(e?.detail ?? "No se pudieron revisar los requisitos."));
    return () => {
      cancel = true;
    };
  }, [key]);
  return { data, error };
}

export function EnabledChip({ enabled }: { enabled: boolean }) {
  return <Chip tone={enabled ? "ok" : "bad"}>{enabled ? "Habilitado" : "No habilitado"}</Chip>;
}

export function RequirementChips({ checks }: { checks: Check[] }) {
  return (
    <ul className="flex flex-wrap gap-1.5">
      {checks.map((c) => (
        <li key={c.code} title={c.detail}>
          <Chip tone={c.status as Tone}>
            {c.label}
            <span className="sr-only">: {c.detail}</span>
          </Chip>
        </li>
      ))}
    </ul>
  );
}
