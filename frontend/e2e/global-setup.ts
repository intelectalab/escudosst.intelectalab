// Antes de las pruebas: comprueba que el frontend responda, «calienta» las rutas (el servidor de desarrollo compila
// cada página la primera vez que se pide y eso puede tardar más que una prueba) y crea una sesión por cada usuario.
// E2E_NO_WARMUP=1 se salta el calentamiento (por ejemplo, si el servidor ya estaba en uso).

import { readdirSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";
import { USERS, createSession, writeAll, type Session, type UserKey } from "./support/auth";

const SAMPLE_ID = "00000000-0000-7000-8000-000000000000";

/** Rutas de las páginas de la aplicación (las dinámicas, con un valor de ejemplo). */
function routes(): string[] {
  const app = join(__dirname, "..", "app");
  const out = new Set<string>(["/m/ins", `/m/trb/${SAMPLE_ID}`, `/m/aul/${SAMPLE_ID}`, "/cursos/ejemplo"]);
  const walk = (dir: string) => {
    for (const name of readdirSync(dir)) {
      const full = join(dir, name);
      if (statSync(full).isDirectory()) walk(full);
      else if (name === "page.tsx") {
        const path = "/" + relative(app, dir).split(sep).filter((s) => !/^\(.*\)$/.test(s)).join("/");
        if (!path.includes("[")) out.add(path === "/" ? "/" : path.replace(/\/$/, ""));
      }
    }
  };
  walk(app);
  return [...out];
}

async function warm(base: string) {
  const list = routes();
  let i = 0;
  const worker = async () => {
    while (i < list.length) {
      const path = list[i++];
      await fetch(`${base}${path}`, { signal: AbortSignal.timeout(180_000) }).catch(() => undefined);
    }
  };
  await Promise.all([worker(), worker()]);
}

export default async function globalSetup() {
  const base = process.env.E2E_BASE_URL ?? "http://localhost:3000";
  const res = await fetch(`${base}/login`).catch(() => null);
  if (!res || !res.ok) throw new Error(`El frontend no responde en ${base}. Inicia «npm run dev» antes de las pruebas.`);
  if (!process.env.E2E_NO_WARMUP) await warm(base);

  const all: Partial<Record<UserKey, Session>> = {};
  for (const key of Object.keys(USERS) as UserKey[]) all[key] = createSession(key);
  writeAll(all);
}
