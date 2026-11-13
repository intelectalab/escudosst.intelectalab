"use client";

// Mis avisos (canal «App» de las fichas para el trabajador): encuestas, EPP por reponer, fotocheck, capacitaciones,
// citas de control, votación del comité y más. Sin datos clínicos. Al abrir la página se marcan como leídos.

import { useEffect, useState } from "react";
import { Alert, Empty, Loading, Title } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

type Notice = { id: string; event_code: string; title: string; body: string | null; priority: string; read_at: string | null; at: string };

export default function NoticesPage() {
  const { reload } = useWorker();
  const [items, setItems] = useState<Notice[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    workerApi<Notice[]>("/notifications")
      .then((rows) => {
        setItems(rows);
        if (rows.some((r) => !r.read_at)) void workerApi("/notifications/read-all", { method: "POST" }).then(() => reload()).catch(() => undefined);
      })
      .catch((e) => setError(errorText(e)));
    // Solo al abrir la página.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <>
      <Title sub="Lo que EscudoSST te avisó. Lo que debes firmar o responder está en «Mis pendientes».">Mis avisos</Title>
      {error && <Alert>{error}</Alert>}
      {!items && !error && <Loading />}
      {items && items.length === 0 && <Empty text="No tienes avisos." />}
      {items && items.length > 0 && (
        <ul className="space-y-3">
          {items.map((n) => (
            <li key={n.id} className={`rounded-2xl border bg-surface p-4 ${n.read_at ? "border-line" : "border-brand"}`}>
              <b className="block leading-tight">{n.title}</b>
              {n.body && <span className="mt-1 block text-sm">{n.body}</span>}
              <span className="mt-1 block text-xs text-muted">{fmtDate(n.at, true)}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
