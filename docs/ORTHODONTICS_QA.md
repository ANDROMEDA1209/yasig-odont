# Verificación de ortodoncia

## Ejecución comprobada · 21 de octubre de 2026

- Suite PHP completa: **245 pruebas correctas, 0 fallidas**, sobre `yasig_odont_test`; incluye 89 comprobaciones específicas del módulo.
- `node tests/orthodontics-browser.cjs`: recorrido real de apertura/reapertura de expediente, evaluación, plan autorizado, control firmado, carga múltiple HTTP de dos JPG, lectura del original privado, comparador lateral/deslizador, borrador local y validación profesional, PDF e impresión. Bloqueo HTTP 403 de expediente, informe y fotografía para otro odontólogo.
- Diez pestañas comprobadas a 390, 768 y 1440 px sin desbordamiento horizontal. Axe sin incidencias tras completarse las animaciones; sin errores JavaScript. Se corrigió el contraste de los números FDI.
- Verificación adicional de PDF con el logo de la clínica incrustado. Evidencias locales en `storage/orthodontics-*.png`, `storage/orthodontics-report-qa.pdf` y `storage/orth-ui-audit.json`.
- Migración 006 aplicada y verificada en la instalación local conservando sus registros. `/health` y `/login` responden 200 en el servidor de trabajo.

Los fixtures son ficticios y las fotografías de prueba son las imágenes públicas de marca, empleadas únicamente para verificar el almacenamiento; no son estudios clínicos. Las comprobaciones técnicas no validan decisiones clínicas ni constituyen certificación normativa. La IA externa permanece expresamente pendiente y no se realizan envíos a terceros.

## Pruebas automáticas

`php tests/run.php` utiliza exclusivamente `yasig_odont_test`. El ejecutor recrea ese esquema y carga fixtures ficticios; no debe apuntarse a la base de trabajo. `tests/orthodontics.php` se incluye después de las migraciones y de las pruebas existentes.

La cobertura comprueba persistencia al reabrir, ausencia de duplicados, conservación de diagnósticos y planes, autorización explícita, controles firmados y aclaraciones, finalización clínica, aislamiento financiero, citas de la agenda institucional, conflictos de horario, referencias cruzadas entre pacientes, permisos por profesional y autorización de adjuntos privados. También verifica que el resumen local esté identificado sin IA externa y que su validación conserve el borrador original.

Ejecución del 21 de octubre de 2026: `C:\xampp\php\php.exe tests/run.php` terminó con **241 pruebas correctas y 0 fallidas**, incluidas **85 comprobaciones de ortodoncia**. Se probó además el reenvío idempotente de controles, el rechazo de la misma clave con datos diferentes, la asignación y revocación de asistentes, los consentimientos, la retención, la creación explícita de un nuevo episodio conservando el anterior y el bloqueo de escrituras desde las vías genéricas de tratamientos.

Las fotografías ficticias del navegador deben cargarse por HTTP: `is_uploaded_file()` impide sustituir esta comprobación por una llamada CLI a `Uploads::save()`. La prueba CLI de metadatos privados no crea un archivo binario y no demuestra por sí sola que una descarga funcione.

## Flujos que requieren navegador

Usar el servidor de pruebas conectado a `yasig_odont_test`, datos ficticios y archivos JPG/PNG/PDF sin información personal.

1. Abrir Ortodoncia, buscar un paciente institucional y crear su expediente. Volver al listado y abrirlo otra vez: debe conservar el mismo expediente y sus datos.
2. Guardar una evaluación y una versión posterior. Consultar ambas y confirmar que mantienen autor, fecha, medidas y observaciones. Abrir dos pestañas y comprobar el rechazo de una escritura obsoleta.
3. Guardar un plan como borrador, autorizarlo mediante confirmación y crear otra versión. Comparar sus objetivos, criterios y fases. Los pagos y comprobantes previos deben conservarse.
4. Firmar un control, volver a abrirlo y registrar una aclaración con motivo. La evolución firmada original debe continuar visible.
5. Subir dos fotografías reales de prueba con categoría, fecha y control. Recargar, abrir los originales, seleccionarlas para comparación y mover el deslizador. Intentar subir un PDF como fotografía y un script con extensión JPG.
6. Vincular una cita existente del mismo paciente y profesional. Programar el siguiente control y comprobarlo en la agenda institucional. Intentar reservar otra cita superpuesta y cambiar una cita ajena modificando el identificador.
7. Abrir el mismo expediente con el profesional responsable, otro profesional, recepción y un asistente sin asignación. Probar tanto la URL del expediente como las URLs de fotografías, descarga e informes. Solo un asistente asignado debe obtener el alcance autorizado; no puede firmar diagnósticos, controles o planes.
8. Preparar un resumen local y confirmar que indica «sin IA externa». Editarlo y aceptarlo tras revisión; verificar conservación del original. Descartar otro borrador. Ninguna de estas acciones debe crear un tratamiento autónomo.
9. Intentar finalizar sin plan autorizado, sin confirmación y sin criterios. Completar con criterios clínicos explícitos y revisar fecha, profesional, historial e informe. Probar posteriormente la documentación de retención.
10. Generar informes con secciones seleccionadas, abrir el PDF y comprobar paciente, clínica, profesional, fechas y campos de firma. Revisar un expediente sin fotografías ni evaluaciones: debe mostrar vacíos honestos, sin resultados inventados.
11. Comprobar navegación, formularios, tablas, galería y comparación a 390 px, 768 px y escritorio. Verificar teclado, etiquetas, foco y conservación de datos ante un error de validación.

Registrar el resultado observado, el tamaño de pantalla y cualquier limitación real. La existencia de esta lista no implica que esos flujos hayan sido ejecutados.
