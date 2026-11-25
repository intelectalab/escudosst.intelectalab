// E2E-17 (docs/12 §5) · Cupos corporativos: una empresa compra N cupos en el catálogo público → la empresa vendedora
// confirma el pago → el representante de la compradora asigna desde su portal → no puede pasar del límite → libera un
// cupo que no se inició (uno iniciado no se libera) → ve el avance de sus asignados (M-VTA RN-VTA-15, SCR-VTA-19).

import type { Page } from "@playwright/test";
import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { apiRaw, openAs, whoByEmail, type Who } from "./helpers";
import { cleanupSales, dni, HUAYLAS, lit, one, orderByNumber, payOrder, ruc, seedOffer, slugSuffix, type Seeded } from "./_vta";

test.use({ user: "jefe" });

test.describe.serial("E2E-17 · Cupos corporativos", () => {
  const sfx = slugSuffix();
  const mail = (who: string) => `e2e17.${who}.${sfx}@example.test`;
  const buyerRuc = ruc();
  let seeded: Seeded | null = null;
  let orderNumber = "";
  let licenseId = "";
  let rep: Who | null = null;

  test.beforeAll(() => {
    seeded = seedOffer(sfx, { title: `Rescate en altura E2E ${sfx}`, minSeats: 2 });
  });
  test.afterAll(() => cleanupSales(seeded, `e2e17.%.${sfx}@%`));

  test("la empresa compradora pide 2 cupos en el catálogo público", async ({ browser }) => {
    test.setTimeout(6 * 60_000);
    const { context, page } = await openAs(browser, null);
    try {
      await page.goto(`/cursos/${HUAYLAS}/${seeded!.slug}`, { waitUntil: "domcontentloaded" });
      await expect(page.getByRole("heading", { level: 1, name: seeded!.title })).toBeVisible({ timeout: 120_000 });
      await waitForHydration(page, "form button[type=submit]");
      const form = page.locator("form").filter({ has: page.getByRole("button", { name: "Solicitar compra" }) });
      await form.getByRole("button", { name: "Cupos para mi empresa" }).click();
      await form.getByLabel("Razón social").fill(`Compradora E2E ${sfx} S.A.C.`);
      await form.locator("label", { hasText: /^RUC/ }).locator("input").fill(buyerRuc);
      await form.locator("label", { hasText: /^Cupos/ }).locator("input").fill("2");
      await form.getByLabel("Correo").fill(mail("rep"));
      await expect(form.getByText(/2 cupos × S\/\s?100\.00/)).toBeVisible();
      await form.getByText(/Acepto que .* use mis datos/).click();
      await form.getByRole("button", { name: "Solicitar compra" }).click();
      await expect(page.getByRole("heading", { name: "Recibimos tu pedido" })).toBeVisible({ timeout: 60_000 });
      await expect(page.getByText("recibirás tus cupos para asignarlos")).toBeVisible();
      orderNumber = (await page.locator("b.font-mono").first().textContent())?.trim() ?? "";
      await expectHealthy(page);
    } finally {
      await context.close();
    }
    expect(orderByNumber(orderNumber).total).toBe(200);
  });

  test("la empresa vendedora confirma el pago y se crea la licencia con 2 cupos", async ({ page, apiToken, companyId }) => {
    const o = orderByNumber(orderNumber);
    const paid = await payOrder(page.request, { token: apiToken!, company: companyId }, o.id, "transfer");
    expect(paid.data.delivered[0]).toMatchObject({ type: "license", seats: 2 });
    licenseId = one(`select course_license_id from order_items where order_id = ${lit(o.id)}`);
    expect(licenseId).not.toBe("");
    expect(one(`select seats_purchased || '/' || seats_used from course_licenses where id = ${lit(licenseId)}`)).toBe("2/0");
    rep = whoByEmail(mail("rep"), HUAYLAS);
  });

  test("el representante asigna hasta el límite, libera un cupo no iniciado y ve el avance", async ({ browser }) => {
    test.setTimeout(8 * 60_000);
    const { context, page } = await openAs(browser, rep!);
    try {
      await page.goto("/m/vta", { waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      await expect(page.getByRole("heading", { name: "Cupos de tu empresa" })).toBeVisible({ timeout: 120_000 });
      const seats = page.locator("section", { hasText: /^Cupos: / }).first();
      await expect(seats.getByText("Cupos: 0 asignados de 2 · 2 disponibles")).toBeVisible();

      await assign(page, "Ana", `Cupo ${sfx}`, mail("ana"));
      await expect(seats.getByText("Cupos: 1 asignados de 2 · 1 disponibles")).toBeVisible({ timeout: 60_000 });
      await assign(page, "Beto", `Cupo ${sfx}`, mail("beto"));
      await expect(seats.getByText("Cupos: 2 asignados de 2 · 0 disponibles")).toBeVisible({ timeout: 60_000 });
      // Sin cupos libres ya no se ofrece asignar y el servidor rechaza uno más.
      await expect(seats.getByRole("button", { name: "Asignar cupo" })).toHaveCount(0);
      const extra = await apiRaw(page.request, rep, `/vta/licenses/${licenseId}/assignments`, {
        method: "POST", data: { document_type: "DNI", document_number: dni(), first_name: "Ciro", last_name: "Sobra", email: mail("ciro") },
      });
      expect(extra.status).toBe(409);

      // Ana empieza el curso (40 %): su cupo ya no se libera; el de Beto sí.
      const ana = one(`select a.id from license_assignments a join external_learners l on l.id = a.external_learner_id where a.course_license_id = ${lit(licenseId)} and l.email = ${lit(mail("ana"))}`);
      startCourse(ana, 40);
      await page.reload({ waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      const anaRow = page.locator("li", { hasText: `Ana Cupo ${sfx}` });
      const betoRow = page.locator("li", { hasText: `Beto Cupo ${sfx}` });
      await expect(anaRow).toContainText("Avance 40 %", { timeout: 120_000 });
      await expect(anaRow.getByRole("button", { name: "Liberar" })).toHaveCount(0);
      const forced = await apiRaw(page.request, rep, `/vta/license-assignments/${ana}/release`, { method: "POST", data: { reason: "Prueba E2E" } });
      expect(forced.status).toBe(409);

      page.once("dialog", (d) => void d.accept("Ya no trabaja con nosotros"));
      await betoRow.getByRole("button", { name: "Liberar" }).click();
      await expect(page.getByText("Cupo liberado.")).toBeVisible({ timeout: 60_000 });
      await expect(betoRow).toContainText("Liberado");
      await expect(seats.getByText("Cupos: 1 asignados de 2 · 1 disponibles")).toBeVisible();

      // El cupo liberado se vuelve a usar.
      await assign(page, "Ciro", `Cupo ${sfx}`, mail("ciro"));
      await expect(seats.getByText("Cupos: 2 asignados de 2 · 0 disponibles")).toBeVisible({ timeout: 60_000 });
      await expect(page.locator("li", { hasText: `Ciro Cupo ${sfx}` })).toContainText("Avance 0 %");
      await expectHealthy(page);
    } finally {
      await context.close();
    }
    // La matrícula de Beto quedó cancelada; Ana y Ciro siguen matriculados.
    expect(one(`select count(*) from license_assignments where course_license_id = ${lit(licenseId)} and status <> 'revoked' and deleted_at is null`)).toBe("2");
  });
});

async function assign(page: Page, first: string, last: string, email: string) {
  const seats = page.locator("section", { hasText: /^Cupos: / }).first();
  await seats.getByLabel("Nombres").fill(first);
  await seats.getByLabel("Apellidos").fill(last);
  await seats.getByLabel("DNI").fill(dni());
  await seats.getByLabel("Correo").fill(email);
  const [res] = await Promise.all([
    page.waitForResponse((r) => /\/vta\/licenses\/[^/]+\/assignments$/.test(r.url()) && r.request().method() === "POST", { timeout: 60_000 }),
    seats.getByRole("button", { name: "Asignar cupo" }).click(),
  ]);
  expect(res.status(), await res.text()).toBe(201);
  await expect(seats.getByText("Cupo asignado y persona matriculada.")).toBeVisible({ timeout: 60_000 });
  await expect(seats.locator("li", { hasText: `${first} ${last}` })).toBeVisible({ timeout: 60_000 });
}

/** El participante inició el curso: matrícula en curso con su avance. */
function startCourse(assignmentId: string, pct: number) {
  one(`update course_enrollments set started_at = now(), status = 'in_progress', progress_pct = ${pct}
       where id = (select enrollment_id from license_assignments where id = ${lit(assignmentId)}) returning id`);
}
