"use client";

// Reporte de actos y condiciones subestándar por QR: el trabajador escanea el código del punto y reporta desde su
// celular, sin instalar nada ni iniciar sesión. Puede reportar sin su nombre si el punto lo permite.

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

type Point = { company: string | null; point: string; site: string | null; location: string | null; allow_anonymous: boolean; whatsapp_url?: string | null };

const TYPES: [string, string, string][] = [
  ["unsafe_condition", "Condición insegura", "Algo del lugar que puede causar un accidente (cables, pisos, máquinas, falta de señal)."],
  ["unsafe_act", "Acto inseguro", "Alguien hace algo riesgoso (sin EPP, atajos, sin bloquear la máquina)."],
  ["near_miss", "Casi accidente", "Pasó algo que pudo lastimar a alguien, pero no lo hizo."],
  ["suggestion", "Sugerencia", "Una idea para trabajar más seguros."],
];

export default function ReportarPage() {
  const { company, token } = useParams<{ company: string; token: string }>();
  const [point, setPoint] = useState<Point | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [type, setType] = useState("");
  const [description, setDescription] = useState("");
  const [location, setLocation] = useState("");
  const [anonymous, setAnonymous] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Point }>(`/public/report/${company}/${token}`)
      .then((r) => setPoint(r.data))
      .catch((e: ApiError) => setLoadError(e.detail ?? "Este código QR no es válido."));
  }, [company, token]);

  async function send(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const r = await api<{ data: { code: string } }>(`/public/report/${company}/${token}`, {
        method: "POST",
        body: { report_type: type, description, location_text: location || null, is_anonymous: anonymous, reporter_name: anonymous ? null : name, reporter_phone: anonymous ? null : phone || null },
      });
      setDone(r.data.code);
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? { form: [ae.detail ?? "No se pudo enviar. Inténtalo de nuevo."] });
    } finally {
      setBusy(false);
    }
  }

  const err = (k: string) => errors[k]?.[0];
  const shell = (children: React.ReactNode) => (
    <main className="min-h-screen bg-[#EEF4FB] px-4 py-6 text-[#0b2440]">
      <div className="mx-auto max-w-md space-y-4">
        <p className="text-center text-sm font-bold uppercase tracking-wider text-[#1b6fb7]">Reporte de seguridad</p>
        {children}
        <p className="text-center text-xs text-[#5b6b7c]">Con EscudoSST · Tu reporte llega al área de Seguridad y Salud en el Trabajo.</p>
      </div>
    </main>
  );

  if (loadError) return shell(<div className="rounded-2xl bg-white p-6 text-center shadow">{loadError}</div>);
  if (!point) return shell(<div className="rounded-2xl bg-white p-6 text-center shadow">Cargando…</div>);
  if (done)
    return shell(
      <div className="space-y-3 rounded-2xl bg-white p-6 text-center shadow">
        <p className="text-4xl" aria-hidden="true">✅</p>
        <h1 className="text-xl font-bold">¡Gracias por reportar!</h1>
        <p>Tu reporte <b>{done}</b> llegó al área de Seguridad. Lo revisarán y tomarán acción.</p>
        <button type="button" className="font-semibold text-[#1b6fb7] underline" onClick={() => { setDone(null); setType(""); setDescription(""); setLocation(""); }}>
          Hacer otro reporte
        </button>
      </div>,
    );

  return shell(
    <form onSubmit={send} className="space-y-5 rounded-2xl bg-white p-5 shadow">
      <div>
        <h1 className="text-xl font-bold">{point.company}</h1>
        <p className="text-sm text-[#5b6b7c]">
          {point.point}
          {point.site ? ` · ${point.site}` : ""}
        </p>
      </div>

      {point.whatsapp_url && (
        <a href={point.whatsapp_url} className="flex min-h-12 items-center justify-center gap-2 rounded-xl border-2 border-[#1f9d55] px-4 font-semibold text-[#14713c]">
          Reportar por WhatsApp
        </a>
      )}

      <fieldset className="space-y-2">
        <legend className="mb-1 font-semibold">¿Qué quieres reportar?</legend>
        {TYPES.map(([v, label, help]) => (
          <label key={v} className={`flex cursor-pointer gap-3 rounded-xl border-2 p-3 ${type === v ? "border-[#1b6fb7] bg-[#EEF4FB]" : "border-[#dbe4ee]"}`}>
            <input type="radio" name="type" value={v} checked={type === v} onChange={() => setType(v)} className="mt-1" />
            <span>
              <span className="block font-semibold">{label}</span>
              <span className="text-sm text-[#5b6b7c]">{help}</span>
            </span>
          </label>
        ))}
        {err("report_type") && <p className="text-sm font-medium text-[#b42318]">{err("report_type")}</p>}
      </fieldset>

      <label className="block">
        <span className="mb-1 block font-semibold">¿Qué pasó?</span>
        <textarea className="min-h-[110px] w-full rounded-xl border border-[#c9d5e2] p-3" maxLength={2000} value={description} onChange={(e) => setDescription(e.target.value)}
          placeholder="Ej.: Hay un cable pelado junto a la máquina 3 del almacén." />
        {err("description") && <span className="text-sm font-medium text-[#b42318]">{err("description")}</span>}
      </label>

      <label className="block">
        <span className="mb-1 block font-semibold">¿Dónde exactamente? <span className="font-normal text-[#5b6b7c]">(opcional)</span></span>
        <input className="w-full rounded-xl border border-[#c9d5e2] p-3" maxLength={200} value={location} onChange={(e) => setLocation(e.target.value)} placeholder={point.location ?? "Ej.: pasillo 2, junto a la puerta"} />
      </label>

      {point.allow_anonymous && (
        <label className="flex items-center gap-3 rounded-xl bg-[#F5F8FC] p-3">
          <input type="checkbox" checked={anonymous} onChange={(e) => setAnonymous(e.target.checked)} className="h-5 w-5" />
          <span>Reportar sin mi nombre</span>
        </label>
      )}
      {!anonymous && (
        <div className="grid gap-3">
          <label className="block">
            <span className="mb-1 block font-semibold">Tu nombre</span>
            <input className="w-full rounded-xl border border-[#c9d5e2] p-3" maxLength={120} value={name} onChange={(e) => setName(e.target.value)} />
            {err("reporter_name") && <span className="text-sm font-medium text-[#b42318]">{err("reporter_name")}</span>}
          </label>
          <label className="block">
            <span className="mb-1 block font-semibold">Celular <span className="font-normal text-[#5b6b7c]">(opcional, para avisarte qué se hizo)</span></span>
            <input className="w-full rounded-xl border border-[#c9d5e2] p-3" inputMode="tel" maxLength={30} value={phone} onChange={(e) => setPhone(e.target.value)} />
          </label>
        </div>
      )}

      {err("form") && <p className="rounded-lg bg-[#FDECEB] px-3 py-2 text-sm font-medium text-[#b42318]">{err("form")}</p>}
      {err("is_anonymous") && <p className="rounded-lg bg-[#FDECEB] px-3 py-2 text-sm font-medium text-[#b42318]">{err("is_anonymous")}</p>}
      <button type="submit" disabled={busy || !type} className="w-full rounded-xl bg-[#0d3b6e] py-3.5 text-lg font-bold text-white disabled:opacity-50">
        {busy ? "Enviando…" : "Enviar reporte"}
      </button>
    </form>,
  );
}
