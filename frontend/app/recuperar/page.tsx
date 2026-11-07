"use client";

// «¿Olvidaste tu contraseña?»: pide el correo y envía un enlace (60 min). La respuesta es la misma exista o no la
// cuenta, para no revelar qué correos están registrados.

import Link from "next/link";
import { FormEvent, useState } from "react";
import { AuthCard, AuthNotice } from "@/components/auth/AuthCard";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { api, ApiError } from "@/lib/api";

export default function RecuperarPage() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api("/auth/password/forgot", { method: "POST", body: { email } });
      setSent(true);
    } catch (err) {
      setError(err instanceof ApiError ? (err.errors?.email?.[0] ?? err.message) : "No pudimos enviar el correo.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <AuthCard>
      {sent ? (
        <AuthNotice icon="mail" tone="ok" title="Revisa tu correo">
          <p>
            Si <b className="text-fg">{email}</b> tiene una cuenta, te llegará un enlace para crear una contraseña nueva. Vence en 60 minutos.
          </p>
          <p>Si no lo ves, revisa spam o promociones.</p>
        </AuthNotice>
      ) : (
        <form onSubmit={submit} noValidate className="space-y-5">
          <div>
            <h1 className="text-2xl font-semibold">¿Olvidaste tu contraseña?</h1>
            <p className="mt-1 text-muted">Escribe tu correo y te enviamos un enlace para crear una nueva.</p>
          </div>
          {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}
          <FormField label="Correo">
            <input className="input" type="email" autoComplete="username" value={email} maxLength={160} onChange={(e) => setEmail(e.target.value)} autoFocus />
          </FormField>
          <Button type="submit" variant="primary" size="lg" full icon="mail" loading={saving} disabled={!email.includes("@")}>
            Enviarme el enlace
          </Button>
          <p className="text-center text-sm">
            <Link href="/login" className="font-semibold text-brand hover:underline">
              Volver a iniciar sesión
            </Link>
          </p>
        </form>
      )}
    </AuthCard>
  );
}
