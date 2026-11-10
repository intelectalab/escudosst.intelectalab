"use client";

// Venta de cursos (VTA): vender una oferta a una persona o a una empresa (cupos) y cobrar el pedido.
// El pago se registra a mano (Yape, Plin, transferencia, depósito o efectivo, con su número de operación); con la pasarela
// configurada en Proveedores y claves, el pago en línea se confirma solo.

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";
import type { RecordRow } from "@/lib/records";
import { useProviders } from "./TestModeBanner";

const errText = (e: unknown) => Object.values((e as ApiError).errors ?? {})[0]?.[0] ?? (e as ApiError).detail ?? (e as ApiError).title;

export function SellOffer({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [f, setF] = useState({ buyer_type: "person", document_type: "DNI", document_number: "", first_name: "", last_name: "", legal_name: "", ruc: "", email: "", seats: "5" });
  const [order, setOrder] = useState<{ id: string; order_number: string; total: number } | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  if (row.status !== "published") return <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">Publica la oferta (estado «Publicada») para venderla.</p>;
  const company = f.buyer_type === "company";
  const set = (k: string, v: string) => setF({ ...f, [k]: v });
  const sell = () =>
    api<{ data: { id: string; order_number: string; total: number } }>("/vta/sell", {
      method: "POST", withCompany: true,
      body: { ...f, course_offer_id: row.id, document_type: company ? "RUC" : f.document_type, document_number: company ? f.ruc : f.document_number, seats: company ? Number(f.seats) : null },
    }).then((r) => { setOrder(r.data); setMsg(null); onChanged(); }).catch((e) => setMsg({ text: errText(e), ok: false }));
  const input = (k: string, label: string, w = "!w-44") => (
    <label className="text-xs">{label}<input className={`input mt-1 ${w}`} value={(f as Record<string, string>)[k]} onChange={(e) => set(k, e.target.value)} /></label>
  );
  return (
    <section className="space-y-3 rounded-xl border border-line px-4 py-3 text-sm">
      <p className="font-semibold">Vender esta oferta</p>
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-xs">Comprador
          <select className="input mt-1 !w-36" value={f.buyer_type} onChange={(e) => set("buyer_type", e.target.value)}>
            <option value="person">Una persona</option>
            <option value="company">Una empresa (cupos)</option>
          </select>
        </label>
        {company ? (<>{input("legal_name", "Razón social", "!w-56")}{input("ruc", "RUC", "!w-36")}{input("seats", "Cupos", "!w-20")}</>) : (<>{input("first_name", "Nombres")}{input("last_name", "Apellidos")}{input("document_number", "DNI", "!w-32")}</>)}
        {input("email", "Correo", "!w-56")}
        <Button variant="primary" onClick={sell}>Crear pedido</Button>
      </div>
      {order && <PayOrder id={order.id} label={`Pedido ${order.order_number} · S/ ${Number(order.total).toFixed(2)}`} onChanged={onChanged} />}
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}

export function PayOrder({ id, label, onChanged }: { id: string; label?: string; onChanged: () => void }) {
  const providers = useProviders();
  const [f, setF] = useState({ method: "yape", reference: "", paid_on: new Date().toISOString().slice(0, 10) });
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const live = providers?.payments?.live;
  const pay = () =>
    api<{ data: { delivered: { type: string; code?: string; seats?: number; learner?: string }[] } }>(`/vta/orders/${id}/pay`, { method: "POST", withCompany: true, body: f })
      .then((r) => {
        const d = r.data.delivered.map((x) => (x.type === "license" ? `licencia ${x.code} con ${x.seats} cupos` : `${x.learner} matriculado`)).join("; ");
        setMsg({ text: `Pago registrado: ${d}.`, ok: true });
        onChanged();
      })
      .catch((e) => setMsg({ text: errText(e), ok: false }));
  return (
    <div className="space-y-2 rounded-lg bg-bg px-3 py-2 text-sm">
      <p className="font-semibold">{label ?? "Pedido pendiente de pago"}</p>
      {live && <p className="text-xs text-muted">La pasarela está activa: si el cliente pagó en línea, el pedido se confirma solo. Si pagó por Yape, Plin o transferencia, regístralo aquí.</p>}
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-xs">Cómo pagó
          <select className="input mt-1 !w-36" value={f.method} onChange={(e) => setF({ ...f, method: e.target.value })}>
            <option value="yape">Yape</option><option value="plin">Plin</option><option value="transfer">Transferencia</option><option value="deposit">Depósito</option><option value="cash">Efectivo</option>
          </select>
        </label>
        {f.method !== "cash" && <label className="text-xs">N.° de operación<input className="input mt-1 !w-40" value={f.reference} onChange={(e) => setF({ ...f, reference: e.target.value })} /></label>}
        <label className="text-xs">Fecha<input type="date" className="input mt-1 !w-40" value={f.paid_on} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setF({ ...f, paid_on: e.target.value })} /></label>
        <Button size="sm" variant="primary" onClick={pay}>Registrar pago</Button>
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </div>
  );
}
