// E2E-07 (docs/12 §5): capacitación con asistencia firmada → constancia → fotocheck pasa a habilitado → verificación
// pública verde. El fotocheck exige esa capacitación: antes del certificado la verificación pública dice «No habilitado»;
// el participante firma su asistencia en la pantalla, se emite el certificado y el fotocheck queda «Habilitado».

import { expect, expectHealthy, test } from "../fixtures";
import { api, openAs, uniq, who } from "./helpers";
import { demoSite, fillForm, openChild, openModule, openNew, openRecord, recordsSection, sqlIn, submitNew, topFicha } from "./b-records";

test.use({ user: "jefe" });

/** DNI ficticio de 8 dígitos que empieza en 9 (no choca con los de la demostración). */
function fakeDni(): string {
  return `9${String(Math.floor(Math.random() * 10_000_000)).padStart(7, "0")}`;
}

test("E2E-07 capacitación firmada, certificado y fotocheck habilitado", async ({ page, request, browser }) => {
  test.setTimeout(12 * 60_000);
  const jefe = who("jefe");
  const company = jefe.company!;
  const tag = uniq("E2E07");
  const suffix = tag.split(" ")[1];
  const site = demoSite(company);
  const created: Record<string, string> = {};

  try {
    // Trabajador propio del recorrido (no se tocan los de la demostración).
    const worker = await api<{ data: { id: string } }>(request, jefe, "/records/TRB/workers", {
      method: "POST",
      data: { document_number: fakeDni(), first_name: "Participante", paternal_last_name: `Recorrido ${suffix}` },
    });
    created.worker = worker.data.id;

    await test.step("programa la capacitación en la pantalla", async () => {
      await openModule(page, "CAP");
      const section = await recordsSection(page, "Capacitaciones");
      const form = await openNew(page, section, "Programar capacitación");
      await fillForm(form, { Título: `${tag} Trabajo en caliente`, "Tipo de capacitación": "Específico", "Duración (min)": "120", "Vigencia del certificado (meses)": "12" });
      await submitNew(form);
      const list = await api<{ data: { id: string; code: string }[] }>(request, jefe, `/records/CAP/trainings?q=${encodeURIComponent(tag)}`);
      expect(list.data).toHaveLength(1);
      created.training = list.data[0].id;
      created.trainingCode = list.data[0].code;
    });

    let verifyToken = "";
    await test.step("el fotocheck exige la capacitación: verificación pública «No habilitado»", async () => {
      const type = await api<{ data: { id: string } }>(request, jefe, "/records/FOT/credential_types", {
        method: "POST",
        data: { code: `E7${suffix}`.slice(0, 20), name: `${tag} Fotocheck de obra`, applies_to: "workers", validity_months: 12 },
      });
      created.type = type.data.id;
      await api(request, jefe, "/records/FOT/credential_requirements", {
        method: "POST",
        data: { credential_type_id: type.data.id, requirement_type: "training", training_id: created.training, description: `${tag} Trabajo en caliente vigente`, is_blocking: true },
      });
      const cred = await api<{ data: { id: string } }>(request, jefe, "/records/FOT/credentials", { method: "POST", data: { credential_type_id: type.data.id, worker_id: created.worker } });
      created.credential = cred.data.id;
      const print = await api<{ data: { verify_url: string } }>(request, jefe, `/fot/credentials/${cred.data.id}/print`);
      verifyToken = print.data.verify_url.split("/").pop()!;

      const visitor = await openAs(browser, null);
      await visitor.page.goto(`/verificar/fotocheck/${verifyToken}`, { waitUntil: "domcontentloaded" });
      await expect(visitor.page.getByText("No habilitado", { exact: true })).toBeVisible({ timeout: 90_000 });
      await visitor.context.close();
    });

    await test.step("sesión con asistencia firmada por el participante", async () => {
      const session = await api<{ data: { id: string } }>(request, jefe, "/records/CAP/training_sessions", {
        method: "POST",
        data: { training_id: created.training, session_number: 1, starts_at: new Date(Date.now() - 3 * 3_600_000).toISOString(), ends_at: new Date(Date.now() - 3_600_000).toISOString(), site_id: site.id },
      });
      await api(request, jefe, "/records/CAP/training_attendances", { method: "POST", data: { session_id: session.data.id, worker_id: created.worker, hours: 2, check_in_method: "list" } });

      await openModule(page, "CAP");
      const training = await openRecord(page, await recordsSection(page, "Capacitaciones"), created.trainingCode);
      const sess = await openChild(page, training, "training_sessions");
      const att = await openChild(page, sess, "training_attendances");
      const pad = att.getByLabel("Espacio para dibujar la firma");
      await pad.scrollIntoViewIfNeeded();
      const box = (await pad.boundingBox())!;
      await page.mouse.move(box.x + 20, box.y + box.height / 2);
      await page.mouse.down();
      for (let i = 1; i <= 12; i++) await page.mouse.move(box.x + 20 + i * 15, box.y + box.height / 2 + (i % 2 ? 20 : -20), { steps: 3 });
      await page.mouse.up();
      await att.getByRole("button", { name: "Guardar firma" }).click();
      await expect(att.getByRole("status").filter({ hasText: "Firma registrada" }).or(att.getByText("Asistencia firmada por el participante."))).toBeVisible({ timeout: 60_000 });
      await att.getByRole("button", { name: "Cancelar", exact: true }).click();
      await expect(att.getByLabel("Espacio para dibujar la firma")).toBeHidden();

      // La sesión se marca como realizada en su ficha.
      const sessAgain = topFicha(page);
      await sessAgain.locator('[aria-label="Partes de la ficha"]').getByRole("tab", { name: "Resumen" }).click();
      await expect(sessAgain.getByRole("button", { name: "Marcar como realizada" })).toBeVisible({ timeout: 60_000 });
      await sessAgain.getByRole("button", { name: "Marcar como realizada" }).click();
      await expect.poll(async () => (await api<{ data: { status: string } }>(request, jefe, `/records/CAP/training_sessions/${session.data.id}`)).data.status, { timeout: 60_000 }).toBe("held");
      await expect(sessAgain.getByRole("button", { name: "Marcar como realizada" })).toBeHidden({ timeout: 60_000 });
      await sessAgain.getByRole("button", { name: "Cancelar", exact: true }).click();
    });

    let certId = "";
    await test.step("emite el certificado (constancia) desde la capacitación", async () => {
      const training = topFicha(page);
      await training.locator('[aria-label="Partes de la ficha"]').getByRole("tab", { name: "Resumen" }).click();
      await expect(training.getByRole("button", { name: "Emitir certificados" })).toBeVisible({ timeout: 60_000 });
      await training.getByRole("button", { name: "Emitir certificados" }).click();
      await expect(training.getByText(/1 certificado\(s\) emitido\(s\)/)).toBeVisible({ timeout: 60_000 });
      const certs = await api<{ data: { id: string; certificate_code: string; status: string }[] }>(request, jefe, `/records/CAP/training_certificates?f[training_id]=${created.training}`);
      expect(certs.data).toHaveLength(1);
      certId = certs.data[0].id;
      expect(certs.data[0].certificate_code).toMatch(/^CERT-\d{4}-\d{6}$/);
      expect(certs.data[0].status).toBe("validated");
      await page.keyboard.press("Escape");

      // La constancia imprimible lleva el nombre del participante.
      await page.goto(`/imprimir/certificado/${certId}`, { waitUntil: "domcontentloaded" });
      await expect(page.getByText(`Recorrido ${suffix}`).first()).toBeVisible({ timeout: 90_000 });
      await expect(page.getByText(certs.data[0].certificate_code).first()).toBeVisible();
    });

    await test.step("el fotocheck pasa a habilitado y la verificación pública es verde", async () => {
      await openModule(page, "FOT");
      const section = await recordsSection(page, "Fotochecks");
      const ficha = await openRecord(page, section, `Recorrido ${suffix}`);
      await ficha.getByRole("button", { name: "Reevaluar requisitos" }).click();
      await expect.poll(async () => (await api<{ data: { blocking_reasons: unknown } }>(request, jefe, `/records/FOT/credentials/${created.credential}`)).data.blocking_reasons, { timeout: 60_000 }).toEqual([]);
      await expect(ficha.getByText(/Bloqueado por:/)).toHaveCount(0);
      await page.keyboard.press("Escape");

      const visitor = await openAs(browser, null);
      await visitor.page.goto(`/verificar/fotocheck/${verifyToken}`, { waitUntil: "domcontentloaded" });
      const ok = visitor.page.getByText("Habilitado para ingresar", { exact: true });
      await expect(ok).toBeVisible({ timeout: 90_000 });
      await expect(ok).toHaveClass(/17703e/);
      await expect(visitor.page.getByText(`Participante Recorrido ${suffix}`)).toBeVisible();
      await expectHealthy(visitor.page);
      await visitor.context.close();
    });
  } finally {
    // Retira de la demostración lo creado (borrado lógico directo en la base).
    const del = (table: string, id?: string) => (id ? `update ${table} set deleted_at = now() where id = '${id}' and deleted_at is null;` : "");
    try {
      sqlIn(
        company,
        [
          created.training ? `update training_certificates set deleted_at = now() where training_id = '${created.training}';` : "",
          del("credentials", created.credential),
          created.type ? `update credential_requirements set deleted_at = now() where credential_type_id = '${created.type}';` : "",
          del("credential_types", created.type),
          del("trainings", created.training),
          del("workers", created.worker),
        ].join(" "),
      );
    } catch {
      /* queda marcado con el sufijo E2E07 */
    }
  }
});
