# Ortodoncia integrada

El módulo se abre en **Ortodoncia** dentro del menú del consultorio (`/admin/orthodontics`). No contiene pacientes ni estadísticas de demostración adicionales. Los indicadores se calculan sobre los expedientes accesibles al usuario y los filtros seleccionados.

## Instalación y actualización

Con la configuración habitual del proyecto, ejecutar `php scripts/console.php migrate`. La migración `006_orthodontics.php` es aditiva: conserva pacientes, odontogramas, citas, tratamientos, cobros y archivos existentes. Añade expedientes especializados, versiones de evaluaciones/planes, relaciones con controles/citas/adjuntos, adendas, tareas, consentimientos, miembros, borradores e historial. El esquema conserva claves foráneas, índices y una restricción de un episodio abierto por historia clínica. No ejecutar el restablecimiento de demo para instalar este módulo.

Las sesiones continúan en `treatment_sessions`, las fases en `treatment_stages`, los presupuestos en `treatment_plans`/`treatment_plan_items` y los originales en `attachments` + `storage/private`. Los cambios del estado especializado sincronizan la columna clínica del tratamiento institucional, conservando su historial. No liquidan ni modifican el estado financiero o los cobros automáticamente. Los respaldos de la base y de `storage/private` deben conservarse juntos conforme a la política documental de la clínica; no eliminar originales al cerrar un tratamiento.

## Flujo de uso

1. Abrir un expediente seleccionando un paciente institucional y su profesional. Si ya tiene un episodio, se recupera. Un nuevo episodio requiere una acción explícita y que el anterior esté cerrado.
2. Registrar una evaluación con fecha, hallazgos, medidas en milímetros y conclusión profesional. Las siguientes evaluaciones crean versiones; ninguna conclusión se calcula automáticamente.
3. Guardar el plan como borrador; completar objetivos y criterios de finalización para autorizarlo. Comparar versiones y personalizar fases. La estimación de meses no finaliza el tratamiento.
4. Firmar cada control, adjuntar fotografías/estudios y programar o vincular citas. Las correcciones de controles se registran como adendas. La fecha indicada de próximo control no reserva una cita: la reserva pasa por la agenda y sus validaciones de horario/solapamiento.
5. Cargar JPG/PNG/PDF de hasta 5 MB por archivo, hasta 12 por selección. Las fotografías solo admiten JPG/PNG. La galería conserva categoría, fecha, profesional, fase, control y original. Si un fallo del almacenamiento interrumpe una selección, se informa cuántos archivos ya se guardaron.
6. Registrar autorizaciones y revocaciones documentales, tareas y acceso de asistentes por expediente. Revisar historial y emitir informes con secciones seleccionadas. Los consentimientos son registros de la clínica, no sustituyen sus documentos firmados ni definen por sí solos una política legal de conservación.
7. Finalizar mediante confirmación profesional de criterios; documentar retenedor e indicaciones para retención. La finalización nunca depende únicamente del tiempo transcurrido.

## Permisos

`orthodontics` habilita el módulo. Administradores ven todos los casos; los profesionales clínicos acceden a los asignados mediante `dentists.user_id`. `orthodontics_assist` exige además una asignación activa en el expediente. Los asistentes pueden consultar, adjuntar archivos, programar citas y gestionar tareas del caso asignado; no firman diagnósticos, planes ni controles. Recepción y caja mantienen sus permisos habituales en los módulos existentes.

Los identificadores de casos, controles, fases, citas, consentimientos y archivos se validan en el servidor. Las descargas e informes aplican el mismo alcance que el expediente. El acceso directo a la antigua página de detalle de un tratamiento de ortodoncia conduce a su expediente especializado.

Los tipos, etiquetas y categorías pueden ampliarse mediante el ajuste `orthodontics_options` en `settings`: JSON con objetos `types`, `states` y `photo_categories`, cuyas claves usan letras minúsculas, números y guion bajo. Las claves de los estados principales conservan su significado; las opciones adicionales no sustituyen las comprobaciones clínicas de cierre. Cada caso permite fases personales. La gestión visual de este JSON no está incluida.

## IA y documentación

**No hay un proveedor externo de IA operativo en esta entrega y no se transmiten datos a terceros.** El asistente lo muestra expresamente. El resumen local recupera hechos existentes y prepara documentación editable; no interpreta preguntas clínicas, diagnostica, analiza imágenes ni predice movimientos. El profesional puede editar, validar o descartar el borrador. Se conservan el texto original, el revisado, el autor y la decisión.

`OrthodonticAIProvider` define el puerto desacoplado para una integración posterior. No basta añadir una clave a `.env` para activarlo: se necesita implementar y registrar un adaptador, su configuración secreta, minimizar contexto autorizado, verificar consentimiento vigente específico y autorización por envío, controlar tiempos/errores y auditar proveedor/modelo. La integración visual requiere además un modelo adecuado y revisión clínica. No se incluyen credenciales ni llamadas de red en el asistente actual.

## Comprobación

`php tests/run.php` reconstruye exclusivamente `yasig_odont_test` con fixtures ficticios. Incluye `tests/orthodontics.php`. Las comprobaciones HTTP/navegador se realizan con un servidor separado configurado para esa misma base; nunca ejecutar estas pruebas sobre la base de trabajo. Véase `ORTHODONTICS_QA.md` para cobertura y límites.
