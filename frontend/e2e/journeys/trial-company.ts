// Empresa nueva por «Prueba gratis» para los recorridos E2E-01 y E2E-03, sin enviar correos reales.
// En desarrollo la API manda los correos por el SMTP configurado; para no escribirle a direcciones de prueba, el alta se
// procesa dentro del contenedor de la API con el correo simulado (Mail::fake) por el mismo kernel HTTP (mismas reglas y
// validaciones que POST /public/trial) y se toma de ahí el enlace de la invitación. Al final, la empresa, su cuenta y sus
// personas se dan de baja (borrado lógico, como en backend/tests/Feature/QA/CompanyJourneyTest.php).

import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { API_CONTAINER, psql } from "../support/auth";

/** RUC 20xxxxxxxxx con dígito verificador correcto. */
export function validRuc(): string {
  const base = "20" + String(Math.floor(Math.random() * 1e8)).padStart(8, "0");
  const w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const sum = w.reduce((s, x, i) => s + Number(base[i]) * x, 0);
  let check = 11 - (sum % 11);
  if (check === 10) check = 0;
  if (check === 11) check = 1;
  return base + check;
}

const PHP = String.raw`
\Illuminate\Support\Facades\Mail::fake();
$server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
foreach (json_decode(base64_decode(getenv('E2E_HEADERS')), true) ?: [] as $k => $v) { $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v; }
$req = \Illuminate\Http\Request::create(getenv('E2E_PATH'), 'POST', [], [], [], $server, base64_decode(getenv('E2E_BODY')));
$res = app(\Illuminate\Contracts\Http\Kernel::class)->handle($req);
$urls = [];
foreach (\Illuminate\Support\Facades\Mail::sent(\App\Modules\USR\Mail\AccountInvitationMail::class) as $m) { if ($m->url) { $urls[] = $m->url; } }
echo "\nE2E_JSON ".json_encode(['status' => $res->getStatusCode(), 'body' => json_decode((string) $res->getContent(), true), 'urls' => $urls]);
`;

export type KernelResult = { status: number; body: unknown; urls: string[] };

/**
 * POST a la API dentro del contenedor con el correo simulado; devuelve la respuesta y los enlaces de invitación «enviados».
 * headers: los de la petición original que importan (Authorization, X-Company-Id).
 */
export async function postWithoutMail(path: string, body: unknown, headers: Record<string, string> = {}): Promise<KernelResult> {
  const { stdout: out } = await promisify(execFile)(
    "docker",
    ["exec", "-e", `E2E_PATH=${path}`, "-e", `E2E_BODY=${Buffer.from(JSON.stringify(body)).toString("base64")}`, "-e", `E2E_HEADERS=${Buffer.from(JSON.stringify(headers)).toString("base64")}`, API_CONTAINER, "php", "artisan", "tinker", `--execute=${PHP}`],
    { encoding: "utf8", maxBuffer: 10 * 1024 * 1024 },
  );
  const line = out.split(/\r?\n/).find((l) => l.startsWith("E2E_JSON "));
  if (!line) throw new Error(`Sin respuesta del kernel para ${path}: ${out.slice(0, 800)}`);
  return JSON.parse(line.slice("E2E_JSON ".length)) as KernelResult;
}

/** Pasa por postWithoutMail una petición que el navegador hace a la API (para que no salga el correo real). */
export async function forwardWithoutMail(route: import("@playwright/test").Route, apiPath: string): Promise<KernelResult> {
  const h = route.request().headers();
  const pick: Record<string, string> = {};
  for (const k of ["authorization", "x-company-id"]) if (h[k]) pick[k] = h[k];
  const r = await postWithoutMail(apiPath, route.request().postDataJSON(), pick);
  await route.fulfill({ status: r.status, contentType: "application/json", body: JSON.stringify(r.body) });
  return r;
}

/** Da de baja la empresa de prueba (por RUC), su suscripción, su cuenta y los usuarios con ese prefijo de correo. */
export function cleanupTrialCompany(ruc: string, emailPrefix: string) {
  const companies = `select id from companies where ruc = '${ruc}' and deleted_at is null`;
  psql(
    [
      `update subscription_addons set deleted_at = now() where deleted_at is null and subscription_id in (select id from subscriptions where company_id in (${companies}))`,
      `update subscriptions set deleted_at = now() where deleted_at is null and company_id in (${companies})`,
      `update accounts set deleted_at = now() where deleted_at is null and id in (select account_id from companies where id in (${companies}))`,
      `update companies set deleted_at = now() where id in (${companies})`,
      `update users set deleted_at = now() where email like '${emailPrefix}%' and deleted_at is null`,
    ].join("; "),
  );
}
