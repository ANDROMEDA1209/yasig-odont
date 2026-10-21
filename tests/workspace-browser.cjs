// Local browser regression checks. Never point this script at the working database.
// First run php tests/run.php, then serve yasig_odont_test on 127.0.0.1:8081.
const { chromium, expect } = require('../storage/qa/node_modules/@playwright/test');
const { default: AxeBuilder } = require('../storage/qa/node_modules/@axe-core/playwright');
const fs = require('fs');
const path = require('path');

const base = 'http://127.0.0.1:8081';
const executable = 'C:/Users/ASUS/AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe';
const auditOnly = process.argv.includes('--audit-only');
const run = 'QAUI-' + Date.now().toString(36).toUpperCase();
const artifacts = path.resolve(__dirname, '../storage/workspace-qa');
fs.mkdirSync(artifacts, { recursive: true });
const results = [];
const incidents = [];
const fixtures = { patients: [], run };
let expectedValidation = false;

async function ready(page) {
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('body')).toHaveAttribute('data-workspace-ready', 'yes');
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.allSettled(document.getAnimations()
      .filter(animation => animation.effect?.getTiming().iterations !== Infinity)
      .map(animation => animation.finished));
  });
}

async function go(page, route) {
  const response = await page.goto(base + route);
  expect(response.status(), route).toBe(200);
  await ready(page);
}

async function check(name, callback) {
  const started = Date.now();
  await callback();
  results.push({ name, status: 'pass', milliseconds: Date.now() - started });
  console.log('PASS ' + name);
}

async function submit(page, form, button) {
  const invalid = await form.evaluate(element => [...element.elements]
    .filter(field => field.willValidate && !field.validity.valid)
    .map(field => field.name));
  expect(invalid, 'El fixture debe ser válido antes de enviarlo').toEqual([]);
  const response = page.waitForResponse(value => value.request().method() === 'POST'
    && new URL(value.url()).origin === base);
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  await (button || form.locator('button:not([type=button])').last()).click();
  await response;
  await navigation;
  await ready(page);
}

async function postFixture(context, token, route, values) {
  const response = await context.request.post(base + route, {
    form: { _csrf: token, ...values }, maxRedirects: 0,
  });
  expect([302, 303], 'Fixture HTTP: ' + route + '\n' + (response.status() >= 400 ? await response.text() : ''))
    .toContain(response.status());
  return response;
}

function patientData(index) {
  return {
    first_name: 'Ficticio ' + String(index).padStart(2, '0'), last_name: run,
    document: run + '-' + index, phone: 'QA sin teléfono real', email: '',
    birth_date: '', address: 'Fixture de interfaz', emergency_contact: '', status: 'active',
  };
}

async function requiredIndicators(page) {
  const missing = await page.locator('input[required],select[required],textarea[required]').evaluateAll(fields => fields
    .filter(field => field.type !== 'hidden' && !field.disabled)
    .filter(field => ![...(field.labels || [])].some(label => label.querySelector('.required-indicator') || label.textContent.includes('*')))
    .map(field => field.name || field.id));
  expect(missing, 'Todo campo obligatorio debe mostrar su indicador').toEqual([]);
}

async function preservesScroll(page, before) {
  const actual = await page.evaluate(() => ({
    y: window.scrollY, max: Math.max(0, document.documentElement.scrollHeight - innerHeight),
  }));
  // A confirmed annulment may legitimately remove its expanded form. Respect the new document limit.
  expect(Math.abs(actual.y - Math.min(before, actual.max)), 'Desplazamiento conservado dentro del documento disponible').toBeLessThan(180);
}

(async () => {
  const browser = await chromium.launch({ executablePath: executable, headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  page.on('pageerror', error => incidents.push({ type: 'javascript', text: error.message, url: page.url() }));
  page.on('console', message => {
    if (message.type() === 'error' && !(expectedValidation && /4\d\d/.test(message.text())))
      incidents.push({ type: 'console', text: message.text(), url: page.url() });
  });
  page.on('requestfailed', request => {
    if (!request.failure()?.errorText.includes('ERR_ABORTED'))
      incidents.push({ type: 'network', text: request.failure()?.errorText, url: request.url() });
  });
  page.on('response', response => {
    if (response.status() >= 400 && !expectedValidation)
      incidents.push({ type: 'http', status: response.status(), url: response.url() });
  });
  try {
    await check('Acceso real al servidor de pruebas', async () => {
      expect((await context.request.get(base + '/health')).status()).toBe(200);
      await go(page, '/login');
      await requiredIndicators(page);
      for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Login a ' + width + ' px').toBe(true);
      }
      const loginAudit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
      expect(loginAudit.violations.map(violation => ({ id: violation.id, nodes: violation.nodes.map(node => node.target) })), 'Axe acceso').toEqual([]);
      await page.screenshot({ path: path.join(artifacts, 'login-mobile.png'), fullPage: true });
      await page.setViewportSize({ width: 1440, height: 1000 });
      await page.locator('[name=email]').fill('admin@yasig.demo');
      await page.locator('[name=password]').fill('Demo-Yasig-2026!');
      await page.locator('form.stack button[type=submit]').click();
      await page.waitForURL(base + '/admin');
      await ready(page);
    });

    if (!auditOnly) {
      await check('Validación obligatoria e inválida antes de enviar', async () => {
        await go(page, '/admin/patients?new=1');
        const form = page.locator('form:has(input[name=first_name])');
        await requiredIndicators(page);
        await expect(form).toContainText('Los campos marcados con');
        let posts = 0;
        const listener = request => { if (request.method() === 'POST') posts++; };
        page.on('request', listener);
        await form.locator('button').last().click();
        await expect(form.locator('[name=first_name]')).toHaveAttribute('aria-invalid', 'true');
        const description = await form.locator('[name=first_name]').getAttribute('aria-describedby');
        expect(description).toBeTruthy();
        await expect(page.locator('#' + description.split(' ').pop())).not.toBeEmpty();
        for (const [name, value] of Object.entries(patientData(0))) {
          const field = form.locator('[name=' + name + ']');
          if (await field.count()) {
            if (name === 'status') await field.selectOption(value);
            else await field.fill(value);
          }
        }
        await form.locator('[name=email]').fill('correo-invalido');
        await form.locator('button').last().click();
        await expect(form.locator('[name=email]')).toHaveAttribute('aria-invalid', 'true');
        expect(posts, 'No deben enviarse formularios inválidos').toBe(0);
        page.off('request', listener);
      });

      await check('Backend rechaza campos vacíos aunque se omita JavaScript', async () => {
        const token = await page.locator('[name=_csrf]').first().inputValue();
        expectedValidation = true;
        try {
          const response = await context.request.post(base + '/admin/patients?new=1', {
            form: { _csrf: token, ...patientData(0), first_name: '' },
          });
          expect([200, 400, 422]).toContain(response.status());
          const html = await response.text();
          const rendered = await page.evaluate(markup => {
            const document = new DOMParser().parseFromString(markup, 'text/html');
            const field = document.querySelector('[name=first_name]');
            const described = (field?.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
            return {
              invalid: field?.getAttribute('aria-invalid'),
              error: described.map(id => document.getElementById(id)?.textContent.trim() || '').join(' '),
            };
          }, html);
          expect(rendered.invalid).toBe('true');
          expect(rendered.error).toMatch(/nombre|obligatori|completa/i);
        } finally { expectedValidation = false; }
      });

      await check('Fixtures HTTP aislados con paginación real', async () => {
        const token = await page.locator('[name=_csrf]').first().inputValue();
        for (let index = 1; index <= 14; index++) {
          const response = await postFixture(context, token, '/admin/patients?new=1', patientData(index));
          const location = new URL(response.headers().location, base);
          const id = Number(location.searchParams.get('view') || location.searchParams.get('edit'));
          expect(id).toBeGreaterThan(0);
          fixtures.patients.push({ id, ...patientData(index) });
        }
        await go(page, '/admin/patients?' + new URLSearchParams({ q: run, status: 'active', page: '2' }));
        await expect(page.locator('.pagination [aria-current=page]')).toHaveText('2');
        expect(await page.locator('tbody tr').count()).toBe(2);
      });

      await check('Editar conserva búsqueda, estado, página y posición', async () => {
        await page.locator('tbody tr').first().getByRole('link', { name: 'Editar', exact: true }).click();
        await ready(page);
        let current = new URL(page.url());
        expect(current.searchParams.get('q')).toBe(run);
        expect(current.searchParams.get('status')).toBe('active');
        expect(current.searchParams.get('page')).toBe('2');
        const form = page.locator('form:has(input[name=first_name])');
        const id = Number(await form.locator('[name=id]').inputValue());
        fixtures.editedPatient = id;
        await form.locator('[name=phone]').fill('QA edición persistida');
        await form.locator('button').last().scrollIntoViewIfNeeded();
        const scroll = await page.evaluate(() => window.scrollY);
        expect(scroll).toBeGreaterThan(80);
        await submit(page, form);
        current = new URL(page.url());
        for (const [key, expected] of Object.entries({ q: run, status: 'active', page: '2' }))
          expect(current.searchParams.get(key), key).toBe(expected);
        await preservesScroll(page, scroll);
        await expect(page.locator('.notice.success')).toBeVisible();
        await go(page, '/admin/patients?edit=' + id);
        await expect(page.locator('[name=phone]')).toHaveValue('QA edición persistida');
      });

      await check('Guardar al final conserva desplegable y evita doble envío', async () => {
        await go(page, '/admin/treatments');
        const details = page.locator('details').filter({ has: page.locator('summary', { hasText: 'Agregar al catálogo' }) });
        await details.locator('summary').click();
        const form = details.locator('form');
        await form.locator('[name=code]').fill(run);
        await form.locator('[name=name]').fill(run + ' tratamiento ficticio');
        await form.locator('[name=price]').fill('100');
        await form.locator('[name=duration_minutes]').fill('30');
        const button = form.locator('button').last();
        await button.scrollIntoViewIfNeeded();
        const scroll = await page.evaluate(() => window.scrollY);
        expect(scroll).toBeGreaterThan(300);
        let posts = 0;
        const listener = request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/admin/treatments') posts++; };
        page.on('request', listener);
        await page.route('**/admin/treatments*', async route => {
          if (route.request().method() === 'POST') await new Promise(resolve => setTimeout(resolve, 250));
          await route.continue();
        });
        const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
        await button.evaluate(element => { element.click(); element.click(); });
        await expect(button).toBeDisabled();
        await expect(form).toHaveAttribute('aria-busy', 'true');
        await navigation;
        await ready(page);
        await page.unroute('**/admin/treatments*');
        page.off('request', listener);
        expect(posts).toBe(1);
        await expect(details).toHaveAttribute('open', '');
        await preservesScroll(page, scroll);
        await expect(page.locator('.notice.success')).toBeVisible();
        const matches = page.locator('table tbody tr').filter({ has: page.locator('td', { hasText: run + ' tratamiento ficticio' }) });
        expect(await matches.count()).toBe(1);
        fixtures.treatmentId = Number(await matches.locator('[name=id]').inputValue());
      });

      await check('Edición de catálogo persiste y conserva su desplegable', async () => {
        const row = page.locator('table tbody tr').filter({ has: page.locator('td', { hasText: run + ' tratamiento ficticio' }) });
        await row.locator('summary').click();
        const form = row.locator('form');
        await form.locator('[name=description]').fill('Edición posterior de fixture');
        await submit(page, form);
        await expect(row.locator('details')).toHaveAttribute('open', '');
        await expect(row.locator('[name=description]')).toHaveValue('Edición posterior de fixture');
      });

      await check('Plan y cobro ficticios para verificar anulación sin borrar historia', async () => {
        await go(page, '/admin/treatments');
        const details = page.locator('#new-plan');
        await details.locator('summary').click();
        const planForm = details.locator('form');
        await planForm.locator('[name=name]').fill(run + ' plan ficticio');
        await planForm.locator('[name=patient_id]').selectOption(String(fixtures.patients[0].id));
        await planForm.locator('[name=dentist_id]').selectOption({ index: 1 });
        await planForm.locator('[name=treatment_id]').selectOption(String(fixtures.treatmentId));
        await submit(page, planForm);
        await go(page, '/admin/payments');
        const payment = page.locator('form:has([name=request_key])');
        const option = payment.locator('[name=plan_id] option').filter({ hasText: fixtures.patients[0].first_name + ' ' + run });
        expect(await option.count()).toBe(1);
        fixtures.planId = Number(await option.getAttribute('value'));
        await payment.locator('[name=plan_id]').selectOption(String(fixtures.planId));
        await payment.locator('[name=amount]').fill('10.00');
        await payment.locator('[name=reference]').fill(run);
        await submit(page, payment);
        const row = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Historial de cobros', exact: true }) })
          .locator('tbody tr').filter({ hasText: fixtures.patients[0].first_name + ' ' + run });
        expect(await row.count()).toBe(1);
        fixtures.paymentId = Number(await row.locator('[name=id]').inputValue());
        await row.locator('summary').click();
        const form = row.locator('form');
        await form.locator('[name=void_reason]').fill('QA: reversión de cobro ficticio ' + run);
        let posts = 0;
        const listener = request => { if (request.method() === 'POST') posts++; };
        page.on('request', listener);
        await form.locator('button').click();
        await expect(page.locator('#confirm-dialog')).toBeVisible();
        expect(posts).toBe(0);
        await expect(form.locator('button')).toBeEnabled();
        await page.locator('#confirm-dialog button[value=cancel]').click();
        await expect(page.locator('#confirm-dialog')).not.toBeVisible();
        await expect(form.locator('button')).toBeEnabled();
        await form.locator('button').scrollIntoViewIfNeeded();
        const scroll = await page.evaluate(() => window.scrollY);
        await form.locator('button').click();
        const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
        await page.locator('#confirm-dialog button[value=confirm]').click();
        await navigation;
        await ready(page);
        page.off('request', listener);
        expect(posts).toBe(1);
        await expect(row).toContainText('Anulado');
        await expect(row).toContainText('QA: reversión de cobro ficticio');
        await preservesScroll(page, scroll);
        await go(page, '/admin/patients?view=' + fixtures.patients[0].id);
        await expect(page.locator('.patient-summary')).toContainText(run);
        await go(page, '/admin/clinical?patient_id=' + fixtures.patients[0].id);
        await expect(page.locator('main')).toContainText(fixtures.patients[0].first_name);
      });

      await check('Ortodoncia conserva pestaña, filtros locales y desplegable tras guardar', async () => {
        await go(page, '/admin/orthodontics?' + new URLSearchParams({ q: run }));
        await expect(page.locator('#orthodontics')).toHaveAttribute('data-initialized', 'yes');
        await page.locator('[data-orth-open=orth-new-case]').click();
        const creation = page.locator('#orth-new-case form');
        await creation.locator('[name=patient_id]').selectOption(String(fixtures.patients[1].id));
        await creation.locator('[name=dentist_id]').selectOption({ index: 1 });
        await creation.locator('[name=type_key]').selectOption('metal');
        await creation.locator('[name=appliance]').fill(run + ' fixture de aparatología');
        await creation.locator('[name=price]').fill('0');
        await submit(page, creation);
        fixtures.caseId = Number(new URL(page.url()).searchParams.get('case_id'));
        expect(fixtures.caseId).toBeGreaterThan(0);
        const route = '/admin/orthodontics?case_id=' + fixtures.caseId + '&tab=controls';
        await go(page, route);
        await expect(page.locator('#orthodontics')).toHaveAttribute('data-initialized', 'yes');
        const control = page.locator('form:has([name=action][value=control])');
        await control.evaluate(form => { form.closest('details').open = true; });
        await control.locator('[name=phase]').fill(run + ' fase');
        await control.locator('[name=observations]').fill(run + ' observación ficticia firmada');
        await control.locator('[name=confirm_sign]').check();
        await submit(page, control);
        expect(new URL(page.url()).searchParams.get('tab')).toBe('controls');
        const phase = page.locator('[data-orth-control-phase]:not(article)');
        await phase.fill(run);
        const entry = page.locator('article[data-orth-control-date]').filter({ hasText: run + ' observación ficticia firmada' });
        await entry.locator('details summary').click();
        const addendum = entry.locator('form');
        await addendum.locator('[name=reason]').fill('QA: añadir observación de prueba');
        await addendum.locator('[name=notes]').fill(run + ' adenda ficticia conservada');
        await addendum.locator('button').scrollIntoViewIfNeeded();
        const scroll = await page.evaluate(() => window.scrollY);
        await submit(page, addendum);
        expect(new URL(page.url()).searchParams.get('tab')).toBe('controls');
        await expect(phase).toHaveValue(run);
        await expect(entry.locator('details')).toHaveAttribute('open', '');
        await expect(entry).toContainText(run + ' adenda ficticia conservada');
        await preservesScroll(page, scroll);
      });

      await check('Error de negocio restaura modal, datos y búsqueda sin crear duplicados', async () => {
        await go(page, '/admin/orthodontics?' + new URLSearchParams({ q: run }));
        await expect(page.locator('#orthodontics')).toHaveAttribute('data-initialized', 'yes');
        const rowsBefore = await page.locator('.orth-cases tbody tr').count();
        await page.evaluate(() => window.scrollTo(0, 500));
        await page.locator('[data-orth-open=orth-new-case]').evaluate(button => button.click());
        const modal = page.locator('#orth-new-case');
        const form = modal.locator('form');
        await form.locator('[name=patient_id]').selectOption(String(fixtures.patients[1].id));
        await form.locator('[name=dentist_id]').selectOption({ index: 1 });
        await form.locator('[name=type_key]').selectOption('metal');
        await form.locator('[name=appliance]').fill(run + ' datos conservados en error');
        await form.locator('[name=confirm_new_episode]').check();
        expectedValidation = true;
        try { await submit(page, form); } finally { expectedValidation = false; }
        await expect(modal).toBeVisible();
        await expect(form.locator('[name=appliance]')).toHaveValue(run + ' datos conservados en error');
        await expect(form.locator('[name=patient_id]')).toHaveValue(String(fixtures.patients[1].id));
        expect(new URL(page.url()).searchParams.get('q')).toBe(run);
        await expect(page.locator('.notice.error')).toContainText('expediente abierto');
        expect(await page.locator('.orth-cases tbody tr').count()).toBe(rowsBefore);
        await modal.locator('[data-orth-close]').click();
      });
    }

    await check('Dashboard AJAX mantiene filtros sin recargar el documento', async () => {
      await go(page, '/admin');
      const marker = 'workspace-marker-' + Date.now();
      await page.evaluate(value => { window.workspaceQaMarker = value; }, marker);
      const form = page.locator('#dashboard-filters');
      await form.locator('[name=q]').fill(run);
      const response = page.waitForResponse(value => new URL(value.url()).pathname.endsWith('/dashboard-data')
        && !new URL(value.url()).searchParams.has('_background'));
      await form.locator('button[type=submit]').click();
      await response;
      await expect(page.locator('#dashboard-sync')).toContainText('Actualizado');
      expect(await page.evaluate(() => window.workspaceQaMarker)).toBe(marker);
      expect(new URL(page.url()).searchParams.get('q')).toBe(run);
      await expect(form.locator('[name=q]')).toHaveValue(run);
    });

    await check('Responsive, campos obligatorios y accesibilidad de todos los módulos', async () => {
      const routes = ['/admin', '/admin/patients?new=1', '/admin/appointments?new=1', '/admin/clinical',
        '/admin/odontogram', '/admin/treatments', '/admin/orthodontics', '/admin/payments',
        '/admin/inventory', '/admin/reports', '/admin/users', '/admin/settings', '/admin/account'];
      for (const route of routes) {
        await go(page, route);
        await requiredIndicators(page);
        for (const width of [1440, 768, 390, 320]) {
          await page.setViewportSize({ width, height: 1000 });
          expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), route + ' a ' + width + ' px').toBe(true);
        }
        await ready(page);
        const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        expect(audit.violations.map(violation => ({ id: violation.id, nodes: violation.nodes.map(node => node.target) })), 'Axe ' + route).toEqual([]);
        if (['/admin', '/admin/patients?new=1', '/admin/treatments', '/admin/orthodontics'].includes(route)) {
          const name = route.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '');
          await page.screenshot({ path: path.join(artifacts, name + '-mobile.png'), fullPage: true });
          await page.setViewportSize({ width: 1440, height: 1000 });
          await page.screenshot({ path: path.join(artifacts, name + '-desktop.png'), fullPage: true });
        }
      }
    });

    await check('Preferencia de movimiento reducido y estilos de interfaz aplicados', async () => {
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await go(page, '/admin/settings');
      expect(await page.locator('link[href*="workspace-ui.css"]').count()).toBe(1);
      const motion = await page.locator('main .button').first().evaluate(button => ({
        transition: getComputedStyle(button).transitionDuration,
        animation: getComputedStyle(button).animationName,
      }));
      expect(motion.animation).toBe('none');
      expect(motion.transition.split(',').every(value => Number.parseFloat(value) === 0)).toBe(true);
      await page.emulateMedia({ reducedMotion: 'no-preference' });
    });

    await check('Sin errores de JavaScript, consola ni recursos fallidos', async () => {
      expect(incidents).toEqual([]);
    });
    fs.writeFileSync(path.join(artifacts, 'results.json'), JSON.stringify({ mode: auditOnly ? 'audit-only' : 'full', run, results, incidents, fixtures }, null, 2));
    console.log('PASS interfaz: ' + results.length + ' escenarios. Evidencias: ' + artifacts);
  } catch (error) {
    fs.writeFileSync(path.join(artifacts, 'failure.json'), JSON.stringify({ run, results, incidents, fixtures, error: error.message }, null, 2));
    await page.screenshot({ path: path.join(artifacts, 'failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
