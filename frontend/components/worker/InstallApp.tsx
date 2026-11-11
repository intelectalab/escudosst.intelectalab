"use client";

// Botón «Instalar la app»: pone el ícono de EscudoSST en la pantalla de inicio del celular sin pasar por el menú del
// navegador. En Android/Chrome usa el aviso de instalación del navegador; en iPhone (Safari no lo ofrece) explica los
// dos toques necesarios. No se muestra si la app ya está instalada o si el trabajador lo cerró.

import { useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { BigButton } from "@/components/worker/WorkerUi";

type InstallPrompt = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: "accepted" | "dismissed" }> };

// El navegador avisa una sola vez y puede hacerlo antes de que se vea esta tarjeta (por ejemplo, en la activación).
let saved: InstallPrompt | null = null;
const listeners = new Set<() => void>();
if (typeof window !== "undefined") {
  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault();
    saved = e as InstallPrompt;
    listeners.forEach((f) => f());
  });
  window.addEventListener("appinstalled", () => {
    saved = null;
    listeners.forEach((f) => f());
  });
}

const HIDE_KEY = "escudo.install.hidden";

function installed(): boolean {
  return window.matchMedia("(display-mode: standalone)").matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

export function InstallAppCard() {
  const [mode, setMode] = useState<"none" | "prompt" | "ios">("none");
  const [hidden, setHidden] = useState(true);

  useEffect(() => {
    const update = () => setMode(installed() ? "none" : saved ? "prompt" : /iphone|ipad|ipod/i.test(navigator.userAgent) ? "ios" : "none");
    update();
    listeners.add(update);
    try { setHidden(localStorage.getItem(HIDE_KEY) === "1"); } catch { setHidden(false); }
    return () => { listeners.delete(update); };
  }, []);

  if (hidden || mode === "none") return null;

  const hide = () => {
    try { localStorage.setItem(HIDE_KEY, "1"); } catch { /* sin almacenamiento: solo se oculta ahora */ }
    setHidden(true);
  };
  const install = async () => {
    if (!saved) return;
    await saved.prompt();
    const { outcome } = await saved.userChoice;
    saved = null;
    if (outcome === "accepted") setHidden(true);
    else setMode("none");
  };

  return (
    <section aria-label="Instalar la app" className="rounded-2xl border border-line bg-surface p-4">
      <div className="flex items-start gap-3">
        <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong" aria-hidden="true"><Icon name="download" size={24} /></span>
        <div className="min-w-0 flex-1">
          <p className="font-semibold">Instala la app en tu celular</p>
          {mode === "prompt" ? (
            <p className="text-sm text-muted">Tendrás el ícono de EscudoSST en tu pantalla de inicio y se abrirá como cualquier app.</p>
          ) : (
            <p className="text-sm text-muted">En Safari toca <b>Compartir</b> (el cuadrado con la flecha hacia arriba) y luego <b>Agregar a inicio</b>.</p>
          )}
        </div>
      </div>
      <div className="mt-3 flex gap-2">
        {mode === "prompt" && (
          <BigButton type="button" onClick={install} className="flex-1">Instalar la app</BigButton>
        )}
        <BigButton type="button" variant="secondary" onClick={hide} className={mode === "prompt" ? "!w-auto" : "flex-1"}>
          {mode === "prompt" ? "Ahora no" : "Entendido"}
        </BigButton>
      </div>
    </section>
  );
}
