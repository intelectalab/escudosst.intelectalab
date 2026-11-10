"use client";

// Firma manuscrita digitalizada: dibujarla con el dedo o el mouse, o subir una foto de la firma en papel.
// La imagen se ajusta a 600 px de ancho y se guarda en PNG (dibujada) o JPG (foto) para que pese poco.

import { useCallback, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";

export function SignaturePad({ onChange, height = 180 }: { onChange: (dataUrl: string | null, kind: "drawn" | "uploaded") => void; height?: number }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const [empty, setEmpty] = useState(true);

  const reset = useCallback(() => {
    const c = canvas.current!;
    const ratio = window.devicePixelRatio || 1;
    c.width = c.clientWidth * ratio;
    c.height = height * ratio;
    const ctx = c.getContext("2d")!;
    ctx.scale(ratio, ratio);
    ctx.fillStyle = "#fff";
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.lineWidth = 2.4;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.strokeStyle = "#0b2440";
    setEmpty(true);
    onChange(null, "drawn");
  }, [height, onChange]);
  useEffect(() => { reset(); }, [reset]);

  const point = (e: React.PointerEvent) => {
    const r = canvas.current!.getBoundingClientRect();
    return [e.clientX - r.left, e.clientY - r.top] as const;
  };
  const export_ = (kind: "drawn" | "uploaded") => {
    const c = canvas.current!;
    const out = document.createElement("canvas");
    const scale = Math.min(1, 600 / c.width);
    out.width = Math.round(c.width * scale);
    out.height = Math.round(c.height * scale);
    out.getContext("2d")!.drawImage(c, 0, 0, out.width, out.height);
    onChange(kind === "drawn" ? out.toDataURL("image/png") : out.toDataURL("image/jpeg", 0.85), kind);
  };

  function upload(file: File) {
    const img = new Image();
    img.onload = () => {
      reset();
      const c = canvas.current!;
      const ctx = c.getContext("2d")!;
      const w = c.clientWidth;
      const k = Math.min(w / img.width, height / img.height);
      ctx.drawImage(img, (w - img.width * k) / 2, (height - img.height * k) / 2, img.width * k, img.height * k);
      setEmpty(false);
      export_("uploaded");
      URL.revokeObjectURL(img.src);
    };
    img.src = URL.createObjectURL(file);
  }

  return (
    <div className="space-y-2">
      <canvas
        ref={canvas}
        className="w-full touch-none rounded-xl border-2 border-dashed border-line bg-white"
        style={{ height }}
        aria-label="Espacio para dibujar la firma"
        onPointerDown={(e) => { drawing.current = true; const [x, y] = point(e); const ctx = canvas.current!.getContext("2d")!; ctx.beginPath(); ctx.moveTo(x, y); canvas.current!.setPointerCapture(e.pointerId); }}
        onPointerMove={(e) => { if (!drawing.current) return; const [x, y] = point(e); const ctx = canvas.current!.getContext("2d")!; ctx.lineTo(x, y); ctx.stroke(); setEmpty(false); }}
        onPointerUp={() => { if (drawing.current) { drawing.current = false; export_("drawn"); } }}
      />
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted">{empty ? "Firma con el dedo o el mouse, o sube una foto de tu firma en papel blanco." : "Así se verá tu firma."}</span>
        <Button size="sm" variant="ghost" onClick={reset}>Borrar</Button>
        <label className="cursor-pointer rounded-lg border border-line px-3 py-1.5 font-semibold text-brand hover:border-brand">
          Subir foto
          <input type="file" accept="image/png,image/jpeg" className="sr-only" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
        </label>
      </div>
    </div>
  );
}

/** «Mi firma»: guardar la firma para que se pegue sola en los documentos que firmo o valido. */
export function MySignature() {
  const [saved, setSaved] = useState<string | null | undefined>(undefined);
  const [draft, setDraft] = useState<{ image: string; kind: "drawn" | "uploaded" } | null>(null);
  const [editing, setEditing] = useState(false);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const load = useCallback(() => {
    api<{ data: { image: string } | null }>("/me/signature").then((r) => setSaved(r.data?.image ?? null)).catch(() => setSaved(null));
  }, []);
  useEffect(load, [load]);
  const onChange = useCallback((image: string | null, kind: "drawn" | "uploaded") => setDraft(image ? { image, kind } : null), []);
  const save = () =>
    draft && api("/me/signature", { method: "PUT", body: draft })
      .then(() => { setMsg({ text: "Firma guardada. Se pegará sola en los documentos que firmes o valides.", ok: true }); setEditing(false); load(); })
      .catch((e: ApiError) => setMsg({ text: Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title, ok: false }));
  const remove = () => api("/me/signature", { method: "DELETE" }).then(() => { setSaved(null); setMsg(null); });

  if (saved === undefined) return null;
  return (
    <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <div>
        <h2 className="font-display text-lg font-semibold">Mi firma</h2>
        <p className="text-sm text-muted">Se pega sola en los certificados que validas y en los documentos que firmas en EscudoSST (junto con la evidencia: fecha, hora y quién firmó).</p>
      </div>
      {saved && !editing ? (
        <div className="flex flex-wrap items-center gap-3">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={saved} alt="Tu firma guardada" className="h-20 max-w-full rounded-lg border border-line bg-white object-contain p-1" />
          <Button size="sm" variant="secondary" onClick={() => setEditing(true)}>Cambiar</Button>
          <Button size="sm" variant="danger-ghost" onClick={remove}>Quitar</Button>
        </div>
      ) : (
        <>
          <SignaturePad onChange={onChange} />
          <div className="flex gap-2">
            <Button variant="primary" onClick={save} disabled={!draft}>Guardar firma</Button>
            {saved && <Button variant="ghost" onClick={() => setEditing(false)}>Cancelar</Button>}
          </div>
        </>
      )}
      {msg && <p className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </section>
  );
}
