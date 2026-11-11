"use client";

// Indicador de conexión y sincronización (M-OFF SCR-OFF-01, 03 y 04; docs/16 §1):
// «Sin conexión — N registros por sincronizar», «Sincronizando (N)», pendientes, rechazados y conflictos,
// con la cola local y la resolución de conflictos. Registra el service worker (app instalable) y, si la empresa
// tiene el complemento OFF, el dispositivo y la cola offline. Sin OFF solo avisa que no hay conexión.

import { useCallback, useEffect, useState } from "react";
import { useApp } from "@/components/AppContext";
import {
  discardOp, listOutbox, loadConflicts, OFFLINE_ENTITIES, registerDevice, resolveConflict, retryOp, setOfflineContext,
  setOnline, subscribeOffline, syncNow, type OfflineState, type OutboxOp, type SyncConflict,
} from "@/lib/offline";

const CONFLICT_TEXT: Record<string, string> = {
  version_mismatch: "Cambió también en el servidor",
  deleted_on_server: "Archivado en el servidor",
  state_transition: "Cambió de estado en el servidor",
  duplicate: "Persona ya registrada",
  validation_failed: "Datos por revisar",
  permission_denied: "Sin permiso",
};

function WifiOff() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M2 2l20 20M8.5 16.5a5 5 0 0 1 7 0M5 13a10 10 0 0 1 5.2-2.8M19 13a10 10 0 0 0-2.2-1.6M2 8.8a15 15 0 0 1 4.2-2.6M22 8.8A15 15 0 0 0 11 5M12 20h.01" />
    </svg>
  );
}

const fmt = (iso: string) => {
  try {
    return new Date(iso).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
  } catch {
    return iso;
  }
};

export function OfflineIndicator() {
  const { user, company, modules } = useApp();
  const enabled = modules.some((m) => m.code === "OFF" && m.mode === "enabled");
  const [s, setS] = useState<OfflineState | null>(null);
  const [open, setOpen] = useState(false);
  const [ops, setOps] = useState<OutboxOp[]>([]);
  const [conflicts, setConflicts] = useState<SyncConflict[]>([]);
  const [busy, setBusy] = useState<string | null>(null);

  // App instalable: service worker en producción (en desarrollo, con localStorage escudo.sw = 1).
  useEffect(() => {
    if (!("serviceWorker" in navigator)) return;
    let dev = false;
    try {
      dev = localStorage.getItem("escudo.sw") === "1";
    } catch {
      dev = false;
    }
    if (process.env.NODE_ENV === "production" || dev) navigator.serviceWorker.register("/sw.js").catch(() => undefined);
  }, []);

  useEffect(() => {
    setOfflineContext({ userId: user.id, companyId: company.company_id, enabled });
    const unsub = subscribeOffline(setS);
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener("online", on);
    window.addEventListener("offline", off);
    if (enabled) void registerDevice().then(() => syncNow());
    return () => {
      unsub();
      window.removeEventListener("online", on);
      window.removeEventListener("offline", off);
    };
  }, [user.id, company.company_id, enabled]);

  const refresh = useCallback(async () => {
    setOps(await listOutbox().catch(() => []));
    setConflicts(navigator.onLine ? await loadConflicts() : []);
  }, []);

  useEffect(() => {
    if (open) void refresh();
  }, [open, refresh, s?.pending, s?.rejected, s?.conflicts]);

  if (!s) return null;
  const show = !s.online || s.syncing || s.pending > 0 || s.rejected > 0 || s.conflicts > 0;
  if (!show) return null;

  let text: string;
  if (!s.online) {
    text = !enabled
      ? "Sin conexión — lo que registres no se guardará hasta recuperar la señal"
      : `Sin conexión — ${s.pending} ${s.pending === 1 ? "registro" : "registros"} por sincronizar`;
  } else if (s.syncing) {
    text = `Sincronizando (${s.pending})…`;
  } else {
    const parts = [];
    if (s.pending) parts.push(`${s.pending} ${s.pending === 1 ? "pendiente" : "pendientes"}`);
    if (s.rejected) parts.push(`${s.rejected} ${s.rejected === 1 ? "rechazado" : "rechazados"}`);
    if (s.conflicts) parts.push(`${s.conflicts} ${s.conflicts === 1 ? "conflicto" : "conflictos"} por revisar`);
    text = parts.join(" · ");
  }
  const warn = !s.online || s.rejected > 0 || s.conflicts > 0;

  async function act(id: string, fn: () => Promise<void>) {
    setBusy(id);
    try {
      await fn();
    } finally {
      setBusy(null);
      void refresh();
    }
  }

  return (
    <div className={`mb-3 rounded-lg border text-sm ${warn ? "border-amber-ink/30 bg-amber-soft text-amber-ink" : "border-line bg-brand-soft text-brand-strong"}`} role="status" aria-live="polite">
      <div className="flex flex-wrap items-center gap-2 px-3 py-2">
        <WifiOff />
        <span className="font-semibold">{text}</span>
        {s.lastSync && <span className="opacity-75">· última sincronización {fmt(s.lastSync)}</span>}
        <span className="ml-auto flex gap-2">
          {enabled && s.online && s.pending > 0 && !s.syncing && (
            <button className="rounded-md bg-white/70 px-2.5 py-0.5 font-semibold hover:bg-white" onClick={() => void syncNow()}>
              Sincronizar ahora
            </button>
          )}
          {enabled && (
            <button className="rounded-md px-2.5 py-0.5 font-semibold underline-offset-2 hover:underline" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
              {open ? "Ocultar" : "Ver cola"}
            </button>
          )}
        </span>
      </div>
      {s.lastError && s.online && <p className="px-3 pb-2 text-xs">{s.lastError}</p>}

      {open && enabled && (
        <div className="space-y-3 border-t border-current/15 bg-surface px-3 py-3 text-fg">
          <section>
            <h3 className="mb-1 font-display font-semibold">Registros en este dispositivo</h3>
            {ops.length === 0 ? (
              <p className="text-muted">No hay registros por sincronizar.</p>
            ) : (
              <ul className="divide-y divide-line">
                {ops.map((o) => (
                  <li key={o.idempotency_key} className="flex flex-wrap items-center gap-2 py-1.5">
                    <span className="font-semibold">{OFFLINE_ENTITIES[o.entity]?.label ?? o.entity}</span>
                    <span className="text-muted">{o.op === "update" ? "edición" : "nuevo"} · {fmt(o.created_at_device)}</span>
                    {o.status === "rejected" ? (
                      <>
                        <span className="text-bad">Rechazado: {o.reason ?? "sin motivo"}</span>
                        <span className="ml-auto flex gap-2">
                          <button className="rounded-md border border-line px-2 py-0.5 hover:bg-bg" disabled={busy === o.idempotency_key}
                            onClick={() => act(o.idempotency_key, () => retryOp(o.idempotency_key))}>Reintentar</button>
                          <button className="rounded-md border border-line px-2 py-0.5 text-bad hover:bg-bg" disabled={busy === o.idempotency_key}
                            onClick={() => window.confirm("¿Descartar este registro del dispositivo? No se podrá recuperar.") && act(o.idempotency_key, () => discardOp(o.idempotency_key))}>
                            Descartar
                          </button>
                        </span>
                      </>
                    ) : (
                      <span className="ml-auto text-muted">Pendiente</span>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </section>
          {conflicts.length > 0 && (
            <section>
              <h3 className="mb-1 font-display font-semibold">Conflictos por revisar</h3>
              <p className="mb-1 text-xs text-muted">Nada se sobrescribe sin revisión: elige qué valor conservar.</p>
              <ul className="divide-y divide-line">
                {conflicts.map((c) => (
                  <li key={c.id} className="py-2">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold">{OFFLINE_ENTITIES[c.entity_type]?.label ?? c.entity_type}</span>
                      <span className="text-muted">{CONFLICT_TEXT[c.conflict_type] ?? c.conflict_type} · {fmt(c.created_at)}{c.user_name ? ` · ${c.user_name}` : ""}</span>
                    </div>
                    {c.operation === "update" && c.client_payload && (
                      <dl className="mt-1 grid grid-cols-[auto_1fr_1fr] gap-x-3 text-xs">
                        <dt className="text-muted">Campo</dt><dd className="text-muted">En el teléfono</dd><dd className="text-muted">En el servidor</dd>
                        {Object.entries(c.client_payload).map(([k, v]) => (
                          <div key={k} className="contents">
                            <dt>{k}</dt><dd>{String(v ?? "—")}</dd><dd>{String(c.server_snapshot?.[k] ?? "—")}</dd>
                          </div>
                        ))}
                      </dl>
                    )}
                    <div className="mt-1.5 flex flex-wrap gap-2">
                      {c.operation === "update" && c.conflict_type !== "deleted_on_server" && (
                        <button className="rounded-md border border-line px-2 py-0.5 hover:bg-bg" disabled={busy === c.id}
                          onClick={() => act(c.id, () => resolveConflict(c.id, "client_wins"))}>Conservar el del teléfono</button>
                      )}
                      <button className="rounded-md bg-brand px-2 py-0.5 font-semibold text-white hover:bg-brand-strong" disabled={busy === c.id}
                        onClick={() => act(c.id, () => resolveConflict(c.id, c.operation === "update" ? "server_wins" : "discarded"))}>
                        {c.operation === "update" ? "Conservar el del servidor" : "Descartar el del teléfono"}
                      </button>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}
    </div>
  );
}
