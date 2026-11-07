"use client";

// Elegir a qué página lleva un enlace: una página del sitio que existe (de la lista) u otra web (https://).
// Lo usan el menú de la cabecera y las redirecciones.

export type LinkPage = { url: string; label: string; group: string };

/** Destino de un enlace del menú: una página del sitio (de la lista) u otra web (https://). */
export function LinkPicker({ value, pages, invalid, onChange }: { value: string; pages: LinkPage[]; invalid: boolean; onChange: (url: string, label?: string) => void }) {
  const external = !value.startsWith("/");
  const base = value.split(/[?#]/)[0] || "/";
  const known = pages.some((p) => p.url === base);
  const groups = [...new Set(pages.map((p) => p.group))];
  return (
    <span className="flex flex-col gap-2 sm:flex-row">
      <select
        className="input text-sm sm:max-w-[16rem]"
        aria-invalid={invalid || undefined}
        aria-label="Página de destino"
        value={external ? "__external" : known ? base : "__missing"}
        onChange={(e) => {
          const v = e.target.value;
          if (v === "__external") onChange("https://");
          else if (v !== "__missing") onChange(v, pages.find((p) => p.url === v)?.label);
        }}
      >
        {!external && !known && <option value="__missing">{base} (no publicada)</option>}
        {groups.map((g) => (
          <optgroup key={g} label={g}>
            {pages
              .filter((p) => p.group === g)
              .map((p) => (
                <option key={p.url} value={p.url}>
                  {p.label}
                </option>
              ))}
          </optgroup>
        ))}
        <option value="__external">Otra web (https://…)</option>
      </select>
      {external ? (
        <input className="input font-mono text-sm" maxLength={250} placeholder="https://…" value={value} onChange={(e) => onChange(e.target.value.trim())} aria-label="Dirección de la otra web" />
      ) : (
        <span className="inline-flex items-center truncate rounded-lg bg-bg px-3 font-mono text-xs text-muted" title={value}>
          {value}
        </span>
      )}
    </span>
  );
}
