// E2E-10 (docs/12 §5): consultora → invitar cliente → aceptar → panel multi-cliente → cambiar de empresa → marca blanca
// en PDF.
// La demostración no trae una consultora: se arma en la base una cuenta con el plan Consultora, su empresa y su
// administradora (persona de prueba). La invitación se registra directamente en la base con un token conocido: el
// entorno de desarrollo envía los correos por un SMTP real y la prueba no debe mandar correos. Lo demás se hace en la
// pantalla: la consultora configura su marca blanca, ve la invitación pendiente, el administrador de la empresa cliente
// (Minera Los Andes) la acepta, la consultora la ve en su panel, entra a ella y su informe en PDF lleva la marca blanca.
// Al final se deshace todo (la empresa cliente vuelve a no tener consultora).

import { createHash, randomBytes } from "node:crypto";
import { expect, expectHealthy, settle, test } from "../fixtures";
import { api, openAs, psql, uniq, whoByEmail, withSession } from "./helpers";
import { dropTestUser, ensureTestUser } from "./b-records";

const CONSULTANT = "e2e.consultora@escudo.test";
const CLIENT_ADMIN = "lucia@minera.test";

test.use({ user: null });

/** RUC 20xxxxxxxxx con dígito verificador correcto (módulo 11). */
function ruc(): string {
  const base = `20${String(Math.floor(Math.random() * 100_000_000)).padStart(8, "0")}`;
  const w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const sum = [...base].reduce((s, d, i) => s + Number(d) * w[i], 0);
  const c = 11 - (sum % 11);
  return base + String(c === 10 ? 0 : c === 11 ? 1 : c);
}

test("E2E-10 consultora: invitación aceptada, panel multi-cliente, cambio de empresa y marca blanca en el PDF", async ({ page, request, browser }) => {
  test.setTimeout(12 * 60_000);
  const tag = uniq("E2E10");
  const suffix = tag.split(" ")[1];
  const brand = `Prevencia ${suffix}`;
  const footer = `Informe preparado por ${brand} para su cliente`;
  const client = psql(`select id || '|' || ruc || '|' || legal_name || '|' || coalesce(trade_name, legal_name) from companies where id in (select company_id from memberships m join users u on u.id = m.user_id where u.email = '${CLIENT_ADMIN}' and m.deleted_at is null) and deleted_at is null limit 1`).split("|");
  const [clientId, clientRuc, clientName, clientShown] = client;
  expect(clientId, "la demostración trae la empresa Minera Los Andes con su administradora").toMatch(/^[0-9a-f-]{36}$/);
  expect(psql(`select coalesce(managed_by_account_id::text, '') from companies where id = '${clientId}'`), "la empresa cliente no tiene consultora").toBe("");

  const ids: Record<string, string> = {};
  try {
    await test.step("prepara la consultora (cuenta, empresa con plan Consultora y su administradora)", async () => {
      ids.account = psql(`insert into accounts (name, account_type, white_label_enabled) values ('${tag} Consultora', 'consultancy', true) returning id`).split(/\r?\n/)[0];
      ids.company = psql(`insert into companies (account_id, ruc, legal_name, trade_name, headcount_declared) values ('${ids.account}', '${ruc()}', '${tag} Consultora S.A.C.', '${tag} Consultora', 5) returning id`).split(/\r?\n/)[0];
      psql(`insert into subscriptions (account_id, company_id, plan_id, status, billing_period, current_period_start, current_period_end)
              select '${ids.account}', '${ids.company}', id, 'active', 'monthly', now(), now() + interval '1 month' from plans where code = 'K'`);
      psql(`select seed_company_defaults('${ids.company}')`);
      ensureTestUser(CONSULTANT, "Carla Consultora (prueba)", "account_admin", ids.company);
      ids.consultantUser = psql(`select id from users where email = '${CONSULTANT}'`);
    });

    const consultant = whoByEmail(CONSULTANT, ids.company);
    await withSession(page, consultant);

    await test.step("configura su marca blanca en el panel de la consultora", async () => {
      await page.goto("/m/con", { waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      await expect(page.getByText(/Panel multi-cliente de/)).toBeVisible({ timeout: 120_000 });
      await page.getByRole("button", { name: "Configurar marca blanca" }).click();
      const dlg = page.getByRole("dialog").last();
      // El formulario se llena con la marca guardada al terminar de cargar: se escribe cuando ya cargó.
      await expect(dlg.getByText("Vista previa del menú")).toBeVisible({ timeout: 60_000 });
      await expect(async () => {
        await dlg.locator("label", { hasText: "Nombre de tu consultora" }).locator("input").fill(brand);
        await dlg.locator("label", { hasText: "Pie de página en documentos" }).locator("input").fill(footer);
        await dlg.getByLabel("Activar la marca para mis clientes").check();
        await expect(dlg.getByRole("button", { name: "Guardar marca" })).toBeEnabled({ timeout: 2_000 });
      }).toPass({ timeout: 60_000 });
      await dlg.getByRole("button", { name: "Guardar marca" }).click();
      await expect.poll(() => psql(`select coalesce(brand_name, '') || '|' || status from white_label_settings where account_id = '${ids.account}' and deleted_at is null`), { timeout: 60_000 }).toBe(`${brand}|active`);
    });

    const token = randomBytes(24).toString("hex"); // 48 caracteres, como los del servidor
    await test.step("invita a la empresa cliente: la invitación queda pendiente en el panel", async () => {
      ids.invitation = psql(
        `insert into client_invitations (consultancy_account_id, company_id, target_ruc, target_legal_name, invited_email, token_hash, suggested_plan_code, invited_by_user_id, status, sent_at, expires_at, created_by)
         values ('${ids.account}', '${clientId}', '${clientRuc}', '${clientName}', '${CLIENT_ADMIN}', decode('${createHash("sha256").update(token).digest("hex")}', 'hex'), 'P', '${ids.consultantUser}', 'sent', now(), now() + interval '7 days', '${ids.consultantUser}') returning id`,
      ).split(/\r?\n/)[0];
      await page.reload({ waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      const pending = page.locator("li").filter({ hasText: `RUC ${clientRuc}` }).filter({ hasText: CLIENT_ADMIN }).first();
      await expect(pending).toBeVisible({ timeout: 120_000 });
      await expect(pending).toContainText(clientShown);
      await expect(pending).toContainText("plan sugerido Profesional");
      const preview = await api<{ data: { consultancy: string; status: string } }>(request, null, `/public/con/invitations/${token}`);
      expect(preview.data.consultancy).toBe(brand);
      expect(preview.data.status).toBe("sent");
    });

    await test.step("el administrador de la empresa cliente acepta la vinculación", async () => {
      const { context, page: admin } = await openAs(browser, whoByEmail(CLIENT_ADMIN, clientId));
      await admin.goto(`/invitacion-consultora/${token}`, { waitUntil: "domcontentloaded" });
      await expect(admin.getByRole("heading", { name: `${brand} te invita a EscudoSST` })).toBeVisible({ timeout: 120_000 });
      await admin.getByRole("button", { name: "Aceptar la vinculación" }).click();
      await expect(admin.getByRole("heading", { name: `Listo: ${brand} ya gestiona tu empresa` })).toBeVisible({ timeout: 60_000 });
      await expectHealthy(admin);
      await context.close();
      expect(psql(`select managed_by_account_id from companies where id = '${clientId}'`)).toBe(ids.account);
    });

    await test.step("el panel multi-cliente muestra la empresa y entra a ella", async () => {
      await page.reload({ waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      const row = page.getByRole("link", { name: `Entrar a ${clientShown}` });
      await expect(row).toBeVisible({ timeout: 120_000 });
      await expect(row).toContainText(`RUC ${clientRuc}`);
      await expect(page.getByText("Empresas gestionadas").locator("..")).toContainText("1");
      await row.click();
      await expect(page).toHaveURL(/\/inicio$/, { timeout: 120_000 });
      await settle(page, 180_000);
      expect(await page.evaluate(() => window.sessionStorage.getItem("escudo.companyId"))).toBe(clientId);
      await expect(page.getByText(clientShown).first()).toBeVisible({ timeout: 60_000 });
      await expectHealthy(page);
    });

    await test.step("el informe del cliente en PDF lleva la marca blanca de la consultora", async () => {
      await page.goto(`/imprimir/informe-cliente/${clientId}`, { waitUntil: "domcontentloaded" });
      await expect(page.getByRole("button", { name: "Imprimir / Guardar PDF" })).toBeEnabled({ timeout: 120_000 });
      await expect(page.getByText(brand).first()).toBeVisible();
      await expect(page.locator("footer").filter({ hasText: footer })).toBeVisible();
      await expect(page.getByText(clientShown).first()).toBeVisible();
      const pdf = await page.pdf({ format: "A4" }).catch(() => null);
      if (pdf) expect(pdf.subarray(0, 4).toString()).toBe("%PDF");
      await expectHealthy(page);
    });
  } finally {
    try {
      psql(`update companies set managed_by_account_id = null where id = '${clientId}' and managed_by_account_id is not null;`);
      if (ids.consultantUser) {
        psql(`update consultant_assignments set deleted_at = now() where consultant_user_id = '${ids.consultantUser}' and deleted_at is null;
              update memberships set deleted_at = now() where user_id = '${ids.consultantUser}' and deleted_at is null;`);
      }
      if (ids.account) {
        psql(`update client_invitations set deleted_at = now() where consultancy_account_id = '${ids.account}';
              update white_label_settings set deleted_at = now() where account_id = '${ids.account}';
              update subscriptions set deleted_at = now() where account_id = '${ids.account}';
              update companies set deleted_at = now() where account_id = '${ids.account}';
              update accounts set deleted_at = now() where id = '${ids.account}';`);
      }
      dropTestUser(CONSULTANT);
    } catch {
      /* queda marcado con el sufijo E2E10 */
    }
  }
});
