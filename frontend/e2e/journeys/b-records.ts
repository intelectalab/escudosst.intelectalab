// Ayudas de pantalla para los recorridos E2E-06…E2E-10: la pantalla común de registros (lista con buscador, formulario
// «Nuevo» y ficha del registro) y datos de la empresa de demostración que usan varios recorridos.

import { expect, type Locator, type Page } from "@playwright/test";
import { settle } from "../fixtures";
import { psql } from "./helpers";

/** Abre /m/<código> y espera a que cargue. */
export async function openModule(page: Page, code: string) {
  await page.goto(`/m/${code.toLowerCase()}`, { waitUntil: "domcontentloaded" });
  await settle(page, 180_000);
}

/** Sección de registros de la pestaña (o la primera si el módulo no tiene pestañas). */
export async function recordsSection(page: Page, tab?: string): Promise<Locator> {
  const main = page.locator("main");
  if (tab) {
    const t = main.locator('[role=tablist][aria-label="Registros del módulo"]').getByRole("tab", { name: tab, exact: true });
    if (await t.count()) await t.click();
    const s = main.locator(`section[data-records]:not([data-nested])[aria-label="${tab}"]`);
    await expect(s).toBeVisible();
    return s;
  }
  const s = main.locator("section[data-records]:not([data-nested])").first();
  await expect(s).toBeVisible();
  return s;
}

/** Busca en la lista y abre la ficha de la fila que contiene el texto. */
export async function openRecord(page: Page, section: Locator, search: string, rowText: string | RegExp = search): Promise<Locator> {
  const box = section.getByPlaceholder("Buscar por código, nombre o texto…").first();
  await box.fill(search);
  const row = section.locator("tbody tr").filter({ hasText: rowText }).first();
  await expect(row).toBeVisible({ timeout: 90_000 });
  await row.click();
  const dialog = page.getByRole("dialog").filter({ has: page.locator('[aria-label="Partes de la ficha"]') }).last();
  await expect(dialog).toBeVisible({ timeout: 90_000 });
  return dialog;
}

/** Abre el formulario «Nuevo» (o el botón con su nombre propio) de la sección. */
export async function openNew(page: Page, section: Locator, label: string | RegExp): Promise<Locator> {
  await section.locator("> div").first().getByRole("button", { name: label }).first().click();
  const dialog = page.getByRole("dialog", { name: /^Nuevo · / });
  await expect(dialog).toBeVisible();
  return dialog;
}

/**
 * Completa campos del formulario común por su etiqueta: lista (por el texto de la opción), referencia con buscador
 * (escribe y elige la primera opción que contenga el texto) o campo de texto, fecha o número.
 */
export async function fillForm(dialog: Locator, values: Record<string, string>) {
  for (const [label, value] of Object.entries(values)) {
    const field = dialog.locator("label").filter({ has: dialog.page().locator("span", { hasText: new RegExp(`^${escape(label)}( \\*)?$`) }) }).first();
    await expect(field, `campo «${label}»`).toBeVisible();
    const select = field.locator("select");
    if (await select.count()) {
      await select.selectOption({ label: value });
      continue;
    }
    const combo = field.locator('input[role="combobox"]');
    if (await combo.count()) {
      await combo.fill(value);
      const opt = dialog.page().getByRole("option").filter({ hasText: value }).first();
      await expect(opt).toBeVisible({ timeout: 120_000 });
      await opt.click();
      continue;
    }
    await field.locator("input:not([type=hidden]), textarea").first().fill(value);
  }
}

function escape(s: string) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/** Guarda el formulario «Nuevo» y espera a que se cierre (si queda abierto, muestra el error). */
export async function submitNew(dialog: Locator) {
  await dialog.getByRole("button", { name: "Crear", exact: true }).click();
  await expect(dialog, "el formulario se guardó").toBeHidden({ timeout: 90_000 }).catch(async (e) => {
    const errs = (await dialog.locator("[role=alert], span.text-bad").allTextContents()).join(" | ");
    throw new Error(`No se guardó el formulario: ${errs || e}`);
  });
}

/** Hora local para un campo datetime-local (AAAA-MM-DDTHH:MM), desplazada en horas. */
export function localDateTime(offsetHours = 0): string {
  const d = new Date(Date.now() + offsetHours * 3_600_000);
  const p = new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).formatToParts(d);
  const v = (t: string) => p.find((x) => x.type === t)!.value;
  return `${v("year")}-${v("month")}-${v("day")}T${v("hour")}:${v("minute")}`;
}

/** Consulta en la base con el contexto de la empresa (las tablas de empresa tienen RLS forzado). */
export function sqlIn(company: string, sql: string): string {
  return psql(`select set_config('app.company_id', '${company}', false); ${sql}`).split(/\r?\n/).slice(1).join("\n").trim();
}

/** Trabajadores activos de la empresa (id, nombre), para usarlos en los recorridos. */
export function demoWorkers(company: string, n = 3): { id: string; name: string }[] {
  return sqlIn(company, `select id || '|' || full_name from workers where company_id = '${company}' and deleted_at is null and status = 'active' order by created_at limit ${n}`)
    .split(/\r?\n/)
    .filter(Boolean)
    .map((l) => {
      const [id, name] = l.split("|");
      return { id, name };
    });
}

/** Primera sede activa de la empresa. */
export function demoSite(company: string): { id: string; name: string } {
  const [id, name] = sqlIn(company, `select id || '|' || name from sites where company_id = '${company}' and deleted_at is null order by created_at limit 1`).split("|");
  return { id, name };
}

/** Id de usuario por correo. */
export function userId(email: string): string {
  return psql(`select id from users where email = '${email}' and deleted_at is null`);
}

/** Ficha abierta más reciente (las fichas de registros hijos se abren encima de la del padre). */
export function topFicha(page: Page): Locator {
  return page.getByRole("dialog").filter({ has: page.locator('[aria-label="Partes de la ficha"]') }).last();
}

/**
 * Dentro de una ficha, abre la fila de un registro hijo (p. ej. las sesiones de una capacitación): busca la sección
 * de esa tabla recorriendo las partes de la ficha y devuelve la ficha del hijo.
 */
export async function openChild(page: Page, ficha: Locator, table: string, rowText?: string | RegExp): Promise<Locator> {
  const nested = ficha.locator(`section[data-records="${table}"]`);
  if (!(await nested.isVisible())) {
    const parts = ficha.locator('[aria-label="Partes de la ficha"]').getByRole("tab");
    for (let i = 0; i < (await parts.count()); i++) {
      await parts.nth(i).click();
      if (await nested.isVisible().catch(() => false)) break;
      await page.waitForTimeout(300);
      if (await nested.isVisible().catch(() => false)) break;
    }
  }
  await expect(nested, `registros «${table}» en la ficha`).toBeVisible({ timeout: 60_000 });
  const row = rowText ? nested.locator("tbody tr").filter({ hasText: rowText }).first() : nested.locator("tbody tr").first();
  await expect(row).toBeVisible({ timeout: 90_000 });
  const before = await page.getByRole("dialog").count();
  await row.click();
  await expect.poll(() => page.getByRole("dialog").count(), { timeout: 60_000 }).toBeGreaterThan(before);
  const child = topFicha(page);
  await expect(child).toBeVisible();
  return child;
}

/**
 * Persona de prueba con un rol en la empresa (p. ej. gerencia o RR. HH., que la demostración no trae), con la misma
 * contraseña de los usuarios de demostración. Se crea en la base (sin enviar correos de invitación); dropTestUser la retira.
 */
export function ensureTestUser(email: string, fullName: string, roleCode: string, company: string): void {
  const hash = psql("select password_hash from users where email = 'corporativo@escudo.test'");
  psql(
    `insert into users (email, full_name, password_hash, status, email_verified_at) select '${email}', '${fullName}', '${hash}', 'active', now()
       where not exists (select 1 from users where email = '${email}');
     update users set deleted_at = null, status = 'active' where email = '${email}';
     insert into memberships (company_id, user_id, role_id, status, accepted_at)
       select '${company}', u.id, r.id, 'active', now() from users u, roles r
        where u.email = '${email}' and r.code = '${roleCode}' and r.account_id is null
          and not exists (select 1 from memberships m where m.company_id = '${company}' and m.user_id = u.id and m.deleted_at is null);`,
  );
}

export function dropTestUser(email: string): void {
  psql(`update memberships set deleted_at = now() where user_id = (select id from users where email = '${email}') and deleted_at is null;
        update users set deleted_at = now() where email = '${email}' and deleted_at is null;`);
}
