# Dashboard y movimientos

El panel reutiliza PHP, PDO, MySQL, SVG, canvas y JavaScript modular. No añade dependencias ni tablas. El logotipo y el banner suministrados siguen integrados.

## Consulta y filtros

Al abrir Vista general se consultan los últimos 30 días, incluyendo hoy. Hoy, 7 días, 30 días y Este mes permiten cambiar el intervalo; también se aceptan fechas personalizadas de hasta 366 días. Fecha y paciente se aplican a indicadores, series, estados de citas, saldos y movimientos. Los filtros adicionales de búsqueda, tipo, estado y orden afectan las listas de movimientos; esto se indica junto a los controles. Cada lista tiene paginación independiente de seis registros y enlaces compartibles.

- Pacientes: activos registrados en el período.
- Citas: fecha de atención dentro del período; la distribución de estados suma el mismo total.
- Tratamientos: procedimientos completados por fecha de finalización.
- Ingresos: cobros registrados en el período que siguen vigentes. La suma de la serie diaria coincide con el indicador.
- Saldo: valor actual de los planes creados en el período, descontando pagos vigentes. No es un cierre histórico ni un ingreso.
- Próximas citas: visitas futuras dentro del período y paciente seleccionados. Si el período termina hoy y ya pasó la última cita del día, puede estar vacío; ampliar la fecha final para revisar próximas visitas.
- Inventario: alertas del stock actual, señaladas con ese alcance.

La gráfica permite elegir ingresos, cantidad de pagos, citas o tratamientos según los permisos. El puntero y el control de fecha permiten explorar cada día; se ofrece una tabla equivalente para teclado y lectores de pantalla.

## Naturaleza de los movimientos

**Financieros:** pagos y abonos, anulaciones contables, saldos calculados y valor presupuestado de procedimientos realizados. Se distinguen por tipo y estado. No se suman como si todos fueran cobros; solo los pagos vigentes alimentan ingresos. Una anulación mantiene el pago, el motivo y el responsable; no procesa una devolución bancaria.

**Clínica y agenda:** citas, cambios de cita, evoluciones firmadas, versiones de odontograma y tratamientos realizados. No se agregan importes financieros en esta lista. Los cambios nuevos de agenda conservan fecha/estado antes y después en auditoría. Los registros antiguos sin autor se identifican como «Sin autor registrado», sin inventar responsables. Los movimientos históricos que no fueron auditados originalmente no se reconstruyen como si hubiesen sido registrados.

Al seleccionar un movimiento se abre un panel con paciente, concepto, estado, fecha y responsable disponibles. Los detalles clínicos requieren permiso clínico; recepción no puede acceder a ellos solicitando su ID. Los enlaces llevan al comprobante, cita, historia, versión de odontograma o módulo correspondiente.

## Actualización y errores

`GET /admin/dashboard-data` devuelve fragmentos generados por el servidor con consultas reales, en una transacción de lectura con aislamiento REPEATABLE READ. Indicadores y tablas se calculan sobre una misma instantánea. La consulta normal y la actualización utilizan el mismo servicio.

El navegador consulta cada 15 segundos mientras la pestaña está visible, no se está editando el filtro y el panel de detalle está cerrado. También se puede actualizar manualmente. Las consultas en segundo plano no renuevan el tiempo de inactividad de la sesión. Se conserva la última consulta válida y se muestra un error si falla la conexión; no se generan datos ficticios de reemplazo. Si la sesión vence, vuelve al acceso; si se revoca el acceso al dashboard, retira sus datos visibles. Esto es actualización periódica, no WebSocket ni sincronización instantánea.

Los filtros funcionan también con una recarga GET si JavaScript está desactivado. La actualización automática, las animaciones y el panel lateral requieren JavaScript. No se agregan escrituras financieras al refrescar el panel.

## Integridad y verificación

Los cobros siguen usando bloqueo del plan, validación en centavos, clave de envío única, comprobante y auditoría en una transacción SQL. Repetir la misma solicitud no crea otro pago; reutilizar su clave con otro importe, método, referencia o plan se rechaza. Las anulaciones no eliminan el registro original.

Pruebas PHP/MySQL: `php tests/run.php`. El total actualizado es 115 pruebas, incluidas consistencia entre indicadores y series, separación de eventos, filtros, paginación, detalle, autorizaciones, base vacía e idempotencia.

Para el recorrido del dashboard, preparar nuevamente la base aislada con el test PHP y servirla en el puerto 8081 según `PRUEBAS.md`; ejecutar `node tests/dashboard-browser.cjs`. Comprueba filtros sin recarga, conservación de paciente al cambiar período, búsqueda, paginación, detalle y Escape, gráfico por teclado, actualización automática tras un cobro repetido, manejo de fallo de red, HTTP 401/403, anchos 320/390/768/1024/1440, Axe y movimiento reducido. Solo usa datos ficticios de la base de pruebas.
