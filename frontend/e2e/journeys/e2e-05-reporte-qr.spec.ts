// E2E-05 (docs/12 §5): reporte público por QR sin sesión → bandeja → acción → cierre.
// El trabajador escanea el QR del punto y reporta desde el celular sin iniciar sesión; el Jefe SST lo ve en la bandeja de
// reportes, lo revisa y le asigna una acción correctiva; el responsable sube la evidencia y otra persona la verifica: el
// reporte queda «Atendido y cerrado» y así lo ve quien reportó en su enlace de seguimiento.

import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import type { Locator } from "@playwright/test";
import { api, apiRaw, day, DEMO_COMPANY, openAs, psql, uniq, uploadFile, who } from "./helpers";

test.use({ user: "jefe" });

/** Elige una opción de un campo de búsqueda (RefSelect): escribe, espera la lista y elige la que contiene el texto. */
async function pickRef(combo: Locator, text: string) {
  await combo.click();
  await combo.fill(text);
  const opt = combo.page().getByRole("option", { name: new RegExp(text) }).first();
  await expect(opt).toBeVisible({ timeout: 30_000 });
  await opt.click();
}

test("E2E-05 · reporte por QR → bandeja → acción → cierre", async ({ page, browser }) => {
  test.setTimeout(30 * 60_000);
  const jefe = who("jefe");
  const otro = who("aprobador");
  const tag = uniq("E2E05");
  const site = psql(`select id from sites where company_id = '${DEMO_COMPANY}' and deleted_at is null order by created_at limit 1`);
  
  // Punto de reporte con su QR (enlace público).
  const point = (await api(page.request, jefe, "/records/ACT/report_points", { method: "POST", data: { code: `QR-${tag.slice(-6)}`, name: `Punto ${tag}`, site_id: site } })).data.id as string;
  const url = (await api(page.request, jefe, `/act/report-points/${point}/link`)).data.url as string;
  const path = new URL(url).pathname;
  expect(path).toMatch(/^\/reportar\/[^/]+\/[^/]+$/);

  let reportId = "";
  let code = "";
  let track = "";
  try {
    // ------------------------------------------------ 1. Reporte desde el celular, sin sesión
    await test.step("el trabajador reporta por el QR sin iniciar sesión", async () => {
      const { context, page: pub } = await openAs(browser, null);
      try {
        await pub.goto(path, { waitUntil: "domcontentloaded" });
        await expect(pub.getByText("Reporte de seguridad")).toBeVisible();
        await expect(pub.getByText(`Punto ${tag}`)).toBeVisible({ timeout: 180_000 });
        await waitForHydration(pub, 'button[type="submit"]');
        await expect(pub.getByRole("button", { name: "Enviar reporte" })).toBeDisabled();
        await pub.getByText("Condición insegura", { exact: true }).click();
        await pub.getByPlaceholder(/cable pelado/).fill(`Aceite derramado en el pasillo principal ${tag}`);
        await pub.locator("label", { hasText: "Tu nombre" }).locator("input").fill("Rosa Reportante");
        await pub.getByRole("button", { name: "Enviar reporte" }).click();
        await expect(pub.getByRole("heading", { name: "¡Gracias por reportar!" })).toBeVisible({ timeout: 180_000 });
        code = ((await pub.locator("p b").first().textContent()) ?? "").trim();
        expect(code).toMatch(/^[A-Z]{3}-\d{4}-\d{4,}$/);
        track = (await pub.getByRole("link", { name: "seguir mi reporte" }).getAttribute("href")) ?? "";
        expect(track).toContain("/seguimiento/");
        await pub.goto(new URL(track).pathname, { waitUntil: "domcontentloaded" });
        await expect(pub.getByText("Recibido").first()).toBeVisible({ timeout: 180_000 });
      } finally {
        await context.close();
      }
    });

    // ------------------------------------------------ 2. Bandeja de reportes del Jefe SST
    await test.step("el reporte llega a la bandeja", async () => {
      const list = await api(page.request, jefe, `/records/ACT/substandard_reports?f[report_point_id]=${point}`);
      expect(list.data).toHaveLength(1);
      reportId = list.data[0].id;
      expect(list.data[0].code).toBe(code);
      expect(list.data[0].status).toBe("received");

      await page.goto("/m/act?t=substandard_reports", { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByRole("tab", { name: "Bandeja de reportes" })).toHaveAttribute("aria-selected", "true");
      await page.goto(`/m/act?t=substandard_reports&open=${reportId}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      const card = page.getByRole("dialog", { name: new RegExp(`Aceite derramado en el pasillo principal ${tag}`) });
      await expect(card).toBeVisible({ timeout: 180_000 });
      await expect(card.getByRole("definition").filter({ hasText: code })).toBeVisible();
      await expect(card.getByText("Recibido", { exact: true })).toBeVisible();
      await expectHealthy(page);
    });

    // ------------------------------------------------ 3. Revisión y acción correctiva (desde la ficha del reporte)
    let action = "";
    await test.step("se revisa y se asigna una acción correctiva", async () => {
      const card = page.getByRole("dialog", { name: new RegExp(`Aceite derramado en el pasillo principal ${tag}`) });
      // Sin nivel de riesgo no se puede revisar (lo pide en castellano); se indica en «Detalle» y se guarda.
      await card.getByRole("button", { name: "Marcar revisado" }).click();
      await expect(card.getByRole("alert").filter({ hasText: "nivel de riesgo" })).toBeVisible({ timeout: 180_000 });
      await card.getByRole("tab", { name: "Detalle" }).click();
      await card.getByLabel(/^Nivel de riesgo/).selectOption({ label: "Alta" });
      await card.getByRole("button", { name: /^Guardar/ }).first().click();
      await expect(card).toBeHidden({ timeout: 240_000 });
      await expect.poll(async () => (await api(page.request, jefe, `/records/ACT/substandard_reports/${reportId}`)).data.risk_level, { timeout: 240_000 }).toBe("high");
      await page.goto(`/m/act?t=substandard_reports&open=${reportId}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await card.getByRole("button", { name: "Marcar revisado" }).click();
      await expect(card.getByRole("definition").filter({ hasText: /^Clasificado$/ })).toBeVisible({ timeout: 180_000 });

      await card.getByRole("button", { name: "Crear acción correctiva" }).click();
      const modal = page.getByRole("dialog", { name: "Nueva acción correctiva" });
      await modal.locator("label", { hasText: "Qué se va a hacer" }).locator("input").fill(`Contener derrames ${tag}`);
      await pickRef(modal.getByRole("combobox", { name: "Responsable" }), "Patricia Salazar");
      await modal.locator('input[type="date"]').fill(day(3));
      await modal.getByRole("button", { name: "Crear", exact: true }).click();
      await expect(modal).toBeHidden({ timeout: 300_000 });
      await expect.poll(async () => (await api(page.request, jefe, `/records/ACT/substandard_reports/${reportId}`)).data.status, { timeout: 240_000 }).toBe("action_assigned");
      action = (await api(page.request, jefe, `/records/ACT/substandard_reports/${reportId}`)).data.corrective_action_id;
      expect(action).toBeTruthy();

      await page.goto(`/m/acr?t=corrective_actions&open=${action}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByRole("dialog", { name: new RegExp(`Contener derrames ${tag}`) })).toBeVisible({ timeout: 180_000 });
    });

    // ------------------------------------------------ 4. Evidencia del responsable y verificación por otra persona → cierre
    await test.step("el responsable sube evidencia y otra persona verifica: el reporte se cierra", async () => {
      const file = await uploadFile(page.request, otro, "ACR");
      await api(page.request, otro, "/records/ACR/action_evidences", { method: "POST", data: { corrective_action_id: action, evidence_type: "document", description: "Derrame limpiado", file_id: file } });
      await api(page.request, otro, `/records/ACR/corrective_actions/${action}`, { method: "PATCH", data: { status: "pending_verification" } });
      // El responsable no verifica su propia acción.
      const self = await apiRaw(page.request, otro, `/records/ACR/corrective_actions/${action}`, { method: "PATCH", data: { status: "verified", effectiveness: "effective" } });
      expect(self.status).toBe(422);
      await api(page.request, jefe, `/records/ACR/corrective_actions/${action}`, { method: "PATCH", data: { status: "verified", effectiveness: "effective" } });
      expect((await api(page.request, jefe, `/records/ACT/substandard_reports/${reportId}`)).data.status).toBe("closed");

      // Quien reportó ve el cierre en su enlace de seguimiento.
      const { context, page: pub } = await openAs(browser, null);
      try {
        await pub.goto(new URL(track).pathname, { waitUntil: "domcontentloaded" });
        await expect(pub.getByText("Atendido y cerrado").first()).toBeVisible({ timeout: 180_000 });
        await expect(pub.getByText(/Cerrado el /)).toBeVisible();
      } finally {
        await context.close();
      }
    });
  } finally {
    // Limpieza: el punto queda inactivo y los registros de prueba, borrados lógicamente.
    psql(`update report_points set is_active = false, deleted_at = now() where id = '${point}'`);
    if (reportId) psql(`update substandard_reports set deleted_at = now() where id = '${reportId}'`);
    psql(`update corrective_actions set deleted_at = now() where title like 'Contener derrames ${tag}%'`);
  }
});
