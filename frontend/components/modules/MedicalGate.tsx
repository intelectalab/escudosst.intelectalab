"use client";

// Clave médica para EMO, VMD, VUL, DES y REG (docs/10 §5-6): antes de ver o registrar datos de salud se confirma la contraseña
// con el segundo factor (código de la app o de recuperación) y el motivo; la clave
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
        throw new ApiError(403, "reauth_required", "Confirma tu identidad");
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

/**
 * Formulario «Confirma tu identidad» contra /{module}/reauth (M-EMO §7, docs/10 §5): contraseña, código de 6 dígitos de la
 * app de autenticación (o un código de recuperación, que se gasta) y el motivo de la consulta.
 */
export function MedReauth({ endpoint, onOk, onCancel, who = "el personal de salud", submitLabel = "Ver datos de salud" }: {
  endpoint: string; onOk: (k: MedKey) => void; onCancel?: () => void; who?: string; submitLabel?: string;
}) {
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [recovery, setRecovery] = useState("");
  const [useRecovery, setUseRecovery] = useState(false);
  const [purpose, setPurpose] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const factorOk = useRecovery ? recovery.trim().length >= 8 : code.length === 6;
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const body = useRecovery ? { password, recovery_code: recovery.trim(), purpose } : { password, code, purpose };
      const r = await api<{ data: { reauth_id: string; expires_at: string } }>(endpoint, { method: "POST", body, withCompany: true });
      onOk({ id: r.data.reauth_id, expires: new Date(r.data.expires_at).getTime() });
    } catch (err) {
      if (err instanceof ApiError && err.errors) setFieldErrors(err.errors);
      else if (err instanceof ApiError && err.status === 403 && err.type !== "mfa_not_enabled") setError(`Solo ${who} ve estos datos de salud.`);
      else setError(errMsg(err));
      setCode("");
    } finally {
      setBusy(false);
    }
  }
  function toggle() {
    setUseRecovery(!useRecovery);
    setCode("");
    setRecovery("");
    setFieldErrors({});
  }
  return (
    <form onSubmit={submit} className="max-w-md space-y-3 rounded-xl border border-line bg-surface p-5">
      <h3 className="font-display text-base font-semibold">Confirma tu identidad</h3>
      <p className="text-sm text-muted">
        Vas a ver datos de salud confidenciales. Confirma tu contraseña y el código de tu app de autenticación, e indica el motivo. El acceso dura unos
        minutos y cada consulta queda registrada a tu nombre.
      </p>
      {error && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      <FormField label="Contraseña" error={fieldErrors.password?.[0]}>
        <input type="password" aria-label="Contraseña" autoComplete="current-password" className="input w-full" value={password} onChange={(e) => setPassword(e.target.value)} />
      </FormField>
      {useRecovery ? (
        <FormField label="Código de recuperación" hint="Uno de los que guardaste al activar la verificación en dos pasos. Cada uno sirve una sola vez." error={fieldErrors.recovery_code?.[0]}>
          <input aria-label="Código de recuperación" autoComplete="off" placeholder="XXXX-XXXX" className="input w-full font-mono tracking-widest" value={recovery}
            onChange={(e) => setRecovery(e.target.value.toUpperCase())} />
        </FormField>
      ) : (
        <FormField label="Código de la app" hint="Los 6 dígitos que muestra tu app de autenticación." error={fieldErrors.code?.[0]}>
          <input aria-label="Código de la app" inputMode="numeric" autoComplete="one-time-code" maxLength={6} placeholder="000000" className="input w-full font-mono tracking-widest" value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))} />
        </FormField>
      )}
      <button type="button" className="text-sm text-brand hover:underline" onClick={toggle}>
        {useRecovery ? "Usar el código de la app" : "Usar un código de recuperación"}
      </button>
      <FormField label="Motivo de la consulta" error={fieldErrors.purpose?.[0]}>
        <input aria-label="Motivo de la consulta" maxLength={300} placeholder="Ej.: revisión de aptitud del examen periódico" className="input w-full" value={purpose}
          onChange={(e) => setPurpose(e.target.value)} />
      </FormField>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" variant="primary" loading={busy} disabled={!password || !factorOk || purpose.trim().length < 3}>{submitLabel}</Button>
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
        ? "Datos de salud de la base separada. Ver el detalle exige confirmar tu contraseña y el código de tu app de autenticación, y cada acceso queda registrado."
        : text ?? "Ves solo información no sensible. El detalle de salud es exclusivo del personal médico."}
    </p>
  );
}

export const fmtDate = (v: string | null | undefined) => (v ? v.slice(0, 10).split("-").reverse().join("/") : "—");
