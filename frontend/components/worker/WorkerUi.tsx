"use client";

// Piezas de la app del trabajador (docs/07 §5.2): tarjetas grandes y planas, botones de 48 px, texto en tú.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { SignaturePad } from "@/components/addons/SignaturePad";
import { Modal } from "@/components/ui/Modal";
import { Icon } from "@/components/ui/Icon";
import { errorText, openProtectedFile, safeHtml, workerApi, workerDevice, type SignItem, type SignKind } from "@/lib/workerApp";
import { signPlace } from "@/lib/geo";

export type Tone = "ok" | "bad" | "amber" | "brand" | "na";

const TONE: Record<Tone, string> = {
  ok: "bg-ok-soft text-ok",
  bad: "bg-bad-soft text-bad",
  amber: "bg-amber-soft text-amber-ink",
  brand: "bg-brand-soft text-brand-strong",
  na: "bg-na-soft text-na",
};

/** Tarjeta grande del inicio: ícono, título, una línea de estado y toda la tarjeta es el botón. */
export function BigCard({ href, icon, title, line, tone = "brand", badge }: { href: string; icon: string; title: string; line: string; tone?: Tone; badge?: string | number | null }) {
  return (
    <Link href={href} className="flex min-h-[4.5rem] items-center gap-4 rounded-2xl border border-line bg-surface p-4 hover:border-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
      <span className={`grid h-12 w-12 shrink-0 place-items-center rounded-xl ${TONE[tone]}`} aria-hidden="true"><Icon name={icon} size={24} /></span>
      <span className="min-w-0 flex-1">
        <b className="block font-display text-base leading-tight">{title}</b>
        <span className="block text-sm text-muted">{line}</span>
      </span>
      {badge ? <span className="grid h-7 min-w-7 place-items-center rounded-full bg-bad px-2 text-xs font-bold text-white">{badge}</span> : null}
      <Icon name="chevron" size={18} className="shrink-0 text-muted" />
    </Link>
  );
}

export function Pill({ tone, children }: { tone: Tone; children: React.ReactNode }) {
  return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${TONE[tone]}`}>{children}</span>;
}

export function Title({ children, sub }: { children: React.ReactNode; sub?: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <h1 className="font-display text-xl font-bold">{children}</h1>
      {sub && <p className="text-sm text-muted">{sub}</p>}
    </div>
  );
}

export function Panel({ title, children }: { title?: string; children: React.ReactNode }) {
  return (
    <section className="space-y-2 rounded-2xl border border-line bg-surface p-4">
      {title && <h2 className="font-display text-base font-semibold">{title}</h2>}
      {children}
    </section>
  );
}

export function Empty({ text }: { text: string }) {
  return <p className="rounded-2xl border border-line bg-surface p-4 text-sm text-muted">{text}</p>;
}

export function Loading() {
  return <div className="h-40 animate-pulse rounded-2xl border border-line bg-surface" aria-label="Cargando" />;
}

export function Alert({ tone = "bad", children }: { tone?: Tone; children: React.ReactNode }) {
  return <p role={tone === "bad" ? "alert" : "status"} className={`rounded-xl px-4 py-3 text-sm ${TONE[tone]}`}>{children}</p>;
}

/** Botón de la app: 48 px de alto, ancho completo en el celular. */
export function BigButton({ children, variant = "primary", loading, className = "", ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "secondary" | "danger"; loading?: boolean }) {
  const v = variant === "primary" ? "bg-brand text-white hover:bg-brand-strong" : variant === "danger" ? "bg-bad text-white" : "border border-line bg-surface hover:border-brand";
  return (
    <button {...props} disabled={props.disabled || loading}
      className={`inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl px-5 text-base font-semibold disabled:opacity-60 ${v} ${className}`}>
      {loading ? "Un momento…" : children}
    </button>
  );
}

/** Campo de PIN: solo números, teclado numérico, se oculta. */
export function PinField({ id, label, value, onChange, error, autoFocus }: { id: string; label: string; value: string; onChange: (v: string) => void; error?: string | null; autoFocus?: boolean }) {
  return (
    <label htmlFor={id} className="block space-y-1">
      <span className="text-sm font-semibold">{label}</span>
      <input id={id} type="password" inputMode="numeric" autoComplete="off" pattern="[0-9]*" maxLength={6} value={value} autoFocus={autoFocus}
        onChange={(e) => onChange(e.target.value.replace(/\D/g, "").slice(0, 6))}
        aria-invalid={!!error} aria-describedby={error ? `${id}-err` : undefined}
        className="h-12 w-full rounded-xl border border-line bg-surface px-4 text-center font-mono text-2xl tracking-[0.5em] focus:border-brand focus:outline-none" />
      {error && <span id={`${id}-err`} className="block text-sm text-bad">{error}</span>}
    </label>
  );
}

/**
 * SCR-FIR-01: lee lo que firmas, marca «He leído» y firma con tu trazo o tu PIN. Si el tipo de registro exige un método
 * mayor que el trazo (RN-FIR-08), el PIN es obligatorio; el servidor lo vuelve a verificar.
 */
export function SignSheet({ kind, id, onClose, onSigned }: { kind: SignKind; id: string; onClose: () => void; onSigned: () => void }) {
  const [item, setItem] = useState<SignItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pinError, setPinError] = useState<string | null>(null);
  const [pin, setPin] = useState("");
  const [image, setImage] = useState<string | null>(null);
  const [read, setRead] = useState(false);
  const [busy, setBusy] = useState(false);
  const onImage = useCallback((d: string | null) => setImage(d), []);

  useEffect(() => {
    workerApi<SignItem>(`/items/${kind}/${id}`).then(setItem).catch((e) => setError(errorText(e)));
  }, [kind, id]);

  async function sign() {
    setBusy(true);
    setError(null);
    setPinError(null);
    try {
      await workerApi("/sign", { method: "POST", body: { kind, id, pin: pin || undefined, image, read_confirmed: read, device_id: workerDevice.get()?.device_id, ...(await signPlace()) } });
      onSigned();
    } catch (e) {
      const msg = errorText(e);
      if (msg.toLowerCase().includes("pin")) setPinError(msg);
      else setError(msg);
      setPin("");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal title={item?.title ?? "Firmar"} subtitle={item?.subtitle ?? undefined} onClose={onClose}
      footer={item && !item.signed ? <BigButton onClick={sign} loading={busy} disabled={!read || !(pin.length >= 4 || (item.stroke_allowed && !!image))}>Firmar</BigButton> : <BigButton variant="secondary" onClick={onClose}>Cerrar</BigButton>}>
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {!item && !error && <Loading />}
        {item?.signed && <Alert tone="ok">Ya firmaste este pendiente. ¡Gracias!</Alert>}
        {item && !item.signed && (
          <>
            {item.content_html && (
              <div className="max-h-64 overflow-y-auto rounded-xl border border-line bg-bg p-3 text-sm" dangerouslySetInnerHTML={{ __html: safeHtml(item.content_html) }} />
            )}
            {item.file_id && (
              <BigButton variant="secondary" onClick={() => openProtectedFile(`/files/${item.file_id}`).catch((e) => setError(errorText(e)))}>
                <Icon name="file" size={18} /> Ver el documento
              </BigButton>
            )}
            <p className="rounded-xl bg-brand-soft px-4 py-3 text-sm">{item.declaration}</p>
            <div className="space-y-1">
              <p className="text-sm font-semibold">{item.stroke_allowed ? "Firma con tu dedo" : "Firma con tu dedo (opcional)"}</p>
              <SignaturePad onChange={onImage} height={item.stroke_allowed ? 200 : 150} />
            </div>
            <PinField id="sign-pin" label={item.stroke_allowed ? "Tu PIN (si no dibujas tu firma)" : "Tu PIN"} value={pin} onChange={setPin} error={pinError} />
            <label className="flex min-h-12 items-start gap-3 rounded-xl border border-line px-4 py-3 text-sm">
              <input type="checkbox" className="mt-0.5 h-5 w-5 shrink-0" checked={read} onChange={(e) => setRead(e.target.checked)} />
              <span>He leído lo que firmo.</span>
            </label>
            <p className="text-xs text-muted">Se guardan la fecha, la hora y tu equipo. La firma no se puede cambiar.</p>
          </>
        )}
      </div>
    </Modal>
  );
}
