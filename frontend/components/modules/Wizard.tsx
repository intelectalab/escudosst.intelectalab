"use client";

// Asistente común (docs/07-pantallas-y-ux.md §3.3): pasos numerados con barra de progreso, «Atrás» que nunca pierde
// datos, guardado automático del borrador en este dispositivo (por usuario, empresa y asistente) y resumen final
// antes de confirmar. Se usa en línea base, eventos, PETAR, expediente SUNAFIL, carga de trabajadores y primeros pasos.

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useApp } from "@/components/AppContext";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import type { ApiError } from "@/lib/api";

export type WizardStep<T> = {
  title: string;
  /** Texto corto bajo el título del paso. */
  hint?: string;
  render: (data: T, set: (patch: Partial<T>) => void) => React.ReactNode;
  /** Mensaje si el paso no está completo (bloquea «Siguiente»). */
  validate?: (data: T) => string | null;
  /** Trabajo al pasar al siguiente paso (p. ej. crear el borrador en el servidor). Devuelve cambios a los datos. */
  onNext?: (data: T) => Promise<Partial<T> | void>;
};

/** Mensaje claro de un error de la API (primer error de campo o el detalle). */
export function errorText(e: unknown): string {
  const ae = e as ApiError;
  return Object.values(ae?.errors ?? {})[0]?.[0] ?? ae?.detail ?? ae?.title ?? ((e as Error)?.message || "No se pudo completar. Inténtalo otra vez.");
}

type Draft<T> = { data: T; step: number; saved_at: string };

function storage(): Storage | null {
  try {
    return typeof window === "undefined" ? null : window.localStorage;
  } catch {
    return null;
  }
}

/** Clave del borrador: un asistente por usuario y empresa. */
export function draftKey(userId: string, companyId: string, id: string) {
  return `escudo.wizard.${userId}.${companyId}.${id}`;
}

export function Wizard<T extends object>({
  id,
  title,
  subtitle,
  icon = "checklist",
  initial,
  steps: stepsOf,
  summary,
  confirmLabel = "Confirmar",
  onConfirm,
  onClose,
  inline = false,
}: {
  /** Identificador del asistente para el borrador (p. ej. «lba-new»). */
  id: string;
  title: string;
  subtitle?: string;
  icon?: string;
  initial: T;
  /** Pasos (pueden depender de los datos, p. ej. un paso por lineamiento). El resumen se agrega al final. */
  steps: WizardStep<T>[] | ((data: T) => WizardStep<T>[]);
  summary: (data: T, goTo: (step: number) => void) => React.ReactNode;
  confirmLabel?: string;
  /** Guarda todo. Puede devolver una pantalla de resultado (con sus propias acciones) para mostrar al final. */
  onConfirm: (data: T) => Promise<React.ReactNode | void>;
  onClose: () => void;
  /** Sin ventana: se muestra dentro de la página. */
  inline?: boolean;
}) {
  const { user, company } = useApp();
  const key = draftKey(user.id, company.company_id, id);
  const restored = useRef<Draft<T> | null>(null);
  if (restored.current === null) {
    try {
      const raw = storage()?.getItem(key);
      restored.current = raw ? (JSON.parse(raw) as Draft<T>) : ({ data: null, step: -1, saved_at: "" } as unknown as Draft<T>);
    } catch {
      restored.current = { data: null, step: -1, saved_at: "" } as unknown as Draft<T>;
    }
  }
  const hadDraft = restored.current.step >= 0 && restored.current.data !== null;
  const [data, setData] = useState<T>(() => (hadDraft ? { ...initial, ...restored.current!.data } : initial));
  const [step, setStep] = useState(() => (hadDraft ? restored.current!.step : 0));
  const [savedAt, setSavedAt] = useState<string | null>(hadDraft ? restored.current!.saved_at : null);
  const [notice, setNotice] = useState(hadDraft);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<React.ReactNode | null>(null);

  const steps = useMemo(() => (typeof stepsOf === "function" ? stepsOf(data) : stepsOf), [stepsOf, data]);
  const total = steps.length + 1;
  const current = Math.min(step, total - 1);
  const isSummary = current === steps.length;

  // Guardado automático del borrador al cambiar datos o paso.
  useEffect(() => {
    if (result) return;
    const at = new Date().toISOString();
    try {
      storage()?.setItem(key, JSON.stringify({ data, step: current, saved_at: at } satisfies Draft<T>));
      setSavedAt(at);
    } catch {
      /* sin almacenamiento local: el asistente sigue funcionando sin borrador */
    }
  }, [data, current, key, result]);

  const set = useCallback((patch: Partial<T>) => setData((d) => ({ ...d, ...patch })), []);
  const clearDraft = () => {
    try {
      storage()?.removeItem(key);
    } catch {
      /* nada */
    }
  };
  const restart = () => {
    clearDraft();
    setData(initial);
    setStep(0);
    setNotice(false);
    setError(null);
  };

  const next = async () => {
    setError(null);
    const s = steps[current];
    const msg = s?.validate?.(data);
    if (msg) {
      setError(msg);
      return;
    }
    if (s?.onNext) {
      setBusy(true);
      try {
        const patch = await s.onNext(data);
        if (patch) setData((d) => ({ ...d, ...patch }));
      } catch (e) {
        setError(errorText(e));
        setBusy(false);
        return;
      }
      setBusy(false);
    }
    setStep(current + 1);
  };
  const back = () => {
    setError(null);
    if (current === 0) onClose();
    else setStep(current - 1);
  };
  const confirm = async () => {
    setError(null);
    for (let i = 0; i < steps.length; i++) {
      const msg = steps[i].validate?.(data);
      if (msg) {
        setStep(i);
        setError(msg);
        return;
      }
    }
    setBusy(true);
    try {
      const r = await onConfirm(data);
      clearDraft();
      setResult(r ?? <p className="text-sm">Listo. Los datos quedaron guardados.</p>);
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const pct = result ? 100 : Math.round(((current + 1) / total) * 100);
  const time = savedAt ? new Intl.DateTimeFormat("es-PE", { timeStyle: "short" }).format(new Date(savedAt)) : null;

  const body = (
    <div className="space-y-4">
      {/* Pasos numerados con progreso */}
      <div>
        <ol className="flex gap-1.5 overflow-x-auto pb-1 text-xs" aria-label="Pasos">
          {[...steps.map((s) => s.title), "Resumen"].map((t, i) => {
            const done = result || i < current;
            const cur = !result && i === current;
            return (
              <li key={`${t}-${i}`} className="shrink-0">
                <button
                  type="button"
                  disabled={!!result || i > current || busy}
                  onClick={() => setStep(i)}
                  aria-current={cur ? "step" : undefined}
                  className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-semibold ${
                    cur ? "border-brand bg-brand-soft text-brand-strong" : done ? "border-line text-ok" : "border-line text-muted"
                  }`}
                >
                  <span className={`grid h-5 w-5 place-items-center rounded-full text-[11px] ${cur ? "bg-brand text-white" : done ? "bg-ok-soft text-ok" : "bg-bg"}`}>
                    {done ? "✓" : i + 1}
                  </span>
                  <span className={cur ? "" : "hidden sm:inline"}>{t}</span>
                </button>
              </li>
            );
          })}
        </ol>
        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-bg" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Avance">
          <div className="h-full bg-brand transition-all" style={{ width: `${pct}%` }} />
        </div>
        <p className="mt-1 flex flex-wrap justify-between gap-2 text-xs text-muted">
          <span>{result ? "Terminado" : `Paso ${current + 1} de ${total}`}</span>
          {!result && time && <span>Borrador guardado en este dispositivo · {time}</span>}
        </p>
      </div>

      {notice && !result && (
        <div role="status" className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-brand-soft/60 px-3 py-2 text-sm">
          <span>Recuperamos tu borrador para que sigas donde te quedaste.</span>
          <button type="button" className="font-semibold text-brand hover:underline" onClick={restart}>
            Empezar de nuevo
          </button>
        </div>
      )}

      {result ? (
        <div className="space-y-3">{result}</div>
      ) : (
        <>
          <div>
            <h3 className="font-display text-lg font-semibold">{isSummary ? "Revisa antes de confirmar" : steps[current].title}</h3>
            {!isSummary && steps[current].hint && <p className="text-sm text-muted">{steps[current].hint}</p>}
          </div>
          <div className="space-y-4">{isSummary ? summary(data, (i) => setStep(i)) : steps[current].render(data, set)}</div>
        </>
      )}

      {error && (
        <p role="alert" className="flex items-start gap-1.5 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">
          <Icon name="alert" size={16} />
          {error}
        </p>
      )}
    </div>
  );

  const footer = result ? (
    <div className="flex justify-end">
      <Button variant="primary" onClick={onClose}>
        Cerrar
      </Button>
    </div>
  ) : (
    <div className="flex items-center justify-between gap-2">
      <Button onClick={back} disabled={busy} icon={current === 0 ? undefined : "back"}>
        {current === 0 ? "Cancelar" : "Atrás"}
      </Button>
      {isSummary ? (
        <Button variant="primary" loading={busy} onClick={confirm} icon="check">
          {confirmLabel}
        </Button>
      ) : (
        <Button variant="primary" loading={busy} onClick={next}>
          Siguiente
        </Button>
      )}
    </div>
  );

  if (inline) {
    return (
      <section className="rounded-xl border border-line bg-surface" aria-label={title}>
        <div className="border-b border-line px-4 py-3 sm:px-5">
          <h2 className="font-display text-lg font-semibold">{title}</h2>
          {subtitle && <p className="text-sm text-muted">{subtitle}</p>}
        </div>
        <div className="px-4 py-4 sm:px-5">{body}</div>
        <div className="border-t border-line px-4 py-3 sm:px-5">{footer}</div>
      </section>
    );
  }
  return (
    <Modal title={title} subtitle={subtitle} icon={icon} size="lg" onClose={onClose} footer={footer}>
      {body}
    </Modal>
  );
}

/** Enlaces con aspecto de botón (para no anidar un botón dentro de un enlace). */
export const BTN = "inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand hover:text-brand-strong";
export const BTN_PRIMARY = "inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-strong";

/** Fila «etiqueta: valor» del resumen, con enlace para volver al paso. */
export function SummaryRow({ label, value, onEdit }: { label: string; value: React.ReactNode; onEdit?: () => void }) {
  return (
    <div className="flex items-start justify-between gap-3 border-b border-line py-2 last:border-0">
      <div className="min-w-0">
        <p className="text-xs text-muted">{label}</p>
        <div className="text-sm font-medium">{value || "—"}</div>
      </div>
      {onEdit && (
        <button type="button" className="shrink-0 text-xs font-semibold text-brand hover:underline" onClick={onEdit}>
          Cambiar
        </button>
      )}
    </div>
  );
}
