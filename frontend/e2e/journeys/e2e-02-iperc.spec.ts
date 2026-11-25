// E2E-02 (docs/12 §5): IPERC → crear matriz → filas → enviar → aprobar (otro usuario) → vigente.
// El Jefe SST crea la matriz y su fila (peligro, riesgo, probabilidad y severidad: el nivel se calcula solo), la envía a
// aprobación con los trabajadores que participaron; quien la envió no la puede aprobar; otra persona la aprueba con el
// acta de una sesión del comité (la empresa trabaja con Comité SST) y la matriz pasa a «Vigente».

import { expect, expectHealthy, settle, test } from "../fixtures";
import { api, apiRaw, day, DEMO_COMPANY, openAs, psql, uniq, who } from "./helpers";

test.use({ user: "jefe" });

test("E2E-02 · IPERC: matriz → filas → enviar → aprobar por otra persona → vigente", async ({ page, browser }) => {
  test.setTimeout(30 * 60_000);
  const jefe = who("jefe");
  const otro = who("aprobador");
  const tag = uniq("E2E02");
  const code = `IPERC-${tag.slice(-6)}`;
  const criteria = psql(`select id from risk_criteria where company_id = '${DEMO_COMPANY}' and name like 'Método de índices%' and deleted_at is null limit 1`);
  expect(criteria, "criterio R.M. 050-2013-TR de la empresa").toBeTruthy();
  const position = psql(`select id from positions where company_id = '${DEMO_COMPANY}' and deleted_at is null order by created_at limit 1`);
  const otroId = psql("select id from users where email = 'revb.aprobador@escudo.test'");

  let matrix = "";
  try {
    // ------------------------------------------------ 1. Matriz y fila (cálculo del nivel)
    await test.step("se crea la matriz con una fila evaluada", async () => {
      matrix = (await api(page.request, jefe, "/records/IPC/iperc_matrices", { method: "POST", data: { code, title: `IPERC ${tag}`, risk_criteria_id: criteria } })).data.id;
      const version = (await api(page.request, jefe, `/ipc/matrices/${matrix}/workspace`)).data.version_id as string;
      const row = await api(page.request, jefe, "/records/IPC/iperc_rows", {
        method: "POST",
        data: {
          iperc_version_id: version,
          activity: "Soldadura",
          task: `Soldar estructuras ${tag}`,
          hazard: "Humos metálicos",
          risk: "Intoxicación",
          probability_detail: { exposed_people: 2, procedures: 2, training: 2, exposure: 3 },
          severity_value: 2,
          position_id: position,
        },
      });
      expect(row.data.risk_score).toBe(18);
      expect(row.data.risk_level).toBe("important");
      // RN-IPC-03: riesgo significativo con un control de mayor jerarquía que el EPP.
      await api(page.request, jefe, "/records/IPC/iperc_controls", {
        method: "POST",
        data: { iperc_row_id: row.data.id, hierarchy: "engineering", description: `Extracción localizada de humos ${tag}`, responsible_user_id: otroId, due_on: day(45) },
      });

      await page.goto(`/m/ipc?t=matrices&matriz=${matrix}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByText(`Soldar estructuras ${tag}`).first()).toBeVisible({ timeout: 240_000 });
      await expect(page.getByText("Humos metálicos").first()).toBeVisible();
      await expect(page.getByRole("list", { name: "Estado de la versión" })).toContainText("Borrador");
      await expectHealthy(page);
    });

    // ------------------------------------------------ 2. Envío a aprobación con los participantes
    await test.step("se envía a aprobación con los trabajadores que participaron", async () => {
      await page.getByRole("button", { name: "Enviar a aprobación" }).click();
      const modal = page.getByRole("dialog", { name: "Enviar a aprobación" });
      await expect(modal.getByRole("button", { name: "Enviar", exact: true })).toBeDisabled();
      await modal.getByLabel("Nombre del participante 1").fill("Rosa Quispe");
      await modal.getByLabel("Puesto del participante 1").fill("Soldadora");
      await modal.getByRole("button", { name: "Agregar participante" }).click();
      await modal.getByLabel("Nombre del participante 2").fill("Jorge Huamán");
      await modal.getByRole("button", { name: "Enviar", exact: true }).click();
      await expect(modal).toBeHidden({ timeout: 300_000 });
      await expect.poll(async () => (await api(page.request, jefe, `/ipc/matrices/${matrix}/workspace`)).data.versions[0].status, { timeout: 240_000 }).toBe("in_review");
      // Quien la envió no la aprueba (RN: aprueba otra persona).
      const version = (await api(page.request, jefe, `/ipc/matrices/${matrix}/workspace`)).data.version_id as string;
      const self = await apiRaw(page.request, jefe, `/ipc/versions/${version}/approve`, { method: "POST", data: {} });
      expect(self.status).toBe(409);
    });

    // ------------------------------------------------ 3. Otra persona aprueba con el acta del comité → vigente
    await test.step("otra persona la aprueba con el acta del comité y queda vigente", async () => {
      const { context, page: other } = await openAs(browser, otro);
      try {
        await other.goto(`/m/ipc?t=matrices&matriz=${matrix}`, { waitUntil: "domcontentloaded" });
        await settle(other);
        await expect(other.getByRole("list", { name: "Estado de la versión" })).toContainText("En revisión", { timeout: 240_000 });
        await other.getByRole("button", { name: "Aprobar versión 1" }).click();
        const dialog = other.getByRole("dialog", { name: "Aprobar con el acta del comité" });
        await expect(dialog).toBeVisible();
        const select = dialog.locator("select");
        await expect(select.locator("option")).not.toHaveCount(1, { timeout: 60_000 });
        await select.selectOption({ index: 1 });
        await dialog.getByRole("button", { name: "Aprobar", exact: true }).click();
        await expect(dialog).toBeHidden({ timeout: 300_000 });
        await expect.poll(async () => (await api(other.request, otro, `/ipc/matrices/${matrix}/workspace`)).data.versions[0].status, { timeout: 240_000 }).toBe("current");

        await other.goto("/m/ipc?t=matrices", { waitUntil: "domcontentloaded" });
        await settle(other);
        const cardBtn = other.getByRole("button", { name: new RegExp(`${code}.*IPERC ${tag}`) });
        await expect(cardBtn).toBeVisible({ timeout: 240_000 });
        await expect(cardBtn).toContainText(/Vigente|Revisión próxima/);
        await expectHealthy(other);
      } finally {
        await context.close();
      }
    });
  } finally {
    if (matrix) psql(`update iperc_matrices set deleted_at = now() where id = '${matrix}'`);
  }
});
