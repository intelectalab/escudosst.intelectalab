"use client";

// «Mi cuenta» › tus datos: nombre, correo (con confirmación al correo nuevo) y contraseña (pide la actual).
// Sirve igual para el equipo de la plataforma y para los usuarios de las empresas.

import { FormEvent, useState } from "react";
import { PASSWORD_RULES, PasswordRules } from "@/components/auth/AuthCard";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";

type Mode = "idle" | "name" | "email" | "password";

export function AccountEditor({ name, email, roleLabel, roleValue, platform }: { name: string; email: string; roleLabel: string; roleValue: string; platform: boolean }) {
  const [mode, setMode] = useState<Mode>("idle");
  const [notice, setNotice] = useState<string | null>(null);

  const row = (icon: string, label: string, value: string, edit?: Mode, action?: string) => (
    <div className="flex items-center gap-3 rounded-xl border border-line px-3.5 py-2.5">
      <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-bg text-muted">
        <Icon name={icon} size={16} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-xs text-muted">{label}</span>
        <b className="block truncate text-sm">{value}</b>
      </span>
      {edit && (
        <button type="button" onClick={() => (setMode(edit), setNotice(null))} className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-brand hover:bg-brand-soft">
          {action ?? "Cambiar"}
        </button>
      )}
    </div>
  );

  return (
    <div className="space-y-3">
      {notice && <p className="rounded-lg bg-ok-soft px-4 py-3 text-sm text-ok">{notice}</p>}
      {mode === "name" ? (
        <NameForm initial={name} onDone={() => window.location.reload()} onCancel={() => setMode("idle")} />
      ) : (
        row("user", "Nombre", name, "name", "Editar")
      )}
      {mode === "email" ? (
        <EmailForm
          current={email}
          onDone={(to) => {
            setMode("idle");
            setNotice(`Te enviamos un enlace a ${to}. Tu correo cambia cuando lo confirmes; mientras tanto sigues ingresando con ${email}.`);
          }}
          onCancel={() => setMode("idle")}
        />
      ) : (
        row("mail", "Correo para ingresar", email, "email")
      )}
      {mode === "password" ? (
        <PasswordForm
          onDone={() => {
            setMode("idle");
            setNotice("Contraseña cambiada. Cerramos tus otras sesiones abiertas.");
          }}
          onCancel={() => setMode("idle")}
        />
      ) : (
        row("lock", "Contraseña", "••••••••••", "password")
      )}
      {row("shield", roleLabel, roleValue)}
      {platform && (
        <p className="flex items-start gap-2 rounded-xl bg-amber-soft/70 px-3.5 py-2.5 text-xs text-amber-ink">
          <Icon name="bell" size={14} className="mt-0.5 shrink-0" />
          Usa un correo real, al que entres a diario y con espacio libre: ahí llegan los códigos de verificación, los enlaces para recuperar tu contraseña y los avisos del equipo.
        </p>
      )}
    </div>
  );
}

function Actions({ saving, onCancel, label }: { saving: boolean; onCancel: () => void; label: string }) {
  return (
    <div className="flex flex-wrap gap-2">
      <Button type="submit" variant="primary" icon="check" loading={saving}>
        {label}
      </Button>
      <Button type="button" onClick={onCancel}>
        Cancelar
      </Button>
    </div>
  );
}

function NameForm({ initial, onDone, onCancel }: { initial: string; onDone: () => void; onCancel: () => void }) {
  const [v, setV] = useState(initial);
  const [err, setErr] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      await api("/me/profile", { method: "PATCH", body: { full_name: v } });
      onDone();
    } catch (x) {
      setErr(x instanceof ApiError ? (x.errors?.full_name?.[0] ?? x.message) : "No se pudo guardar.");
      setSaving(false);
    }
  }
  return (
    <form onSubmit={submit} className="space-y-3 rounded-xl border border-brand/40 bg-brand-soft/30 p-3.5" noValidate>
      <FormField label="Nombre completo" error={err ?? undefined}>
        <input className="input" maxLength={120} value={v} onChange={(e) => setV(e.target.value)} autoFocus />
      </FormField>
      <Actions saving={saving} onCancel={onCancel} label="Guardar" />
    </form>
  );
}

function EmailForm({ current, onDone, onCancel }: { current: string; onDone: (to: string) => void; onCancel: () => void }) {
  const [email, setEmail] = useState("");
  const [pass, setPass] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api("/me/email", { method: "POST", body: { email, current_password: pass } });
      onDone(email);
    } catch (x) {
      setErrors(x instanceof ApiError ? (x.errors ?? { email: [x.message] }) : { email: ["No se pudo enviar."] });
      setSaving(false);
    }
  }
  return (
    <form onSubmit={submit} className="space-y-3 rounded-xl border border-brand/40 bg-brand-soft/30 p-3.5" noValidate>
      <p className="text-xs text-muted">Hoy ingresas con {current}. Te enviaremos un enlace al correo nuevo para confirmarlo.</p>
      <FormField label="Correo nuevo" error={errors.email?.[0]}>
        <input className="input" type="email" maxLength={160} value={email} onChange={(e) => setEmail(e.target.value)} autoFocus />
      </FormField>
      <FormField label="Tu contraseña actual" error={errors.current_password?.[0]}>
        <input className="input" type="password" autoComplete="current-password" value={pass} onChange={(e) => setPass(e.target.value)} />
      </FormField>
      <Actions saving={saving} onCancel={onCancel} label="Enviar enlace" />
    </form>
  );
}

function PasswordForm({ onDone, onCancel }: { onDone: () => void; onCancel: () => void }) {
  const [cur, setCur] = useState("");
  const [pw, setPw] = useState("");
  const [confirm, setConfirm] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const ok = PASSWORD_RULES.every(([, t]) => t(pw)) && pw === confirm && !!cur;
  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api("/me/password", { method: "POST", body: { current_password: cur, password: pw, password_confirmation: confirm } });
      onDone();
    } catch (x) {
      setErrors(x instanceof ApiError ? (x.errors ?? { password: [x.message] }) : { password: ["No se pudo guardar."] });
      setSaving(false);
    }
  }
  return (
    <form onSubmit={submit} className="space-y-3 rounded-xl border border-brand/40 bg-brand-soft/30 p-3.5" noValidate>
      <FormField label="Contraseña actual" error={errors.current_password?.[0]}>
        <input className="input" type="password" autoComplete="current-password" value={cur} onChange={(e) => setCur(e.target.value)} autoFocus />
      </FormField>
      <FormField label="Contraseña nueva" error={errors.password?.[0]}>
        <input className="input" type="password" autoComplete="new-password" maxLength={128} value={pw} onChange={(e) => setPw(e.target.value)} />
      </FormField>
      <PasswordRules value={pw} />
      <FormField label="Repite la contraseña nueva" error={confirm && pw !== confirm ? "Las contraseñas no coinciden." : undefined}>
        <input className="input" type="password" autoComplete="new-password" maxLength={128} value={confirm} onChange={(e) => setConfirm(e.target.value)} />
      </FormField>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" variant="primary" icon="check" loading={saving} disabled={!ok}>
          Cambiar contraseña
        </Button>
        <Button type="button" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </form>
  );
}
