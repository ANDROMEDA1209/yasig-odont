export function initPublicSite(){
 if(!document.body.classList.contains('public-site'))return;
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 const header=document.querySelector('.public-header'),back=document.querySelector('.public-back-top'),visit=document.querySelector('.public-visit-bar'),footer=document.querySelector('.public-footer');

 // Shows/hides the floating back-to-top and visit-cta without the hard pop of toggling `hidden` directly:
 // `hidden` is only re-applied once the hide transition truly finishes, so it keeps animating smoothly
 // while staying out of the accessibility tree the rest of the time.
 const setVisible=(el,visible)=>{
  if(!el||visible===el.classList.contains('is-visible'))return;
  if(el._hideHandler){el.removeEventListener('transitionend',el._hideHandler);el._hideHandler=null;}
  if(visible){el.hidden=false;requestAnimationFrame(()=>el.classList.add('is-visible'));}
  else{el.classList.remove('is-visible');if(reduced.matches){el.hidden=true;}else{el._hideHandler=()=>{el.hidden=true;el._hideHandler=null;};el.addEventListener('transitionend',el._hideHandler,{once:true});}}
 };

 let scheduled=false,footerVisible=false;
 const onScroll=()=>{
  scheduled=false;
  const max=document.documentElement.scrollHeight-innerHeight;
  document.documentElement.style.setProperty('--public-progress',max>0?String(scrollY/max):'0');
  header.classList.toggle('is-scrolled',scrollY>30);
  setVisible(back,scrollY>=500);
  setVisible(visit,scrollY>=650 && !footerVisible && !document.querySelector('.booking-grid'));
 };
 addEventListener('scroll',()=>{if(!scheduled){scheduled=true;requestAnimationFrame(onScroll);}},{passive:true});addEventListener('resize',onScroll);onScroll();
 new IntersectionObserver(entries=>{footerVisible=entries[0].isIntersecting;onScroll();}).observe(footer);
 back.addEventListener('click',()=>{window.scrollTo({top:0,behavior:reduced.matches?'instant':'smooth'});header.querySelector('a')?.focus({preventScroll:true});});

 // Scroll reveal: every section/card/line fades, lifts and sharpens into place on its own beat.
 const targets=document.querySelectorAll('.hero-copy,.hero-brand-column,.public-brand-banner,.section-heading,.about-grid>div,.service-card,.team-card,.gallery-grid img,.testimonial-section blockquote,.faq>div,.contact-grid>div,.visit-journey>div,.visit-journey li,.page-intro,.booking-grid>aside,.booking-grid>.panel');
 if(!reduced.matches){const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('public-enter');observer.unobserve(entry.target);}}),{threshold:.08});targets.forEach((el,i)=>{el.style.setProperty('--public-delay',`${i%3*120}ms`);observer.observe(el);});}

 // Hero visual: a gentle cursor tilt on precise pointers, plus a slow drift as the page scrolls past it.
 const heroVisual=document.querySelector('.hero-visual--brand'),heroSection=document.querySelector('.hero');
 if(heroVisual && heroSection && !reduced.matches){
  if(matchMedia('(hover:hover) and (pointer:fine)').matches){
   let tiltRaf=null;
   heroVisual.addEventListener('pointermove',event=>{
    if(tiltRaf)return;
    tiltRaf=requestAnimationFrame(()=>{
     tiltRaf=null;
     const r=heroVisual.getBoundingClientRect();
     const px=(event.clientX-r.left)/r.width,py=(event.clientY-r.top)/r.height;
     heroVisual.style.setProperty('--public-tilt-y',`${(px-.5)*8}deg`);
     heroVisual.style.setProperty('--public-tilt-x',`${(.5-py)*8}deg`);
    });
   });
   heroVisual.addEventListener('pointerleave',()=>{heroVisual.style.setProperty('--public-tilt-x','0deg');heroVisual.style.setProperty('--public-tilt-y','0deg');});
  }
  let parRaf=false;
  const updateParallax=()=>{
   parRaf=false;
   const r=heroSection.getBoundingClientRect();
   if(r.bottom<0||r.top>innerHeight)return;
   const y=Math.max(-16,Math.min(16,-(r.top)/(r.height||1)*16));
   heroVisual.style.setProperty('--public-par-y',`${y}px`);
  };
  addEventListener('scroll',()=>{if(!parRaf){parRaf=true;requestAnimationFrame(updateParallax);}},{passive:true});
  updateParallax();
 }

 const search=document.getElementById('public-service-search');if(search){const cards=[...document.querySelectorAll('[data-service-search]')],count=document.getElementById('public-service-count');const diacritics=new RegExp('['+String.fromCharCode(768)+'-'+String.fromCharCode(879)+']','g');const normalize=value=>value.normalize('NFD').replace(diacritics,'').toLowerCase().trim();search.addEventListener('input',()=>{const term=normalize(search.value);let total=0;cards.forEach(card=>{const visible=normalize(card.dataset.serviceSearch).includes(term);card.hidden=!visible;if(visible)total++;});count.textContent=total?`${total} ${total===1?'servicio disponible':'servicios disponibles'}`:'No encontramos ese servicio. Prueba otro término o consulta al equipo.';});}
 // A real, keyboard-accessible lightbox for the existing local photographs.
 const photos=[...document.querySelectorAll('.gallery-grid>img')];let current=0,trigger=null;
 if(photos.length){const dialog=document.createElement('dialog');dialog.className='public-lightbox';dialog.setAttribute('aria-labelledby','public-photo-caption');const toolbar=document.createElement('div');toolbar.className='lightbox-toolbar';const label=document.createElement('span');label.textContent='NUESTRO ESPACIO · IMÁGENES DE REFERENCIA';const close=document.createElement('button');close.type='button';close.className='icon-button';close.textContent='×';close.setAttribute('aria-label','Cerrar fotografía');toolbar.append(label,close);const image=document.createElement('img'),caption=document.createElement('p');caption.id='public-photo-caption';const controls=document.createElement('div');controls.className='lightbox-controls';const previous=document.createElement('button'),next=document.createElement('button');previous.type=next.type='button';previous.className=next.className='button secondary';previous.textContent='← Anterior';next.textContent='Siguiente →';controls.append(previous,next);dialog.append(toolbar,image,caption,controls);document.body.append(dialog);
  const show=index=>{current=(index+photos.length)%photos.length;image.src=photos[current].src;image.alt=photos[current].alt;caption.textContent=`${current+1} / ${photos.length} · ${photos[current].alt}. Fotografía de referencia; no representa las instalaciones reales.`;};
  photos.forEach((photo,index)=>{const button=document.createElement('button');button.type='button';button.className='gallery-photo';button.setAttribute('aria-label',`Ampliar: ${photo.alt}`);photo.replaceWith(button);button.append(photo);button.addEventListener('click',()=>{trigger=button;show(index);dialog.showModal();});});
  close.addEventListener('click',()=>dialog.close());previous.addEventListener('click',()=>show(current-1));next.addEventListener('click',()=>show(current+1));dialog.addEventListener('keydown',e=>{if(e.key==='ArrowRight'){e.preventDefault();show(current+1);}if(e.key==='ArrowLeft'){e.preventDefault();show(current-1);}});dialog.addEventListener('close',()=>trigger?.focus({preventScroll:true}));dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
 }
 document.querySelectorAll('.faq details').forEach(details=>details.addEventListener('toggle',()=>{if(details.open)document.querySelectorAll('.faq details').forEach(other=>{if(other!==details)other.open=false;});}));
 // The mobile menu closes when a destination is chosen; desktop navigation stays native.
 document.querySelectorAll('#public-nav a').forEach(link=>link.addEventListener('click',()=>{document.getElementById('public-nav').classList.remove('open');document.querySelector('[aria-controls="public-nav"]')?.setAttribute('aria-expanded','false');}));
}
