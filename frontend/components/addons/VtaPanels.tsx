"use client";

// Venta de cursos (VTA): vender una oferta a una persona o a una empresa (cupos) y cobrar el pedido.
// El pago se registra a mano (Yape, Plin, transferencia, depósito o efectivo, con su número de operación); con la pasarela
// configurada en Proveedores y claves, el pago en línea se confirma solo.

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";
import { uploadFile, type RecordRow } from "@/lib/records";
import { useProviders } from "./TestModeBanner";

const errText = (e: unknown) => Object.values((e as ApiError).errors ?? {})[0]?.[0] ?? (e as ApiError).detail ?? (e as ApiError).title;

export function SellOffer({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [f, setF] = useState({ buyer_type: "person", document_type: "DNI", document_number: "", first_name: "", last_name: "", legal_name: "", ruc: "", email: "", seats: "5", coupon_code: "" });
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
      <CatalogLink offerSlug={String(row.slug ?? "")} isPublic={!!row.is_public} />
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
        {input("coupon_code", "Cupón (opcional)", "!w-32")}
        <Button variant="primary" onClick={sell}>Crear pedido</Button>
      </div>
      {order && <PayOrder id={order.id} label={`Pedido ${order.order_number} · S/ ${Number(order.total).toFixed(2)}`} onChanged={onChanged} />}
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}

export function PayOrder({ id, label, onChanged }: { id: string; label?: string; onChanged: () => void }) {
  const providers = useProviders();
  const [f, setF] = useState({ method: "yape", reference: "", paid_on: new Date().toISOString().slice(0, 10), evidence_file_id: "" });
  const [fileName, setFileName] = useState("");
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
        <label className="text-xs">Constancia del pago (foto o PDF)
          <input type="file" accept="image/*,application/pdf" className="mt-1 block text-xs" onChange={(e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            setMsg(null);
            uploadFile(file, "VTA").then((u) => { setF((x) => ({ ...x, evidence_file_id: u.id })); setFileName(u.original_name); }).catch((er) => setMsg({ text: errText(er), ok: false }));
          }} />
          {fileName && <span className="text-ok">Adjunta: {fileName}</span>}
        </label>
        <Button size="sm" variant="primary" disabled={!f.evidence_file_id} onClick={pay}>Registrar pago</Button>
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </div>
  );
}

/** Dirección del catálogo público de la empresa y de esta oferta (SCR-VTA-12/15); se puede cambiar el nombre de la dirección. */
export function CatalogLink({ offerSlug, isPublic }: { offerSlug: string; isPublic: boolean }) {
  const [link, setLink] = useState<{ slug: string | null; url: string } | null>(null);
  const [slug, setSlug] = useState("");
  const [editing, setEditing] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: { slug: string | null; url: string } }>("/vta/catalog", { withCompany: true }).then((r) => { setLink(r.data); setSlug(r.data.slug ?? ""); }).catch(() => setLink(null));
  }, []);
  if (!link) return null;
  const save = () =>
    api<{ data: { slug: string | null; url: string } }>("/vta/catalog", { method: "PUT", withCompany: true, body: { slug } })
      .then((r) => { setLink(r.data); setEditing(false); setMsg(null); })
      .catch((e) => setMsg(errText(e)));
  const offerUrl = offerSlug ? `${link.url}/${offerSlug}` : null;
  return (
    <div className="space-y-1 rounded-xl bg-bg px-3 py-2 text-xs">
      <p>
        Catálogo público: <a className="font-semibold text-brand underline" href={link.url} target="_blank" rel="noreferrer">{link.url}</a>{" "}
        <button type="button" className="text-muted underline" onClick={() => setEditing(!editing)}>{link.slug ? "Cambiar dirección" : "Elegir una dirección"}</button>
      </p>
      {offerUrl && (
        <p className="text-muted">
          Esta oferta: <a className="underline" href={offerUrl} target="_blank" rel="noreferrer">{offerUrl}</a>
          {isPublic ? "" : " · no aparece en el catálogo (solo con este enlace)"}
        </p>
      )}
      {editing && (
        <div className="flex flex-wrap items-end gap-2 pt-1">
          <label>Dirección<input className="input mt-1 !w-56" placeholder="mi-empresa" value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase())} /></label>
          <Button variant="primary" onClick={save}>Guardar</Button>
        </div>
      )}
      {msg && <p className="text-bad">{msg}</p>}
    </div>
  );
}

/** SCR-VTA-07: reembolso total o parcial de un pedido pagado; el total anula la matrícula y el certificado. */
export function RefundOrder({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const left = Number(row.total ?? 0) - Number(row.refunded_total ?? 0);
  const [f, setF] = useState({ amount: left.toFixed(2), reason: "" });
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  if (left <= 0) return null;
  const go = () => {
    if (!window.confirm(`¿Registrar el reembolso de S/ ${Number(f.amount).toFixed(2)}?${Number(f.amount) >= left ? " Se cancelará la matrícula y se anulará el certificado." : ""}`)) return;
    api<{ data: { status: string; enrollments_cancelled: number; certificates_voided: number } }>(`/vta/orders/${row.id}/refund`, { method: "POST", withCompany: true, body: { amount: Number(f.amount), reason: f.reason } })
      .then((r) => {
        setMsg({ ok: true, text: r.data.status === "refunded"
          ? `Reembolso total registrado: ${r.data.enrollments_cancelled} matrícula(s) cancelada(s) y ${r.data.certificates_voided} certificado(s) anulado(s). La nota de crédito queda pendiente del proveedor de comprobantes.`
          : "Reembolso parcial registrado." });
        onChanged();
      })
      .catch((e) => setMsg({ text: errText(e), ok: false }));
  };
  return (
    <div className="space-y-2 rounded-lg bg-bg px-3 py-2 text-sm">
      <p className="font-semibold">Reembolso</p>
      <p className="text-xs text-muted">Queda por devolver S/ {left.toFixed(2)}. Haz la devolución por el mismo medio del pago y regístrala aquí.</p>
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-xs">Importe (S/)<input className="input mt-1 !w-28" inputMode="decimal" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} /></label>
        <label className="text-xs">Motivo<input className="input mt-1 !w-72" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></label>
        <Button size="sm" onClick={go}>Registrar reembolso</Button>
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </div>
  );
}

type Assignment = { id: string; status: string; first_name: string; last_name: string; email: string; document_last4: string; enrollment_status: string | null; progress_pct: number | null; passed: boolean | null };
type Seats = { license: { seats: number; used: number; status: string }; assignments: Assignment[] };

/** SCR-VTA-08/19: cupos de una empresa compradora: asignar por documento y correo, liberar si no inició, avance de cada uno. */
export function LicenseSeats({ row }: { row: RecordRow }) {
  const [d, setD] = useState<Seats | null>(null);
  const [f, setF] = useState({ document_type: "DNI", document_number: "", first_name: "", last_name: "", email: "" });
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [tick, setTick] = useState(0);
  useEffect(() => {
    api<{ data: Seats }>(`/vta/licenses/${row.id}/assignments`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null));
  }, [row.id, tick]);
  if (!d) return null;
  const assign = () => api(`/vta/licenses/${row.id}/assignments`, { method: "POST", withCompany: true, body: f })
    .then(() => { setF({ ...f, document_number: "", first_name: "", last_name: "", email: "" }); setMsg({ text: "Cupo asignado y persona matriculada.", ok: true }); setTick((t) => t + 1); })
    .catch((e) => setMsg({ text: errText(e), ok: false }));
  const release = (id: string) => {
    const reason = window.prompt("Motivo para liberar el cupo");
    if (!reason) return;
    api(`/vta/license-assignments/${id}/release`, { method: "POST", withCompany: true, body: { reason } })
      .then(() => { setMsg({ text: "Cupo liberado.", ok: true }); setTick((t) => t + 1); })
      .catch((e) => setMsg({ text: errText(e), ok: false }));
  };
  const free = d.license.seats - d.license.used;
  const input = (k: keyof typeof f, label: string, w = "!w-40") => (
    <label className="text-xs">{label}<input className={`input mt-1 ${w}`} value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></label>
  );
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <p className="font-semibold">Cupos: {d.license.used} asignados de {d.license.seats} · {free} disponibles</p>
      {free > 0 && d.license.status === "active" && (
        <div className="flex flex-wrap items-end gap-2">
          {input("first_name", "Nombres")}{input("last_name", "Apellidos")}{input("document_number", "DNI", "!w-28")}{input("email", "Correo", "!w-52")}
          <Button size="sm" variant="primary" onClick={assign}>Asignar cupo</Button>
        </div>
      )}
      {d.assignments.length > 0 && (
        <ul className="divide-y divide-line">{d.assignments.map((a) => (
          <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
            <span>{a.first_name} {a.last_name} · ***{a.document_last4} · {a.status === "revoked" ? "Liberado" : a.passed ? "Aprobado" : `Avance ${Math.round(Number(a.progress_pct ?? 0))} %`}</span>
            {a.status !== "revoked" && a.enrollment_status === "enrolled" && Number(a.progress_pct ?? 0) === 0 && (
              <button type="button" className="text-xs text-muted underline" onClick={() => release(a.id)}>Liberar</button>
            )}
          </li>
        ))}</ul>
      )}
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}

type Dash = {
  from: string; to: string; sales: number; paid: number; pending: number; refunded: number; expired: number; refunded_amount: number; average_ticket: number;
  seats_sold: number; seats_assigned: number; top_courses: { title: string; units: number; amount: number }[]; coupons: { code: string; uses: number; discount: number }[];
};
const soles = (n: number) => `S/ ${Number(n).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** SCR-VTA-01 y 20: panel de ventas del periodo y reporte en Excel. */
export function VtaDashboard() {
  const today = new Date().toISOString().slice(0, 10);
  const [p, setP] = useState({ from: `${today.slice(0, 8)}01`, to: today });
  const [d, setD] = useState<Dash | null>(null);
  const [err, setErr] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Dash }>(`/vta/dashboard?from=${p.from}&to=${p.to}`, { withCompany: true }).then((r) => { setD(r.data); setErr(null); }).catch((e) => setErr(errText(e)));
  }, [p.from, p.to]);
  const download = async () => {
    const { session } = await import("@/lib/session");
    const { API_URL } = await import("@/lib/api");
    const res = await fetch(`${API_URL}/api/v1/vta/reports.xlsx?from=${p.from}&to=${p.to}`, { headers: { Authorization: `Bearer ${session.getToken() ?? ""}`, "X-Company-Id": session.getCompanyId() ?? "" } });
    if (!res.ok) { setErr("No se pudo descargar el reporte."); return; }
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement("a");
    a.href = url;
    a.download = `ventas-cursos-${p.from}-${p.to}.xlsx`;
    a.click();
    URL.revokeObjectURL(url);
  };
  if (err && !d) return null;
  const kpi = (label: string, value: string, sub?: string) => (
    <div className="rounded-xl border border-line bg-surface px-4 py-3"><p className="text-xs text-muted">{label}</p><p className="text-xl font-bold">{value}</p>{sub && <p className="text-xs text-muted">{sub}</p>}</div>
  );
  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <h2 className="font-display text-lg font-semibold">Panel de ventas</h2>
        <div className="flex flex-wrap items-end gap-2 text-xs">
          <label>Desde<input type="date" className="input mt-1 !w-36" value={p.from} onChange={(e) => setP({ ...p, from: e.target.value })} /></label>
          <label>Hasta<input type="date" className="input mt-1 !w-36" value={p.to} onChange={(e) => setP({ ...p, to: e.target.value })} /></label>
          <Button size="sm" onClick={download}>Reporte en Excel</Button>
        </div>
      </div>
      {d && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {kpi("Ventas del periodo", soles(d.sales), d.refunded_amount ? `Reembolsado ${soles(d.refunded_amount)}` : undefined)}
            {kpi("Pedidos pagados", String(d.paid), `Ticket promedio ${soles(d.average_ticket)}`)}
            {kpi("Pendientes de pago", String(d.pending), `${d.expired} vencidos · ${d.refunded} con reembolso`)}
            {kpi("Cupos para empresas", `${d.seats_assigned} / ${d.seats_sold}`, "Asignados / vendidos")}
          </div>
          <div className="grid gap-3 text-sm lg:grid-cols-2">
            <div className="rounded-xl border border-line bg-surface px-4 py-3">
              <p className="font-semibold">Cursos más vendidos</p>
              {d.top_courses.length === 0 ? <p className="text-muted">Sin ventas en el periodo.</p> : <ul>{d.top_courses.map((c) => <li key={c.title}>{c.title} · {Number(c.units)} · {soles(c.amount)}</li>)}</ul>}
            </div>
            <div className="rounded-xl border border-line bg-surface px-4 py-3">
              <p className="font-semibold">Cupones usados</p>
              {d.coupons.length === 0 ? <p className="text-muted">Ningún cupón usado en el periodo.</p> : <ul>{d.coupons.map((c) => <li key={c.code}>{c.code} · {Number(c.uses)} usos · descuento {soles(c.discount)}</li>)}</ul>}
            </div>
          </div>
        </>
      )}
    </section>
  );
}

type Reach = { base: number; without_consent: number; already_have: number; reach: number; sent?: number };

/** SCR-VTA-14: campaña de recomendación por correo a quienes aprobaron un curso o recibieron cupos; vista previa del alcance. */
export function VtaCampaign() {
  const [offers, setOffers] = useState<{ id: string; title: string }[]>([]);
  const [courses, setCourses] = useState<{ id: string; title: string }[]>([]);
  const [f, setF] = useState({ course_offer_id: "", audience: "passed_course", base_course_id: "", message: "" });
  const [prev, setPrev] = useState<Reach | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: { id: string; title: string }[] }>("/records/VTA/course_offers?f[status]=published&per_page=100", { withCompany: true }).then((r) => setOffers(r.data)).catch(() => setOffers([]));
    api<{ data: { id: string; title: string }[] }>("/records/AUL/courses?per_page=200", { withCompany: true }).then((r) => setCourses(r.data)).catch(() => setCourses([]));
  }, []);
  const run = (send: boolean) => {
    setMsg(null);
    api<{ data: Reach }>("/vta/campaigns", { method: "POST", withCompany: true, body: { ...f, send } }).then((r) => setPrev(r.data)).catch((e) => setMsg(errText(e)));
  };
  if (offers.length === 0) return null;
  return (
    <details className="rounded-xl border border-line bg-surface">
      <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Campaña de recomendación</summary>
      <div className="space-y-2 border-t border-line p-4 text-sm">
        <div className="flex flex-wrap items-end gap-2">
          <label className="text-xs">Curso que se recomienda
            <select className="input mt-1 !w-56" value={f.course_offer_id} onChange={(e) => { setF({ ...f, course_offer_id: e.target.value }); setPrev(null); }}>
              <option value="">Elige…</option>{offers.map((o) => <option key={o.id} value={o.id}>{o.title}</option>)}
            </select>
          </label>
          <label className="text-xs">A quiénes
            <select className="input mt-1 !w-64" value={f.audience} onChange={(e) => { setF({ ...f, audience: e.target.value }); setPrev(null); }}>
              <option value="passed_course">Aprobaron el curso base</option><option value="license_buyers">Recibieron cupos de empresas para el curso base</option>
            </select>
          </label>
          <label className="text-xs">Curso base
            <select className="input mt-1 !w-56" value={f.base_course_id} onChange={(e) => { setF({ ...f, base_course_id: e.target.value }); setPrev(null); }}>
              <option value="">Elige…</option>{courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </select>
          </label>
        </div>
        <label className="block text-xs">Mensaje (opcional)<input className="input mt-1" value={f.message} onChange={(e) => setF({ ...f, message: e.target.value })} /></label>
        <div className="flex gap-2">
          <Button size="sm" onClick={() => run(false)}>Ver alcance</Button>
          {prev && prev.reach > 0 && prev.sent === undefined && <Button size="sm" variant="primary" onClick={() => run(true)}>Enviar por correo</Button>}
        </div>
        {prev && (
          <p className="text-muted">
            Base {prev.base} · sin consentimiento de publicidad {prev.without_consent} · ya tienen el curso {prev.already_have} · <b className="text-fg">llega a {prev.reach}</b>
            {prev.sent !== undefined ? ` · enviados ${prev.sent}` : ""}
          </p>
        )}
        {msg && <p className="text-bad">{msg}</p>}
      </div>
    </details>
  );
}

type Portal = {
  learner: { first_name: string; last_name: string; email: string; document: string; marketing_consent: boolean; data_consent_at: string | null } | null;
  certificates: { id: string; certificate_code: string; course_title: string; issued_on: string; expires_on: string | null; status: string }[];
  orders: { id: string; order_number: string; created_at: string; status: string; total: number; refunded_total: number; items: string | null }[];
  licenses: { id: string; license_code: string; title: string; seats_purchased: number; seats_used: number; expires_at: string | null; status: string }[];
};
const ORDER_ST: Record<string, string> = { pending: "Pendiente de pago", paid: "Pagado", failed: "Rechazado", voided: "Anulado", expired: "Vencido", partially_refunded: "Reembolso parcial", refunded: "Reembolsado" };

/** SCR-VTA-18/19: portal del participante externo: certificados, pedidos, datos y consentimientos, y cupos si representa a una empresa. */
export function VtaPortal() {
  const [d, setD] = useState<Portal | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Portal }>("/vta/portal", { withCompany: true }).then((r) => setD(r.data)).catch((e) => setMsg(errText(e)));
  }, []);
  if (!d) return <p className="text-sm text-muted">{msg ?? "Cargando…"}</p>;
  const consent = (v: boolean) =>
    api("/vta/portal/consents", { method: "PUT", withCompany: true, body: { marketing_consent: v } })
      .then(() => setD({ ...d, learner: d.learner && { ...d.learner, marketing_consent: v } })).catch((e) => setMsg(errText(e)));
  const box = "space-y-2 rounded-xl border border-line bg-surface px-4 py-3 text-sm";
  return (
    <div className="space-y-4">
      <p className="text-sm text-muted">Tus cursos están en <a href="/m/aul" className="font-semibold text-brand underline">Aula virtual</a>.</p>
      {d.licenses.length > 0 && (
        <section className="space-y-2">
          <h2 className="font-display text-lg font-semibold">Cupos de tu empresa</h2>
          {d.licenses.map((l) => (
            <div key={l.id} className="space-y-1">
              <p className="text-sm font-semibold">{l.title} · {l.license_code}{l.expires_at ? ` · asignar hasta el ${new Date(l.expires_at).toLocaleDateString("es-PE")}` : ""}</p>
              <LicenseSeats row={{ id: l.id } as RecordRow} />
            </div>
          ))}
        </section>
      )}
      <section className={box}>
        <p className="font-semibold">Mis certificados</p>
        {d.certificates.length === 0 ? <p className="text-muted">Aún no tienes certificados.</p> : (
          <ul className="divide-y divide-line">{d.certificates.map((c) => (
            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
              <span>{c.course_title} · {c.certificate_code} · {new Date(c.issued_on).toLocaleDateString("es-PE")}{c.status === "expired" ? " · vencido" : ""}</span>
              <a href={`/imprimir/certificado/${c.id}`} target="_blank" rel="noreferrer" className="text-brand underline">Ver</a>
            </li>
          ))}</ul>
        )}
      </section>
      <section className={box}>
        <p className="font-semibold">Mis pedidos</p>
        {d.orders.length === 0 ? <p className="text-muted">Aún no tienes pedidos.</p> : (
          <ul className="divide-y divide-line">{d.orders.map((o) => (
            <li key={o.id} className="py-1.5">{o.order_number} · {o.items ?? ""} · {soles(Number(o.total))} · {ORDER_ST[o.status] ?? o.status}{Number(o.refunded_total) > 0 ? ` · reembolsado ${soles(Number(o.refunded_total))}` : ""}</li>
          ))}</ul>
        )}
        <p className="text-xs text-muted">Los comprobantes electrónicos se mostrarán aquí cuando la empresa active su proveedor de comprobantes.</p>
      </section>
      {d.learner && (
        <section className={box}>
          <p className="font-semibold">Mis datos</p>
          <p>{d.learner.first_name} {d.learner.last_name} · {d.learner.email} · documento {d.learner.document}</p>
          <label className="flex items-center gap-2"><input type="checkbox" checked={d.learner.marketing_consent} onChange={(e) => consent(e.target.checked)} /> Acepto recibir información de otros cursos por correo (puedes quitarlo cuando quieras)</label>
          <p className="text-xs text-muted">Para ver, corregir o eliminar tus datos (derechos ARCO), escribe a la empresa que te vendió el curso.</p>
        </section>
      )}
      {msg && <p className="text-sm text-bad">{msg}</p>}
    </div>
  );
}
