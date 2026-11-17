"use client";

// Mis accesos (M-AUD): quién consultó tus datos sensibles (fecha, rol, qué dato y motivo). Nunca muestra el contenido.

import { useEffect, useState } from "react";
import { Alert, Empty, Loading, Title } from "@/components/worker/WorkerUi";
import { errorText, workerApi } from "@/lib/workerApp";

type Access = { at: string; who: string | null; role: string | null; what: string; action: string; purpose: string | null };

export default function MyAccessesPage() {
  const [rows, setRows] = useState<Access[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { workerApi<Access[]>("/accesses").then(setRows).catch((e) => setError(errorText(e))); }, []);
  return (
    <>
      <Title sub="Quién consultó tus datos de salud y otros datos sensibles.">Mis accesos</Title>
      {error && <Alert>{error}</Alert>}
      {!rows && !error && <Loading />}
      {rows && rows.length === 0 && <Empty text="Nadie más ha consultado tus datos sensibles." />}
      {rows && rows.length > 0 && (
        <ul className="space-y-2">
          {rows.map((r, i) => (
            <li key={i} className="rounded-2xl border border-line bg-surface p-3 text-sm">
              <b className="block">{r.action} · {r.what}</b>
              <span className="text-muted">{new Date(r.at).toLocaleString("es-PE")} · {r.who ?? "Usuario"}{r.role ? ` (${r.role})` : ""}{r.purpose ? ` · ${r.purpose}` : ""}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
