"use client";

// Enlaces rotos (M-WEB §11): la página 404 del sitio avisa la ruta que no existe para el reporte del panel. Solo se manda la ruta
// (sin parámetros) y la dirección de la página que enlazó (la API guarda solo su dominio); nada que identifique a la persona.

import { useEffect } from "react";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export function NotFoundBeacon() {
  useEffect(() => {
    try {
      const path = window.location.pathname.slice(0, 300);
      // Solo el origen de la página que enlazó (sin ruta ni parámetros, que podrían traer datos de la persona).
      const referrer = document.referrer ? new URL(document.referrer).origin : null;
      fetch(`${API}/api/v1/public/not-found`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ path, referrer }),
        keepalive: true,
      }).catch(() => {});
    } catch {
      /* el aviso nunca rompe la página */
    }
  }, []);
  return null;
}
