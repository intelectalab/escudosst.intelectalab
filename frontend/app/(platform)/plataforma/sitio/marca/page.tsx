"use client";

// Sitio web › Logo y menú (SCR-WEB-21): logo (fondo claro), logo para fondo oscuro, favicon y el menú de la cabecera.
// Los archivos se aplican al subirlos; el menú se guarda con su botón. El sitio los muestra en menos de un minuto.

import { useCallback, useEffect, useRef, useState } from "react";
import { SitePanelTabs } from "@/components/site/SitePanelTabs";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import { assetUrl } from "@/lib/site";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Kind = "logo" | "logo_dark" | "favicon";
type Item = { label: string; url: string; visible: boolean };

const ASSETS: { kind: Kind; column: string; title: string; text: string; accept: string; dark?: boolean; square?: boolean }[] = [
  { kind: "logo", column: "logo_file_id", title: "Logo", text: "Se ve en la cabecera del sitio. PNG, JPG, WEBP o AVIF, menos de 1 MB. Ideal: fondo transparente, unos 400 × 120 px.", accept: ".png,.jpg,.jpeg,.webp,.avif" },
  { kind: "logo_dark", column: "logo_dark_file_id", title: "Logo para fondo oscuro", text: "Se ve en el pie de página (azul oscuro). Si no lo subes, se usa el de EscudoSST en blanco.", accept: ".png,.jpg,.jpeg,.webp,.avif", dark: true },
  { kind: "favicon", column: "favicon_file_id", title: "Favicon", text: "El ícono de la pestaña del navegador. PNG o ICO cuadrado, al menos 32 × 32 px (ideal 512 × 512).", accept: ".png,.ico", square: true },
];

export default function MarcaPage() {
  const [ids, setIds] = useState<Record<string, string | null> | null>(null);
  const [items, setItems] = useState<Item[] | null>(null);
  const [defaults, setDefaults] = useState<Item[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Record<string, string | null> }>("/internal/site/settings").then((r) =>
      setIds({ logo_file_id: r.data.logo_file_id, logo_dark_file_id: r.data.logo_dark_file_id, favicon_file_id: r.data.favicon_file_id }),
    );
    api<{ data: Item[]; defaults: Item[] }>("/internal/site/menu").then((r) => {
      setItems(r.data);
      setDefaults(r.defaults);
    });
  }, []);
  useEffect(load, [load]);

  async function upload(kind: Kind, file: File) {
    setBusy(kind);
    const fd = new FormData();
    fd.append("file", file);
    try {
      const res = await fetch(`${API_URL}/api/v1/internal/site/assets/${kind}`, {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${session.getToken()}` },
        body: fd,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json.errors?.file?.[0] ?? json.detail ?? "No se pudo subir el archivo.");
      setToast({ text: "Listo. El sitio lo mostrará en menos de un minuto.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof Error ? e.message : "No se pudo subir el archivo.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  async function remove(kind: Kind) {
    setBusy(kind);
    try {
      await api(`/internal/site/assets/${kind}`, { method: "DELETE" });
      setToast({ text: "Se volvió al de EscudoSST por defecto.", tone: "ok" });
      load();
    } finally {
      setBusy(null);
    }
  }

  const setItem = (i: number, patch: Partial<Item>) => {
    setItems((list) => list!.map((it, k) => (k === i ? { ...it, ...patch } : it)));
    setDirty(true);
  };
  const move = (i: number, d: -1 | 1) => {
    setItems((list) => {
      const l = [...list!];
      [l[i], l[i + d]] = [l[i + d], l[i]];
      return l;
    });
    setDirty(true);
  };

  async function saveMenu() {
    setBusy("menu");
    setErrors({});
    try {
      await api("/internal/site/menu", { method: "PUT", body: { items } });
      setDirty(false);
      setToast({ text: "Menú guardado. El sitio lo mostrará en menos de un minuto.", tone: "ok" });
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(e.errors);
      setToast({ text: e instanceof ApiError ? e.message : "No se pudo guardar.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Logo y menú"]]}
        icon="globe"
        title="Sitio web"
        description="Cómo se ve la marca en el sitio: logo, ícono de la pestaña y los enlaces del menú superior."
      />
      <SitePanelTabs />

      <section className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {ASSETS.map((a) => (
          <AssetCard key={a.kind} a={a} id={ids?.[a.column] ?? null} busy={busy === a.kind} onPick={(f) => upload(a.kind, f)} onRemove={() => remove(a.kind)} />
        ))}
      </section>

      <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
              <Icon name="grid" size={19} />
            </span>
            <div>
              <h2 className="font-display text-lg font-semibold">Menú de la cabecera</h2>
              <p className="text-sm text-muted">
                «Productos» siempre va primero. Aquí cambias el resto: el texto, a dónde lleva, el orden y si se muestra. El «Blog» se agrega solo si pusiste su enlace.
              </p>
            </div>
          </div>
          <div className="flex gap-2">
            <Button
              variant="ghost"
              onClick={() => {
                setItems(defaults);
                setDirty(true);
              }}
            >
              Restaurar
            </Button>
            <Button variant="primary" icon="check" loading={busy === "menu"} disabled={!dirty} onClick={saveMenu}>
              Guardar menú
            </Button>
          </div>
        </div>

        {/* Vista previa */}
        <div className="mt-5 flex flex-wrap items-center gap-1 rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-semibold text-[#0D3B6E]">
          <span className="rounded-md px-2.5 py-1">Productos ▾</span>
          {items
            ?.filter((i) => i.visible)
            .map((i, k) => (
              <span key={k} className="rounded-md px-2.5 py-1">
                {i.label || "…"}
              </span>
            ))}
          <span className="ml-auto text-xs font-normal text-muted">Vista previa</span>
        </div>

        <ul className="mt-4 space-y-2">
          {items?.map((it, i) => (
            <li key={i} className={`grid grid-cols-1 items-start gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_1.4fr_auto] ${it.visible ? "border-line" : "border-dashed border-line opacity-60"}`}>
              <label className="block">
                <span className="sr-only">Texto</span>
                <input className="input" maxLength={30} placeholder="Texto" value={it.label} aria-invalid={!!errors[`items.${i}.label`] || undefined} onChange={(e) => setItem(i, { label: e.target.value })} />
                {errors[`items.${i}.label`] && <span className="mt-1 block text-xs text-bad">● {errors[`items.${i}.label`][0]}</span>}
              </label>
              <label className="block">
                <span className="sr-only">Destino</span>
                <input className="input font-mono text-sm" maxLength={250} placeholder="/planes o https://…" value={it.url} aria-invalid={!!errors[`items.${i}.url`] || undefined} onChange={(e) => setItem(i, { url: e.target.value.trim() })} />
                {errors[`items.${i}.url`] && <span className="mt-1 block text-xs text-bad">● {errors[`items.${i}.url`][0]}</span>}
              </label>
              <span className="flex items-center gap-1">
                <button type="button" onClick={() => setItem(i, { visible: !it.visible })} className="grid h-10 w-10 place-items-center rounded-lg text-muted hover:bg-bg" aria-label={it.visible ? "Ocultar" : "Mostrar"} title={it.visible ? "Ocultar" : "Mostrar"}>
                  <Icon name="eye" size={16} />
                </button>
                <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="grid h-10 w-10 place-items-center rounded-lg text-muted hover:bg-bg disabled:opacity-30" aria-label="Subir">
                  <Icon name="chevron" size={15} className="-rotate-90" />
                </button>
                <button type="button" disabled={i === items.length - 1} onClick={() => move(i, 1)} className="grid h-10 w-10 place-items-center rounded-lg text-muted hover:bg-bg disabled:opacity-30" aria-label="Bajar">
                  <Icon name="chevron" size={15} className="rotate-90" />
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setItems((l) => l!.filter((_, k) => k !== i));
                    setDirty(true);
                  }}
                  className="grid h-10 w-10 place-items-center rounded-lg text-bad hover:bg-bad-soft"
                  aria-label="Quitar"
                >
                  <Icon name="close" size={15} />
                </button>
              </span>
            </li>
          ))}
        </ul>
        {items && items.length < 8 && (
          <Button
            className="mt-3"
            variant="secondary"
            icon="plus"
            onClick={() => {
              setItems((l) => [...(l ?? []), { label: "", url: "/", visible: true }]);
              setDirty(true);
            }}
          >
            Agregar enlace
          </Button>
        )}
        <p className="mt-3 text-xs text-muted">Páginas del sitio: /producto, /como-funciona, /planes, /complementos, /preguntas-frecuentes, /demo, /prueba-gratis. También puedes enlazar a otra web con https://.</p>
      </section>

      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function AssetCard({ a, id, busy, onPick, onRemove }: { a: (typeof ASSETS)[number]; id: string | null; busy: boolean; onPick: (f: File) => void; onRemove: () => void }) {
  const input = useRef<HTMLInputElement>(null);
  const url = assetUrl(id);
  return (
    <div className="flex flex-col rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <h2 className="font-display font-semibold">{a.title}</h2>
      <p className="mt-1 text-xs text-muted">{a.text}</p>
      <div className={`mt-4 grid h-28 place-items-center rounded-xl border border-dashed border-line ${a.dark ? "bg-[#0D3B6E]" : "bg-white"}`}>
        {url ? (
          // eslint-disable-next-line @next/next/no-img-element -- vista previa del archivo subido
          <img src={url} alt={a.title} className={a.square ? "h-14 w-14 object-contain" : "max-h-16 max-w-[85%] object-contain"} />
        ) : (
          <span className={`text-xs ${a.dark ? "text-white/70" : "text-muted"}`}>Se usa el de EscudoSST</span>
        )}
      </div>
      <input
        ref={input}
        type="file"
        accept={a.accept}
        className="hidden"
        onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) onPick(f);
          e.target.value = "";
        }}
      />
      <div className="mt-4 flex flex-wrap gap-2">
        <Button variant="secondary" icon="plus" loading={busy} onClick={() => input.current?.click()}>
          {url ? "Cambiar" : "Subir"}
        </Button>
        {url && (
          <Button variant="ghost" onClick={onRemove} disabled={busy}>
            Quitar
          </Button>
        )}
      </div>
    </div>
  );
}
