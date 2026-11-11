"use client";

// Compartir el expediente con el inspector (M-SUN SCR-SUN-06/08): enlace con vencimiento, máximo de visitas, descarga
// sí/no con máximo de descargas y aprobación de gerencia opcional antes de activarse; copiar, aprobar, rechazar o anular,
// y el historial de accesos de cada enlace (Fecha, Desde, Acción). El enlace completo se muestra una sola vez.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Switch } from "@/components/ui/Switch";
import { Chip, type Tone } from "./Summary";
import { errorText } from "./Wizard";

type Access = { occurred_at: string; from: string | null; action: string; detail: string | null };
export type ShareLink = {
  id: string;
  token_last4: string | null;
  recipient_name: string | null;
  status: string;
  expires_at: string | null;
  valid_days: number | null;
  max_views: number | null;
  view_count: number;
  allow_download: boolean;
  max_downloads: number | null;
  download_count: number;
  requires_approval: boolean;
  approved_at: string | null;
  approved_by_name: string | null;
  created_at: string;
  created_by_name: string | null;
  can_approve: boolean;
  accesses: Access[];
};
type Meta = { can_share: boolean; can_revoke: boolean; is_approver: boolean; max_days: number };

const STATUS: Record<string, [string, Tone]> = {
  pending_approval: ["Por aprobar", "warn"],
  active: ["Activo", "ok"],
  expired: ["Vencido", "na"],
  exhausted: ["Agotado", "na"],
  revoked: ["Anulado", "bad"],
  rejected: ["Rechazado", "bad"],
};
const ACTION: Record<string, string> = {
  created: "Creado",
  approved: "Aprobado",
  rejected: "Rechazado",
  view: "Vio el índice",
  download: "Descargó",
  denied: "Acceso rechazado",
  revoked: "Anulado",
};
const dt = (v: string | null) => (v ? new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(v)) : "—");

function useLinks(dossierId: string) {
  const [links, setLinks] = useState<ShareLink[] | null>(null);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: ShareLink[]; meta: Meta }>(`/sun/dossiers/${dossierId}/share-links`, { withCompany: true })
      .then((r) => {
        setLinks(r.data);
        setMeta(r.meta);
      })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [dossierId]);
  useEffect(load, [load]);
  return { links, meta, error, load };
}

/** Historial de accesos de un enlace: Fecha, Desde, Acción. */
export function AccessHistory({ accesses }: { accesses: Access[] }) {
  if (accesses.length === 0) return <p className="text-xs text-muted">Sin accesos todavía.</p>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[420px] text-left text-xs">
        <thead className="text-muted">
          <tr>
            <th className="py-1 pr-3 font-semibold">Fecha</th>
            <th className="py-1 pr-3 font-semibold">Desde</th>
            <th className="py-1 font-semibold">Acción</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {accesses.map((a, i) => (
            <tr key={`${a.occurred_at}-${i}`}>
              <td className="py-1.5 pr-3 tabular-nums">{dt(a.occurred_at)}</td>
              <td className="py-1.5 pr-3">{a.from ?? "—"}</td>
              <td className="py-1.5">
                <b className={a.action === "denied" ? "text-bad" : ""}>{ACTION[a.action] ?? a.action}</b>
                {a.detail && <span className="text-muted"> · {a.detail}</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function LinkRow({ l, meta, onChanged }: { l: ShareLink; meta: Meta; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [label, tone] = STATUS[l.status] ?? [l.status, "na" as Tone];
  const act = async (path: string, body?: unknown) => {
    setBusy(true);
    setMsg(null);
    try {
      await api(`/sun/share-links/${l.id}/${path}`, { method: "POST", withCompany: true, body });
      onChanged();
    } catch (e) {
      setMsg(errorText(e));
    } finally {
      setBusy(false);
    }
  };
  const live = l.status === "active" || l.status === "pending_approval";
  return (
    <li className="space-y-2 py-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="min-w-0 flex-1">
          <b className="block text-sm">
            {l.recipient_name || "Inspector"} <span className="font-normal text-muted">· …{l.token_last4}</span>
          </b>
          <span className="block text-xs text-muted">
            {l.status === "pending_approval" ? `Vigencia de ${l.valid_days ?? "—"} día(s) desde la aprobación` : `Vence ${dt(l.expires_at)}`} · Visitas {l.view_count}
            {l.max_views ? ` de ${l.max_views}` : ""} · {l.allow_download ? `Descargas ${l.download_count}${l.max_downloads ? ` de ${l.max_downloads}` : ""}` : "Sin descarga"}
            {l.approved_by_name ? ` · Aprobado por ${l.approved_by_name}` : ""}
          </span>
        </span>
        <Chip tone={tone}>{label}</Chip>
      </div>
      <div className="flex flex-wrap gap-2">
        {l.can_approve && (
          <>
            <Button size="sm" variant="primary" loading={busy} onClick={() => act("approve")}>Aprobar</Button>
            <Button size="sm" variant="danger-ghost" disabled={busy} onClick={() => act("reject")}>Rechazar</Button>
          </>
        )}
        {live && meta.can_revoke && (
          <Button size="sm" variant="danger-ghost" disabled={busy} onClick={() => window.confirm("¿Anular el enlace? El inspector ya no podrá verlo.") && act("revoke")}>Anular</Button>
        )}
        <Button size="sm" variant="ghost" onClick={() => setOpen(!open)} aria-expanded={open}>
          {open ? "Ocultar historial" : `Historial de accesos (${l.accesses.length})`}
        </Button>
      </div>
      {msg && <p className="text-sm text-bad">{msg}</p>}
      {open && <AccessHistory accesses={l.accesses} />}
    </li>
  );
}

/** Panel de compartir del expediente: crear el enlace y la lista de enlaces con su historial. */
export function DossierSharePanel({ dossierId, onChanged }: { dossierId: string; onChanged?: () => void }) {
  const { links, meta, error, load } = useLinks(dossierId);
  const [f, setF] = useState({ name: "", days: "7", views: "", download: false, downloads: "", approval: false });
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [created, setCreated] = useState<{ url: string; pending: boolean } | null>(null);
  const reload = () => {
    load();
    onChanged?.();
  };

  const create = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { url: string; status: string } }>(`/sun/dossiers/${dossierId}/share`, {
        method: "POST",
        withCompany: true,
        body: {
          recipient_name: f.name.trim() || null,
          days: Number(f.days),
          max_views: f.views ? Number(f.views) : null,
          allow_download: f.download,
          max_downloads: f.download && f.downloads ? Number(f.downloads) : null,
          requires_approval: f.approval,
        },
      });
      setCreated({ url: r.data.url, pending: r.data.status === "pending_approval" });
      reload();
    } catch (e) {
      setMsg(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const num = (v: string) => v.replace(/\D/g, "").slice(0, 3);
  return (
    <div className="space-y-3 rounded-lg border border-line p-3">
      <p className="font-semibold">Compartir con el inspector</p>
      {meta?.can_share !== false && (
        <>
          <p className="text-xs text-muted">
            El inspector ve el índice sin iniciar sesión, con marca de agua y sin datos médicos. El enlace vence solo (máximo {meta?.max_days ?? 15} días) y puedes anularlo cuando quieras.
          </p>
          <div className="grid gap-2 sm:grid-cols-3">
            <label className="text-xs">Para<input className="input mt-1" placeholder="Nombre del inspector" maxLength={120} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></label>
            <label className="text-xs">Días de vigencia<input className="input mt-1" inputMode="numeric" value={f.days} onChange={(e) => setF({ ...f, days: num(e.target.value) })} /></label>
            <label className="text-xs">Visitas máx.<input className="input mt-1" inputMode="numeric" placeholder="Sin límite" value={f.views} onChange={(e) => setF({ ...f, views: num(e.target.value) })} /></label>
          </div>
          <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            <span className="flex items-center gap-2">
              <Switch checked={f.download} onChange={(v) => setF({ ...f, download: v })} label="Permitir descarga" /> Permitir descarga
            </span>
            {f.download && (
              <label className="flex items-center gap-2 text-xs">
                Descargas máx.
                <input className="input !h-8 !w-24" inputMode="numeric" placeholder="Sin límite" value={f.downloads} onChange={(e) => setF({ ...f, downloads: num(e.target.value) })} />
              </label>
            )}
            <span className="flex items-center gap-2">
              <Switch checked={f.approval} onChange={(v) => setF({ ...f, approval: v })} label="Requiere aprobación de gerencia" /> Requiere aprobación de gerencia
            </span>
          </div>
          <Button variant="secondary" icon="link" loading={busy} disabled={!f.days} onClick={create}>Crear enlace</Button>
          {msg && <p className="text-sm text-bad">{msg}</p>}
          {created && (
            <div className="rounded-lg border border-line bg-ok-soft px-3 py-2 text-sm">
              <p className="font-semibold text-ok">Copia este enlace ahora: no se vuelve a mostrar.</p>
              {created.pending && <p className="text-xs">Quedó pendiente: funcionará cuando gerencia lo apruebe. Ya le avisamos.</p>}
              <p className="break-all text-xs">{created.url}</p>
              <button type="button" className="mt-1 text-xs font-semibold text-brand underline" onClick={() => navigator.clipboard?.writeText(created.url)}>Copiar</button>
            </div>
          )}
        </>
      )}
      {error ? (
        <p className="text-sm text-muted">{error}</p>
      ) : links === null || meta === null ? (
        <div className="h-12 animate-pulse rounded-lg bg-bg" />
      ) : links.length === 0 ? (
        <p className="text-sm text-muted">Aún no hay enlaces de este expediente.</p>
      ) : (
        <ul className="divide-y divide-line border-t border-line">
          {links.map((l) => <LinkRow key={l.id} l={l} meta={meta} onChanged={reload} />)}
        </ul>
      )}
    </div>
  );
}

/** Detalle de un enlace en la lista «Enlaces al inspector»: estado, aprobar/anular e historial. */
export function ShareLinkDetail({ dossierId, linkId, onChanged }: { dossierId: string; linkId: string; onChanged: () => void }) {
  const { links, meta, error, load } = useLinks(dossierId);
  const l = links?.find((x) => x.id === linkId);
  if (error) return <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">{error}</p>;
  if (!links || !meta) return <div className="h-12 animate-pulse rounded-xl bg-bg" />;
  if (!l) return null;
  return (
    <section className="rounded-xl border border-line px-4 text-sm">
      <ul>
        <LinkRow l={l} meta={meta} onChanged={() => { load(); onChanged(); }} />
      </ul>
    </section>
  );
}
