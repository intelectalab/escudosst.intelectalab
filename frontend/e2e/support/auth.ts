// Sesiones para las pruebas: tokens de Sanctum creados con «php artisan escudo:e2e-token» dentro del contenedor de la
// API (solo desarrollo), sin pasar por la verificación en dos pasos. Se guardan en e2e/.auth/tokens.json y se
// renuevan solos si tienen más de 20 minutos (la API cierra una sesión tras 30 minutos sin uso).

import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

export const API_CONTAINER = process.env.E2E_API_CONTAINER ?? "escudo_api";
export const DB_CONTAINER = process.env.E2E_DB_CONTAINER ?? "escudo_pg_main";
export const DEMO_COMPANY = "01900000-0000-7000-8000-000000000102";

export type UserKey = "jefe" | "aprobador" | "medico" | "esencial" | "plataforma";

export const USERS: Record<UserKey, { email: () => string; company?: string }> = {
  jefe: { email: () => "corporativo@escudo.test", company: DEMO_COMPANY },
  aprobador: { email: () => "revb.aprobador@escudo.test", company: DEMO_COMPANY },
  medico: { email: () => "rev-c-medico@escudo.test", company: DEMO_COMPANY },
  esencial: { email: () => "esencial@escudo.test" },
  plataforma: { email: platformEmail },
};

export type Session = { token: string; company_id: string | null; at: number };

const DIR = join(__dirname, "..", ".auth");
const FILE = join(DIR, "tokens.json");
const MAX_AGE_MS = 20 * 60_000;

let platform: string | null = null;
/** Usuario del equipo de la plataforma: E2E_PLATFORM_EMAIL, admin@escudo.test si existe, o el primero registrado. */
function platformEmail(): string {
  if (platform) return platform;
  if (process.env.E2E_PLATFORM_EMAIL) return (platform = process.env.E2E_PLATFORM_EMAIL);
  const out = psql("select email from users where platform_role is not null and deleted_at is null order by (email = 'admin@escudo.test') desc, created_at limit 1");
  if (!out) throw new Error("No hay usuarios del equipo de la plataforma en la base de desarrollo.");
  return (platform = out);
}

export function psql(sql: string): string {
  return execFileSync("docker", ["exec", DB_CONTAINER, "psql", "-U", "postgres", "-d", "escudo_main", "-tA", "-c", sql], { encoding: "utf8" }).trim();
}

export function createSession(key: UserKey): Session {
  const u = USERS[key];
  const args = ["exec", API_CONTAINER, "php", "artisan", "escudo:e2e-token", u.email()];
  if (u.company) args.push(`--company=${u.company}`);
  const out = execFileSync("docker", args, { encoding: "utf8" });
  const line = out.trim().split(/\r?\n/).filter((l) => l.startsWith("{")).pop();
  if (!line) throw new Error(`escudo:e2e-token no devolvió un token para ${key}: ${out}`);
  const parsed = JSON.parse(line) as { token: string; company_id: string | null };
  return { ...parsed, at: Date.now() };
}

function readAll(): Partial<Record<UserKey, Session>> {
  try {
    return JSON.parse(readFileSync(FILE, "utf8"));
  } catch {
    return {};
  }
}

export function writeAll(all: Partial<Record<UserKey, Session>>) {
  if (!existsSync(DIR)) mkdirSync(DIR, { recursive: true });
  writeFileSync(FILE, JSON.stringify(all, null, 2));
}

/** Sesión vigente del usuario (la crea o la renueva si hace falta). */
export function sessionFor(key: UserKey): Session {
  const all = readAll();
  const s = all[key];
  if (s && Date.now() - s.at < MAX_AGE_MS) return s;
  const fresh = createSession(key);
  writeAll({ ...readAll(), [key]: fresh });
  return fresh;
}
