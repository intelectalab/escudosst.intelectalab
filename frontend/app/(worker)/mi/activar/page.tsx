"use client";

// Activar la app del trabajador (docs/07 §5.2, docs/10 §5): DNI + código de activación (o el enlace que te llegó por
// WhatsApp o correo) y luego creas tu PIN. Desde ese momento ingresas con DNI + PIN en este equipo.

import "@/components/worker/InstallApp"; // escucha desde aquí el aviso de instalación del navegador
import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { Alert, BigButton, PinField } from "@/components/worker/WorkerUi";
import { api } from "@/lib/api";
import { session } from "@/lib/session";
import type { SessionResponse } from "@/lib/types";
import { errorText, workerDevice } from "@/lib/workerApp";

type Activated = SessionResponse & { company_id: string; company_name: string | null; first_name: string; device: { id: string; secret: string } };

export default function ActivateWorkerAppPage() {
  const router = useRouter();
  const [token, setToken] = useState<string | null>(null);
  const [who, setWho] = useState<{ first_name: string; company: string | null } | null>(null);
  const [dni, setDni] = useState("");
  const [code, setCode] = useState("");
  const [pin, setPin] = useState("");
  const [pin2, setPin2] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [fieldErr, setFieldErr] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const t = new URLSearchParams(window.location.search).get("t");
    if (!t) return;
    setToken(t);
    api<{ data: { first_name: string; company: string | null } }>(`/auth/worker/activation?t=${encodeURIComponent(t)}`)
      .then((r) => setWho(r.data))
      .catch((e) => {
        setToken(null);
        setError(errorText(e));
      });
  }, []);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErr({});
    try {
      const body = { ...(token ? { t: token } : { dni, code }), pin, pin_confirmation: pin2, device_uid: workerDevice.uid(), model: navigator.userAgent.slice(0, 120) };
      const r = await api<Activated>("/auth/worker/activate", { method: "POST", body });
      session.clear();
      session.setToken(r.token);
      session.setCompanyId(r.company_id);
      workerDevice.set({ company_id: r.company_id, company_name: r.company_name, device_id: r.device.id, device_secret: r.device.secret, first_name: r.first_name });
      router.replace("/mi");
    } catch (err) {
      setError(errorText(err));
      const ae = err as { errors?: Record<string, string[]> };
      setFieldErr(ae.errors ?? {});
    } finally {
      setBusy(false);
    }
  }

  const input = "h-12 w-full rounded-xl border border-line bg-surface px-4 text-lg focus:border-brand focus:outline-none";
  return (
    <main className="min-h-screen bg-bg px-4 py-8 text-fg">
      <div className="mx-auto max-w-sm space-y-5">
        <AppBrand />
        <div>
          <h1 className="font-display text-2xl font-bold">{who ? `Hola, ${who.first_name}` : "Activa tu app"}</h1>
          <p className="text-sm text-muted">{who ? `${who.company ?? "Tu empresa"} te dio acceso. Crea tu PIN para entrar.` : "Escribe tu DNI y el código que te dio el área de SST o de Recursos Humanos."}</p>
        </div>
        <form onSubmit={submit} className="space-y-4 rounded-2xl border border-line bg-surface p-5">
          {!token && (
            <>
              <label htmlFor="act-dni" className="block space-y-1">
                <span className="text-sm font-semibold">Tu DNI</span>
                <input id="act-dni" inputMode="numeric" autoComplete="off" value={dni} onChange={(e) => setDni(e.target.value.replace(/[^0-9A-Za-z]/g, "").slice(0, 12))} className={input} required />
              </label>
              <label htmlFor="act-code" className="block space-y-1">
                <span className="text-sm font-semibold">Código de activación</span>
                <input id="act-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 8))}
                  className={`${input} text-center font-mono tracking-[0.3em]`} required />
              </label>
            </>
          )}
          <PinField id="act-pin" label="Crea tu PIN (6 números)" value={pin} onChange={setPin} error={fieldErr.pin?.[0]} />
          <PinField id="act-pin2" label="Repite tu PIN" value={pin2} onChange={setPin2} />
          <p className="text-xs text-muted">No uses números repetidos ni seguidos (como 111111 o 123456). No compartas tu PIN.</p>
          {error && <Alert>{error}</Alert>}
          <BigButton type="submit" loading={busy} disabled={pin.length < 4 || pin2.length < 4 || (!token && (dni.length < 8 || code.length !== 8))}>Activar</BigButton>
        </form>
        <p className="text-center text-sm text-muted">
          ¿Ya activaste la app en este equipo? <Link href="/mi/ingresar" className="font-semibold text-brand underline">Ingresa con tu PIN</Link>
        </p>
      </div>
    </main>
  );
}
