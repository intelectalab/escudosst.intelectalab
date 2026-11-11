"use client";

// Buscador global de la cabecera (docs/07-pantallas-y-ux.md §2): módulos, trabajadores y acciones correctivas.
// Atajo «/» para enfocarlo; flechas para moverse entre resultados, Enter para abrir y Esc para cerrar.
// En el celular queda detrás de un botón con lupa.

import { useRouter } from "next/navigation";
import { useEffect, useId, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";

type Results = {
  modules: { code: string; name: string; solves: string | null }[];
  workers: { id: string; full_name: string; document_last4: string | null; position_name: string | null }[];
  corrective_actions: { id: string; code: string; title: string; status: string }[];
};

type Item = { key: string; group: string; href: string; icon: string; title: string; detail?: string };

function toItems(r: Results): Item[] {
  return [
    ...r.modules.map((m) => ({ key: `m-${m.code}`, group: "Módulos", href: `/m/${m.code.toLowerCase()}`, icon: "grid", title: m.name, detail: m.solves ?? undefined })),
    ...r.workers.map((w) => ({
      key: `w-${w.id}`, group: "Trabajadores", href: `/m/trb/${w.id}`, icon: "user", title: w.full_name,
      detail: [w.document_last4 ? `Doc. ****${w.document_last4}` : null, w.position_name].filter(Boolean).join(" · ") || undefined,
    })),
    ...r.corrective_actions.map((a) => ({ key: `a-${a.id}`, group: "Acciones correctivas", href: `/m/acr?t=corrective_actions&open=${a.id}`, icon: "check", title: `${a.code} · ${a.title}` })),
  ];
}

export function GlobalSearch() {
  const router = useRouter();
  const id = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const boxRef = useRef<HTMLDivElement>(null);
  const [q, setQ] = useState("");
  const [items, setItems] = useState<Item[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [active, setActive] = useState(0);
  const [open, setOpen] = useState(false);
  const [mobile, setMobile] = useState(false);

  // Búsqueda con espera de 250 ms tras la última tecla; se descarta la respuesta si el texto ya cambió.
  useEffect(() => {
    const term = q.trim();
    if (term.length < 2) {
      setItems(null);
      setLoading(false);
      return;
    }
    let cancelled = false;
    setLoading(true);
    const t = setTimeout(() => {
      api<{ data: Results }>(`/search?q=${encodeURIComponent(term)}`, { withCompany: true })
        .then((r) => {
          if (cancelled) return;
          setItems(toItems(r.data));
          setActive(0);
        })
        .catch(() => !cancelled && setItems([]))
        .finally(() => !cancelled && setLoading(false));
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(t);
    };
  }, [q]);

  // Atajo «/» (fuera de campos de texto) y cierre al hacer clic fuera.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "/" || e.ctrlKey || e.metaKey || e.altKey) return;
      const el = e.target as HTMLElement | null;
      if (el && (el.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(el.tagName))) return;
      e.preventDefault();
      if (window.innerWidth < 640) setMobile(true);
      setOpen(true);
      setTimeout(() => inputRef.current?.focus(), 0);
    };
    const onDown = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) {
        setOpen(false);
        setMobile(false);
      }
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("mousedown", onDown);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("mousedown", onDown);
    };
  }, []);

  const close = () => {
    setOpen(false);
    setMobile(false);
    setQ("");
    setItems(null);
  };

  const go = (item: Item) => {
    close();
    inputRef.current?.blur();
    router.push(item.href);
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Escape") {
      e.preventDefault();
      close();
      inputRef.current?.blur();
      return;
    }
    if (!items?.length) return;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setOpen(true);
      setActive((a) => (a + 1) % items.length);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive((a) => (a - 1 + items.length) % items.length);
    } else if (e.key === "Enter") {
      e.preventDefault();
      const item = items[active];
      if (item) go(item);
    }
  };

  const showPanel = open && q.trim().length >= 2;
  const listId = `${id}-results`;

  return (
    <div ref={boxRef} className="relative shrink-0 sm:min-w-0 sm:max-w-md sm:flex-1 sm:shrink">
      <button
        type="button"
        className="grid h-10 w-10 place-items-center rounded-lg border border-line hover:bg-bg sm:hidden"
        aria-label="Buscar trabajador, módulo o registro"
        title="Buscar"
        onClick={() => {
          setMobile(true);
          setOpen(true);
          setTimeout(() => inputRef.current?.focus(), 0);
        }}
      >
        <Icon name="search" size={19} />
      </button>

      <div
        className={
          mobile
            ? "fixed inset-x-0 top-0 z-50 flex h-16 items-center gap-2 border-b border-line bg-surface px-3"
            : "hidden sm:block"
        }
      >
        <span className="relative block w-full">
          <Icon name="search" size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
          <input
            ref={inputRef}
            type="search"
            role="combobox"
            aria-expanded={showPanel}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={showPanel && items?.length ? `${id}-${active}` : undefined}
            aria-label="Búsqueda global"
            autoComplete="off"
            placeholder="Buscar trabajador, módulo o registro"
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setOpen(true);
            }}
            onFocus={() => setOpen(true)}
            onKeyDown={onKeyDown}
            className="input h-10 w-full pl-9 pr-9"
          />
          <kbd className="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-line px-1.5 text-xs text-muted sm:inline">/</kbd>
        </span>
        {mobile && (
          <button type="button" className="shrink-0 rounded-lg px-2 py-2 text-sm font-semibold text-brand" onClick={close}>
            Cerrar
          </button>
        )}
      </div>

      {showPanel && (
        <div
          className={`${mobile ? "fixed inset-x-0 top-16" : "absolute left-0 right-0 top-full mt-1"} z-50 max-h-[70vh] overflow-y-auto rounded-b-xl border border-line bg-surface p-1.5 shadow-lg sm:rounded-xl`}
        >
          {loading && !items ? (
            <p className="px-3 py-4 text-sm text-muted">Buscando…</p>
          ) : items && items.length === 0 ? (
            <p className="px-3 py-4 text-sm text-muted">Sin resultados para «{q.trim()}». Prueba con un DNI, un apellido o el nombre de un módulo.</p>
          ) : (
            <ul id={listId} role="listbox" aria-label="Resultados de la búsqueda">
              {(items ?? []).map((it, i) => (
                <li key={it.key} role="presentation">
                  {(i === 0 || items![i - 1].group !== it.group) && (
                    <p className="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-muted" role="presentation">{it.group}</p>
                  )}
                  <a
                    id={`${id}-${i}`}
                    role="option"
                    aria-selected={i === active}
                    href={it.href}
                    onMouseEnter={() => setActive(i)}
                    onClick={(e) => {
                      e.preventDefault();
                      go(it);
                    }}
                    className={`flex items-start gap-2.5 rounded-lg px-3 py-2 text-sm ${i === active ? "bg-bg" : ""}`}
                  >
                    <Icon name={it.icon} size={16} className="mt-0.5 shrink-0 text-muted" />
                    <span className="min-w-0">
                      <span className="block truncate font-medium">{it.title}</span>
                      {it.detail && <span className="block truncate text-xs text-muted">{it.detail}</span>}
                    </span>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
