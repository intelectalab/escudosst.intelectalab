"use client";

// Recuadro para firmar con el dedo, el lápiz o el mouse. Entrega un PNG (data URL) o null si está en blanco.
// Funciona con puntero (mouse y pantalla táctil). El trazo se adapta a la densidad de la pantalla.

import { useCallback, useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";

export function SignaturePad({ onChange, error }: { onChange: (png: string | null) => void; error?: string }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const last = useRef<{ x: number; y: number } | null>(null);
  const length = useRef(0);   // largo total del trazo en px
  const [empty, setEmpty] = useState(true);

  const setup = useCallback(() => {
    const c = canvas.current;
    if (!c) return;
    const ratio = window.devicePixelRatio || 1;
    const { width, height } = c.getBoundingClientRect();
    c.width = Math.round(width * ratio);
    c.height = Math.round(height * ratio);
    const ctx = c.getContext("2d")!;
    ctx.scale(ratio, ratio);
    ctx.fillStyle = "#fff";
    ctx.fillRect(0, 0, width, height);
    ctx.lineWidth = 2.4;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.strokeStyle = "#0D3B6E";
  }, []);

  useEffect(setup, [setup]);

  const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  };

  function down(e: React.PointerEvent<HTMLCanvasElement>) {
    e.currentTarget.setPointerCapture(e.pointerId);
    drawing.current = true;
    last.current = point(e);
  }

  function move(e: React.PointerEvent<HTMLCanvasElement>) {
    if (!drawing.current || !last.current) return;
    const ctx = canvas.current!.getContext("2d")!;
    const p = point(e);
    ctx.beginPath();
    ctx.moveTo(last.current.x, last.current.y);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    length.current += Math.hypot(p.x - last.current.x, p.y - last.current.y);
    last.current = p;
  }

  function up() {
    if (!drawing.current) return;
    drawing.current = false;
    last.current = null;
    // Un toque o un punto suelto no cuenta como firma.
    if (length.current > 40) {
      setEmpty(false);
      onChange(canvas.current!.toDataURL("image/png"));
    }
  }

  function clear() {
    length.current = 0;
    setEmpty(true);
    setup();
    onChange(null);
  }

  return (
    <div>
      <div className={`relative overflow-hidden rounded-xl border-2 border-dashed bg-white ${error ? "border-[var(--site-red)]" : "border-[var(--site-border)]"}`}>
        <canvas
          ref={canvas}
          className="block h-40 w-full cursor-crosshair touch-none"
          aria-label="Recuadro para firmar"
          onPointerDown={down}
          onPointerMove={move}
          onPointerUp={up}
          onPointerLeave={up}
          onPointerCancel={up}
        />
        {empty && <span className="pointer-events-none absolute inset-0 grid place-items-center text-sm text-[var(--site-text-2)]">Firma aquí con el dedo o el mouse</span>}
        <span className="pointer-events-none absolute bottom-8 left-6 right-6 border-b border-[var(--site-border)]" aria-hidden="true" />
      </div>
      <div className="mt-1.5 flex items-center justify-between">
        {error ? <span className="text-xs font-semibold text-[var(--site-red)]">● {error}</span> : <span className="text-xs text-[var(--site-text-2)]">Tu firma queda guardada con la constancia.</span>}
        <button type="button" onClick={clear} className="inline-flex items-center gap-1 text-xs font-bold text-[var(--site-action)] hover:underline">
          <Icon name="refresh" size={12} /> Borrar
        </button>
      </div>
    </div>
  );
}
