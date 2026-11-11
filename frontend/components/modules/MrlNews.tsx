"use client";

// Novedades normativas (M-MRL SCR-MRL-05, RN-MRL-05): requisitos de la matriz que «requieren revisión» porque su norma
// cambió en el catálogo (con el motivo) y normas nuevas o modificadas en los últimos 90 días. «Revisado» quita la marca.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { recordsHref } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Card, Chip } from "./Summary";

type Flagged = {
  id: string; article: string | null; requirement: string; review_reason: string | null; review_flagged_at: string | null; legal_norm_id: string;
  norm_type: string; number: string; norm_title: string; norm_status: string; responsible_name: string | null;
};
type Recent = { id: string; norm_type: string; number: string; title: string; status: string; updated_at: string; is_new: boolean; linked_count: number; review_count: number };
type News = { needs_review: Flagged[]; recent_norms: Recent[]; can_review: boolean };

const TYPES: Record<string, string> = { law: "Ley", supreme_decree: "D.S.", ministerial_resolution: "R.M.", technical_standard: "Norma técnica", ordinance: "Ordenanza", directive: "Directiva", other: "" };
const name = (n: { norm_type: string; number: string }) => `${TYPES[n.norm_type] ?? ""} ${n.number}`.trim();
const date = (v: string | null) => (v ? new Date(v).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");

export function MrlNews({ readOnly, version = 0, onChanged }: { readOnly: boolean; version?: number; onChanged?: () => void }) {
  const [data, setData] = useState<News | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: News }>("/mrl/news", { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(() => { load(); }, [load, version]);

  const reviewed = async (id: string) => {
    setBusy(id);
    setError(null);
    try {
      await api(`/mrl/requirements/${id}/reviewed`, { method: "POST", withCompany: true, body: {} });
      load();
      onChanged?.();
    } catch (e) {
      const err = e as ApiError;
      setError(err.detail ?? err.title);
    } finally {
      setBusy(null);
    }
  };

  if (!data) return error ? <p className="rounded-xl border border-line px-4 py-3 text-sm text-muted">{error}</p> : null;
  if (data.needs_review.length === 0 && data.recent_norms.length === 0) return null;

  return (
    <Card title="Novedades normativas" action={data.needs_review.length > 0 ? <Chip tone="warn">{data.needs_review.length} por revisar</Chip> : undefined}>
      <div className="space-y-3">
        {data.needs_review.length > 0 ? (
          <>
            <p className="text-sm text-muted">Estos requisitos cambiaron en el catálogo de normas: no cuentan como cumplidos hasta que los revises.</p>
            <ul className="divide-y divide-line">
              {data.needs_review.map((r) => (
                <li key={r.id} className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
                  <span className="min-w-0 flex-1">
                    <b>{name(r)}{r.article ? ` · ${r.article}` : ""}</b> <Chip tone="warn">Requiere revisión</Chip>
                    <span className="block">{r.requirement}</span>
                    <span className="block text-xs text-muted">
                      {[r.review_reason, r.review_flagged_at ? `desde el ${date(r.review_flagged_at)}` : null, r.responsible_name ? `responsable: ${r.responsible_name}` : null].filter(Boolean).join(" · ")}
                    </span>
                  </span>
                  <span className="flex items-center gap-2">
                    <a href={recordsHref("MRL", "legal_requirements", { open: r.id })} className="text-xs font-semibold text-brand hover:underline">Abrir</a>
                    {!readOnly && data.can_review && (
                      <Button size="sm" icon="check" loading={busy === r.id} onClick={() => reviewed(r.id)}>Revisado</Button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </>
        ) : (
          <p className="text-sm text-muted">Ningún requisito de tu matriz requiere revisión por cambios normativos.</p>
        )}
        {data.recent_norms.length > 0 && (
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Cambios en el catálogo (últimos 90 días)</p>
            <ul className="space-y-1 text-sm">
              {data.recent_norms.slice(0, 8).map((n) => (
                <li key={n.id} className="flex flex-wrap items-center gap-2">
                  <span className="min-w-0 flex-1">{name(n)} · {n.title}</span>
                  <Chip tone={n.is_new ? "ok" : "na"}>{n.is_new ? "Nueva" : "Modificada"} {date(n.updated_at)}</Chip>
                  {n.linked_count > 0 && <span className="text-xs text-muted">{n.linked_count} en tu matriz</span>}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
      {error && <p className="mt-2 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
    </Card>
  );
}
