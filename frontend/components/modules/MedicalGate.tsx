"use client";

// Clave médica para VUL y DES (docs/10 §6): antes de ver o registrar datos de salud se confirma la contraseña; la clave
// dura unos minutos, se envía en la cabecera X-Medical-Reauth y cada acceso queda registrado a nombre de quien consulta.

import { useCallback, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";

export type MedKey = { id: string; expires: number };
export type MedFetch = <T>(path: string, opts?: { method?: "GET" | "POST" | "PUT"; body?: unknown }) => Promise<T>;

export const errMsg = (e: unknown) => (e instanceof ApiError ? (e.detail ?? e.title) : "No pudimos completar la acción.");

/** Clave médica vigente y un fetch que la envía; si vence o el servidor la rechaza, se pide de nuevo. */
export function useMedicalKey() {
  const [key, setKey] = useState<MedKey | null>(null);
  const valid = key && key.expires > Date.now() ? key : null;
  const med: MedFetch = useCallback(
    async <T,>(path: string, opts: { method?: "GET" | "POST" | "PUT"; body?: unknown } = {}): Promise<T> => {
      if (!key || key.expires < Date.now()) {
        setKey(null);
        throw new ApiError(403, "reauth_required", "Confirma tu contraseña");
      }
      try {
        return await api<T>(path, { ...opts, withCompany: true, headers: { "X-Medical-Reauth": key.id } });
      } catch (e) {
        if (e instanceof ApiError && e.type === "reauth_required") setKey(null);
        throw e;
      }
    },
    [key],
  ) as MedFetch;
  return { key: valid, setKey, clear: () => setKey(null), med };
}

/** Formulario «Confirma tu contraseña» contra /{module}/reauth. */
export function MedReauth({ endpoint, onOk, onCancel, who = "el personal de salud" }: { endpoint: string; onOk: (k: MedKey) => void; onCancel?: () => void; who?: string }) {
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { reauth_id: string; expires_at: string } }>(endpoint, { method: "POST", body: { password }, withCompany: true });
      onOk({ id: r.data.reauth_id, expires: new Date(r.data.expires_at).getTime() });
    } catch (err) {
      setError(err instanceof ApiError && err.status === 403 ? `Solo ${who} ve estos datos de salud.` : errMsg(err));
    } finally {
      setBusy(false);
    }
  }
  return (
    <form onSubmit={submit} className="max-w-md space-y-3 rounded-xl border border-line bg-surface p-5">
      <h3 className="font-display text-base font-semibold">Confirma tu contraseña</h3>
      <p className="text-sm text-muted">Vas a ver datos de salud confidenciales. El acceso dura unos minutos y cada consulta queda registrada a tu nombre.</p>
      <FormField label="Contraseña" error={error ?? undefined}>
        <input type="password" aria-label="Contraseña" autoComplete="current-password" className="input w-full" value={password} onChange={(e) => setPassword(e.target.value)} />
      </FormField>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" variant="primary" loading={busy} disabled={!password}>Ver datos de salud</Button>
        {onCancel && <Button type="button" variant="ghost" onClick={onCancel}>Cancelar</Button>}
      </div>
    </form>
  );
}

/** Aviso de la parte superior (prototipo avisoMed): qué ve cada rol. */
export function MedNotice({ medical, text }: { medical: boolean; text?: string }) {
  return (
    <p className={`rounded-lg px-4 py-2 text-sm ${medical ? "bg-brand-soft" : "bg-bg text-muted"}`}>
      {medical
        ? "Datos de salud de la base separada. Ver el detalle exige confirmar tu contraseña y cada acceso queda registrado."
        : text ?? "Ves solo información no sensible. El detalle de salud es exclusivo del personal médico."}
    </p>
  );
}

export const fmtDate = (v: string | null | undefined) => (v ? v.slice(0, 10).split("-").reverse().join("/") : "—");
