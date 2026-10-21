const names={healthy:'Sano',caries:'Caries',restoration:'Restauración',missing:'Ausente',planned_procedure:'Procedimiento planificado',performed:'Procedimiento realizado'};
const stageLabels={pediatric:'Pediátrico',mixed:'Mixto',permanent:'Permanente'};
const surfaceHints={whole:'Diente completo: abarca toda la pieza dental.',mesial:'Mesial: la cara más cercana a la línea media de la boca.',distal:'Distal: la cara más alejada de la línea media de la boca.',vestibular:'Vestibular: la cara hacia la mejilla o el labio.',lingual:'Lingual / palatina: la cara hacia la lengua o el paladar.',occlusal:'Oclusal / incisal: la superficie de masticación o el borde de corte.'};
// Shared tooth geometry: the small chart buttons and the large surface-picker diagram draw the same shape.
function toothShape(molar){
 return {
  rootPath:molar?'M9 31C6 45 7 61 12 70L17 45L22 70C27 59 28 44 25 31Z':'M11 31C10 47 12 62 17 72C22 60 23 47 22 31Z',
  crown:molar?'M6 11Q8 5 13 8Q17 4 21 8Q27 4 29 11L28 31Q17 37 7 31Z':'M10 8Q17 4 24 8L25 31Q17 35 9 31Z',
  coords:molar?{vestibular:'M7 11L28 11L23 17L12 17Z',distal:'M7 11L12 17L12 25L7 31Z',mesial:'M28 11L23 17L23 25L28 31Z',lingual:'M7 31L12 25L23 25L28 31Z',occlusal:'M12 17H23V25H12Z'}:{vestibular:'M10 9H24L21 16H13Z',distal:'M10 9L13 16V25L10 31Z',mesial:'M24 9L21 16V25L24 31Z',lingual:'M10 31L13 25H21L24 31Z',occlusal:'M13 16H21V25H13Z'},
 };
}
export function initOdontogram(){
 const root=document.getElementById('odontogram');if(!root)return;
 let findings=JSON.parse(root.dataset.findings),activeTooth=null,dirty=false;const readOnly=root.dataset.readonly==='true',treatments=JSON.parse(root.dataset.treatments||'[]'),recommended=root.dataset.recommended||'',diagnoses=JSON.parse(root.dataset.diagnoses||'[]');
 const chart=document.getElementById('dental-chart'),dentition=document.getElementById('dentition'),dialog=document.getElementById('tooth-dialog'),surface=document.getElementById('tooth-surface'),state=document.getElementById('tooth-state'),observation=document.getElementById('tooth-observation');
 const color=(fdi,s)=>findings.find(f=>Number(f.tooth_fdi)===fdi&&f.surface===s)?.state||findings.find(f=>Number(f.tooth_fdi)===fdi&&f.surface==='whole')?.state||'healthy';
 // One arch set (upper + lower) for a single dentition family; «mixed» mode calls this twice.
 function buildArchSet(primary,container){const q=primary?5:1,n=primary?5:8;const rows=[[...Array.from({length:n},(_,i)=>q*10+n-i),...Array.from({length:n},(_,i)=>(q+1)*10+i+1)],[...Array.from({length:n},(_,i)=>(q+3)*10+n-i),...Array.from({length:n},(_,i)=>(q+2)*10+i+1)]];
 rows.forEach((teeth,row)=>{const arch=document.createElement('div');arch.className='dental-arch'+(primary?' primary':'');arch.setAttribute('role','group');arch.setAttribute('aria-label',row?'Arcada inferior':'Arcada superior');teeth.forEach(fdi=>{const button=document.createElement('button');button.type='button';button.className='tooth-button';const fs=findings.filter(f=>Number(f.tooth_fdi)===fdi);button.setAttribute('aria-label',`Pieza ${fdi}: ${fs.length?fs.map(f=>names[f.state]).join(', '):'Sin hallazgos registrados'}`);
 const molar=fdi%10>=4;const missing=fs.some(f=>f.state==='missing'&&f.surface==='whole');
 const {rootPath,crown,coords}=toothShape(molar);
 const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 35 78');svg.setAttribute('aria-hidden','true');
 const add=(d,c)=>{const p=document.createElementNS(svg.namespaceURI,'path');p.setAttribute('d',d);p.setAttribute('class',c);svg.append(p);};
 add(rootPath,'root'+(missing?' missing':''));add(crown,'crown '+color(fdi,'whole'));Object.entries(coords).forEach(([s,d])=>add(d,'surface '+color(fdi,s)));if(missing)add('M5 7L30 37M30 7L5 37','missing-cross');
 if(row===1){svg.style.transform='rotate(180deg)';}button.append(svg);const text=document.createElement('span');text.textContent=fdi;button.append(text);button.addEventListener('click',()=>open(fdi));arch.append(button);});container.append(arch);});}
 function render(){chart.replaceChildren();const mode=dentition.value;
 if(mode==='mixed'){
  const permGroup=document.createElement('div');permGroup.className='dental-chart-group';const permLabel=document.createElement('h3');permLabel.textContent='Dentición permanente';permGroup.append(permLabel);buildArchSet(false,permGroup);chart.append(permGroup);
  const primGroup=document.createElement('div');primGroup.className='dental-chart-group';const primLabel=document.createElement('h3');primLabel.textContent='Dentición temporal';primGroup.append(primLabel);buildArchSet(true,primGroup);chart.append(primGroup);
 } else buildArchSet(mode==='pediatric',chart);}
 function loadSurface(){const f=findings.find(f=>Number(f.tooth_fdi)===activeTooth&&f.surface===surface.value);state.value=f?.state||'healthy';observation.value=f?.observation||'';const surfaces={whole:'Diente completo',mesial:'Mesial',distal:'Distal',vestibular:'Vestibular',lingual:'Lingual / palatina',occlusal:'Oclusal / incisal'};const notes=findings.filter(f=>Number(f.tooth_fdi)===activeTooth).map(f=>`${surfaces[f.surface]}: ${names[f.state]}${f.observation?' · '+f.observation:''}`);const procedures=treatments.filter(t=>Number(t.tooth_fdi)===activeTooth).map(t=>`Realizado: ${t.treatment}${t.notes?' · '+t.notes:''}`);const diag=diagnoses.filter(d=>Number(d.tooth_fdi)===activeTooth).map(d=>`Diagnóstico (${d.status==='active'?'activo':'resuelto'}): ${d.diagnosis_name}${d.surfaces.length?' · '+d.surfaces.join(', '):''}`);document.getElementById('tooth-summary').textContent=[...diag,...notes,...procedures].join('\n');renderSurfaceDiagram();}
 // A life-size reference diagram next to the surface picker: it highlights whichever face is selected,
 // matches the real molar/incisor shape of the tooth being edited, and doubles as a click target.
 const surfaceDiagram=document.getElementById('surface-diagram'),surfaceHint=document.getElementById('surface-hint');
 function renderSurfaceDiagram(){
  if(!surfaceDiagram)return;
  surfaceDiagram.replaceChildren();
  const molar=activeTooth!==null&&activeTooth%10>=4;
  const {rootPath,crown,coords}=toothShape(molar);
  const active=surface.value;
  const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 35 78');svg.setAttribute('aria-hidden','true');
  const add=(d,c,key)=>{const p=document.createElementNS(svg.namespaceURI,'path');p.setAttribute('d',d);p.setAttribute('class',c);if(key){p.setAttribute('tabindex','-1');p.addEventListener('click',()=>{if(readOnly)return;surface.value=key;surface.dispatchEvent(new Event('change'));});}svg.append(p);};
  add(rootPath,'diagram-root');
  add(crown,'diagram-crown'+(active==='whole'?' active':'')+(readOnly?'':' pickable'),'whole');
  Object.entries(coords).forEach(([key,d])=>add(d,'diagram-surface'+(active===key||active==='whole'?' active':'')+(readOnly?'':' pickable'),key));
  surfaceDiagram.append(svg);
  if(surfaceHint)surfaceHint.textContent=surfaceHints[active]||'';
 }
 function open(fdi){activeTooth=fdi;document.getElementById('tooth-title').textContent=`Pieza dental ${fdi}`;const saved=findings.filter(f=>Number(f.tooth_fdi)===fdi);surface.value=(saved.find(f=>f.state!=='healthy')||saved[0])?.surface||'whole';loadSurface();state.disabled=readOnly;observation.readOnly=readOnly;dialog.showModal();const diagnoseTooth=document.getElementById('diagnose-tooth');if(diagnoseTooth)diagnoseTooth.value=fdi;}
 // Keeps the dentist informed of whether their pick matches the system's age-based suggestion, and only
 // asks for a reason when it doesn't — the override itself is always allowed without one.
 const overrideField=document.getElementById('override-reason-field'),selectionNote=document.getElementById('dentition-selection-note');
 function updateDentitionNote(){
  if(!recommended){if(selectionNote)selectionNote.textContent='';if(overrideField)overrideField.hidden=true;return;}
  const differs=dentition.value!==recommended;
  if(selectionNote)selectionNote.textContent=differs?`Elegiste ${stageLabels[dentition.value]}; el sistema recomendaba ${stageLabels[recommended]} según la edad.`:`Coincide con la recomendación del sistema (${stageLabels[recommended]}).`;
  if(overrideField)overrideField.hidden=!differs;
 }
 surface.addEventListener('change',loadSurface);
 dentition.addEventListener('change',()=>{render();updateDentitionNote();});
 document.getElementById('open-recommended')?.addEventListener('click',function(){dentition.value=this.dataset.stage;dentition.dispatchEvent(new Event('change'));chart.scrollIntoView({behavior:'smooth',block:'start'});});
 const chooseOther=document.getElementById('choose-other'),choices=document.getElementById('dentition-choices');
 chooseOther?.addEventListener('click',()=>{const open=Boolean(choices.hidden);choices.hidden=!open;chooseOther.setAttribute('aria-expanded',String(open));});
 choices?.querySelectorAll('.choice').forEach(button=>button.addEventListener('click',()=>{dentition.value=button.dataset.stage;dentition.dispatchEvent(new Event('change'));choices.hidden=true;chooseOther?.setAttribute('aria-expanded','false');chart.scrollIntoView({behavior:'smooth',block:'start'});}));
 document.getElementById('apply-tooth')?.addEventListener('click',()=>{findings=findings.filter(f=>!(Number(f.tooth_fdi)===activeTooth&&f.surface===surface.value));if(surface.value==='whole')findings=findings.filter(f=>Number(f.tooth_fdi)!==activeTooth);findings.push({tooth_fdi:activeTooth,surface:surface.value,state:state.value,observation:observation.value.trim()});document.getElementById('findings-input').value=JSON.stringify(findings);dirty=true;document.getElementById('odonto-status').textContent='Tienes cambios pendientes de guardar.';render();dialog.close();});
 document.getElementById('odontogram-form')?.addEventListener('submit',()=>dirty=false);window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
 document.querySelectorAll('.propose-treatment').forEach(button=>button.addEventListener('click',()=>{
  const diagnosisSelect=document.getElementById('propose-diagnosis'),toothInput=document.getElementById('propose-tooth');
  if(diagnosisSelect)diagnosisSelect.value=button.dataset.diagnosis;
  if(toothInput)toothInput.value=button.dataset.tooth;
  document.getElementById('propose-treatment-section')?.scrollIntoView({behavior:'smooth',block:'start'});
 }));
 render();updateDentitionNote();
}
