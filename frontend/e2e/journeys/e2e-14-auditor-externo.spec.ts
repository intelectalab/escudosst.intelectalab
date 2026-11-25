// E2E-14 (docs/12 §5, M-AUI RN-AUI-02/08): el Jefe SST da acceso temporal a un auditor externo desde «Preparar una
// auditoría», limitado a los módulos elegidos y al periodo de la auditoría → el auditor acepta la invitación (correo en
// Mailpit), entra y ve solo esos módulos y los registros del periodo → el acceso vence → pierde el acceso.

import { randomBytes } from "node:crypto";
import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { api, apiRaw, artisan, DEMO_COMPANY, day, openAs, psql, uniq, who, whoByEmail } from "./helpers";

test.use({ user: "jefe" });

/**
 * Enlace de invitación del auditor. El token solo viaja en el correo (en caché queda su hash): la prueba no lee correos
 * (el servidor de desarrollo puede usar un SMTP real), así que vuelve a guardar la invitación pendiente bajo un token
 * conocido, con los mismos datos y vencimiento de una hora, y la acepta por la pantalla real /invitacion/<token>.
 */
function invitationToken(email: string): string {
  const userId = psql(`select id from users where email = '${email}' and deleted_at is null`);
  const token = randomBytes(36).toString("base64url").slice(0, 48);
  const php = [
    `$h = Cache::get('invitation:user:${userId}');`,
    `$d = $h ? Cache::get('invitation:'.$h) : null;`,
    "if (! $d) { echo 'SIN-INVITACION'; return; }",
    `$n = hash('sha256', '${token}');`,
    "Cache::put('invitation:'.$n, $d, now()->addHour());",
    `Cache::put('invitation:user:${userId}', $n, now()->addHour());`,
    "Cache::forget('invitation:'.$h);",
    "echo 'OK';",
  ].join(" ");
  const out = artisan("tinker", "--execute", php);
  if (!out.includes("OK")) throw new Error(`No hay invitación pendiente para ${email}: ${out}`);
  return token;
}

test("E2E-14 · auditor externo temporal: solo sus módulos y periodo, y pierde el acceso al vencer", async ({ page, browser, request }) => {
  test.setTimeout(9 * 60_000);
  const jefe = who("jefe");
  const tag = uniq("E2E-14");
  const email = `e2e14.${Date.now().toString(36)}@ejemplo.test`;
  const ids: { audit?: string; inside?: string; outside?: string } = {};

  try {
    await test.step("auditoría con su periodo y dos inspecciones (dentro y fuera del periodo)", async () => {
      const site = (await api<{ data: { id: string }[] }>(request, jefe, "/records/INS/inspections/options/site_id")).data[0].id;
      ids.inside = (await api<{ data: { id: string } }>(request, jefe, "/records/INS/inspections", {
        method: "POST", data: { title: `${tag} dentro del periodo`, inspection_type: "planned", site_id: site, scheduled_on: day(-5) } })).data.id;
      ids.outside = (await api<{ data: { id: string } }>(request, jefe, "/records/INS/inspections", {
        method: "POST", data: { title: `${tag} fuera del periodo`, inspection_type: "planned", site_id: site, scheduled_on: "2019-06-15" } })).data.id;
      ids.audit = (await api<{ data: { id: string } }>(request, jefe, "/records/AUI/audits", {
        method: "POST", data: { audit_type: "external", standard: "ley_29783", starts_on: day(-30), ends_on: day(20), lead_auditor_name: `${tag} Auditora` } })).data.id;
    });

    await test.step("el Jefe SST da acceso temporal solo a Inspecciones y Documentos", async () => {
      await page.goto("/m/aui", { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      await expect(page.getByText("Preparar una auditoría", { exact: true })).toBeVisible({ timeout: 60_000 });
      const card = page.locator("main");
      const select = page.getByRole("combobox", { name: "Auditoría", exact: true });
      await expect(select.locator(`option[value="${ids.audit}"]`)).toBeAttached({ timeout: 60_000 });
      await select.selectOption(ids.audit!);
      await card.getByPlaceholder("Nombre completo").fill(`${tag} Auditor Externo`);
      await card.getByPlaceholder("Correo").fill(email);
      const modules = card.locator("fieldset");
      for (const name of ["Inspecciones", "Gestión documental"]) await modules.locator("label").filter({ hasText: name }).first().locator("input").check();
      await card.getByRole("button", { name: "Dar acceso temporal" }).click();
      await expect(page.getByRole("status").filter({ hasText: "Se envió la invitación al auditor externo. Su acceso vence el" })).toContainText(day(20).split("-").reverse().join("/"));
      const m = psql(`select coalesce(array_to_string(allowed_modules, ','), '') || '|' || audit_period_from || '|' || audit_period_to from memberships where id = (select external_auditor_membership_id from audits where id = '${ids.audit}')`);
      const [allowed, from, to] = m.split("|");
      expect(allowed.split(",").sort()).toEqual(["DOC", "INS"]);
      expect([from, to]).toEqual([day(-30), day(20)]);
    });

    await test.step("el auditor acepta la invitación y crea su contraseña", async () => {
      const token = invitationToken(email);
      const { context, page: inv } = await openAs(browser, null);
      try {
        await inv.goto(`/invitacion/${token}`, { waitUntil: "domcontentloaded" });
        await waitForHydration(inv);
        await expect(inv.getByRole("heading", { name: `Hola, ${tag.split(" ")[0]}` })).toBeVisible({ timeout: 60_000 });
        const password = `Auditor-${randomBytes(6).toString("hex")}-2026`;
        await inv.locator('input[autocomplete="new-password"]').nth(0).fill(password);
        await inv.locator('input[autocomplete="new-password"]').nth(1).fill(password);
        await inv.getByRole("button", { name: "Crear contraseña" }).click();
        await expect(inv).toHaveURL(/\/login\?email=/, { timeout: 60_000 });
      } finally {
        await context.close();
      }
      expect(psql(`select status from users where email = '${email}'`)).toBe("active");
    });

    const auditor = whoByEmail(email, DEMO_COMPANY);
    const { context, page: ap } = await openAs(browser, auditor);
    try {
      await test.step("ve Inspecciones solo con los registros del periodo", async () => {
        await ap.goto("/m/ins", { waitUntil: "domcontentloaded" });
        await settle(ap);
        await expectHealthy(ap);
        await ap.locator("main").locator('[role=tablist][aria-label="Registros del módulo"]').getByRole("tab", { name: "Inspecciones", exact: true }).click();
        const section = ap.locator('main section[data-records="inspections"]:not([data-nested])');
        await section.getByPlaceholder(/Buscar/).fill(tag);
        await expect(section.getByRole("cell", { name: `${tag} dentro del periodo`, exact: true })).toBeVisible({ timeout: 60_000 });
        await expect(section.getByRole("cell", { name: `${tag} fuera del periodo`, exact: true })).toHaveCount(0);
        // Solo lectura: el auditor no crea inspecciones.
        await expect(section.getByRole("button", { name: "Nueva inspección", exact: true })).toHaveCount(0);
        expect((await apiRaw(request, auditor, `/records/INS/inspections/${ids.outside}`)).status, "registro fuera del periodo").toBe(404);
      });

      await test.step("no entra a módulos que no se le dieron", async () => {
        await ap.goto("/m/acc", { waitUntil: "domcontentloaded" });
        await settle(ap);
        await expect(ap.getByRole("heading", { name: "Página no disponible" })).toBeVisible();
        const r = await apiRaw(request, auditor, "/records/ACC/sst_events");
        expect(r.status).toBe(403);
        expect(r.body.type).toBe("module_not_allowed");
        const mods = (await api<{ data: { code: string; role_can_use: boolean }[] }>(request, auditor, "/me/modules")).data;
        expect(mods.filter((m) => m.role_can_use && !["DSH", "NOT", "TEN", "USR"].includes(m.code)).map((m) => m.code).sort()).toEqual(["DOC", "INS"]);
      });

      await test.step("al vencer el acceso, lo pierde", async () => {
        psql(`update memberships set expires_at = now() - interval '1 minute' where id = (select external_auditor_membership_id from audits where id = '${ids.audit}')`);
        const r = await apiRaw(request, auditor, "/records/INS/inspections");
        expect(r.status, JSON.stringify(r.body)).toBeGreaterThanOrEqual(401);
        expect(r.status).toBeLessThan(500);
        // La tarea programada lo deja «vencido» y cierra sus sesiones.
        artisan("escudo:access-expiry");
        expect(psql(`select status from memberships where id = (select external_auditor_membership_id from audits where id = '${ids.audit}')`)).toBe("expired");
        await ap.goto("/m/ins", { waitUntil: "domcontentloaded" });
        await expect(ap).toHaveURL(/\/login|\/select-company/, { timeout: 120_000 });
      });
    } finally {
      await context.close();
    }
  } finally {
    if (ids.audit) {
      psql(`update memberships set deleted_at = now() where id = (select external_auditor_membership_id from audits where id = '${ids.audit}')`);
      psql(`update audits set deleted_at = now() where id = '${ids.audit}'`);
    }
    for (const id of [ids.inside, ids.outside]) if (id) psql(`update inspections set deleted_at = now() where id = '${id}'`);
    psql(`update users set deleted_at = now() where email = '${email}' and deleted_at is null`);
  }
});
