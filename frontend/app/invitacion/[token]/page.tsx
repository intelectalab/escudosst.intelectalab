"use client";

// Aceptar invitación: el administrador de cuenta crea su contraseña (GET/POST /auth/invitations/{token}).
// Después inicia sesión y configura la verificación en dos pasos (obligatoria para su rol).

import { useParams, useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";

type Invitation = { full_name: string; email: string; company_name: string };

const RULES: [string, (p: string) => boolean][] = [
  ["Al menos 10 caracteres", (p) => p.length >= 10],
  ["Una letra", (p) => /[A-Za-zÁÉÍÓÚáéíóúÑñ]/.test(p)],
  ["Un número", (p) => /\d/.test(p)],
];

export default function InvitacionPage() {
  const { token } = useParams<{ token: string }>();
  const router = useRouter();
  const [invite, setInvite] = useState<Invitation | null>(null);
  const [invalid, setInvalid] = useState<string | null>(null);
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [show, setShow] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api<{ data: Invitation }>(`/auth/invitations/${token}`)
      .then((r) => setInvite(r.data))
      .catch((e) => setInvalid(e instanceof ApiError ? e.message : "No pudimos abrir la invitación."));
  }, [token]);

  const allOk = RULES.every(([, test]) => test(password)) && password === confirm;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setError(null);
    try {
      const r = await api<{ data: { email: string } }>(`/auth/invitations/${token}/accept`, {
        method: "POST",
        body: { password, password_confirmation: confirm },
      });
      router.replace(`/login?email=${encodeURIComponent(r.data.email)}`);
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.type === "invitation_invalid") setInvalid(e.message);
        else if (e.errors) setErrors(e.errors);
        else setError(e.message);
      } else setError("No se pudo guardar la contraseña.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-bg p-4 sm:p-6">
      <div className="w-full max-w-md space-y-6 rounded-2xl border border-line bg-surface p-6 shadow-sm sm:p-8">
        <AppBrand />

        {invalid ? (
          <div className="space-y-4 text-center">
            <span className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-amber-soft text-amber-ink">
              <Icon name="clock" size={24} />
            </span>
            <h1 className="text-xl font-semibold">Este enlace ya no sirve</h1>
            <p className="text-sm text-muted">{invalid}</p>
            <Button variant="secondary" full onClick={() => router.push("/login")}>
              Ir a iniciar sesión
            </Button>
          </div>
        ) : !invite ? (
          <div className="h-48 animate-pulse rounded-xl bg-line/40" aria-label="Cargando invitación" />
        ) : (
          <form onSubmit={submit} noValidate className="space-y-5">
            <div>
              <h1 className="text-2xl font-semibold">Hola, {invite.full_name.split(" ")[0]}</h1>
              <p className="mt-1 text-muted">
                Te invitaron a administrar <b className="text-fg">{invite.company_name}</b>. Crea tu contraseña para empezar.
              </p>
            </div>

            {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}

            <FormField label="Correo">
              <input className="input bg-bg text-muted" value={invite.email} disabled autoComplete="username" />
            </FormField>
            <FormField label="Contraseña" error={errors.password?.[0]}>
              <span className="relative block">
                <input
                  className="input pr-20"
                  type={show ? "text" : "password"}
                  autoComplete="new-password"
                  value={password}
                  maxLength={128}
                  aria-invalid={!!errors.password || undefined}
                  onChange={(e) => setPassword(e.target.value)}
                  autoFocus
                />
                <button type="button" onClick={() => setShow((v) => !v)} className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-semibold text-brand hover:bg-brand-soft">
                  {show ? "Ocultar" : "Mostrar"}
                </button>
              </span>
            </FormField>
            <ul className="grid gap-1 text-sm" aria-label="Requisitos de la contraseña">
              {RULES.map(([label, test]) => (
                <li key={label} className={`flex items-center gap-2 ${test(password) ? "text-ok" : "text-muted"}`}>
                  <Icon name={test(password) ? "check" : "close"} size={14} />
                  {label}
                </li>
              ))}
            </ul>
            <FormField label="Repite la contraseña" error={confirm && password !== confirm ? "Las contraseñas no coinciden." : undefined}>
              <input className="input" type={show ? "text" : "password"} autoComplete="new-password" value={confirm} maxLength={128} onChange={(e) => setConfirm(e.target.value)} />
            </FormField>

            <Button type="submit" variant="primary" size="lg" full icon="check" loading={saving} disabled={!allOk}>
              Crear contraseña
            </Button>
            <p className="flex items-start gap-2 text-xs text-muted">
              <Icon name="shield" size={14} className="mt-0.5 shrink-0" />
              Después de ingresar, configurarás la verificación en dos pasos con una app de autenticación.
            </p>
          </form>
        )}
      </div>
    </main>
  );
}
