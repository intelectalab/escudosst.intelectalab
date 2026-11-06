"use client";

// SCR-USR-05 · Mi cuenta (componente): perfil, verificación en dos pasos y apariencia (docs/modulos/M-USR.md §6).
// Pendiente: cambiar contraseña, sesiones activas e idioma.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { CodeInput } from "@/components/mfa/CodeInput";
import { QrCode } from "@/components/mfa/QrCode";
import { RecoveryCodes } from "@/components/mfa/RecoveryCodes";
import { api, ApiError } from "@/lib/api";
import { getTheme, setTheme, type Theme } from "@/lib/theme";
import type { MfaSetup, MfaStatus, User } from "@/lib/types";

type Mode = "idle" | "regenerate" | "enable" | "disable" | "codes";

/** Panel «Mi cuenta» reutilizado por la app de empresa y por el panel de la plataforma. */
export function AccountPanel({ user, roleLabel, roleValue }: { user: User; roleLabel: string; roleValue: string }) {
  const [status, setStatus] = useState<MfaStatus | null>(null);
  const [mode, setMode] = useState<Mode>("idle");
  const [setup, setSetup] = useState<MfaSetup | null>(null);
  const [code, setCode] = useState("");
  const [codes, setCodes] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [theme, setThemeState] = useState<Theme>("auto");

  const load = useCallback(() => api<MfaStatus>("/auth/mfa").then(setStatus), []);

  useEffect(() => {
    load();
    setThemeState(getTheme());
  }, [load]);

  function reset() {
    setMode("idle");
    setSetup(null);
    setCode("");
    setError(null);
  }

  async function run(fn: () => Promise<void>) {
    setError(null);
    setNotice(null);
    setLoading(true);
    try {
      await fn();
    } catch (err) {
      setError(err instanceof ApiError ? (err.errors?.code?.[0] ?? err.message) : "Ocurrió un error inesperado.");
    } finally {
      setLoading(false);
    }
  }

  const startEnable = () =>
    run(async () => {
      setSetup(await api<MfaSetup>("/auth/mfa/setup", { method: "POST", body: {} }));
      setMode("enable");
    });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    run(async () => {
      if (mode === "regenerate") {
        const res = await api<{ recovery_codes: string[] }>("/auth/mfa/recovery-codes", { method: "POST", body: { code } });
        setCodes(res.recovery_codes);
        setMode("codes");
      } else if (mode === "enable") {
        const res = await api<{ recovery_codes: string[] }>("/auth/mfa/confirm", { method: "POST", body: { code } });
        setCodes(res.recovery_codes);
        setMode("codes");
      } else if (mode === "disable") {
        await api("/auth/mfa/disable", { method: "POST", body: { code } });
        setNotice("Verificación en dos pasos desactivada.");
        reset();
      }
      setCode("");
      await load();
    });
  };

  function chooseTheme(t: Theme) {
    setTheme(t);
    setThemeState(t);
  }

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Mi cuenta</h1>
        <p className="text-muted">Tus datos, tu seguridad y cómo ves EscudoSST.</p>
      </div>

      <section className="rounded-xl border border-line bg-surface p-6 space-y-3">
        <h2 className="text-lg font-semibold">Perfil</h2>
        <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-[max-content_1fr] text-sm">
          <dt className="text-muted">Nombre</dt>
          <dd>{user.full_name}</dd>
          <dt className="text-muted">Correo</dt>
          <dd>{user.email}</dd>
          <dt className="text-muted">{roleLabel}</dt>
          <dd>{roleValue}</dd>
        </dl>
      </section>

      <section className="rounded-xl border border-line bg-surface p-6 space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-semibold">Verificación en dos pasos</h2>
          {status && (
            <span className={`rounded-full px-3 py-1 text-sm font-bold ${status.mfa_enabled ? "bg-ok-soft text-ok" : "bg-na-soft text-na"}`}>
              {status.mfa_enabled ? "Activa" : "Desactivada"}
            </span>
          )}
        </div>

        {notice && <div className="rounded-lg bg-ok-soft px-4 py-3 text-sm text-ok">{notice}</div>}
        {error && (
          <div role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">
            {error}
          </div>
        )}

        {status && mode === "idle" && (
          <div className="space-y-3 text-sm">
            <p className="text-muted">
              Además de tu contraseña, al ingresar te pedimos un código de tu app de autenticación.
              {status.mfa_required && " Tu rol la exige, así que no se puede desactivar."}
            </p>
            {status.mfa_enabled && (
              <p>
                Te quedan <b>{status.recovery_codes_left}</b> códigos de recuperación.
                {status.recovery_codes_left <= 2 && <span className="text-bad"> Genera códigos nuevos pronto.</span>}
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              {status.mfa_enabled ? (
                <>
                  <button onClick={() => setMode("regenerate")} className="rounded-lg border border-line px-4 py-2 hover:bg-bg">
                    Generar códigos de recuperación nuevos
                  </button>
                  {!status.mfa_required && (
                    <button onClick={() => setMode("disable")} className="rounded-lg border border-line px-4 py-2 text-bad hover:bg-bad-soft">
                      Desactivar
                    </button>
                  )}
                </>
              ) : (
                <button onClick={startEnable} disabled={loading} className="rounded-lg bg-brand px-4 py-2 font-semibold text-white hover:bg-brand-strong">
                  Activar verificación en dos pasos
                </button>
              )}
            </div>
          </div>
        )}

        {(mode === "regenerate" || mode === "disable" || mode === "enable") && (
          <form onSubmit={submit} className="space-y-4 max-w-sm">
            {mode === "enable" && setup && (
              <>
                <p className="text-sm">Escanea este código con tu app de autenticación (Google Authenticator, Microsoft Authenticator u otra).</p>
                <QrCode value={setup.otpauth_uri} size={180} />
                <p className="break-all rounded bg-bg p-2 font-mono text-xs">{setup.secret.match(/.{1,4}/g)?.join(" ")}</p>
              </>
            )}
            {mode === "regenerate" && <p className="text-sm text-muted">Los códigos anteriores dejarán de servir. Confirma con el código de tu app.</p>}
            {mode === "disable" && <p className="text-sm text-muted">Confirma con el código de tu app para desactivar la verificación.</p>}
            <label className="block space-y-1.5">
              <span className="text-sm font-bold">Código de 6 dígitos</span>
              <CodeInput value={code} onChange={setCode} />
            </label>
            <div className="flex gap-2">
              <button type="submit" disabled={loading} className="flex-1 rounded-lg bg-brand py-2.5 font-semibold text-white hover:bg-brand-strong disabled:opacity-60">
                {loading ? "Verificando…" : "Confirmar"}
              </button>
              <button type="button" onClick={reset} className="rounded-lg border border-line px-4 py-2.5 hover:bg-bg">
                Cancelar
              </button>
            </div>
          </form>
        )}

        {mode === "codes" && <RecoveryCodes codes={codes} onDone={reset} doneLabel="Listo" />}
      </section>

      <section className="rounded-xl border border-line bg-surface p-6 space-y-3">
        <h2 className="text-lg font-semibold">Apariencia</h2>
        <p className="text-sm text-muted">Se guarda en este dispositivo.</p>
        <div role="radiogroup" aria-label="Tema" className="inline-flex rounded-lg border border-line p-1">
          {(
            [
              ["auto", "Automático"],
              ["light", "Claro"],
              ["dark", "Oscuro"],
            ] as [Theme, string][]
          ).map(([t, label]) => (
            <button
              key={t}
              role="radio"
              aria-checked={theme === t}
              onClick={() => chooseTheme(t)}
              className={`rounded-md px-4 py-2 text-sm ${theme === t ? "bg-brand text-white font-semibold" : "hover:bg-bg"}`}
            >
              {label}
            </button>
          ))}
        </div>
      </section>
    </div>
  );
}
