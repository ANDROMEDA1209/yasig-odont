const controlSelector = 'input,select,textarea';
const ignoredTypes = new Set(['hidden', 'submit', 'button', 'reset', 'image']);
const initializedRoots = new WeakMap();
let nextControlId = 0;

function fields(form) {
 return [...form.elements].filter(field => field.matches?.(controlSelector) && !ignoredTypes.has(field.type));
}

function fieldLabel(field) {
 return field.labels?.[0] || field.closest('label');
}

function labelText(label) {
 let title = label.querySelector(':scope > .field-label-text');
 if (title) return title;
 const text = [...label.childNodes].find(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
 if (!text) return null;
 title = document.createElement('span');
 title.className = 'field-label-text';
 label.insertBefore(title, text);
 title.append(text);
 return title;
}

function identify(field) {
 const oldId = field.id;
 if (!oldId || (document.getElementById(oldId) && document.getElementById(oldId) !== field)) {
  let candidate;
  do { candidate = `validated-field-${++nextControlId}`; } while (document.getElementById(candidate));
  field.id = candidate;
  const label = field.closest('label');
  if (label && (!label.htmlFor || label.htmlFor === oldId)) label.htmlFor = field.id;
  label?.querySelectorAll('[data-required-for]').forEach(marker => {
   if (marker.dataset.requiredFor === oldId) marker.dataset.requiredFor = field.id;
  });
  const clonedError = label ? [...label.querySelectorAll('[data-validation-error]')].find(item => item.id === `${oldId}-error`) : null;
  if (clonedError) {
   descriptions(field, clonedError.id, false);
   clonedError.id = `${field.id}-error`;
   descriptions(field, clonedError.id, true);
  }
 }
 return field.id;
}

function descriptions(field, id, include) {
 const ids = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
 if (include) ids.add(id); else ids.delete(id);
 if (ids.size) field.setAttribute('aria-describedby', [...ids].join(' '));
 else field.removeAttribute('aria-describedby');
}

function clearError(field) {
 const errorId = `${identify(field)}-error`;
 const error = document.getElementById(errorId);
 if (error?.dataset.validationError !== undefined) error.remove();
 descriptions(field, errorId, false);
 field.removeAttribute('aria-invalid');
 delete field.dataset.serverFieldError;
 const label = fieldLabel(field);
 if (!label?.querySelector('[aria-invalid="true"]')) label?.classList.remove('field-has-error');
}

function showError(field, message, server = false) {
 const errorId = `${identify(field)}-error`;
 let error = document.getElementById(errorId);
 if (!error || error.dataset.validationError === undefined) {
  error = document.createElement('span');
  error.id = errorId;
  error.className = 'field-error';
  error.dataset.validationError = '';
  error.setAttribute('role', 'status');
  error.setAttribute('aria-live', 'polite');
  const label = fieldLabel(field);
  if (label) label.append(error);
  else field.insertAdjacentElement('afterend', error);
 }
 error.textContent = message;
 field.setAttribute('aria-invalid', 'true');
 descriptions(field, errorId, true);
 fieldLabel(field)?.classList.add('field-has-error');
 if (server) field.dataset.serverFieldError = 'true';
}

function messageFor(field) {
 const validity = field.validity;
 if (validity.valueMissing) {
  if (field.type === 'checkbox') return 'Marca esta casilla para continuar.';
  if (field.type === 'radio' || field.tagName === 'SELECT') return 'Selecciona una opción.';
  if (field.type === 'file') return 'Selecciona el archivo requerido.';
  return 'Completa este campo obligatorio.';
 }
 if (validity.typeMismatch) return field.type === 'email' ? 'Introduce un correo válido, por ejemplo nombre@correo.com.' : 'Introduce una dirección válida.';
 if (validity.badInput) return 'Introduce un valor válido para este campo.';
 if (validity.rangeUnderflow) return `El valor mínimo permitido es ${field.min}.`;
 if (validity.rangeOverflow) return `El valor máximo permitido es ${field.max}.`;
 if (validity.tooShort) return `Escribe al menos ${field.minLength} caracteres.`;
 if (validity.tooLong) return `Escribe un máximo de ${field.maxLength} caracteres.`;
 if (validity.stepMismatch) return field.type === 'number' ? `Introduce un valor compatible con el incremento de ${field.step || 1}.` : 'Selecciona un valor dentro de los intervalos permitidos.';
 if (validity.patternMismatch) return field.title || 'Revisa el formato de este campo.';
 return field.validationMessage || 'Revisa este campo antes de continuar.';
}

function validate(field) {
 if (!field.willValidate) { clearError(field); return true; }
 if (field.required && ['text', 'search', 'tel', 'url', 'email', 'textarea'].includes(field.type) && field.value.trim() === '') {
  showError(field, 'Completa este campo obligatorio.');
  return false;
 }
 if (!field.validity.valid) { showError(field, messageFor(field)); return false; }
 clearError(field);
 return true;
}

function reveal(field) {
 // Open outer containers before focusing an input inside a collapsed clinical section.
 const parents = [];
 for (let node = field.parentElement; node; node = node.parentElement) parents.push(node);
 parents.reverse().forEach(node => {
  if (node.tagName === 'DETAILS') node.open = true;
  if (node.tagName === 'DIALOG' && !node.open) node.showModal();
 });
 requestAnimationFrame(() => {
  field.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  field.focus({ preventScroll: true });
 });
}

function refreshRequired(form) {
 let requiredCount = 0;
 for (const field of fields(form)) {
  identify(field);
  const required = field.required && !field.disabled;
  const label = fieldLabel(field);
  let marker = label ? [...label.querySelectorAll('[data-required-for]')].find(item => item.dataset.requiredFor === field.id) : null;
  if (required) {
   requiredCount++;
   if (!marker && label) {
    marker = document.createElement('span');
    marker.className = 'required-indicator';
    marker.dataset.requiredFor = field.id;
    marker.setAttribute('aria-hidden', 'true');
    marker.textContent = ' *';
    if (['checkbox', 'radio'].includes(field.type)) label.append(marker);
    else {
     const title = labelText(label);
     if (title) title.append(marker);
     else label.insertBefore(marker, [...label.children].find(child => child !== marker) || null);
    }
   }
  } else marker?.remove();
 }
 let legend = form.querySelector(':scope > .required-legend');
 if (requiredCount && !legend) {
  legend = document.createElement('p');
  legend.className = 'required-legend full';
  legend.append('Los campos marcados con ');
  const marker = document.createElement('span');
  marker.className = 'required-indicator';
  marker.textContent = '*';
  legend.append(marker, ' son obligatorios.');
  form.prepend(legend);
 } else if (!requiredCount) legend?.remove();
}

/** Enhance existing forms without changing their submit, loading or confirmation flow. */
export function initFormValidation({ root = document, errors = {} } = {}) {
 if (initializedRoots.has(root)) {
  initializedRoots.get(root).refresh();
  return initializedRoots.get(root);
 }
 const knownForms = new WeakSet();
 let pendingServerFocus = null;
 let refreshQueued = false;

 const serverErrors = form => {
  let map = {};
  try { map = JSON.parse(form.dataset.formErrors || '{}'); } catch { /* Keep unrelated forms usable if feedback is malformed. */ }
  if (errors && typeof errors === 'object' && form.dataset.serverErrorForm !== undefined) map = { ...map, ...errors };
  if (!map || typeof map !== 'object' || Array.isArray(map)) return;
  for (const [name, message] of Object.entries(map)) {
   if (typeof message !== 'string') continue;
   const field = fields(form).find(item => item.name === name || item.name === `${name}[]`);
   if (!field || field.disabled) continue;
   showError(field, message, true);
   if (!pendingServerFocus) pendingServerFocus = field;
  }
 };
 const refresh = () => {
  const forms = [...root.querySelectorAll('form')];
  if (root.matches?.('form')) forms.unshift(root);
  forms.forEach(form => {
   if (form.method === 'dialog' || form.dataset.validation === 'off') return;
   if (!knownForms.has(form)) {
    knownForms.add(form);
    form.noValidate = true;
    refreshRequired(form);
    serverErrors(form);
   } else refreshRequired(form);
  });
 };
 const focusServerError = () => {
  if (!pendingServerFocus?.isConnected) return;
  const field = pendingServerFocus;
  pendingServerFocus = null;
  reveal(field);
 };

 root.addEventListener('submit', event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !knownForms.has(form) || event.submitter?.formNoValidate) return;
  form.dataset.validationAttempted = 'true';
  const invalid = fields(form).filter(field => !validate(field));
  if (invalid.length) {
   event.preventDefault();
   event.stopImmediatePropagation();
   reveal(invalid[0]);
  }
 }, true);

 root.addEventListener('invalid', event => {
  const field = event.target;
  if (!field.matches?.(controlSelector) || !field.form || !knownForms.has(field.form)) return;
  event.preventDefault();
  showError(field, messageFor(field));
 }, true);

 const onEdit = event => {
  const field = event.target;
  if (!field.matches?.(controlSelector) || !field.form || !knownForms.has(field.form)) return;
  if (field.hasAttribute('aria-invalid') || field.form.dataset.validationAttempted) validate(field);
  if (field.type === 'radio') fields(field.form).filter(other => other.name === field.name && other !== field).forEach(clearError);
 };
 root.addEventListener('input', onEdit);
 root.addEventListener('change', onEdit);
 root.addEventListener('focusout', event => {
  const field = event.target;
  if (field.matches?.(controlSelector) && field.form?.dataset.validationAttempted) validate(field);
 });
 root.addEventListener('reset', event => {
  if (!knownForms.has(event.target)) return;
  delete event.target.dataset.validationAttempted;
  fields(event.target).forEach(clearError);
 });
 document.addEventListener('workspace:restored', focusServerError);

 const observer = new MutationObserver(records => {
  records.forEach(record => {
   const field = record.target;
   if (record.type === 'attributes' && field.matches?.(controlSelector) && field.hasAttribute('aria-invalid') && (!field.dataset.serverFieldError || field.disabled)) validate(field);
  });
  if (!records.some(record => record.type === 'attributes' || [...record.addedNodes, ...record.removedNodes].some(node => node.nodeType === 1 && (node.matches?.('form,input,select,textarea') || node.querySelector?.('form,input,select,textarea'))))) return;
  if (refreshQueued) return;
  refreshQueued = true;
  queueMicrotask(() => { refreshQueued = false; refresh(); });
 });
 observer.observe(root, { subtree: true, childList: true, attributes: true, attributeFilter: ['required', 'disabled'] });
 const api = { refresh };
 initializedRoots.set(root, api);
 refresh();
 requestAnimationFrame(focusServerError);
 return api;
}
