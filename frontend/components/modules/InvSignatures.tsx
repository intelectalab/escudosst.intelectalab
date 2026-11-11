"use client";

// Firmas del equipo investigador (M-INV SCR-INV-02 y SCR-INV-06): estado de la firma de cada integrante y
// «Solicitar firmas», que pide por M-FIR la firma del informe a quienes aún no firmaron. Las firmas salen en el informe.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";
import { Toast } from "@/components/ui/Toast";
import { Card } from "./Summary";

type Member = { id: string; name: string; role: string; state: "signed" | "pending" | "none" | "external"; signed_at: string | null; request_id: string | null; is_me: boolean };
type Data = { members: Member[]; can_request: boolean };

const ROLE: Record<string, string> = { leader: "Líder", member: "Integrante", committee_representative: "Representante del comité", worker_representative: "Representante de los trabajadores", expert: "Especialista", witness: "Testigo" };
const STATE: Record<Member["state"], [string, "ok" | "amber" | "na" | "brand"]> = {
  signed: ["Firmó", "ok"], pending: ["Firma pendiente", "amber"], none: ["Sin pedir", "na"], external: ["Externo: firma en papel", "brand"],
};

export function InvTeamSignatures({ investigationId, readOnly }: { investigationId: string; readOnly: boolean }) {
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Data }>(`/inv/investigations/${investigationId}/signatures`, { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [investigationId]);
  useEffect(() => { load(); }, [load]);

  const request = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: Data & { message: string } }>(`/inv/investigations/${investigationId}/signatures`, { method: "POST", withCompany: true });
      setData((d) => ({ members: r.data.members, can_request: d?.can_request ?? true }));
      setToast(r.data.message);
    } catch (e) {
      const err = e as ApiError;
      setError(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };

  if (!data) return error ? <p className="rounded-xl border border-line px-4 py-3 text-sm text-muted">{error}</p> : <div className="h-20 animate-pulse rounded-xl bg-bg" />;
  const missing = data.members.some((m) => m.state === "none");
  const signed = data.members.filter((m) => m.state === "signed").length;

  return (
    <Card
      title="Firmas del equipo investigador"
      action={!readOnly && data.can_request && data.members.length > 0 ? (
        <Button size="sm" icon="pen" onClick={request} loading={busy} disabled={!missing}>Solicitar firmas</Button>
      ) : undefined}
    >
      {data.members.length === 0 ? (
        <p className="text-sm text-muted">Registra al equipo investigador para pedir sus firmas del informe.</p>
      ) : (
        <>
          <p className="mb-2 text-sm text-muted">{signed} de {data.members.length} integrante(s) firmaron. Cada uno firma el informe desde «Firmas pendientes»; las firmas salen en el PDF.</p>
          <ul className="divide-y divide-line">
            {data.members.map((m) => (
              <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span>
                  <b>{m.name}</b>
                  <span className="block text-xs text-muted">{ROLE[m.role] ?? m.role}{m.signed_at ? ` · firmó el ${new Date(m.signed_at).toLocaleDateString("es-PE")}` : ""}</span>
                </span>
                <Chip tone={STATE[m.state][1]}>{STATE[m.state][0]}</Chip>
              </li>
            ))}
          </ul>
        </>
      )}
      {error && <p className="mt-2 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </Card>
  );
}
