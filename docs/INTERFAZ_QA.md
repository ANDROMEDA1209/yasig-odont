# Verificación de interfaz y conservación del contexto

La regresión de navegador está en `tests/workspace-browser.cjs`. Usa exclusivamente el servidor local `http://127.0.0.1:8081`, que debe estar conectado a `yasig_odont_test`. No se debe iniciar ese servidor con la base de trabajo. El script no restaura ni vacía bases de datos.

## Ejecución

1. Ejecutar `C:\xampp\php\php.exe tests/run.php` para reconstruir únicamente `yasig_odont_test` y verificar las reglas del servidor.
2. En otra consola PowerShell, desde la raíz del proyecto:

   ```powershell
   $env:DB_DATABASE = 'yasig_odont_test'
   C:\xampp\php\php.exe -S 127.0.0.1:8081 -t public public/router.php
   ```

3. Ejecutar `node tests/workspace-browser.cjs` desde la raíz del proyecto. Playwright y Axe se reutilizan desde `storage/qa/node_modules`; no se añade una dependencia de producción.
4. Para comprobar solamente la presentación y los filtros del dashboard, usar `node tests/workspace-browser.cjs --audit-only`.

La suite usa el administrador de la demo y genera 14 pacientes con un prefijo irrepetible `QAUI-...`. Crea un tratamiento, planes y un cobro de prueba; anula únicamente ese cobro. También abre un expediente ortodóntico con un control y una adenda ficticios. No modifica los datos clínicos preexistentes ni desactiva cuentas ajenas. Los fixtures permanecen en el esquema de pruebas para poder inspeccionarlos. El siguiente `tests/run.php` los reemplaza al reconstruir ese esquema.

## Escenarios cubiertos por el ejecutor

- Campos vacíos e inválidos: asteriscos, leyenda, errores específicos y asociación accesible con el campo; el navegador no realiza el POST inválido.
- Validación del servidor mediante una solicitud HTTP directa que omite JavaScript.
- Búsqueda y estado activo en el directorio con una segunda página real; edición desde esa página conservando sus parámetros y desplazamiento.
- Persistencia de los datos al volver a abrir la ficha editada.
- Guardado desde la parte inferior de tratamientos, reapertura del desplegable, indicador de carga y bloqueo de un segundo envío inmediato.
- Anulación con cancelación previa del diálogo: no se envía la acción ni se bloquea el formulario al cancelar. La confirmación envía un solo POST y conserva el registro anulado y la historia del paciente.
- Actualización AJAX del dashboard sin sustituir el documento ni perder la búsqueda.
- Expediente ortodóntico nuevo con un control ficticio firmado y una adenda: se conserva la pestaña de evolución, el filtro local y el desplegable tras guardar. Un intento de abrir un episodio duplicado verifica que el error conserva el modal, los datos y la búsqueda sin duplicar el caso.
- Acceso, directorio, agenda, historias, odontograma, tratamientos, ortodoncia, caja, inventario, reportes, usuarios, configuración y cuenta a 320, 390, 768 y 1440 píxeles.
- Auditoría automática WCAG A/AA con Axe tras completar fuentes y animaciones finitas; errores JavaScript, consola y recursos HTTP.
- Comprobación de que la hoja de interfaz se carga una sola vez y que la preferencia de movimiento reducido elimina las transiciones.

Las evidencias se escriben en `storage/workspace-qa/`: capturas de escritorio/móvil, resultado JSON y, si falla una comprobación, captura y JSON del fallo. Los resultados se actualizan tras ejecutar la suite; esta lista describe la cobertura del código y no implica por sí sola que las pruebas hayan pasado.

## Criterios para comprobar manualmente

La navegación por teclado, la claridad del lenguaje y el contenido clínico requieren además revisión humana. Comprobar que Tab mantiene un orden útil, que Escape cierra el diálogo de confirmación sin enviar, que un error dentro de un formulario largo enfoca el campo concreto y que `prefers-reduced-motion` reduce las transiciones. Confirmar adicionalmente los filtros locales de fotografías con la suite general de ortodoncia existente.

Las acciones de la aplicación conservan el historial clínico mediante anulaciones o correcciones auditadas. Esta prueba no introduce borrado físico de pacientes ni entradas clínicas para simular una acción de eliminación que el sistema no ofrece.
