// E2E-12 (docs/12 §5 y §6): sin señal se registran una inspección y una entrega de EPP (quedan en la cola cifrada del
// equipo), al volver la conexión se sincronizan solas y un conflicto (el mismo registro editado en el servidor y en el
// teléfono) se resuelve desde la cola sin pisar nada sin revisión. Empresa de demostración con el complemento OFF.

import type { Locator, Page } from "@playwright/test";
import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { api, apiRaw, psql, uniq, who } from "./helpers";

test.use({ user: "jefe" });

/** Elige la primera opción de un selector con búsqueda (en línea o, sin señal, desde el paquete del equipo). */
async function pickFirst(dialog: Locator, name: string) {
  const box = dialog.getByRole("combobox", { name, exact: true });
  await box.click();
  const option = box.locator("..").getByRole("option").first();
  await expect(option).toBeVisible({ timeout: 30_000 });
  const label = ((await option.textContent()) ?? "").trim();
  await option.click();
  await expect(box).toHaveValue(label);
  return label;
}

/** Abre el formulario «Nuevo» de la pestaña de registros del módulo. */
async function openNew(page: Page, tab: string, button: string) {
  const main = page.locator("main");
  const tabs = main.locator('[role=tablist][aria-label="Registros del módulo"]');
  await tabs.getByRole("tab", { name: tab, exact: true }).click();
  const section = main.locator(`section[data-records]:not([data-nested])[aria-label="${tab}"]`);
  await expect(section).toBeVisible();
  await section.locator("> div").first().getByRole("button", { name: button, exact: true }).click();
  const dialog = page.getByRole("dialog", { name: /^Nuevo · / });
  await expect(dialog).toBeVisible();
  return dialog;
}

const indicator = (page: Page) => page.getByRole("status").filter({ hasText: /Sin conexión|Sincronizando|pendiente|conflicto/ });

test("E2E-12 · inspección y entrega de EPP sin señal, sincronización y conflicto resuelto", async ({ page, context, problems }) => {
  test.setTimeout(8 * 60_000);
  const jefe = who("jefe");
  const tag = uniq("E2E-12");
  const created: { table: string; id: string }[] = [];

  try {
    await test.step("prepara el paquete sin conexión del equipo", async () => {
      await page.goto("/m/off", { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      await page.getByRole("button", { name: /Preparar paquete|Actualizar paquete/ }).click();
      await expect(page.getByText("Paquete guardado en este equipo.")).toBeVisible({ timeout: 120_000 });
    });

    await test.step("inspección registrada sin señal", async () => {
      await page.goto("/m/ins", { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      const dialog = await openNew(page, "Inspecciones", "Nueva inspección");
      await context.setOffline(true);
      await expect(indicator(page)).toContainText("Sin conexión");
      await dialog.getByLabel(/^Título/).fill(`${tag} inspección de campo`);
      await dialog.getByLabel(/^Tipo de inspección/).selectOption({ index: 1 });
      await pickFirst(dialog, "Sede");
      await dialog.getByRole("button", { name: "Crear", exact: true }).click();
      await expect(dialog).toBeHidden({ timeout: 30_000 });
      await expect(indicator(page)).toContainText(/Sin conexión — 1 registro por sincronizar/);
    });

    await test.step("entrega de EPP registrada sin señal", async () => {
      // Sin señal no se cambia de página (sin service worker en las pruebas): se vuelve a la red, se abre el módulo y se corta otra vez.
      await context.setOffline(false);
      await page.goto("/m/epp", { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      // Al recuperar la señal y abrir la app, la cola ya pudo enviarse; se espera a que quede vacía antes de la siguiente captura.
      await expect(indicator(page)).toHaveCount(0, { timeout: 120_000 });
      const dialog = await openNew(page, "Entregas", "Registrar entrega");
      await context.setOffline(true);
      await expect(indicator(page)).toContainText("Sin conexión");
      await pickFirst(dialog, "Trabajador");
      await pickFirst(dialog, "EPP");
      await dialog.getByLabel(/^Talla/).fill(tag);
      await dialog.getByRole("button", { name: "Crear", exact: true }).click();
      await expect(dialog).toBeHidden({ timeout: 30_000 });
      await expect(indicator(page)).toContainText(/Sin conexión — 1 registro por sincronizar/);

      await test.step("la cola del equipo muestra lo pendiente", async () => {
        await indicator(page).getByRole("button", { name: "Ver cola" }).click();
        await expect(page.getByText("Registros en este dispositivo")).toBeVisible();
        await expect(indicator(page).getByText("Entrega de EPP")).toBeVisible();
        await indicator(page).getByRole("button", { name: "Ocultar" }).click();
      });
    });

    await test.step("al reconectar se sincroniza solo", async () => {
      await context.setOffline(false);
      await expect(indicator(page)).toHaveCount(0, { timeout: 120_000 });
      await expectHealthy(page);
    });

    const inspection = await test.step("los registros llegaron al servidor una sola vez", async () => {
      const list = await api<{ data: { id: string; title: string; version: number }[] }>(page.request, jefe, `/records/INS/inspections?q=${encodeURIComponent(tag)}`);
      const rows = list.data.filter((r) => r.title === `${tag} inspección de campo`);
      expect(rows, "una inspección").toHaveLength(1);
      created.push({ table: "inspections", id: rows[0].id });
      // La talla no sale en la lista: se busca en la base (una sola fila, aunque el lote se haya reenviado).
      const deliveries = psql(`select id from ppe_deliveries where size = '${tag}' and deleted_at is null`).split(/\s+/).filter(Boolean);
      expect(deliveries, "una entrega de EPP sincronizada").toHaveLength(1);
      created.push({ table: "ppe_deliveries", id: deliveries[0] });
      const delivery = await api<{ data: { id: string; worker_id: string } }>(page.request, jefe, `/records/EPP/ppe_deliveries/${deliveries[0]}`);
      expect(delivery.data.worker_id).toBeTruthy();
      const full = await api<{ data: { id: string; version: number; title: string } }>(page.request, jefe, `/records/INS/inspections/${rows[0].id}`);
      return full.data;
    });

    await test.step("conflicto: la misma inspección editada en el servidor y en el teléfono", async () => {
      // En el servidor (otra persona en la oficina).
      await api(page.request, jefe, `/records/INS/inspections/${inspection.id}`, { method: "PATCH", data: { title: `${tag} (servidor)` } });
      // En el teléfono, con la versión que tenía antes del cambio.
      const res = await apiRaw(page.request, jefe, "/sync/batches", {
        method: "POST",
        data: {
          batch_uuid: crypto.randomUUID(),
          device_uid: `e2e-12-${Date.now()}`,
          platform: "web_pwa",
          operations: [{
            idempotency_key: crypto.randomUUID(), op: "update", entity: "inspections", entity_id: inspection.id, base_version: inspection.version,
            payload: { title: `${tag} (teléfono)` }, created_at_device: new Date(Date.now() - 10 * 60_000).toISOString(),
          }],
        },
      });
      expect(res.status, JSON.stringify(res.body)).toBe(200);
      expect(res.body.data.results[0].result).toBe("conflict");
      expect(res.body.data.results[0].conflict_type).toBe("version_mismatch");

      await page.goto("/m/ins", { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(indicator(page)).toContainText(/1 conflicto por revisar/, { timeout: 120_000 });
      await indicator(page).getByRole("button", { name: "Ver cola" }).click();
      const item = indicator(page).locator("li").filter({ hasText: "Cambió también en el servidor" }).filter({ hasText: `${tag} (teléfono)` });
      await expect(item).toBeVisible();
      await expect(item.getByText(`${tag} (servidor)`)).toBeVisible();
      await item.getByRole("button", { name: "Conservar el del servidor" }).click();
      await expect(item).toHaveCount(0, { timeout: 60_000 });
      await expect(indicator(page)).toHaveCount(0, { timeout: 60_000 });

      const after = await api<{ data: { title: string } }>(page.request, jefe, `/records/INS/inspections/${inspection.id}`);
      expect(after.data.title, "nada se pisó sin revisión").toBe(`${tag} (servidor)`);
      const open = await api<{ data: { entity_id: string }[] }>(page.request, jefe, "/sync/conflicts");
      expect(open.data.filter((c) => c.entity_id === inspection.id)).toHaveLength(0);
    });
    // Sin señal, el navegador anota en la consola cada petición que no pudo salir: es lo esperado, no una falla de la app.
    problems.console.splice(0, problems.console.length, ...problems.console.filter((m) => !/ERR_INTERNET_DISCONNECTED/.test(m)));
  } finally {
    await context.setOffline(false);
    // Los registros de prueba quedan archivados (baja lógica), también si la prueba se cortó antes de encontrarlos.
    for (const c of created) psql(`update ${c.table} set deleted_at = now() where id = '${c.id}' and deleted_at is null`);
    psql(`update ppe_deliveries set deleted_at = now() where size = '${tag}' and deleted_at is null`);
    psql(`update inspections set deleted_at = now() where title like '${tag}%' and deleted_at is null`);
  }
});
