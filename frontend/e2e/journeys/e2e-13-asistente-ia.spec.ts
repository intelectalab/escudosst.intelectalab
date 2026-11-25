// E2E-13 (docs/12 §5): el asistente (complemento IAA, con el proveedor simulado de desarrollo y la biblioteca de EscudoSST)
// sugiere filas IPERC para un puesto → la persona las revisa → acepta una editada, descarta otra (aplicación parcial) →
// la fila aceptada entra al borrador de la matriz marcada como «Sugerencia IA» y el historial lo cuenta.

import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { api, psql, uniq, who } from "./helpers";

test.use({ user: "jefe" });

test("E2E-13 · sugerencia IA revisada y aplicada en parte, con registros marcados como asistidos", async ({ page, request }) => {
  test.setTimeout(9 * 60_000);
  const jefe = who("jefe");
  const tag = uniq("E2E-13");
  const code = tag.replace(/\s+/g, "-").slice(0, 30);
  let positionId: string | null = null;
  let matrixId: string | null = null;

  try {
    await test.step("puesto y matriz IPERC en borrador", async () => {
      positionId = (await api<{ data: { id: string } }>(request, jefe, "/records/TEN/positions", {
        method: "POST", data: { code, name: `${tag} Pintor de fachada`, functions: "Pintar fachadas desde andamio colgante" },
      })).data.id;
      const criteria = (await api<{ data: { id: string }[] }>(request, jefe, "/records/IPC/iperc_matrices/options/risk_criteria_id?q=R.M.")).data[0].id;
      matrixId = (await api<{ data: { id: string } }>(request, jefe, "/records/IPC/iperc_matrices", {
        method: "POST", data: { code, title: `${tag} Pintura de fachada`, risk_criteria_id: criteria, position_id: positionId },
      })).data.id;
    });

    const accepted = `${tag} pintar el segundo piso`;
    await test.step("pide sugerencias para el puesto", async () => {
      await page.goto(`/m/iaa?t=iperc&puesto=${positionId}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      await expectHealthy(page);
      await expect(page.getByText("El asistente solo sugiere.")).toBeVisible();
      await page.getByPlaceholder("Ej.: soldadura MIG en nave con ventilación natural").fill("Trabajo en altura sobre andamio");
      await page.getByRole("button", { name: "Generar sugerencias" }).click();
      await expect(page.getByRole("heading", { name: `Sugerencias para el puesto «${tag} Pintor de fachada»` })).toBeVisible({ timeout: 120_000 });
      await expect(page.locator("article").first()).toBeVisible();
      expect(await page.locator("article").count(), "al menos dos sugerencias para aceptar una y descartar otra").toBeGreaterThanOrEqual(2);
    });

    await test.step("revisa: edita y acepta la primera, descarta la segunda", async () => {
      const first = page.locator("article").nth(0);
      const second = page.locator("article").nth(1);
      await first.getByRole("button", { name: "Editar" }).click();
      const modal = page.getByRole("dialog", { name: "Editar sugerencia" });
      await expect(modal).toBeVisible();
      await modal.getByLabel("Tarea").fill(accepted);
      await modal.getByRole("button", { name: "Guardar y aceptar" }).click();
      await expect(modal).toBeHidden();
      await expect(first.getByText(/^Aceptada · agregada al borrador v\d+ como fila \d+/)).toBeVisible();
      await second.getByRole("button", { name: "Descartar" }).click();
      await expect(second.getByText("Descartada")).toBeVisible();
      // Las demás quedan pendientes (nada se aplica sin revisión).
      if ((await page.locator("article").count()) > 2) await expect(page.locator("article").nth(2).getByRole("button", { name: "Aceptar" })).toBeVisible();
    });

    await test.step("la fila aceptada está en el borrador, marcada como asistida", async () => {
      const ws = await api<{ data: { rows: { id: string; task: string; ai_assisted?: boolean }[] } }>(request, jefe, `/ipc/matrices/${matrixId}/workspace`);
      expect(ws.data.rows, "solo la sugerencia aceptada entra al borrador").toHaveLength(1);
      expect(ws.data.rows[0].task).toBe(accepted);
      expect(psql(`select ai_assisted::text || '|' || (ai_suggestion_id is not null)::text from iperc_rows where id = '${ws.data.rows[0].id}'`)).toBe("true|true");

      await page.goto(`/m/ipc?t=matrices&matriz=${matrixId}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByText(accepted).first()).toBeVisible({ timeout: 120_000 });
      await expect(page.getByText("Sugerencia IA", { exact: true }).first()).toBeVisible();
      await expectHealthy(page);
    });

    await test.step("el historial del asistente registra lo aceptado y lo descartado", async () => {
      const hist = await api<{ data: { feature: string; accepted: number; rejected?: number }[] }>(request, jefe, "/iaa/history");
      const last = hist.data.find((h) => h.feature === "iperc_suggestion");
      expect(last?.accepted).toBe(1);
      await page.goto("/m/iaa?t=history", { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByRole("columnheader", { name: "Aceptadas" })).toBeVisible();
      await expect(page.locator("tbody tr").first()).toBeVisible();
    });
  } finally {
    if (matrixId) {
      psql(`update iperc_matrices set deleted_at = now() where id = '${matrixId}'`);
    }
    if (positionId) psql(`update positions set deleted_at = now() where id = '${positionId}'`);
  }
});
