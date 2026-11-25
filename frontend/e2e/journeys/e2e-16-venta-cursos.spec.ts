// E2E-16 (docs/12 §5) · Venta de cursos: catálogo público → compra con cupón → pago → matrícula automática →
// comprobante → portal del participante externo.
// La pasarela de pagos y el proveedor de comprobantes aún no están integrados (Proveedores y claves: «pendiente»; M-VTA
// RN-VTA-05/17): no hay simulador ni webhook que firmar. Esos pasos quedan como pruebas omitidas «requiere cuenta
// externa» y el pago se confirma con «Registrar pago» (pago fuera de la pasarela, M-VTA §6), que es el camino que la
// aplicación tiene hoy.

import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { apiRaw, openAs, uniq, uploadFile, who, whoByEmail } from "./helpers";
import { cleanupSales, dni, HUAYLAS, lit, one, orderByNumber, seedCoupon, seedOffer, slugSuffix, type Seeded } from "./_vta";

test.use({ user: "jefe" });

test.describe.serial("E2E-16 · Venta de cursos en el catálogo público", () => {
  const sfx = slugSuffix();
  const email = `e2e16.${sfx}@example.test`;
  const coupon = `E2E16${sfx}`.toUpperCase().slice(0, 20);
  let seeded: Seeded | null = null;
  let couponId: string | null = null;
  let orderNumber = "";

  test.beforeAll(() => {
    seeded = seedOffer(sfx, { title: `Trabajo en caliente ${uniq("E2E16")}` });
    couponId = seedCoupon(coupon, 20);
  });
  test.afterAll(() => cleanupSales(seeded, `e2e16.${sfx}@%`, couponId));

  test("catálogo → ficha → solicitud de compra con cupón (sin sesión)", async ({ browser }) => {
    test.setTimeout(6 * 60_000);
    const s = seeded!;
    const { context, page } = await openAs(browser, null);
    try {
      await page.goto(`/cursos/${HUAYLAS}`, { waitUntil: "domcontentloaded" });
      await expect(page.getByRole("heading", { level: 1 }).first()).toBeVisible({ timeout: 120_000 });
      await expectHealthy(page);
      const card = page.getByRole("link", { name: new RegExp(s.title.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")) }).first();
      await expect(card).toBeVisible({ timeout: 60_000 });
      await card.click();
      await expect(page).toHaveURL(new RegExp(`/cursos/${HUAYLAS}/${s.slug}$`), { timeout: 120_000 });
      await expect(page.getByRole("heading", { level: 1, name: s.title })).toBeVisible({ timeout: 120_000 });
      await waitForHydration(page, "form button[type=submit]");

      const form = page.locator("form").filter({ has: page.getByRole("button", { name: "Solicitar compra" }) });
      // Cupón inexistente: el servidor lo rechaza y no se crea el pedido.
      await form.getByLabel("Nombres").fill("Lucía");
      await form.getByLabel("Apellidos").fill("Prueba E2E");
      await form.getByLabel("Número").fill(dni());
      await form.getByLabel("Correo").fill(email);
      await form.getByLabel(/Cupón de descuento/).fill("NOEXISTE-E2E");
      await form.getByText(/Acepto que .* use mis datos/).click();
      await form.getByRole("button", { name: "Solicitar compra" }).click();
      await expect(form.locator("span", { hasText: /^● / }).first()).toBeVisible({ timeout: 60_000 });
      await expect(page.getByRole("heading", { name: "Recibimos tu pedido" })).toHaveCount(0);

      // Cupón válido: 20 % sobre S/ 100.
      await form.getByLabel(/Cupón de descuento/).fill(coupon);
      await form.getByRole("button", { name: "Solicitar compra" }).click();
      await expect(page.getByRole("heading", { name: "Recibimos tu pedido" })).toBeVisible({ timeout: 60_000 });
      await expect(page.getByText(/Cupón aplicado: descuento de S\/\s?20/)).toBeVisible();
      orderNumber = (await page.locator("b.font-mono").first().textContent())?.trim() ?? "";
      expect(orderNumber).not.toBe("");
      await expectHealthy(page);
    } finally {
      await context.close();
    }
    const o = orderByNumber(orderNumber);
    expect(o.status).toBe("pending");
    expect(o.total).toBe(80);
    expect(o.discount).toBe(20);
    expect(o.coupon).toBe(couponId);
  });

  test("pago en el simulador de la pasarela y webhook firmado", async () => {
    test.skip(true, "requiere cuenta externa: la pasarela de pagos aún no está integrada (M-VTA RN-VTA-05/06; Proveedores y claves la marca «pendiente»), no hay simulador ni webhook /webhooks/payments que firmar");
  });

  test("la empresa registra el pago y se matricula sola al participante", async ({ page, apiToken, companyId }) => {
    test.setTimeout(6 * 60_000);
    const o = orderByNumber(orderNumber);
    await page.goto(`/m/vta?t=orders&open=${o.id}`, { waitUntil: "domcontentloaded" });
    await settle(page, 180_000);
    const pay = page.locator("div", { has: page.getByRole("button", { name: "Registrar pago" }) }).last();
    await expect(pay).toBeVisible({ timeout: 120_000 });
    await pay.locator("select").first().selectOption("yape");
    await pay.getByLabel(/N\.° de operación/).fill(`OP${sfx}`.slice(0, 20));
    await pay.locator("input[type=file]").setInputFiles({ name: "constancia.pdf", mimeType: "application/pdf", buffer: Buffer.from("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n") });
    await expect(pay.getByText(/Adjunta: constancia\.pdf/)).toBeVisible({ timeout: 60_000 });
    await pay.getByRole("button", { name: "Registrar pago" }).click();
    await expect(page.getByText(/Pago registrado: .*matriculado/)).toBeVisible({ timeout: 60_000 });
    await expectHealthy(page);

    // Una matrícula del participante externo, el cupón contado una sola vez.
    const after = orderByNumber(orderNumber);
    expect(after.status).toMatch(/^(paid|fulfilled)$/);
    expect(one(`select count(*) from course_enrollments e join order_items i on i.id = e.order_item_id where i.order_id = ${lit(o.id)} and e.deleted_at is null`)).toBe("1");
    expect(one(`select redemptions_count from coupons where id = ${lit(couponId!)}`)).toBe("1");
    // Registrar el pago otra vez no duplica nada.
    const again = await apiRaw(page.request, { token: apiToken!, company: companyId }, `/vta/orders/${o.id}/pay`, {
      method: "POST", data: { method: "yape", reference: "OTRA", evidence_file_id: await uploadFile(page.request, who("jefe"), "VTA") },
    });
    expect(again.status).toBeGreaterThanOrEqual(400);
    expect(one(`select count(*) from course_enrollments e join order_items i on i.id = e.order_item_id where i.order_id = ${lit(o.id)} and e.deleted_at is null`)).toBe("1");
  });

  test("comprobante electrónico del pedido", async () => {
    test.skip(true, "requiere cuenta externa: el proveedor de comprobantes (OSE) aún no está integrado (M-VTA RN-VTA-17; el portal lo indica como pendiente)");
  });

  test("portal del participante externo: su pedido, su curso y sus datos", async ({ browser }) => {
    test.setTimeout(6 * 60_000);
    const learner = whoByEmail(email, HUAYLAS);
    const { context, page } = await openAs(browser, learner);
    try {
      await page.goto("/m/vta", { waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      await expect(page.getByText("Mis pedidos")).toBeVisible({ timeout: 120_000 });
      await expect(page.getByText(new RegExp(`${orderNumber}.*Pagado`))).toBeVisible();
      await expect(page.getByText("Los comprobantes electrónicos se mostrarán aquí")).toBeVisible();
      await expect(page.getByText("Mis datos")).toBeVisible();
      await expect(page.getByText(new RegExp(`Lucía Prueba E2E · ${email.replace(/\./g, "\\.")}`))).toBeVisible();
      await expectHealthy(page);

      // Su curso aparece en el aula virtual.
      await page.getByRole("link", { name: "Aula virtual" }).first().click();
      await settle(page, 180_000);
      await expect(page.getByText(seeded!.title).first()).toBeVisible({ timeout: 120_000 });
      await expectHealthy(page);
    } finally {
      await context.close();
    }
  });
});
