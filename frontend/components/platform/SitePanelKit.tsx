"use client";

// Piezas comunes de las pantallas del sitio en el panel (campañas, ventanas, integraciones, novedades, webinars…):
// carga de la lista, guardado con errores por campo, selector de medios y tarjeta de pasos «cómo funciona».

import { useCallback, useEffect, useState } from "react";
import { type Media, MediaPicker, MediaThumb } from "@/components/platform/MediaKit";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";

export type Errors = Record<string, string[]>;

/** Lista del panel: GET a la ruta; devuelve el cuerpo completo (data + catálogos). */
export function usePanelList<T>(path: string) {
  const [res, setRes] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<T>(path)
      .then((r) => {
        setRes(r);
        setError(null);
      })
      .catch((e) => setError(e instanceof ApiError ? e.message : "No se pudo cargar."));
  }, [path]);
  useEffect(load, [load]);
  return { res, error, load };
}

/** Guarda (POST/PUT) y traduce el error de la API a errores por campo o un mensaje general. */
export function useSave() {
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  async function run<T>(fn: () => Promise<T>): Promise<T | null> {
    setSaving(true);
    setErrors({});
    setMessage(null);
    try {
      return await fn();
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        setMessage(e.errors ? "Revisa los campos marcados." : e.message);
      } else setMessage("No se pudo guardar.");
      return null;
    } finally {
      setSaving(false);
    }
  }
  const err = (k: string) => errors[k]?.[0];
  return { errors, message, saving, run, err, setMessage };
}

/** Acción rápida (publicar, pausar…) con su error en un aviso. */
export async function act(path: string, onError: (m: string) => void, body?: unknown): Promise<boolean> {
  try {
    await api(path, { method: "POST", body });
    return true;
  } catch (e) {
    onError(e instanceof ApiError ? (e.errors ? Object.values(e.errors)[0]?.[0] : e.message) ?? e.message : "No se pudo.");
    return false;
  }
}

/** Campo «elegir de la biblioteca de medios» con miniatura y botón para quitar. */
export function MediaField({
  label,
  hint,
  value,
  fileId,
  kind = "image",
  error,
  onChange,
}: {
  label: string;
  hint?: string;
  value: string | null;
  fileId?: string | null;
  kind?: Media["kind"];
  error?: string;
  onChange: (id: string | null, fileId: string | null) => void;
}) {
  const [picking, setPicking] = useState(false);
  return (
    <div>
      <span className="mb-1.5 block text-sm font-semibold">{label}</span>
      <div className="flex flex-wrap items-center gap-3">
        {value && fileId && kind !== "video" ? (
          <MediaThumb m={{ id: value, file_id: fileId, kind } as Media} className="h-14 w-14 rounded-lg" />
        ) : value ? (
          <span className="rounded-lg bg-bg px-3 py-2 text-xs text-muted">Archivo elegido</span>
        ) : null}
        <Button size="sm" icon="eye" onClick={() => setPicking(true)}>
          {value ? "Cambiar" : "Elegir de la biblioteca"}
        </Button>
        {value && (
          <Button size="sm" variant="danger-ghost" onClick={() => onChange(null, null)}>
            Quitar
          </Button>
        )}
      </div>
      {error ? <p className="mt-1.5 text-xs font-medium text-bad">● {error}</p> : hint && <p className="mt-1.5 text-xs text-muted">{hint}</p>}
      {picking && (
        <MediaPicker
          kind={kind}
          onClose={() => setPicking(false)}
          onPick={(m) => {
            setPicking(false);
            if (m[0]) onChange(m[0].id, m[0].file_id);
          }}
        />
      )}
    </div>
  );
}

/** Pasos «cómo funciona» de cada pantalla (3 o 4 tarjetas). */
export function HowItWorks({ steps }: { steps: readonly (readonly [string, string, string])[] }) {
  return (
    <ol className={`grid grid-cols-1 gap-3 md:grid-cols-2 ${steps.length > 3 ? "xl:grid-cols-4" : "xl:grid-cols-3"}`}>
      {steps.map(([icon, title, text]) => (
        <li key={title} className="flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
            <Icon name={icon} size={17} />
          </span>
          <span>
            <b className="block text-sm">{title}</b>
            <span className="text-xs text-muted">{text}</span>
          </span>
        </li>
      ))}
    </ol>
  );
}

export const dt = (d: string | null) => (d ? new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d)) : "—");
export const day = (d: string | null) => (d ? new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeZone: "America/Lima" }).format(new Date(d.length === 10 ? `${d}T12:00:00-05:00` : d)) : "—");
/** Fecha y hora de Lima para un <input type="datetime-local">. */
export const toLocalInput = (d: string | null) => (d ? new Date(d).toLocaleString("sv-SE", { timeZone: "America/Lima" }).slice(0, 16).replace(" ", "T") : "");
export const fromLocalInput = (v: string) => (v ? `${v}:00-05:00` : null);

/** Explicación corta de cada pantalla: para qué sirve, dónde se ve y 3 pasos. */
export function Guide({ what, where, steps }: { what: React.ReactNode; where: React.ReactNode; steps: React.ReactNode[] }) {
  return (
    <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div className="space-y-2 text-sm">
          <p>
            <b>Para qué sirve:</b> <span className="text-muted">{what}</span>
          </p>
          <p>
            <b>Dónde se ve:</b> <span className="text-muted">{where}</span>
          </p>
        </div>
        <ol className="space-y-2 text-sm">
          {steps.map((s, i) => (
            <li key={i} className="flex items-start gap-2.5">
              <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand text-xs font-bold text-white">{i + 1}</span>
              <span className="pt-0.5 text-muted">{s}</span>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
