// Run after php tests/run.php with an isolated PHP server on 127.0.0.1:8081.
const {chromium,expect}=require('../storage/qa/node_modules/@playwright/test');
const fs=require('fs');
const base='http://127.0.0.1:8081';
const executable=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'C:/Users/ASUS/AppData/Local/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-win64/chrome-headless-shell.exe';
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:fs.existsSync(executable)?executable:undefined});
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try {
  await page.goto(base+'/login');await page.locator('[name=email]').fill('admin@yasig.demo');await page.locator('[name=password]').fill('Demo-Yasig-2026!');await page.getByRole('button',{name:'Iniciar sesión'}).click();await page.waitForURL(base+'/admin');
  await page.goto(base+'/admin/patients?new=1');for(const [name,value] of Object.entries({first_name:'Odontograma',last_name:'Regresión Ficticia',document:'ODONTO-UI-'+Date.now(),phone:'TEST'}))await page.locator(`[name=${name}]`).fill(value);
  await page.getByRole('button',{name:'Guardar paciente',exact:true}).click();await page.waitForURL(/view=/);const patientId=new URL(page.url()).searchParams.get('view');
  const open=()=>page.goto(base+'/admin/odontogram?patient_id='+patientId);
  const rows=()=>page.locator('#odontogram-history tbody tr').filter({has:page.locator('a')});
  const save=async()=>{await page.locator('#odontogram-form button').click();await page.waitForLoadState('networkidle');await expect(page.locator('.notice.success')).toBeVisible();};
  const apply=async(tooth,surface,state,note)=>{await page.getByRole('button',{name:new RegExp('^Pieza '+tooth+':')}).click();await page.locator('#tooth-surface').selectOption(surface);await page.locator('#tooth-state').selectOption(state);await page.locator('#tooth-observation').fill(note);await page.locator('#apply-tooth').click();};
  await open();await expect(page.locator('#odontogram-context')).toContainText('aún no tiene');await expect(page.locator('#odontogram-form button')).toContainText('Crear primer');await expect(rows()).toHaveCount(0);
  await open();await expect(rows()).toHaveCount(0);
  await apply(16,'occlusal','caries','Hallazgo inicial ficticio');await apply(26,'mesial','restoration','Restauración anterior ficticia');await save();await expect(rows()).toHaveCount(1);const firstVersion=await rows().first().locator('a').getAttribute('href');
  await page.goto(base+'/admin/odontogram');await page.locator('.patient-select [name=patient_id]').selectOption(patientId);await page.getByRole('button',{name:'Abrir odontograma',exact:true}).click();await expect(page.locator('#odontogram-context')).toContainText('versión actual');await expect(rows()).toHaveCount(1);
  await page.getByRole('button',{name:/^Pieza 16:/}).click();await expect(page.locator('#tooth-surface')).toHaveValue('occlusal');await expect(page.locator('#tooth-state')).toHaveValue('caries');await expect(page.locator('#tooth-observation')).toHaveValue('Hallazgo inicial ficticio');await page.locator('#tooth-dialog button[value=cancel]').first().click();
  await save();await expect(rows()).toHaveCount(1);
  await apply(16,'occlusal','performed','Procedimiento realizado ficticio');await save();await expect(rows()).toHaveCount(2);
  await page.goto(base+firstVersion);await expect(page.locator('#odontogram')).toHaveAttribute('data-readonly','true');await expect(page.locator('#odontogram-form')).toHaveCount(0);await page.getByRole('button',{name:/^Pieza 16:/}).click();await expect(page.locator('#tooth-state')).toHaveValue('caries');await expect(page.locator('#tooth-observation')).toHaveValue('Hallazgo inicial ficticio');await page.locator('#tooth-dialog button[value=cancel]').first().click();
  await page.getByRole('link',{name:'Volver al estado actual'}).click();await page.getByRole('button',{name:'Nueva evaluación',exact:true}).click();await page.locator('#confirm-dialog button[value=confirm]').click();await expect(rows()).toHaveCount(3);await expect(rows().first()).toContainText('Nueva evaluación');await page.getByRole('button',{name:/^Pieza 16:/}).click();await expect(page.locator('#tooth-state')).toHaveValue('performed');await expect(page.locator('#tooth-observation')).toHaveValue('Procedimiento realizado ficticio');await page.locator('#tooth-dialog button[value=cancel]').first().click();
  await open();await expect(rows()).toHaveCount(3);await page.getByRole('button',{name:/^Pieza 26:/}).click();await expect(page.locator('#tooth-observation')).toHaveValue('Restauración anterior ficticia');await page.locator('#tooth-dialog button[value=cancel]').first().click();
  // Backend defenses are exercised over HTTP, independently of the displayed form.
  const csrf=await page.locator('#odontogram-form [name=_csrf]').inputValue(),previous=await page.locator('#odontogram-form [name=previous_id]').inputValue();
  await page.request.post(base+'/admin/odontogram',{form:{_csrf:csrf,patient_id:patientId,previous_id:previous,findings:'[]'}});await open();await expect(rows()).toHaveCount(3);
  const badVersion=await page.request.get(base+'/admin/odontogram?patient_id='+patientId+'&version=0');expect(badVersion.status()).toBe(400);
  const foreign=await page.request.get(base+'/admin/odontogram?patient_id='+patientId+'&version=1');expect(foreign.status()).toBe(400);
  // Fixture created by tests/run.php has a completed procedure for piece 16.
  await page.goto(base+'/admin/odontogram?patient_id=13');await expect(page.locator('#odontogram-treatments')).toContainText('Observación de procedimiento ficticio');await page.getByRole('button',{name:/^Pieza 16:/}).click();await expect(page.locator('#tooth-summary')).toContainText('Realizado:');
  await page.locator('#tooth-dialog button[value=cancel]').first().click();await page.setViewportSize({width:390,height:844});await page.screenshot({path:'storage/odontogram-regression.png',fullPage:true});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);expect(errors).toEqual([]);
  console.log('PASS odontograma: primera creación, reselección, superficies/notas, guardado sin duplicados, actualización, historial inmutable, nueva evaluación explícita, protección HTTP y procedimientos por pieza.');
 } catch(error){await page.screenshot({path:'storage/odontogram-regression-failure.png',fullPage:true});throw error;}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
