"use client";

// Crear contraseña desde un enlace: «Olvidé mi contraseña» o invitación al equipo de la plataforma.
// Al guardar se cierran las sesiones abiertas y se vuelve a iniciar sesión.

import Link from "next/link";
import { useParams } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { AuthCard, AuthNotice, PASSWORD_RULES, PasswordRules } from "@/components/auth/AuthCard";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";

type Link = { purpose: "reset" | "team"; email: string; full_name: string; role: string | null };

export default function RestablecerPage() {
  const { token } = useParams<{ token: string }>();
  const [link, setLink] = useState<Link | null>(null);
  const [invalid, setInvalid] = useState<string | null>(null);
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [show, setShow] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    api<{ data: Link }>(`/auth/links/${token}`)
      .then((r) => setLink(r.data))
      .catch((e) => setInvalid(e instanceof ApiError ? e.message : "No pudimos abrir el enlace."));
  }, [token]);

  const ok = PASSWORD_RULES.every(([, t]) => t(password)) && password === confirm;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(`/auth/links/${token}/password`, { method: "POST", body: { password, password_confirmation: confirm } });
      setDone(true);
    } catch (err) {
      if (err instanceof ApiError && err.status === 410) setInvalid(err.message);
      else if (err instanceof ApiError) setErrors(err.errors ?? { password: [err.message] });
    } finally {
      setSaving(false);
    }
  }

  const team = link?.purpose === "team";

  return (
    <AuthCard>
      {invalid ? (
        <AuthNotice icon="clock" tone="amber" title="Este enlace ya no sirve">
          <p>{invalid}</p>
          {!team && (
            <p>
              Puedes pedir otro en{" "}
              <Link href="/recuperar" className="font-semibold text-brand hover:underline">
                ¿Olvidaste tu contraseña?
              </Link>
            </p>
          )}
        </AuthNotice>
      ) : done ? (
        <AuthNotice icon="check" tone="ok" title={team ? "¡Listo! Ya puedes ingresar" : "Contraseña actualizada"}>
          <p>{team ? "Al ingresar configurarás la verificación en dos pasos con una app de autenticación." : "Cerramos las sesiones abiertas. Ingresa con tu contraseña nueva."}</p>
        </AuthNotice>
      ) : !link ? (
        <div className="h-48 animate-pulse rounded-xl bg-line/40" aria-label="Cargando" />
      ) : (
        <form onSubmit={submit} noValidate className="space-y-5">
          <div>
            <h1 className="text-2xl font-semibold">{team ? `Hola, ${link.full_name.split(" ")[0]}` : "Crea una contraseña nueva"}</h1>
            <p className="mt-1 text-muted">{team ? <>Te sumaron al equipo de EscudoSST como <b className="text-fg">{link.role}</b>. Crea tu contraseña para empezar.</> : "Elige una contraseña que no uses en otros sitios."}</p>
          </div>
          <FormField label="Correo">
            <input className="input bg-bg text-muted" value={link.email} disabled autoComplete="username" />
          </FormField>
          <FormField label="Contraseña nueva" error={errors.password?.[0]}>
            <span className="relative block">
              <input className="input pr-20" type={show ? "text" : "password"} autoComplete="new-password" value={password} maxLength={128} onChange={(e) => setPassword(e.target.value)} autoFocus />
              <button type="button" onClick={() => setShow((v) => !v)} className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-semibold text-brand hover:bg-brand-soft">
                {show ? "Ocultar" : "Mostrar"}
              </button>
            </span>
          </FormField>
          <PasswordRules value={password} />
          <FormField label="Repite la contraseña" error={confirm && password !== confirm ? "Las contraseñas no coinciden." : undefined}>
            <input className="input" type={show ? "text" : "password"} autoComplete="new-password" value={confirm} maxLength={128} onChange={(e) => setConfirm(e.target.value)} />
          </FormField>
          <Button type="submit" variant="primary" size="lg" full icon="check" loading={saving} disabled={!ok}>
            {team ? "Crear mi contraseña" : "Guardar contraseña"}
          </Button>
          {team && (
            <p className="flex items-start gap-2 text-xs text-muted">
              <Icon name="shield" size={14} className="mt-0.5 shrink-0" />
              Después de ingresar configurarás la verificación en dos pasos: es obligatoria para el equipo de la plataforma.
            </p>
          )}
        </form>
      )}
    </AuthCard>
  );
}
