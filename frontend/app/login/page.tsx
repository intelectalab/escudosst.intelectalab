"use client";

// SCR-USR-01 · Inicio de sesión con segundo factor (docs/modulos/M-USR.md §6, docs/08-api.md §2).
// Pasos: correo y contraseña → (primera vez) configurar la app de autenticación → código → códigos de recuperación.
// Orden de verificación: 1) código de la app, 2) código por correo, 3) código de recuperación (se usa una sola vez).

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { CodeInput } from "@/components/mfa/CodeInput";
import { QrCode } from "@/components/mfa/QrCode";
import { RecoveryCodes } from "@/components/mfa/RecoveryCodes";
import { Icon } from "@/components/ui/Icon";
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
  const [showPass, setShowPass] = useState(false);

  // La sesión anterior se cerró sola (inactividad o vencimiento): se avisa.
  const [expired, setExpired] = useState(false);
  useEffect(() => setExpired(session.wasExpired()), []);

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
        // Primero se pide el código de la app; si no la tiene a la mano, pide el código por correo y, como último
        // recurso, usa uno de sus códigos de recuperación (cada uno sirve una sola vez).
        setMethod("app");
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
    <main className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-[#eef4fb] via-bg to-[#e6f6f3] px-4 py-8 sm:px-6">
      {/* Fondo suave con los colores de la marca */}
      <div className="pointer-events-none absolute -left-32 -top-32 h-96 w-96 rounded-full bg-brand/10 blur-3xl" aria-hidden="true" />
      <div className="pointer-events-none absolute -bottom-40 -right-24 h-[28rem] w-[28rem] rounded-full bg-[#1FB5A3]/10 blur-3xl" aria-hidden="true" />

      <div className="login-enter relative grid w-full max-w-5xl overflow-hidden rounded-3xl lg:min-h-[600px] border border-line bg-surface shadow-[0_30px_80px_-30px_rgba(13,59,110,.45)] lg:grid-cols-[1.05fr_1fr]">
        <section className="relative hidden flex-col justify-between gap-8 overflow-hidden bg-gradient-to-br from-[#0b2f58] via-side to-[#1b6fb7] p-10 text-white lg:flex">
          <div className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-white/10 blur-2xl" aria-hidden="true" />
          <div className="relative space-y-2">
            <AppBrand light />
            <p className="text-sm text-white/70">Gestión de seguridad y salud en el trabajo para empresas peruanas</p>
          </div>
          <div className="relative space-y-6">
            <h1 className="font-display text-[2rem] font-semibold leading-tight">Seguridad y salud en el trabajo, en orden y a la mano.</h1>
            <ul className="space-y-3">
              {[
                ["shield", "Riesgos e IPERC", "Matrices por puesto, mapas de riesgo y controles."],
                ["clipboard", "Inspecciones y capacitaciones", "En campo, con o sin señal, y con firma del trabajador."],
                ["file", "Expediente SUNAFIL", "Listo en minutos, con enlace temporal para el inspector."],
              ].map(([icon, title, text], i) => (
                <li key={title} className="login-item flex items-start gap-3 rounded-2xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur-sm" style={{ animationDelay: `${0.15 + i * 0.1}s` }}>
                  <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white/15"><Icon name={icon as "shield"} size={18} /></span>
                  <span><b className="block text-sm">{title}</b><span className="text-sm text-white/75">{text}</span></span>
                </li>
              ))}
            </ul>
          </div>
          <p className="relative flex items-center gap-2 text-xs text-white/60"><Icon name="lock" size={14} /> Ley N.° 29783 y su reglamento</p>
        </section>

        <section className="relative flex flex-col justify-center px-6 py-8 sm:px-10 sm:py-10">
        <div className="mb-6 lg:hidden"><AppBrand /></div>
        <div className="relative w-full space-y-5">
          {expired && !error && step === "password" && (
            <div role="status" className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">
              Tu sesión se cerró por seguridad (inactividad o tiempo máximo). Vuelve a ingresar.
            </div>
          )}
          {error && (
            <div role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">
              {error}
            </div>
          )}

          {step === "password" && (
            <form onSubmit={onPassword} noValidate className="space-y-5">
              <div>
                <h2 className="font-display text-2xl font-semibold">Iniciar sesión</h2>
                <p className="mt-1 text-muted">Ingresa con tu correo de trabajo o tu DNI.</p>
              </div>
              <Field label="Correo o DNI" error={fieldErrors.email?.[0]}>
                <span className="relative block">
                  <Icon name="mail" size={17} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
                  <input type="text" autoComplete="username" autoCapitalize="none" spellCheck={false} value={email} onChange={(e) => setEmail(e.target.value.trim())} className="input pl-10" placeholder="nombre@empresa.com o 12345678" required />
                </span>
              </Field>
              <Field label="Contraseña" error={fieldErrors.password?.[0]}>
                <span className="relative block">
                  <Icon name="lock" size={17} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
                  <input type={showPass ? "text" : "password"} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="input pl-10 pr-11" required />
                  <button type="button" onClick={() => setShowPass(!showPass)} className="absolute right-1.5 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-muted hover:bg-bg hover:text-fg" aria-label={showPass ? "Ocultar contraseña" : "Mostrar contraseña"}>
                    <Icon name="eye" size={17} />
                  </button>
                </span>
              </Field>
              <p className="-mt-2 text-right text-sm">
                <Link href="/recuperar" className="font-semibold text-brand hover:underline">
                  ¿Olvidaste tu contraseña?
                </Link>
              </p>
              <Submit loading={loading} label="Ingresar" busy="Ingresando…" />
              <p className="text-center text-sm text-muted">
                ¿Eres trabajador? <Link href="/mi/ingresar" className="font-semibold text-brand hover:underline">Ingresa con tu DNI y PIN</Link>
              </p>
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
              {method === "email" && (
                <button type="button" onClick={sendEmailCode} disabled={loading} className="block w-full text-center text-sm font-semibold text-brand hover:underline">
                  Reenviar el código
                </button>
              )}
              <div className="space-y-2 border-t border-line pt-4">
                <p className="text-xs font-bold uppercase tracking-wide text-muted">¿No puedes con este método?</p>
                {([
                  ["app", "1", "Código de la app", "Google o Microsoft Authenticator en tu celular.", () => changeMethod("app")],
                  ["email", "2", "Código por correo", "Te enviamos 6 dígitos a tu correo.", sendEmailCode],
                  ["recovery", "3", "Código de recuperación", "Los que guardaste al activar la verificación. Cada uno sirve una sola vez.", () => changeMethod("recovery")],
                ] as const)
                  .filter(([k]) => k !== method && (mfaEnabled || k === "email"))
                  .map(([k, n, label, hint, go]) => (
                    <button key={k} type="button" onClick={go} disabled={loading}
                      className="flex w-full items-center gap-3 rounded-xl border border-line px-3 py-2.5 text-left transition hover:border-brand/50 hover:bg-brand-soft/40 disabled:opacity-60">
                      <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-strong">{n}</span>
                      <span className="min-w-0">
                        <b className="block text-sm">{label}</b>
                        <span className="block text-xs text-muted">{hint}</span>
                      </span>
                    </button>
                  ))}
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
        <div className="relative mt-6 space-y-2 text-center text-sm">
          <Link href="/" className="inline-flex items-center gap-1 text-muted hover:text-brand">
            <span aria-hidden="true">←</span> Volver al portal
          </Link>
          <p className="text-xs text-muted">
            ¿Aún no usas EscudoSST? <Link href="/demo" className="font-semibold text-brand hover:underline">Solicita una demostración</Link>
          </p>
        </div>
        </section>
      </div>
      <style>{`
        .login-enter { animation: loginIn .5s cubic-bezier(.2,.7,.2,1) both }
        .login-item { animation: loginItem .5s cubic-bezier(.2,.7,.2,1) both }
        @keyframes loginIn { from { opacity: 0; transform: translateY(14px) scale(.985) } to { opacity: 1; transform: none } }
        @keyframes loginItem { from { opacity: 0; transform: translateX(-10px) } to { opacity: 1; transform: none } }
        @media (prefers-reduced-motion: reduce) { .login-enter, .login-item { animation: none } }
      `}</style>
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
    <button type="submit" disabled={loading} className="w-full rounded-lg bg-brand py-3 text-base font-semibold text-white hover:bg-brand-strong disabled:opacity-60">
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
