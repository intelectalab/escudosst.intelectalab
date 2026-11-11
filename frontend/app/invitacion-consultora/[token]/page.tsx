"use client";

// Invitación de una consultora a una empresa cliente (M-CON SCR-CON-03, RN-CON-02/06). El administrador de la empresa
// (o su Jefe SST) la acepta con su sesión, eligiendo la empresa del RUC invitado. Si la empresa aún no usa EscudoSST,
// crea su cuenta aquí mismo con la invitación (prueba gratuita, el administrador es el correo invitado) y queda vinculada
// a la consultora al terminar; el plan lo elige la empresa después (RN-CON-03).

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import type { CompanyOption } from "@/lib/types";

type Invitation = {
  consultancy: string; ruc: string; legal_name: string; suggested_plan: string | null; company_exists: boolean; status: string; expires_at: string; message: string | null;
  invited_email?: string;
};

export default function InvitacionConsultoraPage() {
  const { token } = useParams<{ token: string }>();
  const [inv, setInv] = useState<Invitation | null>(null);
  const [invalid, setInvalid] = useState<string | null>(null);
  const [companies, setCompanies] = useState<CompanyOption[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);
  const [signedUp, setSignedUp] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Invitation }>(`/public/con/invitations/${token}`)
      .then((r) => setInv(r.data))
      .catch((e) => setInvalid(e instanceof ApiError ? e.message : "No pudimos abrir la invitación."));
    if (session.getToken()) {
      api<{ data: CompanyOption[] }>("/me/companies").then((r) => setCompanies(r.data)).catch(() => setCompanies([]));
    }
  }, [token]);

  const target = inv && companies?.find((c) => c.ruc === inv.ruc);
  const accept = async () => {
    if (!target) return;
    setSaving(true);
    setError(null);
    try {
      await api(`/con/invitations/${token}/accept`, { method: "POST", headers: { "X-Company-Id": target.company_id } });
      setDone(true);
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.title) : "No pudimos aceptar la invitación.");
      setSaving(false);
    }
  };

  return (
    <main className="grid min-h-screen place-items-center bg-bg px-4 py-10">
      <div className="w-full max-w-md space-y-5 rounded-2xl border border-line bg-surface p-6 sm:p-8">
        <div className="rounded-xl bg-side px-4 py-3"><AppBrand light /></div>
        {invalid ? (
          <p className="text-muted">{invalid}</p>
        ) : !inv ? (
          <div className="h-40 animate-pulse rounded-xl bg-bg" />
        ) : signedUp ? (
          <div className="space-y-3 text-center">
            <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-ok-soft text-ok"><Icon name="mail" size={26} /></span>
            <h1 className="text-xl font-semibold">Tu empresa ya está en EscudoSST</h1>
            <p className="text-sm text-muted">
              {inv.consultancy} ya puede gestionarla. Te enviamos a <b className="text-fg">{signedUp}</b> el enlace para crear tu contraseña.
              Puedes quitarle el acceso a la consultora cuando quieras.
            </p>
            <Link href="/login" className="inline-block font-semibold text-brand hover:underline">Ir al ingreso</Link>
          </div>
        ) : done ? (
          <div className="space-y-3 text-center">
            <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-ok-soft text-ok"><Icon name="check" size={26} /></span>
            <h1 className="text-xl font-semibold">Listo: {inv.consultancy} ya gestiona tu empresa</h1>
            <p className="text-sm text-muted">Puedes quitarle el acceso cuando quieras. Tus datos siguen siendo de tu empresa.</p>
            <Link href="/select-company" className="inline-block font-semibold text-brand hover:underline">Ir a EscudoSST</Link>
          </div>
        ) : (
          <div className="space-y-4">
            <div>
              <h1 className="text-xl font-semibold">{inv.consultancy} te invita a EscudoSST</h1>
              <p className="mt-1 text-sm text-muted">Para gestionar la seguridad y salud en el trabajo de <b className="text-fg">{inv.legal_name}</b> (RUC {inv.ruc}).</p>
            </div>
            {inv.message && <p className="rounded-lg bg-bg px-4 py-3 text-sm">{inv.message}</p>}
            {inv.status !== "sent" ? (
              <p className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">
                {inv.status === "accepted" ? "Esta invitación ya fue aceptada." : "La invitación venció o fue anulada. Pide a tu consultora que te envíe otra."}
              </p>
            ) : !inv.company_exists ? (
              <SignupForm token={token} inv={inv} onDone={setSignedUp} />
            ) : !session.getToken() ? (
              <div className="space-y-3">
                <p className="text-sm">Ingresa con la cuenta del administrador o del Jefe SST de la empresa, y vuelve a este enlace para aceptar.</p>
                <Link href="/login" className="flex h-12 items-center justify-center rounded-lg bg-brand font-semibold text-white">Iniciar sesión</Link>
              </div>
            ) : companies === null ? (
              <div className="h-12 animate-pulse rounded-lg bg-bg" />
            ) : !target ? (
              <p className="rounded-lg bg-bg px-4 py-3 text-sm text-muted">Tu usuario no tiene acceso a la empresa con RUC {inv.ruc}. Ingresa con la cuenta de su administrador.</p>
            ) : (
              <div className="space-y-3">
                <p className="text-sm text-muted">Al aceptar, la consultora podrá trabajar en tu empresa. Tu empresa conserva su plan y puede quitarle el acceso cuando quiera.</p>
                <Button variant="primary" size="lg" full loading={saving} onClick={accept}>Aceptar la vinculación</Button>
                <p className="text-center text-xs text-muted">Vence el {new Date(inv.expires_at).toLocaleDateString("es-PE")}.</p>
              </div>
            )}
            {error && <p className="text-sm text-bad">{error}</p>}
          </div>
        )}
      </div>
    </main>
  );
}

/** Alta de la empresa invitada desde la invitación: crea la cuenta (prueba gratuita) y la vincula a la consultora. */
function SignupForm({ token, inv, onDone }: { token: string; inv: Invitation; onDone: (email: string) => void }) {
  const [f, setF] = useState({ full_name: "", trade_name: "", headcount: "", phone: "", accept_legal: false, website: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const send = async () => {
    setBusy(true);
    setErrors({});
    setMsg(null);
    try {
      const r = await api<{ data: { email?: string } }>(`/public/con/invitations/${token}/signup`, {
        method: "POST",
        body: { ...f, headcount: f.headcount ? Number(f.headcount) : null, trade_name: f.trade_name || null, phone: f.phone || null },
      });
      onDone(r.data.email ?? inv.invited_email ?? "tu correo");
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setMsg(err.errors ? null : (err.detail ?? err.title));
    } finally {
      setBusy(false);
    }
  };
  const err = (k: string) => errors[k]?.[0];
  return (
    <div className="space-y-3">
      <p className="text-sm">
        Tu empresa aún no usa EscudoSST. Crea su cuenta aquí: empiezas con la prueba gratuita y luego eliges tu plan
        {inv.suggested_plan ? ` (tu consultora te sugiere el plan ${inv.suggested_plan})` : ""}. Al terminar, la consultora queda vinculada.
      </p>
      <p className="rounded-lg bg-bg px-3 py-2 text-sm">
        Administrador de la cuenta: <b>{inv.invited_email ?? "el correo invitado"}</b>
      </p>
      <label className="block text-sm font-medium">
        Tu nombre completo *
        <input className="input mt-1" autoComplete="name" value={f.full_name} onChange={(e) => setF({ ...f, full_name: e.target.value })} />
        {err("full_name") && <span className="mt-1 block text-xs text-bad">{err("full_name")}</span>}
      </label>
      <label className="block text-sm font-medium">
        Nombre comercial (opcional)
        <input className="input mt-1" value={f.trade_name} onChange={(e) => setF({ ...f, trade_name: e.target.value })} />
      </label>
      <div className="grid grid-cols-2 gap-3">
        <label className="block text-sm font-medium">
          Trabajadores *
          <input className="input mt-1" type="number" min={1} inputMode="numeric" value={f.headcount} onChange={(e) => setF({ ...f, headcount: e.target.value })} />
          {err("headcount") && <span className="mt-1 block text-xs text-bad">{err("headcount")}</span>}
        </label>
        <label className="block text-sm font-medium">
          Teléfono (opcional)
          <input className="input mt-1" type="tel" autoComplete="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
        </label>
      </div>
      <input type="text" tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" value={f.website} onChange={(e) => setF({ ...f, website: e.target.value })} />
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" className="mt-1" checked={f.accept_legal} onChange={(e) => setF({ ...f, accept_legal: e.target.checked })} />
        <span>
          Acepto los <Link href="/legal/terminos-y-condiciones" target="_blank" className="font-semibold text-brand underline">Términos y condiciones</Link> y la{" "}
          <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-brand underline">Política de privacidad</Link>.
        </span>
      </label>
      {err("accept_legal") && <p className="text-xs text-bad">{err("accept_legal")}</p>}
      {msg && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
      <Button variant="primary" size="lg" full loading={busy} disabled={!f.full_name.trim() || !f.headcount || !f.accept_legal} onClick={send}>
        Crear la cuenta y aceptar
      </Button>
      <p className="text-center text-xs text-muted">
        ¿Tu empresa ya usa EscudoSST con otro RUC o cuenta? <Link href="/login" className="font-semibold text-brand hover:underline">Inicia sesión</Link>.
      </p>
    </div>
  );
}
