"use client";

// Ingreso a la app del trabajador con DNI + PIN (docs/07 §5.2, docs/10 §5). Solo en el equipo donde activaste la app:
// la credencial de este equipo viaja con tu DNI y tu PIN. Tras varios PIN equivocados se bloquea unos minutos.

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { Alert, BigButton, PinField } from "@/components/worker/WorkerUi";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import type { SessionResponse } from "@/lib/types";
import { errorText, workerDevice, type WorkerDevice } from "@/lib/workerApp";

export default function WorkerPinLoginPage() {
  const router = useRouter();
  const [device, setDevice] = useState<WorkerDevice | null | undefined>(undefined);
  const [dni, setDni] = useState("");
  const [pin, setPin] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => setDevice(workerDevice.get()), []);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!device) return;
    setBusy(true);
    setError(null);
    try {
      const r = await api<SessionResponse & { company_id: string }>("/auth/worker/pin-login", {
        method: "POST", body: { company_id: device.company_id, device_id: device.device_id, device_secret: device.device_secret, dni, pin },
      });
      session.clear();
      session.setToken(r.token);
      session.setCompanyId(r.company_id);
      router.replace("/mi");
    } catch (err) {
      if (err instanceof ApiError && err.type === "device_unknown") {
        workerDevice.clear();
        setDevice(null);
      }
      setError(errorText(err));
      setPin("");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="min-h-screen bg-bg px-4 py-8 text-fg">
      <div className="mx-auto max-w-sm space-y-5">
        <AppBrand />
        {device === undefined ? null : !device ? (
          <div className="space-y-4 rounded-2xl border border-line bg-surface p-5">
            <h1 className="font-display text-2xl font-bold">Activa tu app</h1>
            {error && <Alert>{error}</Alert>}
            <p className="text-sm text-muted">Este equipo aún no tiene tu app activada. Pide tu código al área de SST o de Recursos Humanos.</p>
            <Link href="/mi/activar" className="inline-flex h-12 w-full items-center justify-center rounded-xl bg-brand font-semibold text-white">Tengo un código</Link>
            <Link href="/login" className="inline-flex h-12 w-full items-center justify-center rounded-xl border border-line font-semibold">Ingresar con correo y contraseña</Link>
          </div>
        ) : (
          <>
            <div>
              <h1 className="font-display text-2xl font-bold">Hola{device.first_name ? `, ${device.first_name}` : ""}</h1>
              <p className="text-sm text-muted">{device.company_name ?? "Tu empresa"} · escribe tu DNI y tu PIN.</p>
            </div>
            <form onSubmit={submit} className="space-y-4 rounded-2xl border border-line bg-surface p-5">
              <label htmlFor="pin-dni" className="block space-y-1">
                <span className="text-sm font-semibold">Tu DNI</span>
                <input id="pin-dni" inputMode="numeric" autoComplete="username" value={dni} onChange={(e) => setDni(e.target.value.replace(/[^0-9A-Za-z]/g, "").slice(0, 12))}
                  className="h-12 w-full rounded-xl border border-line bg-surface px-4 text-lg focus:border-brand focus:outline-none" required />
              </label>
              <PinField id="pin-pin" label="Tu PIN" value={pin} onChange={setPin} />
              {error && <Alert>{error}</Alert>}
              <BigButton type="submit" loading={busy} disabled={dni.length < 8 || pin.length < 4}>Ingresar</BigButton>
            </form>
            <p className="text-center text-sm text-muted">
              ¿Olvidaste tu PIN? Pide un código nuevo al área de SST y <Link href="/mi/activar" className="font-semibold text-brand underline">actívala de nuevo</Link>.
            </p>
          </>
        )}
      </div>
    </main>
  );
}
