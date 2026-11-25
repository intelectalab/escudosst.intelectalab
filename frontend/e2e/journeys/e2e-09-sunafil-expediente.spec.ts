// E2E-09 (docs/12 §5): Modo SUNAFIL → generar expediente → faltantes → corregir → regenerar → link compartido →
// acceso público → revocar.
// El Jefe SST arma en la pantalla el expediente de un trabajador inspeccionado (creado para la prueba): falta la
// entrega del RISST; se registra la entrega con su cargo firmado y, al volver a armar, el punto queda «Incluido».
// Crea el enlace para el inspector; gerencia (persona de prueba) lo aprueba en su pantalla (RN-SUN-09); el inspector
// lo abre sin sesión; el Jefe SST lo anula y el enlace deja de funcionar.

import { expect, expectHealthy, test } from "../fixtures";
import { api, day, minimalPdf, openAs, uniq, uploadFile, who, whoByEmail } from "./helpers";
import { dropTestUser, ensureTestUser, fillForm, openModule, openNew, openRecord, recordsSection, sqlIn, submitNew } from "./b-records";
import type { Locator, Page } from "@playwright/test";

const MANAGER = "e2e.gerencia@escudo.test";

test.use({ user: "jefe" });

/** Ítem del expediente por su código (p. ej. «W5.»). */
function item(ficha: Locator, code: string): Locator {
  return ficha.locator("li").filter({ has: ficha.page().locator("b", { hasText: new RegExp(`^${code}\\.$`) }) }).first();
}

async function selectWorker(page: Page, ficha: Locator, name: string) {
  const combo = ficha.getByRole("combobox", { name: "Trabajador" });
  await combo.fill(name);
  const opt = page.getByRole("option").filter({ hasText: name }).first();
  await expect(opt).toBeVisible({ timeout: 120_000 });
  await opt.click();
}

test("E2E-09 SUNAFIL: expediente con faltantes, corrección, enlace público y revocación", async ({ page, request, browser }) => {
  test.setTimeout(25 * 60_000);
  const jefe = who("jefe");
  const company = jefe.company!;
  const tag = uniq("E2E09");
  const suffix = tag.split(" ")[1];
  const lastName = `Inspeccionado ${suffix}`;
  const created: Record<string, string> = {};

  try {
    const worker = await api<{ data: { id: string } }>(request, jefe, "/records/TRB/workers", {
      method: "POST",
      data: { document_number: `9${String(Math.floor(Math.random() * 10_000_000)).padStart(7, "0")}`, first_name: "Trabajador", paternal_last_name: lastName },
    });
    created.worker = worker.data.id;

    let ficha: Locator;
    await test.step("genera el expediente del trabajador inspeccionado: aparecen los faltantes", async () => {
      await openModule(page, "SUN");
      const section = await recordsSection(page);
      const form = await openNew(page, section, "Nuevo");
      await fillForm(form, { Título: `${tag} Inspección`, "Periodo desde": `${day(0).slice(0, 4)}-01-01`, "Periodo hasta": day(0) });
      await submitNew(form);
      const list = await api<{ data: { id: string }[] }>(request, jefe, `/records/SUN/inspection_dossiers?q=${encodeURIComponent(tag)}`);
      expect(list.data).toHaveLength(1);
      created.dossier = list.data[0].id;

      ficha = await openRecord(page, await recordsSection(page), `${tag} Inspección`);
      await selectWorker(page, ficha, lastName);
      await ficha.getByRole("button", { name: "Armar expediente" }).click();
      await expect(ficha.getByText(/\d+ de \d+ puntos con brechas/)).toBeVisible({ timeout: 420_000 });
      const risst = item(ficha, "W5");
      await expect(risst).toContainText("entrega del RISST");
      await expect(risst).toContainText("No consta la entrega del RISST.");
      await expect(risst).toContainText("Falta");
    });

    await test.step("corrige el faltante (entrega del RISST con cargo firmado) y vuelve a armar", async () => {
      const version = sqlIn(company, `select id from risst_versions where company_id = '${company}' and status = 'current' and deleted_at is null limit 1`);
      expect(version, "la empresa de demostración tiene un RISST vigente").toMatch(/^[0-9a-f-]{36}$/);
      const file = await uploadFile(request, jefe, "RIS", "cargo-risst.pdf", "application/pdf", minimalPdf("Cargo de entrega del RISST"));
      const del = await api<{ data: { id: string } }>(request, jefe, "/records/RIS/risst_deliveries", {
        method: "POST",
        data: { risst_version_id: version, worker_id: created.worker, delivered_on: day(0), method: "physical_signed", acknowledgement_file_id: file, status: "delivered" },
      });
      created.delivery = del.data.id;

      await ficha.getByRole("button", { name: "Volver a armar" }).click();
      await expect(ficha.getByText(/puntos con brechas|Expediente completo/)).toBeVisible({ timeout: 420_000 });
      await expect(item(ficha, "W5")).toContainText("Incluido", { timeout: 60_000 });
      await expect(item(ficha, "W5")).not.toContainText("No consta la entrega del RISST.");
    });

    let url = "";
    await test.step("crea el enlace para el inspector (queda por aprobar)", async () => {
      await ficha.getByPlaceholder("Nombre del inspector").fill(`Inspectora ${suffix}`);
      await ficha.locator("label", { hasText: "Días de vigencia" }).locator("input").fill("2");
      await ficha.locator("label", { hasText: "Visitas máx." }).locator("input").fill("5");
      await ficha.getByRole("button", { name: "Crear enlace" }).click();
      const link = ficha.getByText(/\/expediente\/[0-9a-f-]{36}\/\S+/).first();
      await expect(link).toBeVisible({ timeout: 60_000 });
      url = ((await link.textContent()) ?? "").match(/https?:\/\/\S+\/expediente\/[0-9a-f-]{36}\/[^\s"]+/)?.[0] ?? "";
      expect(url, "la pantalla muestra el enlace").not.toBe("");
      await expect(ficha.getByText("Por aprobar").first()).toBeVisible();

      // Sin aprobar, el inspector no entra.
      const visitor = await openAs(browser, null);
      await visitor.page.goto(new URL(url).pathname, { waitUntil: "domcontentloaded" });
      await expect(visitor.page.getByRole("heading", { level: 1, name: /Constructora|Huaylas/ })).toHaveCount(0);
      await visitor.context.close();
    });

    await test.step("gerencia aprueba el enlace en su pantalla", async () => {
      ensureTestUser(MANAGER, "Gerardo Gerencia (prueba)", "management", company);
      const { context, page: mgr } = await openAs(browser, whoByEmail(MANAGER, company));
      await openModule(mgr, "SUN");
      const f = await openRecord(mgr, await recordsSection(mgr), `${tag} Inspección`);
      const row = f.locator("li, div").filter({ hasText: `Inspectora ${suffix}` }).filter({ has: mgr.getByRole("button", { name: "Aprobar" }) }).last();
      await expect(row).toBeVisible({ timeout: 90_000 });
      await row.getByRole("button", { name: "Aprobar" }).click();
      await expect.poll(async () => {
        const links = await api<{ data: { recipient_name: string; status: string }[] }>(request, jefe, `/sun/dossiers/${created.dossier}/share-links`);
        return links.data.find((l) => l.recipient_name === `Inspectora ${suffix}`)?.status;
      }, { timeout: 60_000 }).toBe("active");
      await context.close();
    });

    await test.step("el inspector abre el expediente sin sesión", async () => {
      const visitor = await openAs(browser, null);
      await visitor.page.goto(new URL(url).pathname, { waitUntil: "domcontentloaded" });
      await expect(visitor.page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 90_000 });
      await expect(visitor.page.getByText(`${tag} Inspección`).first()).toBeVisible();
      await expect(visitor.page.getByText(/entrega del RISST/).first()).toBeVisible();
      await expectHealthy(visitor.page);
      await visitor.context.close();
      const links = await api<{ data: { recipient_name: string; view_count: number }[] }>(request, jefe, `/sun/dossiers/${created.dossier}/share-links`);
      expect(links.data.find((l) => l.recipient_name === `Inspectora ${suffix}`)?.view_count).toBeGreaterThanOrEqual(1);
    });

    await test.step("el Jefe SST anula el enlace y el inspector ya no entra", async () => {
      await openModule(page, "SUN");
      const f = await openRecord(page, await recordsSection(page), `${tag} Inspección`);
      const row = f.locator("li, div").filter({ hasText: `Inspectora ${suffix}` }).filter({ has: page.getByRole("button", { name: "Anular" }) }).last();
      await expect(row).toBeVisible({ timeout: 90_000 });
      page.once("dialog", (d) => void d.accept());
      await row.getByRole("button", { name: "Anular" }).click();
      await expect(f.getByText("Anulado").first()).toBeVisible({ timeout: 60_000 });

      const visitor = await openAs(browser, null);
      await visitor.page.goto(new URL(url).pathname, { waitUntil: "domcontentloaded" });
      await expect(visitor.page.getByText(/anul|no es válido|ya no/i).first()).toBeVisible({ timeout: 90_000 });
      await expect(visitor.page.getByText(`${tag} Inspección`)).toHaveCount(0);
      await visitor.context.close();
    });
  } finally {
    try {
      dropTestUser(MANAGER);
      sqlIn(
        company,
        [
          created.dossier ? `update dossier_share_links set deleted_at = now() where dossier_id = '${created.dossier}'; update inspection_dossiers set deleted_at = now() where id = '${created.dossier}';` : "",
          created.delivery ? `update risst_deliveries set deleted_at = now() where id = '${created.delivery}';` : "",
          created.worker ? `update workers set deleted_at = now() where id = '${created.worker}';` : "",
        ].join(" "),
      );
    } catch {
      /* queda marcado con el sufijo E2E09 */
    }
  }
});
