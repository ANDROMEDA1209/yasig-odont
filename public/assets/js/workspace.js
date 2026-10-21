const pendingKey='yasig-workspace-pending-v1';
const queryKeys=['q','status','page','sort','direction','from','to','dentist','dentist_id','professional','type_key','treatment_type','date','mode','patient_id','version','view','edit','new','id','case_id','tab','control_id','type','finance_page','event_page','finance_type','payment_method','patient','metric','event_type','event_status','finance_status'];
const hash=value=>{let n=2166136261;for(const c of value)n=Math.imul(n^c.charCodeAt(0),16777619);return (n>>>0).toString(36);};
const read=(key)=>{try{return JSON.parse(sessionStorage.getItem(key)||'null');}catch{return null;}};
const write=(key,value)=>{try{sessionStorage.setItem(key,JSON.stringify(value));}catch{/* Navigation still works when storage is unavailable. */}};
const remove=(key)=>{try{sessionStorage.removeItem(key);}catch{}};
const hidden=(form,name,value)=>{let field=form.querySelector(`input[name="${name}"]`);if(!field){field=document.createElement('input');field.type='hidden';field.name=name;form.append(field);}field.value=value;return field;};
const localControls='[data-orth-control-from],[data-orth-control-to],[data-orth-control-phase],[data-orth-control-professional],[name=photo_filter],[data-orth-photo-from],[data-orth-photo-to],[data-orth-photo-control],[data-orth-plan-first],[data-orth-plan-second]';
function identify(){
 const counts=new Map();
 document.querySelectorAll('form').forEach(form=>{
  if(form.method==='dialog')return;
  const identities=[...form.querySelectorAll('input[type=hidden]')].filter(f=>/^(action|id|case_id|item_id|stage_id|control_id|plan_id|role_id|appointment_id|correction_of)$/.test(f.name)).map(f=>`${f.name}=${f.value}`);
  const fields=[...new Set([...form.elements].map(f=>f.name).filter(n=>n&&!n.startsWith('_')))].sort();
  const base=hash([form.method,new URL(form.action,location.href).pathname,...identities,...fields].join('|'));const index=counts.get(base)||0;counts.set(base,index+1);
  if(!form.id)form.id=`workspace-form-${base}-${index}`;
  form.dataset.workspaceForm=form.id;
  if(form.method==='post') {hidden(form,'_form_key',form.id);hidden(form,'_return_to',location.pathname+location.search);if(!form.elements.namedItem('_submission_id'))hidden(form,'_submission_id',crypto.randomUUID());}
 });
 for(const [selector,prefix] of [['details','panel'],['.table-wrap','table'],[localControls,'filter']]){
  const keys=new Map();document.querySelectorAll(selector).forEach(el=>{
   const inside=el.querySelector('form');const form=el.closest('form');
   const seed=el.id||inside?.id||form?.id||el.querySelector('summary,h2,h3,caption')?.textContent?.trim().slice(0,90)||el.getAttribute('aria-label')||el.getAttributeNames().filter(n=>n.startsWith('data-orth')).join('|')||el.name||prefix;
   const key=hash(seed);const i=keys.get(key)||0;keys.set(key,i+1);el.dataset.workspaceKey=`${prefix}-${key}-${i}`;
  });
 }
}
function snapshot(form=null){
 const anchor=form||null;
 return {path:location.pathname,at:Date.now(),y:scrollY,x:scrollX,form:form?.id||'',anchorTop:anchor?.getBoundingClientRect().top,
  panels:[...document.querySelectorAll('details[data-workspace-key]')].map(el=>[el.dataset.workspaceKey,el.open]),
  dialogs:[...document.querySelectorAll('dialog[open]')].filter(el=>!['confirm-dialog','orth-unsaved'].includes(el.id)).map(el=>el.id),
  tables:[...document.querySelectorAll('.table-wrap[data-workspace-key]')].map(el=>[el.dataset.workspaceKey,el.scrollLeft,el.scrollTop]),
  filters:[...document.querySelectorAll(localControls)].map(el=>[el.dataset.workspaceKey,el.value]),
  focus:document.activeElement?.id||''};
}
function recover(form,values){
 if(!form)return;
 // Medication rows are ordinary cloned inputs, never a second request or a separate form.
 const drugs=values.drug_name;
 if(Array.isArray(drugs)&&form.querySelector('#rx-rows')){
  while(form.querySelectorAll('[name="drug_name[]"]').length<Math.min(20,drugs.length)){
   const before=form.querySelectorAll('[name="drug_name[]"]').length;document.getElementById('add-rx-row')?.click();
   if(form.querySelectorAll('[name="drug_name[]"]').length===before)break;
  }
 }
 const offsets={};
 for(const field of form.elements){
  if(!field.name||field.name.startsWith('_')||['password','file','submit','button'].includes(field.type))continue;
  const name=field.name.replace(/\[\]$/,'');if(!Object.hasOwn(values,name))continue;let value=values[name];
  if(field.type==='checkbox'||field.type==='radio')field.checked=Array.isArray(value)?value.map(String).includes(field.value):String(value)===field.value;
  else{if(Array.isArray(value)){const index=offsets[name]||0;value=value[index]??'';offsets[name]=index+1;}if(typeof value!=='object')field.value=String(value);}
 }
}
export function initWorkspaceContext(){
 identify();
 const feedback=document.getElementById('workspace-feedback');let values={},errors={};
 try{values=JSON.parse(feedback?.dataset.values||'{}');errors=JSON.parse(feedback?.dataset.errors||'{}');}catch{}
 const errorForm=feedback?.dataset.state==='error'?document.getElementById(feedback.dataset.formKey):null;
 if(errorForm instanceof HTMLFormElement){recover(errorForm,values);errorForm.dataset.formErrors=JSON.stringify(errors);}
 // Same-module selectors keep directory filters even before the first edit is submitted.
 document.addEventListener('click',event=>{
  const link=event.target.closest('a[href]');if(!link||link.target==='_blank'||link.hasAttribute('download')||link.dataset.contextReset!==undefined)return;
  const next=new URL(link.href,location.href);if(next.origin!==location.origin||next.pathname!==location.pathname||!next.search||next.hash)return;
  if(!['edit','view','new','case_id','tab'].some(key=>next.searchParams.has(key)))return;
  const current=new URL(location.href);
  for(const key of queryKeys)if(current.searchParams.has(key)&&!next.searchParams.has(key)&&!['edit','view','new','id','case_id','tab','control_id'].includes(key))next.searchParams.set(key,current.searchParams.get(key));
  link.href=next.pathname+next.search+next.hash;
 });
 document.addEventListener('submit',event=>{
  const form=event.target;if(!(form instanceof HTMLFormElement)||event.defaultPrevented||form.method!=='post')return;
  hidden(form,'_return_to',location.pathname+location.search);
  if(form.target!=='_blank')write(pendingKey,snapshot(form));
 });
 addEventListener('pageshow',event=>{if(event.persisted)resetBusyForms();});
 return async function restore(){
  identify();if(errorForm instanceof HTMLFormElement)recover(errorForm,values);
  const state=read(pendingKey);const pending=state&&state.path===location.pathname&&Date.now()-state.at<120000?state:null;
  if(pending){
   for(const [key,open] of pending.panels){const panel=document.querySelector(`details[data-workspace-key="${CSS.escape(key)}"]`);if(panel)panel.open=open;}
   for(const id of pending.dialogs){const dialog=document.getElementById(id);if(dialog instanceof HTMLDialogElement&&!dialog.open)dialog.showModal();}
   for(const [key,value] of pending.filters){const field=document.querySelector(`[data-workspace-key="${CSS.escape(key)}"]`);if(field){field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));}}
   for(const [key,left,top] of pending.tables){const table=document.querySelector(`[data-workspace-key="${CSS.escape(key)}"]`);if(table){table.scrollLeft=left;table.scrollTop=top;}}
   // Open the submitted panel on validation errors even if its former parent was collapsed.
   if(errorForm instanceof HTMLFormElement){for(let parent=errorForm.parentElement;parent;parent=parent.parentElement)if(parent instanceof HTMLDetailsElement)parent.open=true;}
   await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
   const anchor=document.getElementById(pending.form);
   const y=anchor instanceof HTMLFormElement&&Number.isFinite(pending.anchorTop)?scrollY+anchor.getBoundingClientRect().top-pending.anchorTop:pending.y;
   scrollTo({left:pending.x,top:Math.max(0,y),behavior:'instant'});
   if(!Object.keys(errors).length){const focus=document.getElementById(pending.focus);if(focus?.isConnected&&!focus.disabled)focus.focus({preventScroll:true});}
   remove(pendingKey);
  }
  document.body.dataset.workspaceReady='yes';document.dispatchEvent(new CustomEvent('workspace:restored'));
 };
}
const busyForms=new Map();
function resetBusy(form){const state=busyForms.get(form);if(!state)return;for(const [button,disabled,html] of state.buttons){button.disabled=disabled;button.innerHTML=html;button.classList.remove('is-loading');}form.removeAttribute('aria-busy');delete form.dataset.submitting;busyForms.delete(form);}
function resetBusyForms(){for(const form of busyForms.keys())resetBusy(form);}
export function initWorkspaceForms(){
 const dialog=document.getElementById('confirm-dialog');let confirmed=null;
 document.addEventListener('submit',event=>{
  const form=event.target;if(!(form instanceof HTMLFormElement)||form.method==='dialog'||event.defaultPrevented)return;
  if(form.dataset.submitting==='yes'){event.preventDefault();return;}
  const confirmation=form.dataset.confirm;
  if(confirmation&&dialog&&form.dataset.confirmed!=='yes'){
   event.preventDefault();confirmed={form,submitter:event.submitter};document.getElementById('confirm-message').textContent=confirmation;dialog.returnValue='';dialog.showModal();return;
  }
  delete form.dataset.confirmed;
  // Requests already managed by dashboard AJAX retain their own loading and cancellation lifecycle.
  if(form.id==='dashboard-filters')return;
  const submitter=event.submitter||form.querySelector('button[type=submit],button:not([type]),input[type=submit]');
  if(!submitter)return;
  const buttons=[...form.querySelectorAll('button[type=submit],button:not([type]),input[type=submit]')].map(button=>[button,button.disabled,button.innerHTML]);
  busyForms.set(form,{buttons});form.dataset.submitting='yes';form.setAttribute('aria-busy','true');
  const text=form.method==='get'?'Buscando…':/actualizar|editar/i.test(submitter.textContent)?'Actualizando…':/anular|eliminar|cancelar/i.test(submitter.textContent)?'Procesando…':'Guardando…';
  if(submitter.name)hidden(form,submitter.name,submitter.value);
  for(const [button] of buttons){button.disabled=true;button.classList.add('is-loading');}submitter.textContent=text;
  if(form.target==='_blank')setTimeout(()=>resetBusy(form),1800);
 });
 dialog?.addEventListener('close',()=>{const action=confirmed;confirmed=null;if(dialog.returnValue!=='confirm'||!action)return;action.form.dataset.confirmed='yes';if(action.submitter?.isConnected&&!action.submitter.disabled)action.form.requestSubmit(action.submitter);else action.form.requestSubmit();});
 document.addEventListener('input',event=>{const form=event.target.form;if(form?.target==='_blank'&&!busyForms.has(form)&&form.dataset.submissionUsed==='yes'){hidden(form,'_submission_id',crypto.randomUUID());delete form.dataset.submissionUsed;}});
 document.addEventListener('submit',event=>{if(!event.defaultPrevented&&event.target.method==='post')event.target.dataset.submissionUsed='yes';});
}
export function initDirectoryFilters(){
 const target=document.getElementById('patient-directory');const form=document.querySelector('form[data-directory-filter]');if(!target||!form)return;
 let controller;
 async function update(url,{navigate=true}={}){
  controller?.abort();controller=new AbortController();const region=document.getElementById('patient-directory');region.setAttribute('aria-busy','true');form.setAttribute('aria-busy','true');
  try{const response=await fetch(url,{signal:controller.signal,headers:{'X-Workspace-Fragment':'patients'}});if(response.redirected&&new URL(response.url).pathname.endsWith('/login')){location.assign(response.url);return;}if(!response.ok)throw Error('No se pudo actualizar el directorio.');const html=new DOMParser().parseFromString(await response.text(),'text/html');const replacement=html.getElementById('patient-directory');if(!replacement)throw Error('No se pudo actualizar el directorio.');region.replaceWith(replacement);identify();if(navigate)history.pushState({},'',url);document.getElementById('directory-error')?.remove();document.dispatchEvent(new CustomEvent('workspace:updated'));}
  catch(error){if(error.name!=='AbortError'){let notice=document.getElementById('directory-error');if(!notice){notice=document.createElement('div');notice.id='directory-error';notice.className='notice error';notice.setAttribute('role','alert');form.after(notice);}notice.textContent=error.message;}}
  finally{document.getElementById('patient-directory')?.removeAttribute('aria-busy');form.removeAttribute('aria-busy');resetBusy(form);}
 }
 form.addEventListener('submit',event=>{event.preventDefault();const next=new URL(location.href);for(const name of ['q','status']){const value=new FormData(form).get(name);if(value)next.searchParams.set(name,value);else next.searchParams.delete(name);}next.searchParams.set('page','1');update(next.pathname+next.search);});
 document.addEventListener('click',event=>{const link=event.target.closest('#patient-directory .pagination a');if(!link||event.ctrlKey||event.metaKey||event.shiftKey)return;event.preventDefault();update(link.getAttribute('href'));});
 addEventListener('popstate',()=>{const next=new URL(location.href);for(const name of ['q','status'])if(form.elements.namedItem(name))form.elements.namedItem(name).value=next.searchParams.get(name)||'';update(next.pathname+next.search,{navigate:false});});
}
