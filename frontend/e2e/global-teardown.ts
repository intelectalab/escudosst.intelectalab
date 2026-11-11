// Al terminar: borra todas las sesiones creadas para las pruebas (tokens «e2e-…»).

import { rmSync } from "node:fs";
import { join } from "node:path";
import { psql } from "./support/auth";

export default async function globalTeardown() {
  psql("delete from personal_access_tokens where name like 'e2e-%'");
  rmSync(join(__dirname, ".auth"), { recursive: true, force: true });
}
