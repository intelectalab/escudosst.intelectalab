// E2E-18 (docs/12 §5) · Reembolso total → nota de crédito → matrícula cancelada → certificado anulado → verificación
// pública «anulado» (M-VTA RN-VTA-18). La nota de crédito depende del proveedor de comprobantes, que aún no está
// integrado: ese paso queda omitido «requiere cuenta externa» y se comprueba que la aplicación lo informa como pendiente.

import { expect, expectHealthy, settle, test } from "../fixtures";
import { apiRaw, openAs, whoByEmail } from "./helpers";
import { cleanupSales, HUAYLAS, lit, one, row, seedCertificate, seedOffer, sellAndPay, slugSuffix, type Seeded } from "./_vta";

test.use({ user: "jefe" });

test.describe.serial("E2E-18 · Reembolso total", () => {
  const sfx = slugSuffix();
  const email = `e2e18.${sfx}@example.test`;
  let seeded: Seeded | null = null;
  let order = { id: "", order_number: "", total: 0 };
  let enrollment = "";
  let cert = { id: "", verification: "" };

  test.beforeAll(() => {
    seeded = seedOffer(sfx, { title: `Uso de extintores E2E ${sfx}` });
  });
  test.afterAll(() => cleanupSales(seeded, `e2e18.${sfx}@%`));

  test("compra pagada, curso aprobado y certificado verificable «válido»", async ({ page, browser, apiToken, companyId }) => {
    test.setTimeout(6 * 60_000);
    const sale = await sellAndPay(page.request, { token: apiToken!, company: companyId }, seeded!.offer, { email, first: "Rosa", last: `Reembolso ${sfx}` });
    order = sale.order;
    enrollment = sale.enrollment;
    cert = seedCertificate(seeded!, sale.enrollment, sale.learner, `Rosa Reembolso ${sfx}`);

    const { context, page: pub } = await openAs(browser, null);
    try {
      await pub.goto(`/verificar/certificado/${cert.verification}`, { waitUntil: "domcontentloaded" });
      await expect(pub.getByText("Certificado válido")).toBeVisible({ timeout: 120_000 });
      await expect(pub.getByText(seeded!.title).first()).toBeVisible();
      await expectHealthy(pub);
    } finally {
      await context.close();
    }
  });

  test("la empresa registra el reembolso total desde el pedido", async ({ page, apiToken, companyId }) => {
    test.setTimeout(6 * 60_000);
    // Más de lo cobrado no se acepta.
    const over = await apiRaw(page.request, { token: apiToken!, company: companyId }, `/vta/orders/${order.id}/refund`, { method: "POST", data: { amount: order.total + 1, reason: "Prueba E2E" } });
    expect(over.status).toBe(422);

    await page.goto(`/m/vta?t=orders&open=${order.id}`, { waitUntil: "domcontentloaded" });
    await settle(page, 180_000);
    const box = page.locator("div", { has: page.getByRole("button", { name: "Registrar reembolso" }) }).filter({ hasText: "Queda por devolver" }).last();
    await expect(box).toBeVisible({ timeout: 120_000 });
    await expect(box.getByText(`Queda por devolver S/ ${order.total.toFixed(2)}`)).toBeVisible();
    await expect(box.getByLabel("Importe (S/)")).toHaveValue(order.total.toFixed(2));
    await box.getByLabel("Motivo").fill("El participante desistió antes de empezar");
    page.once("dialog", (d) => {
      expect(d.message()).toContain("Se cancelará la matrícula y se anulará el certificado");
      void d.accept();
    });
    await box.getByRole("button", { name: "Registrar reembolso" }).click();
    await expect(page.getByText(/Reembolso total registrado: 1 matrícula\(s\) cancelada\(s\) y 1 certificado\(s\) anulado\(s\)/)).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText("La nota de crédito queda pendiente del proveedor de comprobantes.")).toBeVisible();
    await expectHealthy(page);

    const [st, refunded] = row(`select status, refunded_total from orders where id = ${lit(order.id)}`);
    expect(st).toBe("refunded");
    expect(Number(refunded)).toBe(order.total);
    expect(one(`select status from course_enrollments where id = ${lit(enrollment)}`)).toBe("cancelled");
    expect(one(`select status from training_certificates where id = ${lit(cert.id)}`)).toBe("voided");
    // Un segundo reembolso ya no procede.
    const again = await apiRaw(page.request, { token: apiToken!, company: companyId }, `/vta/orders/${order.id}/refund`, { method: "POST", data: { amount: 1, reason: "Otra vez" } });
    expect(again.status).toBeGreaterThanOrEqual(400);
  });

  test("nota de crédito electrónica del reembolso", async () => {
    test.skip(true, "requiere cuenta externa: el proveedor de comprobantes (OSE) aún no está integrado (M-VTA RN-VTA-17); la aplicación informa la nota de crédito como pendiente");
  });

  test("la verificación pública muestra «anulado» y el portal el pedido reembolsado", async ({ browser }) => {
    test.setTimeout(6 * 60_000);
    const pub = await openAs(browser, null);
    try {
      await pub.page.goto(`/verificar/certificado/${cert.verification}`, { waitUntil: "domcontentloaded" });
      await expect(pub.page.getByText("Certificado anulado")).toBeVisible({ timeout: 120_000 });
      await expect(pub.page.getByText("Certificado válido")).toHaveCount(0);
      await expectHealthy(pub.page);
    } finally {
      await pub.context.close();
    }

    const learner = await openAs(browser, whoByEmail(email, HUAYLAS));
    try {
      await learner.page.goto("/m/vta", { waitUntil: "domcontentloaded" });
      await settle(learner.page, 180_000);
      await expect(learner.page.getByText(new RegExp(`${order.order_number}.*Reembolsado`))).toBeVisible({ timeout: 120_000 });
      await expectHealthy(learner.page);
    } finally {
      await learner.context.close();
    }
  });
});
