"use client";

// SCR-USR-01 · Inicio de sesión con segundo factor (docs/modulos/M-USR.md §6, docs/08-api.md §2).
// Pasos: correo y contraseña → (primera vez) configurar la app de autenticación → código → códigos de recuperación.
// Pendiente: «olvidé mi contraseña», código por correo e ingreso por DNI.

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { Brand } from "@/components/Brand";
import { CodeInput } from "@/components/mfa/CodeInput";
import { QrCode } from "@/components/mfa/QrCode";
import { RecoveryCodes } from "@/components/mfa/RecoveryCodes";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import type { LoginResponse, MfaSetup, SessionResponse } from "@/lib/types";

type Step = "password" | "setup" | "verify" | "codes";

export default function LoginPage() {
  const router = useRouter();
  const [step, setStep] = useState<Step>("password");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [challengeId, setChallengeId] = useState("");
  const [setup, setSetup] = useState<MfaSetup | null>(null);
  const [code, setCode] = useState("");
  const [recovery, setRecovery] = useState("");
  // Cómo se verifica: código de la app, código por correo o código de recuperación.
  const [method, setMethod] = useState<"app" | "email" | "recovery">("app");
  const [emailSentTo, setEmailSentTo] = useState<string | null>(null);
  const [mfaEnabled, setMfaEnabled] = useState(true);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);

  // Llega desde la invitación con el correo ya escrito (?email=).
  useEffect(() => {
    const e = new URLSearchParams(window.location.search).get("email");
    if (e) setEmail(e);
  }, []);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [loading, setLoading] = useState(false);

  const [nextPath, setNextPath] = useState("/select-company");

  /** El equipo de la plataforma va a su panel; los demás eligen empresa. */
  const homeFor = (res: SessionResponse) => (res.user.is_platform_staff ? "/plataforma" : "/select-company");

  function enter(res: SessionResponse) {
    session.clear();
    session.setToken(res.token);
    router.push(homeFor(res));
  }

  async function run(fn: () => Promise<void>) {
    setError(null);
    setFieldErrors({});
    setLoading(true);
    try {
      await fn();
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.type === "mfa_challenge_expired" || err.type === "account_locked") {
          resetToPassword();
        }
        setError(err.type === "validation_failed" ? null : err.message);
        setFieldErrors(err.errors ?? {});
      } else {
        setError("Ocurrió un error inesperado.");
      }
    } finally {
      setLoading(false);
    }
  }

  function resetToPassword() {
    setStep("password");
    setChallengeId("");
    setSetup(null);
    setCode("");
    setRecovery("");
    setMethod("app");
    setEmailSentTo(null);
  }

  function changeMethod(m: "app" | "email" | "recovery") {
    setMethod(m);
    setCode("");
    setError(null);
    setFieldErrors({});
  }

  /** Envía (o reenvía) el código por correo y pasa al paso de verificación. */
  const sendEmailCode = () =>
    run(async () => {
      const res = await api<{ sent_to: string }>("/auth/mfa/email", { method: "POST", body: { mfa_challenge_id: challengeId } });
      setEmailSentTo(res.sent_to);
      changeMethod("email");
      setStep("verify");
    });

  const onPassword = (e: FormEvent) => {
    e.preventDefault();
    run(async () => {
      const res = await api<LoginResponse>("/auth/login", { method: "POST", body: { email, password } });
      if (!res.mfa_required) return enter(res);
      setChallengeId(res.mfa_challenge_id);
      setPassword("");
      setMfaEnabled(!res.mfa_setup_required);
      if (res.mfa_setup_required) {
        const s = await api<MfaSetup>("/auth/mfa/setup", { method: "POST", body: { mfa_challenge_id: res.mfa_challenge_id } });
        setSetup(s);
        setStep("setup");
      } else {
        setStep("verify");
      }
    });
  };

  const onConfirmSetup = (e: FormEvent) => {
    e.preventDefault();
    run(async () => {
      const res = await api<SessionResponse>("/auth/mfa/confirm", { method: "POST", body: { mfa_challenge_id: challengeId, code } });
      session.clear();
      session.setToken(res.token);
      setRecoveryCodes(res.recovery_codes ?? []);
      setNextPath(homeFor(res));
      setStep("codes");
    });
  };

  const onVerify = (e: FormEvent) => {
    e.preventDefault();
    run(async () => {
      const body =
        method === "recovery"
          ? { mfa_challenge_id: challengeId, recovery_code: recovery }
          : method === "email"
            ? { mfa_challenge_id: challengeId, email_code: code }
            : { mfa_challenge_id: challengeId, code };
      const res = await api<SessionResponse>("/auth/mfa/verify", { method: "POST", body });
      enter(res);
    });
  };

  return (
    <main className="min-h-screen grid lg:grid-cols-2">
      <section className="hidden lg:flex flex-col justify-between bg-side p-12 text-white">
        <Brand light />
        <div className="space-y-4 max-w-md">
          <h1 className="text-3xl font-semibold leading-tight">Seguridad y salud en el trabajo, en orden y a la mano.</h1>
          <p className="text-white/75">IPERC, inspecciones, capacitaciones, EPP y el expediente para SUNAFIL en un solo lugar.</p>
        </div>
        <p className="text-sm text-white/60">Ley N.° 29783 y su reglamento</p>
      </section>

      <section className="flex items-center justify-center p-6">
        <div className="w-full max-w-sm space-y-5 rounded-xl border border-line bg-surface p-8 shadow-sm">
          <div className="lg:hidden">
            <Brand />
          </div>

          {error && (
            <div role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">
              {error}
            </div>
          )}

          {step === "password" && (
            <form onSubmit={onPassword} noValidate className="space-y-5">
              <div>
                <h2 className="text-2xl font-semibold">Iniciar sesión</h2>
                <p className="mt-1 text-muted">Ingresa con tu correo de trabajo.</p>
              </div>
              <Field label="Correo" error={fieldErrors.email?.[0]}>
                <input type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} className="input" placeholder="nombre@empresa.com" required />
              </Field>
              <Field label="Contraseña" error={fieldErrors.password?.[0]}>
                <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="input" required />
              </Field>
              <p className="-mt-2 text-right text-sm">
                <Link href="/recuperar" className="font-semibold text-brand hover:underline">
                  ¿Olvidaste tu contraseña?
                </Link>
              </p>
              <Submit loading={loading} label="Ingresar" busy="Ingresando…" />
            </form>
          )}

          {step === "setup" && setup && (
            <form onSubmit={onConfirmSetup} noValidate className="space-y-4">
              <div>
                <h2 className="text-xl font-semibold">Activa la verificación en dos pasos</h2>
                <p className="mt-1 text-sm text-muted">Por seguridad, tu rol la exige. Solo se hace una vez.</p>
              </div>
              <ol className="list-decimal space-y-1 pl-5 text-sm">
                <li>
                  Instala una app de autenticación en tu celular, por ejemplo <b>Google Authenticator</b> o <b>Microsoft Authenticator</b>.
                </li>
                <li>En la app, elige «Agregar cuenta» y escanea este código.</li>
              </ol>
              <div className="flex justify-center">
                <QrCode value={setup.otpauth_uri} />
              </div>
              <details className="text-sm">
                <summary className="cursor-pointer text-brand">¿No puedes escanear? Escribe la clave</summary>
                <p className="mt-2 break-all rounded bg-bg p-2 font-mono text-xs">{setup.secret.match(/.{1,4}/g)?.join(" ")}</p>
                <p className="mt-1 text-xs text-muted">Cuenta: {setup.account} · Tipo: basada en tiempo</p>
              </details>
              <Field label="3. Escribe el código de 6 dígitos que muestra la app" error={fieldErrors.code?.[0]}>
                <CodeInput value={code} onChange={setCode} />
              </Field>
              <Submit loading={loading} label="Activar y continuar" busy="Verificando…" />
              <button type="button" onClick={sendEmailCode} disabled={loading} className="w-full text-sm text-brand hover:underline">
                ¿No tienes el celular a la mano? Recibe un código por correo esta vez
              </button>
              <Back onClick={resetToPassword} />
            </form>
          )}

          {step === "verify" && (
            <form onSubmit={onVerify} noValidate className="space-y-4">
              <div>
                <h2 className="text-xl font-semibold">Verificación en dos pasos</h2>
                <p className="mt-1 text-sm text-muted">
                  {method === "recovery"
                    ? "Escribe uno de tus códigos de recuperación."
                    : method === "email"
                      ? `Te enviamos un código de 6 dígitos a ${emailSentTo ?? "tu correo"}. Revisa también la carpeta de spam.`
                      : "Abre tu app de autenticación y escribe el código de EscudoSST."}
                </p>
              </div>
              {method === "recovery" ? (
                <Field label="Código de recuperación" error={fieldErrors.recovery_code?.[0]}>
                  <input value={recovery} onChange={(e) => setRecovery(e.target.value.toUpperCase())} placeholder="XXXX-XXXX" autoFocus className="input text-center font-mono text-xl tracking-widest" />
                </Field>
              ) : (
                <Field label="Código de 6 dígitos" error={fieldErrors.code?.[0]}>
                  <CodeInput value={code} onChange={setCode} />
                </Field>
              )}
              <Submit loading={loading} label="Verificar" busy="Verificando…" />
              <div className="space-y-2 text-center text-sm">
                {method === "email" ? (
                  <button type="button" onClick={sendEmailCode} disabled={loading} className="block w-full text-brand hover:underline">
                    Reenviar el código
                  </button>
                ) : (
                  <button type="button" onClick={sendEmailCode} disabled={loading} className="block w-full text-brand hover:underline">
                    Enviarme un código por correo
                  </button>
                )}
                {mfaEnabled && method !== "app" && (
                  <button type="button" onClick={() => changeMethod("app")} className="block w-full text-brand hover:underline">
                    Usar el código de la app
                  </button>
                )}
                {mfaEnabled && method !== "recovery" && (
                  <button type="button" onClick={() => changeMethod("recovery")} className="block w-full text-brand hover:underline">
                    ¿Perdiste tu celular? Usa un código de recuperación
                  </button>
                )}
              </div>
              <Back onClick={resetToPassword} />
            </form>
          )}

          {step === "codes" && (
            <div className="space-y-4">
              <div>
                <h2 className="text-xl font-semibold">¡Listo! Verificación activada</h2>
                <p className="mt-1 text-sm text-muted">Estos son tus códigos de recuperación.</p>
              </div>
              <RecoveryCodes codes={recoveryCodes} onDone={() => router.push(nextPath)} />
            </div>
          )}
        </div>
      </section>
    </main>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-bold">{label}</span>
      {children}
      {error && <span className="block text-sm text-bad">{error}</span>}
    </label>
  );
}

function Submit({ loading, label, busy }: { loading: boolean; label: string; busy: string }) {
  return (
    <button type="submit" disabled={loading} className="w-full rounded-lg bg-brand py-3 text-lg font-semibold text-white hover:bg-brand-strong disabled:opacity-60">
      {loading ? busy : label}
    </button>
  );
}

function Back({ onClick }: { onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="w-full text-sm text-muted hover:underline">
      Volver a ingresar con otro correo
    </button>
  );
}
