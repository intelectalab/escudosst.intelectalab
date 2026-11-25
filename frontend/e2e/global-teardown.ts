// Al terminar: borra todas las sesiones creadas para las pruebas (tokens «e2e-…»).

import { rmSync } from "node:fs";
import { join } from "node:path";
import { psql } from "./support/auth";

// E2E_KEEP_SESSIONS=1 las deja (otra corrida en paralelo las sigue usando; vencen solas en 2 horas).
export default async function globalTeardown() {
  if (process.env.E2E_KEEP_SESSIONS) return;
  psql("delete from personal_access_tokens where name like 'e2e-%'");
  rmSync(join(__dirname, ".auth"), { recursive: true, force: true });
}
