export function Brand({ light = false }: { light?: boolean }) {
  return (
    <div className="flex items-center gap-2.5">
      <svg width="34" height="34" viewBox="0 0 32 32" aria-hidden="true" className="shrink-0">
        <path d="M16 2 4 6.5v8.2C4 22.4 9.2 28 16 30c6.8-2 12-7.6 12-15.3V6.5L16 2Z" fill="var(--brand)" />
        <path d="m11 16 3.5 3.5L21.5 12" fill="none" stroke="#fff" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <span className={`font-display text-xl font-semibold ${light ? "text-white" : "text-fg"}`}>EscudoSST</span>
    </div>
  );
}
