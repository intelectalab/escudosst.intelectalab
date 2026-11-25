// E2E-08 (docs/12 §5): EMO → programar → resultados (médico con re-autenticación) → aptitud visible para RR. HH. sin
// diagnóstico → registro en sensitive_access_logs.
// El médico ocupacional activa su segundo factor por la API (así se conoce el secreto TOTP), programa el examen en la
// pantalla, confirma su identidad (contraseña + código de la app + motivo) y registra el resultado con un diagnóstico y
// la aptitud. Una persona de RR. HH. (creada para la prueba) ve «Apto» en «Estados de aptitud», nunca el diagnóstico.
// Al terminar se quita el segundo factor del médico (como estaba en la demostración) y se retira lo creado.

import { expect, expectHealthy, test } from "../fixtures";
import { api, apiRaw, artisan, day, freshTotpWindow, openAs, PASSWORD, totp, uniq, who, whoByEmail } from "./helpers";
import { dropTestUser, ensureTestUser, fillForm, localDateTime, openModule, openNew, openRecord, recordsSection, sqlIn, submitNew, topFicha } from "./b-records";

const DOCTOR = "rev-c-medico@escudo.test";
const HR_EMAIL = "e2e.rrhh@escudo.test";

test.use({ user: "medico" });

/** Activa el segundo factor del médico por el ingreso real (login → setup → confirm) y devuelve el secreto TOTP. */
async function enrollDoctorMfa(request: import("@playwright/test").APIRequestContext): Promise<string> {
  artisan("escudo:mfa-reset", DOCTOR);
  const login = await api<{ mfa_required: boolean; mfa_setup_required: boolean; mfa_challenge_id: string }>(request, null, "/auth/login", { method: "POST", data: { email: DOCTOR, password: PASSWORD } });
  expect(login.mfa_required && login.mfa_setup_required, "el médico ocupacional debe configurar el segundo factor").toBe(true);
  const setup = await api<{ secret: string }>(request, null, "/auth/mfa/setup", { method: "POST", data: { mfa_challenge_id: login.mfa_challenge_id } });
  await freshTotpWindow();
  await api(request, null, "/auth/mfa/confirm", { method: "POST", data: { mfa_challenge_id: login.mfa_challenge_id, code: totp(setup.secret) } });
  return setup.secret;
}

test("E2E-08 EMO: programar, resultado con re-autenticación, aptitud para RR. HH. y registro de acceso", async ({ page, request, browser }) => {
  test.setTimeout(12 * 60_000);
  const jefe = who("jefe");
  const medico = who("medico");
  const company = jefe.company!;
  const tag = uniq("E2E08");
  const suffix = tag.split(" ")[1];
  const lastName = `Examen ${suffix}`;
  let workerId = "";
  let orderId = "";

  try {
    const secret = await enrollDoctorMfa(request);
    const worker = await api<{ data: { id: string } }>(request, jefe, "/records/TRB/workers", {
      method: "POST",
      data: { document_number: `9${String(Math.floor(Math.random() * 10_000_000)).padStart(7, "0")}`, first_name: "Paciente", paternal_last_name: lastName },
    });
    workerId = worker.data.id;

    await test.step("el médico programa el EMO en la pantalla", async () => {
      await openModule(page, "EMO");
      const section = await recordsSection(page, "Órdenes de examen");
      const form = await openNew(page, section, "Programar EMO");
      await fillForm(form, { Trabajador: lastName, "Tipo de examen": "Periódico", Clínica: `${tag} Clínica Ocupacional`, "Programado para": localDateTime(-1) });
      await submitNew(form);
      const list = await api<{ data: { id: string; status: string }[] }>(request, medico, `/records/EMO/medical_exam_orders?f[worker_id]=${workerId}`);
      expect(list.data).toHaveLength(1);
      expect(list.data[0].status).toBe("scheduled");
      orderId = list.data[0].id;
    });

    await test.step("sin re-autenticación no hay datos clínicos; el Jefe SST nunca los ve", async () => {
      const noReauth = await apiRaw(request, medico, `/emo/orders/${orderId}/clinical`);
      expect(noReauth.status).toBe(403);
      expect(noReauth.body.type).toBe("reauth_required");
      expect((await apiRaw(request, jefe, `/emo/orders/${orderId}/clinical`)).status).toBe(403);
    });

    await test.step("marca atendido, confirma su identidad y registra el resultado con la aptitud", async () => {
      const ficha = await openRecord(page, await recordsSection(page, "Órdenes de examen"), lastName);
      await ficha.getByRole("button", { name: "Marcar atendido" }).click();
      await expect.poll(async () => (await api<{ data: { status: string } }>(request, medico, `/records/EMO/medical_exam_orders/${orderId}`)).data.status, { timeout: 60_000 }).toBe("attended");

      const f = topFicha(page);
      await expect(f.getByLabel("Contraseña", { exact: true })).toBeVisible({ timeout: 60_000 });
      await f.getByLabel("Contraseña", { exact: true }).fill(PASSWORD);
      // El código de la siguiente ventana (el de la ventana actual ya se usó al activar el segundo factor).
      await f.getByLabel("Código de la app").fill(totp(secret, 1));
      await f.getByLabel("Motivo de la consulta").fill(`${tag} Resultado del examen periódico`);
      await f.getByRole("button", { name: "Ver datos médicos" }).click();
      await expect(f.getByRole("heading", { name: "Registrar resultado" })).toBeVisible({ timeout: 60_000 });

      await f.locator("label", { hasText: "Médico examinador" }).locator("input").fill("Dra. Prueba Ocupacional");
      await f.locator("label", { hasText: "CMP" }).locator("input").fill("54321");
      await f.getByLabel("Resultado", { exact: true }).first().fill("Normal");
      await f.getByRole("button", { name: "+ Agregar diagnóstico" }).click();
      await f.getByLabel("Código CIE-10").fill("Z00.0");
      await f.getByLabel("Descripción", { exact: true }).last().fill(`${tag} Examen médico general`);
      await f.locator("label", { hasText: "Aptitud" }).locator("select").selectOption("fit");
      await f.locator("label", { hasText: "Vigente hasta" }).locator("input").fill(day(365));
      await f.getByRole("button", { name: "Guardar resultado" }).click();
      await expect.poll(async () => (await api<{ data: { status: string } }>(request, medico, `/records/EMO/medical_exam_orders/${orderId}`)).data.status, { timeout: 60_000 }).toBe("fitness_issued");
      await expectHealthy(page);
    });

    await test.step("RR. HH. ve la aptitud sin el diagnóstico", async () => {
      ensureTestUser(HR_EMAIL, "Rosa Recursos Humanos (prueba)", "hr", company);
      const hr = whoByEmail(HR_EMAIL, company);
      const fit = await api<{ data: Record<string, unknown>[] }>(request, hr, `/records/EMO/fitness_status?f[worker_id]=${workerId}`);
      expect(fit.data).toHaveLength(1);
      expect(fit.data[0].status).toBe("fit");
      expect(JSON.stringify(fit.data), "la aptitud no lleva diagnósticos").not.toMatch(/Z00|Examen médico general|diagnos/i);
      expect((await apiRaw(request, hr, `/emo/orders/${orderId}/clinical`)).status, "RR. HH. no entra a los datos clínicos").toBe(403);

      const { context, page: hrPage } = await openAs(browser, hr);
      await openModule(hrPage, "EMO");
      const section = await recordsSection(hrPage, "Estados de aptitud");
      await section.getByPlaceholder("Buscar por código, nombre o texto…").fill(lastName);
      const row = section.locator("tbody tr").filter({ hasText: lastName }).first();
      await expect(row).toBeVisible({ timeout: 90_000 });
      await expect(row).toContainText("Apto");
      await expect(hrPage.locator("main")).not.toContainText("Z00");
      await expect(hrPage.locator("main")).not.toContainText("Examen médico general");
      await expectHealthy(hrPage);
      await context.close();
    });

    await test.step("los accesos a los datos médicos quedan en sensitive_access_logs", async () => {
      const n = Number(sqlIn(company, `select count(*) from sensitive_access_logs where worker_id = '${workerId}' and result = 'granted'`));
      expect(n, "accesos concedidos del médico al examen").toBeGreaterThan(0);
      const purpose = sqlIn(company, `select count(*) from sensitive_access_logs where purpose like '${tag}%'`);
      expect(Number(purpose), "el motivo escrito queda en el registro").toBeGreaterThan(0);
    });
  } finally {
    // Como estaba en la demostración: el médico sin segundo factor; la persona de RR. HH. y el trabajador retirados.
    try {
      artisan("escudo:mfa-reset", DOCTOR);
    } catch {
      /* se puede quitar a mano con escudo:mfa-reset */
    }
    try {
      dropTestUser(HR_EMAIL);
      if (workerId) sqlIn(company, `update fitness_status set deleted_at = now() where worker_id = '${workerId}'; update medical_exam_orders set deleted_at = now() where worker_id = '${workerId}'; update workers set deleted_at = now() where id = '${workerId}'`);
    } catch {
      /* queda marcado con el sufijo E2E08 */
    }
  }
});
