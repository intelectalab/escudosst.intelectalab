"use client";

/** Campo grande para el código de 6 dígitos (teclado numérico en el celular y autocompletado del sistema). */
export function CodeInput({
  value,
  onChange,
  autoFocus = true,
}: {
  value: string;
  onChange: (v: string) => void;
  autoFocus?: boolean;
}) {
  return (
    <input
      inputMode="numeric"
      autoComplete="one-time-code"
      pattern="[0-9]*"
      maxLength={7}
      autoFocus={autoFocus}
      value={value}
      onChange={(e) => onChange(e.target.value.replace(/[^\d ]/g, ""))}
      placeholder="000 000"
      aria-label="Código de 6 dígitos"
      className="input text-center font-display text-2xl tracking-[0.4em]"
    />
  );
}
