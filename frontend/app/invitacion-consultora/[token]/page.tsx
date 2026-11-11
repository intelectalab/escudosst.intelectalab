"use client";

// Invitación de una consultora a una empresa cliente (M-CON SCR-CON-03, RN-CON-02/06). El administrador de la empresa
// (o su Jefe SST) la acepta con su sesión, eligiendo la empresa del RUC invitado. Si la empresa aún no usa EscudoSST,
// primero crea su cuenta y elige su plan; luego vuelve a este enlace.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import type { CompanyOption } from "@/lib/types";

type Invitation = { consultancy: string; ruc: string; legal_name: string; suggested_plan: string | null; company_exists: boolean; status: string; expires_at: string; message: string | null };

export default function InvitacionConsultoraPage() {
  const { token } = useParams<{ token: string }>();
  const [inv, setInv] = useState<Invitation | null>(null);
  const [invalid, setInvalid] = useState<string | null>(null);
  const [companies, setCompanies] = useState<CompanyOption[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);

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
              <div className="space-y-3">
                <p className="text-sm">Tu empresa aún no usa EscudoSST. Crea su cuenta y elige tu plan{inv.suggested_plan ? ` (tu consultora te sugiere el plan ${inv.suggested_plan})` : ""}. Luego vuelve a este enlace para aceptar.</p>
                <Link href="/prueba-gratis" className="flex h-12 items-center justify-center rounded-lg bg-brand font-semibold text-white">Crear la cuenta de mi empresa</Link>
              </div>
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
