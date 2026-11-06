"use client";

// Marco común de las pantallas de acceso sin sesión (recuperar contraseña, crear contraseña, confirmar correo).

import Link from "next/link";
import { Brand } from "@/components/Brand";
import { Icon } from "@/components/ui/Icon";

export function AuthCard({ children }: { children: React.ReactNode }) {
  return (
    <main className="relative grid min-h-screen place-items-center overflow-hidden bg-bg p-4 sm:p-6">
      <div className="absolute inset-x-0 top-0 h-64 bg-gradient-to-br from-side to-brand" aria-hidden="true" />
      <div className="relative w-full max-w-md space-y-6 rounded-2xl border border-line bg-surface p-6 shadow-xl sm:p-8">
        <Brand />
        {children}
      </div>
    </main>
  );
}

export function AuthNotice({ icon, tone, title, children }: { icon: string; tone: "ok" | "amber"; title: string; children: React.ReactNode }) {
  return (
    <div className="space-y-4 text-center">
      <span className={`mx-auto grid h-12 w-12 place-items-center rounded-full ${tone === "ok" ? "bg-ok-soft text-ok" : "bg-amber-soft text-amber-ink"}`}>
        <Icon name={icon} size={24} />
      </span>
      <h1 className="text-xl font-semibold">{title}</h1>
      <div className="space-y-2 text-sm text-muted">{children}</div>
      <Link href="/login" className="inline-flex h-11 w-full items-center justify-center rounded-lg border border-line font-semibold hover:border-brand hover:text-brand-strong">
        Ir a iniciar sesión
      </Link>
    </div>
  );
}

export const PASSWORD_RULES: [string, (p: string) => boolean][] = [
  ["Al menos 10 caracteres", (p) => p.length >= 10],
  ["Una letra", (p) => /[A-Za-zÁÉÍÓÚáéíóúÑñ]/.test(p)],
  ["Un número", (p) => /\d/.test(p)],
];

export function PasswordRules({ value }: { value: string }) {
  return (
    <ul className="grid gap-1 text-sm" aria-label="Requisitos de la contraseña">
      {PASSWORD_RULES.map(([label, test]) => (
        <li key={label} className={`flex items-center gap-2 ${test(value) ? "text-ok" : "text-muted"}`}>
          <Icon name={test(value) ? "check" : "close"} size={14} />
          {label}
        </li>
      ))}
    </ul>
  );
}
