"use client";

// Permisos activos (M-PTR SCR-PTR-01): permisos en curso por sede, por estado y tipo, con la hora de vencimiento y el mapa de
// la sede (OpenStreetMap, sin clave). Un permiso vencido sin cerrar se resalta.

import Link from "next/link";
import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { recordsHref } from "@/lib/records";
import { Card } from "./Summary";

type Permit = { id: string; code: string | null; status: string; type: string | null; area: string | null; location_text: string | null; work_description: string | null;
  valid_from: string | null; valid_until: string | null; overdue: boolean; suspended_reason: string | null; lat: number | null; lng: number | null };
type Site = { site_id: string | null; site: string; lat: number | null; lng: number | null; by_status: Record<string, number>; by_type: Record<string, number>; permits: Permit[] };
type Board = { totals: Record<string, number>; sites: Site[] };

const STATUS: Record<string, [string, string]> = {
  pending_approval: ["Por aprobar", "bg-amber-soft text-amber-ink"],
  approved: ["Aprobado", "bg-brand-soft text-brand-strong"],
  active: ["Activo", "bg-ok-soft text-ok"],
  suspended: ["Suspendido", "bg-bad-soft text-bad"],
};
const hm = (s: string | null) => (s ? new Date(s).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : "—");

function SiteMap({ s }: { s: Site }) {
  if (s.lat === null || s.lng === null) {
    return (
      <p className="text-xs text-muted">
        La sede no tiene latitud y longitud registradas.{" "}
        {s.site_id && <Link className="text-brand underline" href={recordsHref("TEN", "sites", { open: s.site_id })}>Complétalas en la sede</Link>}
      </p>
    );
  }
  const { lat, lng } = s;
  return (
    <div className="space-y-1">
      <iframe title={`Mapa de ${s.site}`} className="h-48 w-full rounded border border-line" loading="lazy"
        src={`https://www.openstreetmap.org/export/embed.html?bbox=${lng - 0.01}%2C${lat - 0.006}%2C${lng + 0.01}%2C${lat + 0.006}&layer=mapnik&marker=${lat}%2C${lng}`} />
      <a className="text-xs text-brand underline" href={`https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=17/${lat}/${lng}`} target="_blank" rel="noreferrer">Abrir el mapa grande</a>
    </div>
  );
}

export function PtrBoard() {
  const [d, setD] = useState<Board | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Board }>("/ptr/board", { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>;
  if (!d) return null;
  return (
    <Card title="Permisos activos">
      <p className="mb-2 text-xs text-muted">Permisos en curso por sede, con su estado, tipo y hora de vencimiento.</p>
      <div className="mb-3 flex flex-wrap gap-2 text-sm">
        {Object.entries(STATUS).map(([k, [label, tone]]) => (
          <span key={k} className={`rounded-full px-3 py-1 font-semibold ${tone}`}>{label}: {d.totals[k] ?? 0}</span>
        ))}
      </div>
      {d.sites.length === 0 && <p className="text-sm text-muted">No hay permisos en curso.</p>}
      <div className="space-y-4">
        {d.sites.map((s) => (
          <section key={s.site_id ?? "none"} className="grid gap-3 rounded-xl border border-line p-3 lg:grid-cols-[1fr_20rem]">
            <div className="min-w-0">
              <p className="font-semibold">{s.site}</p>
              <p className="text-xs text-muted">{Object.entries(s.by_type).map(([t, n]) => `${t}: ${n}`).join(" · ")}</p>
              <ul className="mt-2 divide-y divide-line text-sm">
                {s.permits.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center gap-2 py-2">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS[p.status]?.[1] ?? ""}`}>{STATUS[p.status]?.[0] ?? p.status}</span>
                    <Link className="font-semibold text-brand underline" href={recordsHref("PTR", "work_permits", { open: p.id })}>{p.code ?? "Permiso"}</Link>
                    <span className="min-w-0 flex-1 truncate">{p.type ?? ""}{p.area ? ` · ${p.area}` : ""}{p.location_text ? ` · ${p.location_text}` : ""}</span>
                    <span className={`text-xs ${p.overdue ? "font-semibold text-bad" : "text-muted"}`}>
                      {p.overdue ? "Venció " : "Vence "}{hm(p.valid_until)}
                    </span>
                    {p.status === "suspended" && p.suspended_reason && <span className="block w-full text-xs text-bad">Motivo: {p.suspended_reason}</span>}
                  </li>
                ))}
              </ul>
            </div>
            <SiteMap s={s} />
          </section>
        ))}
      </div>
    </Card>
  );
}
