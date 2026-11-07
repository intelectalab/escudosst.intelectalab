"use client";

// Enlace de partner (?ref=CÓDIGO, RN-WEB-48): si el código existe y está activo, se guarda 30 días en este navegador
// para el registro de prueba gratis. Vale el primer código válido; uno nuevo no reemplaza al anterior.

import { useEffect } from "react";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const KEY = "escudo.ref";
const DAYS = 30;

export function storedRef(): string | null {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    const v = JSON.parse(raw) as { code: string; at: number };
    if (Date.now() - v.at > DAYS * 86_400_000) {
      localStorage.removeItem(KEY);
      return null;
    }
    return v.code;
  } catch {
    return null;
  }
}

export function RefCapture() {
  useEffect(() => {
    const code = new URLSearchParams(window.location.search).get("ref")?.trim().toUpperCase();
    if (!code || !/^[A-Z0-9-]{4,32}$/.test(code) || storedRef()) return;
    fetch(`${API}/api/v1/public/partners/ref/${encodeURIComponent(code)}`, { headers: { Accept: "application/json" } })
      .then((r) => (r.ok ? r.json() : null))
      .then((j) => {
        if (j?.data?.valid) localStorage.setItem(KEY, JSON.stringify({ code, at: Date.now() }));
      })
      .catch(() => {});
  }, []);
  return null;
}
