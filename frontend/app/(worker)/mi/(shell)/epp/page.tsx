"use client";

// Mi EPP (M-EPP SCR-EPP-06): lo que te entregaron, hasta cuándo sirve y si falta firmar la recepción.

import { useCallback, useEffect, useState } from "react";
import { Alert, BigButton, Empty, Loading, Pill, SignSheet, Title, type Tone } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

type Delivery = { id: string; item: string; quantity: number; size: string | null; delivered_at: string; expires_on: string | null; status: string; signed: boolean; expiry: "none" | "ok" | "soon" | "expired" };

const EXPIRY: Record<Delivery["expiry"], [string, Tone] | null> = { none: null, ok: ["Vigente", "ok"], soon: ["Vence pronto", "amber"], expired: ["Vencido: pide el cambio", "bad"] };
const STATUS: Record<string, string> = { returned: "Devuelto", replaced: "Reemplazado", lost: "Perdido" };

export default function MyPpePage() {
  const { reload } = useWorker();
  const [rows, setRows] = useState<Delivery[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sign, setSign] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const load = useCallback(() => workerApi<Delivery[]>("/ppe").then(setRows).catch((e) => setError(errorText(e))), []);
  useEffect(() => {
    void load();
  }, [load]);

  return (
    <>
      <Title sub="Tu equipo de protección personal.">Mi EPP</Title>
      {done && <Alert tone="ok">Recepción firmada. ¡Gracias!</Alert>}
      {error && <Alert>{error}</Alert>}
      {!rows && !error && <Loading />}
      {rows && rows.length === 0 && <Empty text="Aún no hay entregas de EPP registradas a tu nombre." />}
      {rows && rows.length > 0 && (
        <ul className="space-y-3">
          {rows.map((d) => {
            const e = d.status === "delivered" ? EXPIRY[d.expiry] : null;
            return (
              <li key={d.id} className="space-y-2 rounded-2xl border border-line bg-surface p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <span className="min-w-0">
                    <b className="block leading-tight">{d.item}{d.quantity > 1 ? ` × ${d.quantity}` : ""}</b>
                    <span className="text-sm text-muted">
                      Entregado el {fmtDate(d.delivered_at)}{d.size ? ` · talla ${d.size}` : ""}{d.expires_on ? ` · vence ${fmtDate(d.expires_on)}` : ""}
                    </span>
                  </span>
                  {d.status !== "delivered" ? <Pill tone="na">{STATUS[d.status] ?? d.status}</Pill> : e && <Pill tone={e[1]}>{e[0]}</Pill>}
                </div>
                {d.status === "delivered" && !d.signed && <BigButton onClick={() => setSign(d.id)}>Firmar la recepción</BigButton>}
                {d.signed && <p className="text-xs text-ok">Recepción firmada</p>}
              </li>
            );
          })}
        </ul>
      )}
      {sign && (
        <SignSheet kind="ppe" id={sign} onClose={() => setSign(null)}
          onSigned={() => {
            setSign(null);
            setDone(true);
            void load();
            void reload();
          }} />
      )}
    </>
  );
}
