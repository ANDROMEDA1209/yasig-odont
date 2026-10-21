import { initOdontogram } from './odontogram.js';
import { initCharts } from './charts.js';
import { initDashboard } from './dashboard.js';
import { initWorkspaceContext, initWorkspaceForms, initDirectoryFilters } from './workspace.js';
import { initFormValidation } from './form-validation.js';
initWorkspaceForms();
const restoreWorkspace=initWorkspaceContext();
initFormValidation();
document.querySelectorAll('.mobile-toggle').forEach(button=>button.addEventListener('click',()=>{const target=document.getElementById(button.getAttribute('aria-controls'));const open=target.classList.toggle('open');button.setAttribute('aria-expanded',String(open));}));
document.addEventListener('click',event=>{const sidebar=document.getElementById('sidebar');if(sidebar?.classList.contains('open') && !event.target.closest('#sidebar,.mobile-toggle')){sidebar.classList.remove('open');document.querySelector('[aria-controls="sidebar"]')?.setAttribute('aria-expanded','false');}});
document.addEventListener('keydown',event=>{if(event.key==='Escape'){document.querySelectorAll('.open').forEach(el=>el.classList.remove('open'));document.querySelectorAll('.mobile-toggle').forEach(el=>el.setAttribute('aria-expanded','false'));}});
document.querySelectorAll('.password-toggle').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.getAttribute('aria-controls'));const show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'Ocultar':'Mostrar';button.setAttribute('aria-pressed',String(show));}));
document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
// Anchor navigation opens the corresponding collapsed form.
document.querySelectorAll('a[href^="#"]').forEach(a=>a.addEventListener('click',()=>{const el=document.getElementById(a.hash.slice(1));if(el?.tagName==='DETAILS')el.open=true;}));
if(location.hash){const el=document.getElementById(location.hash.slice(1));if(el?.tagName==='DETAILS')el.open=true;}
initOdontogram();initCharts();initDashboard();initDirectoryFilters();
// CSP forbids inline style="" attributes, so dynamic widths (stage progress bar) are set here instead.
document.querySelectorAll('[data-percent]').forEach(el=>{el.style.width=Math.max(0,Math.min(100,Number(el.dataset.percent)||0))+'%';});
// Prescription medication repeater: clones the first row and lets the dentist add or remove lines
// before submitting — field names use drug_name[] etc. so PHP collects them as parallel arrays with
// no index bookkeeping needed here.
{
 const rxRows=document.getElementById('rx-rows');
 if(rxRows){
  const refreshRemovable=()=>{rxRows.querySelectorAll('.remove-rx-row').forEach(btn=>{btn.hidden=rxRows.children.length<=1;});};
  document.getElementById('add-rx-row')?.addEventListener('click',()=>{
   const clone=rxRows.firstElementChild.cloneNode(true);
   clone.querySelectorAll('input').forEach(input=>input.value='');
   rxRows.append(clone);
   refreshRemovable();
  });
  rxRows.addEventListener('click',event=>{
   const btn=event.target.closest('.remove-rx-row');
   if(!btn||rxRows.children.length<=1)return;
   btn.closest('.rx-row').remove();
   refreshRemovable();
  });
  refreshRemovable();
 }
}
// Clinical file comparator: pick exactly two files from the gallery to view them side by side.
// A static viewer for local images today; swapping in a DICOM-aware one later only touches this block.
{
 const gallery=document.getElementById('file-gallery');
 if(gallery){
  const viewer=document.getElementById('compare-viewer'),grid=document.getElementById('compare-grid');
  const sync=()=>{
   const picked=[...gallery.querySelectorAll('.compare-checkbox:checked')];
   gallery.querySelectorAll('.compare-checkbox').forEach(box=>{box.disabled=picked.length>=2 && !box.checked;});
   if(picked.length===2){
    grid.replaceChildren(...picked.map(box=>{
     const card=box.closest('.file-card'),figure=document.createElement('figure'),img=document.createElement('img'),caption=document.createElement('figcaption');
     img.src=card.dataset.url+'&inline=1';img.alt=card.dataset.caption;caption.textContent=card.dataset.caption;figure.append(img,caption);return figure;
    }));
    viewer.hidden=false;viewer.scrollIntoView({behavior:'smooth',block:'nearest'});
   } else viewer.hidden=true;
  };
  gallery.addEventListener('change',sync);
  document.getElementById('compare-close')?.addEventListener('click',()=>{gallery.querySelectorAll('.compare-checkbox').forEach(box=>{box.checked=false;box.disabled=false;});viewer.hidden=true;});
 }
}
const ready=[];
if(document.body.classList.contains('public-site'))ready.push(import('./public-site.js').then(module=>module.initPublicSite()));
if(document.getElementById('orthodontics'))ready.push(import('./orthodontics.js').then(module=>module.initOrthodontics()));
ready.push(document.fonts.ready);
Promise.allSettled(ready).then(restoreWorkspace);
document.querySelectorAll('.notice.success').forEach(notice=>{const close=document.createElement('button');close.type='button';close.className='notice-dismiss';close.setAttribute('aria-label','Cerrar notificación');close.textContent='×';close.addEventListener('click',()=>notice.remove());notice.append(close);setTimeout(()=>{notice.classList.add('notice-leaving');setTimeout(()=>notice.remove(),220);},10000);});
