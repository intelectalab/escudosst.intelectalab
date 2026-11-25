// E2E-15 (docs/12 §5, M-AUL y M-CAP): el administrador crea un curso con contenido y una evaluación de preguntas al azar,
// lo liga al tema de CAP que acredita (con una plantilla de certificado que exige validación) y matricula por la brecha de
// la matriz (todos los del puesto que lo exige) → el trabajador cursa en su app con permanencia real (latidos con
// interacción), rinde y aprueba → el certificado nace «por validar» → el Jefe SST lo valida en CAP → la verificación
// pública por código dice «Certificado válido» → el fotocheck que exige esa capacitación queda habilitado y se emite.

import type { Page } from "@playwright/test";
import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { api, apiRaw, DEMO_COMPANY, openAs, psql, uniq, who } from "./helpers";

test.use({ user: "jefe" });

const LEARNER_WORKER = () => psql("select m.worker_id from users u join memberships m on m.user_id = u.id and m.deleted_at is null where u.email = 'rev-c-trabajador@escudo.test' and m.worker_id is not null limit 1");

/** Mueve el mouse y desplaza la página durante `seconds` (interacción real para que los latidos sumen permanencia). */
async function study(page: Page, seconds: number) {
  const end = Date.now() + seconds * 1000;
  let i = 0;
  while (Date.now() < end) {
    await page.mouse.move(400 + (i % 5) * 40, 300 + (i % 3) * 30);
    await page.mouse.wheel(0, i % 2 ? 60 : -60);
    i++;
    await page.waitForTimeout(2_500);
  }
}

test("E2E-15 · aula: curso → matrícula por brecha → cursar con permanencia → certificado validado → verificación pública y fotocheck", async ({ page, browser, request }) => {
  test.setTimeout(15 * 60_000);
  const jefe = who("jefe");
  const tag = uniq("E2E-15");
  const code = tag.replace(/\s+/g, "-");
  const worker = LEARNER_WORKER();
  expect(worker, "el trabajador de prueba tiene ficha en la empresa").toMatch(/^[0-9a-f-]{36}$/);
  const oldPosition = psql(`select coalesce(current_position_id::text, '') from workers where id = '${worker}'`);
  const ids: Record<string, string> = {};

  try {
    await test.step("prepara el tema de CAP, la plantilla con validación, el curso y la evaluación", async () => {
      ids.template = (await api<{ data: { id: string } }>(request, jefe, "/records/CAP/certificate_templates", {
        method: "POST", data: { code, name: `${tag} plantilla con validación`, body_template: "Se certifica que {participante} aprobó {curso}.", requires_validation: true, code_prefix: "CERT", status: "active" },
      })).data.id;
      ids.course = (await api<{ data: { id: string } }>(request, jefe, "/aul/admin/courses", {
        method: "POST", data: { title: `${tag} Trabajo en altura`, hours: 0.02, min_active_time_pct: 50, passing_score: 70, max_attempts: 3, sequential: true,
          certificate_enabled: true, certificate_validity_months: 12, public_verification_enabled: true },
      })).data.id;
      ids.training = (await api<{ data: { id: string } }>(request, jefe, "/records/CAP/trainings", {
        method: "POST", data: { title: `${tag} Trabajo en altura`, training_type: "specific", modality: "virtual", duration_minutes: 60, course_id: ids.course,
          certificate_template_id: ids.template, certificate_validity_months: 12, public_verification_enabled: true },
      })).data.id;
      const section = (await api<{ data: { id: string } }>(request, jefe, `/aul/admin/courses/${ids.course}/sections`, { method: "POST", data: { title: "Contenido" } })).data.id;
      await api(request, jefe, `/aul/admin/courses/${ids.course}/lessons`, {
        method: "POST", data: { section_id: section, lesson_type: "text", title: "Antes de subir", content_html: "<p>Usa arnés con doble línea de vida y revisa el punto de anclaje.</p>" },
      });
      const exam = (await api<{ data: { id: string; question_bank_id: string } }>(request, jefe, `/aul/admin/courses/${ids.course}/lessons`, {
        method: "POST", data: { section_id: section, lesson_type: "exam", title: "Evaluación final", num_questions: 2 },
      })).data;
      ids.exam = exam.id;
      ids.bank = exam.question_bank_id;
      for (let i = 1; i <= 4; i++) {
        const q = (await api<{ data: { id: string } }>(request, jefe, "/records/AUL/questions", {
          method: "POST", data: { question_bank_id: exam.question_bank_id, code: `${code}-P${i}`, question_type: "single_choice", statement: `${tag} pregunta ${i}`, points: 1, status: "active" },
        })).data.id;
        await api(request, jefe, `/aul/questions/${q}/options`, { method: "PUT", data: { options: [{ text: "Correcta", correct: true }, { text: "Incorrecta", correct: false }] } });
      }
      const pub = await api<{ data: { status: string } }>(request, jefe, `/aul/admin/courses/${ids.course}/publish`, { method: "POST", data: { publish: true } });
      expect(pub.data.status).toBe("published");
    });

    await test.step("la matriz exige el curso al puesto del trabajador y el fotocheck exige el tema", async () => {
      ids.position = (await api<{ data: { id: string } }>(request, jefe, "/records/TEN/positions", { method: "POST", data: { code, name: `${tag} Montajista en altura` } })).data.id;
      psql(`update workers set current_position_id = '${ids.position}' where id = '${worker}'`);
      ids.requirement = (await api<{ data: { id: string } }>(request, jefe, "/records/CAP/position_training_requirements", {
        method: "POST", data: { position_id: ids.position, course_id: ids.course, requirement_level: "mandatory", is_active: true },
      })).data.id;
      ids.ctype = (await api<{ data: { id: string } }>(request, jefe, "/records/FOT/credential_types", {
        method: "POST", data: { code, name: `${tag} Fotocheck de altura`, applies_to: "workers", validity_months: 12, is_active: true },
      })).data.id;
      await api(request, jefe, "/records/FOT/credential_requirements", {
        method: "POST", data: { credential_type_id: ids.ctype, requirement_type: "training", training_id: ids.training, description: `${tag} Trabajo en altura vigente`, is_blocking: true },
      });
      const ev = await api<{ data: { overall: string } }>(request, jefe, `/fot/evaluate?worker_id=${worker}&credential_type_id=${ids.ctype}`);
      expect(ev.data.overall, "sin el curso, el fotocheck no está habilitado").toBe("bad");
    });

    await test.step("el administrador matricula por puesto (brecha de la matriz)", async () => {
      await page.goto(`/m/aul/${ids.course}`, { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      await expect(page.getByRole("heading", { level: 1, name: `${tag} Trabajo en altura` })).toBeVisible();
      await page.getByRole("tablist", { name: "Secciones del curso" }).getByRole("tab", { name: /^Participantes/ }).click();
      await page.getByRole("button", { name: "Matricular por puesto o área" }).click();
      const modal = page.getByRole("dialog", { name: "Matricular por puesto o área" });
      await expect(modal).toBeVisible();
      // Con muchos puestos aparece el buscador; con pocos, la lista ya trae el puesto.
      const search = modal.getByLabel("Buscar puesto");
      await modal.getByRole("combobox", { name: "Puesto", exact: true }).locator("option").nth(1).waitFor({ state: "attached", timeout: 30_000 });
      if (await search.count()) await search.fill(code);
      const select = modal.getByRole("combobox", { name: "Puesto", exact: true });
      await expect(select.locator(`option[value="${ids.position}"]`)).toBeAttached({ timeout: 30_000 });
      await select.selectOption(ids.position);
      await expect(modal.getByText("Se matricularán")).toBeVisible();
      await modal.getByRole("button", { name: "Matricular (1)" }).click();
      await expect(modal, (await modal.textContent().catch(() => "")) ?? "").toBeHidden({ timeout: 30_000 }).catch(async (e) => {
        throw new Error(`${e.message}
Ventana: ${await modal.textContent()}`);
      });
      await expect(page.getByRole("tab", { name: "Participantes (1)" })).toBeVisible();
      ids.enrollment = psql(`select id from course_enrollments where course_id = '${ids.course}' and worker_id = '${worker}' and deleted_at is null`);
      expect(ids.enrollment).toMatch(/^[0-9a-f-]{36}$/);
    });

    const certificate = await test.step("el trabajador cursa con permanencia real y aprueba la evaluación sorteada", async () => {
      const { context, page: wp } = await openAs(browser, who("trabajador"));
      try {
        await wp.goto(`/mi/cursos/${ids.enrollment}`, { waitUntil: "domcontentloaded" });
        await waitForHydration(wp);
        await expect(wp.getByRole("heading", { name: `${tag} Trabajo en altura` })).toBeVisible({ timeout: 120_000 });
        await expect(wp.getByRole("heading", { name: "Antes de subir" })).toBeVisible();
        await expect(wp.getByText(/Tiempo activo: .* de 0 min requeridos|Tiempo activo:/)).toBeVisible();
        // Latidos cada 30 s: con interacción, cada uno suma hasta 30 s; el curso pide 36 s (50 % de 0,02 h).
        await study(wp, 70);
        await wp.getByRole("button", { name: "Marcar como vista" }).click();
        await expect(wp.getByText("Lección vista ✓").or(wp.getByRole("heading", { name: "Evaluación final" }))).toBeVisible();
        await wp.getByRole("button", { name: /Evaluación final/ }).click();
        await expect(wp.getByText("2 al azar")).toBeVisible();
        await wp.getByRole("button", { name: "Empezar evaluación" }).click();
        const questions = wp.locator("fieldset");
        await expect(questions).toHaveCount(2);
        const drawn = (await questions.locator("legend").allTextContents()).map((t) => t.replace(/^\d+\.\s*/, "").replace(/\s*\(\d+ pt\)$/, "").trim());
        expect(new Set(drawn).size).toBe(2);
        for (const s of drawn) expect(s).toMatch(new RegExp(`^${tag} pregunta [1-4]$`));
        // El intento guarda sus preguntas (las mismas que se ven).
        const saved = psql(`select question_set::text from quiz_attempts where enrollment_id = '${ids.enrollment}' and deleted_at is null order by created_at desc limit 1`);
        expect((JSON.parse(saved) as unknown[]).length).toBe(2);
        for (let i = 0; i < 2; i++) await questions.nth(i).getByLabel("Correcta", { exact: true }).check();
        await wp.getByRole("button", { name: "Enviar respuestas" }).click();
        await expect(wp.getByText("¡Aprobaste!")).toBeVisible({ timeout: 60_000 });
        // Completo (contenido, nota y permanencia): aparece el certificado.
        await expect(async () => {
          await wp.reload({ waitUntil: "domcontentloaded" });
          await expect(wp.getByRole("link", { name: "Curso completado: ver mi certificado" })).toBeVisible({ timeout: 15_000 });
        }).toPass({ timeout: 150_000, intervals: [5_000] });
        await expectHealthy(wp);
      } finally {
        await context.close();
      }
      const row = psql(`select id || '|' || status || '|' || verification_code || '|' || certificate_code from training_certificates where course_enrollment_id = '${ids.enrollment}' and deleted_at is null`);
      const [id, status, verification, certCode] = row.split("|");
      ids.certificate = id;
      return { id, status, verification, certCode };
    });

    await test.step("el certificado nace por validar y aún no se verifica como válido", async () => {
      expect(certificate.status, "la plantilla del curso exige validación (RN-CAP-08)").toBe("pending_validation");
      const pub = await apiRaw(request, null, `/public/verify/certificate/${certificate.verification}`);
      expect(pub.body?.data?.result).not.toBe("valid");
      const ev = await api<{ data: { overall: string } }>(request, jefe, `/fot/evaluate?worker_id=${worker}&credential_type_id=${ids.ctype}`);
      expect(ev.data.overall, "un certificado por validar no habilita el fotocheck").toBe("bad");
    });

    await test.step("el Jefe SST lo valida desde la bandeja «Por validar»", async () => {
      await page.goto("/m/cap", { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      const tray = page.getByRole("region", { name: "Certificados por validar" });
      await expect(tray.getByText(certificate.certCode)).toBeVisible({ timeout: 120_000 });
      await tray.getByLabel(`Elegir ${certificate.certCode}`).check();
      await page.getByRole("button", { name: /^Validar 1$/ }).click();
      await expect(page.getByText("Validados: 1.")).toBeVisible({ timeout: 60_000 });
      expect(psql(`select status from training_certificates where id = '${certificate.id}'`)).toBe("validated");
    });

    await test.step("la verificación pública por código dice «Certificado válido»", async () => {
      const { context, page: pp } = await openAs(browser, null);
      try {
        await pp.goto(`/verificar/certificado/${certificate.verification}?empresa=${DEMO_COMPANY}`, { waitUntil: "domcontentloaded" });
        await expect(pp.getByText("Certificado válido", { exact: true })).toBeVisible({ timeout: 60_000 });
        await expect(pp.getByText(`${tag} Trabajo en altura`)).toBeVisible();
        await expect(pp.getByText(certificate.certCode)).toBeVisible();
        await expectHealthy(pp);
      } finally {
        await context.close();
      }
    });

    await test.step("el fotocheck que exige el tema queda habilitado y se emite", async () => {
      await page.goto("/m/fot", { waitUntil: "domcontentloaded" });
      await settle(page);
      await waitForHydration(page);
      const issue = page.locator("label").filter({ hasText: /^Trabajador/ }).locator("..").first();
      await page.locator("select").filter({ has: page.locator(`option[value="${ids.ctype}"]`) }).first().selectOption(ids.ctype);
      const box = issue.getByRole("combobox").first();
      const name = psql(`select full_name from workers where id = '${worker}'`);
      await box.click();
      await box.fill(name.split(" ")[0]);
      await box.locator("..").getByRole("option", { name: new RegExp(name.split(" ").slice(0, 2).join(" ")) }).first().click();
      await expect(page.getByText(`${tag} Trabajo en altura vigente`)).toBeVisible({ timeout: 60_000 });
      await expect(page.getByText("Cumple todos los requisitos.")).toBeVisible();
      await page.getByRole("button", { name: "Emitir fotocheck" }).click();
      await expect(page.getByRole("status").filter({ hasText: /^Fotocheck .* emitido\.$/ })).toBeVisible({ timeout: 60_000 });
      ids.credential = psql(`select id from credentials where worker_id = '${worker}' and credential_type_id = '${ids.ctype}' and deleted_at is null limit 1`);
      expect(psql(`select status from credentials where id = '${ids.credential}'`)).toBe("active");
    });
  } finally {
    psql(`update workers set current_position_id = ${oldPosition ? `'${oldPosition}'` : "null"} where id = '${worker}'`);
    const soft = (table: string, where: string) => psql(`update ${table} set deleted_at = now() where ${where} and deleted_at is null`);
    if (ids.ctype) {
      soft("credentials", `credential_type_id = '${ids.ctype}'`);
      soft("credential_requirements", `credential_type_id = '${ids.ctype}'`);
      soft("credential_types", `id = '${ids.ctype}'`);
    }
    if (ids.course) {
      soft("training_certificates", `course_id = '${ids.course}'`);
      soft("course_enrollments", `course_id = '${ids.course}'`);
      soft("position_training_requirements", `course_id = '${ids.course}'`);
      soft("course_lessons", `course_id = '${ids.course}'`);
      soft("courses", `id = '${ids.course}'`);
    }
    if (ids.training) soft("trainings", `id = '${ids.training}'`);
    if (ids.template) soft("certificate_templates", `id = '${ids.template}'`);
    if (ids.position) soft("positions", `id = '${ids.position}'`);
  }
});
