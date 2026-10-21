# Interfaz del espacio de trabajo

La capa `public/assets/css/workspace-ui.css` adapta el panel administrativo y las pantallas de acceso sobre sus componentes actuales. Se carga después de los estilos compartidos y de los estilos del módulo para mantener la misma escala visual en dashboard, ortodoncia y gestión clínica.

## Identidad y lectura

- Fuente Manrope variable, alojada localmente en `public/assets/fonts`; no requiere servicios externos.
- Texto de trabajo y datos de tabla: 14 px. Etiquetas: 13,5 px y peso 600. Textos secundarios: 12–13 px. Títulos: peso 700.
- Fondos azul suave y turquesa, tarjetas con bordes definidos, encabezados diferenciados y sombras discretas.
- Verde para resultados correctos, azul para información y actividad, ámbar para pendientes y rojo para errores y anulaciones. Las etiquetas conservan su texto para comunicar el significado sin depender del color.
- Botones principales y campos con altura mínima de 44 px; acciones compactas de tabla con altura mínima de 38 px.

## Integración con formularios

Las clases `required-indicator`, `required-legend` y `field-label-text` conservan el asterisco junto al texto de la etiqueta. Los errores por campo pertenecen a `form-validation.css`; esta capa mantiene el contraste del control con `aria-invalid="true"`.

Los botones con `is-loading` o `aria-busy="true"` muestran un indicador de procesamiento. El bloqueo de solicitudes duplicadas pertenece al controlador de formularios en JavaScript, no al CSS. Las notificaciones de éxito del panel aparecen en una esquina para no desplazar el contenido restaurado después de una operación.

## Adaptación y accesibilidad

Las tablas permanecen completas dentro de una región desplazable; las acciones siguen disponibles al mover horizontalmente la tabla. Los formularios pasan a una columna en móvil, las métricas se distribuyen según el espacio disponible y las pestañas conservan desplazamiento horizontal dentro de su contenedor.

Se mantienen indicadores de foco por teclado y diálogos nativos. Las animaciones son breves y se desactivan con `prefers-reduced-motion`. La impresión elimina las sombras y conserva una presentación clara de los registros y recetas.

El alcance principal es `.app-layout` y `.auth-page`. Los diálogos adyacentes al panel se identifican mediante `body:has(>.app-layout,>.auth-page)`. La página web informativa mantiene sus estilos, imágenes y tiempos de transición independientes.

## Comprobación visual inicial

Se revisaron acceso, dashboard, pacientes y ortodoncia en Chromium a 320, 390, 768 y 1440 px, sin desbordamiento horizontal del documento. Se verificaron tamaños y pesos tipográficos calculados y se revisaron capturas de escritorio y móvil. La pantalla de acceso pasó el análisis automatizado WCAG 2 A/AA y WCAG 2.1 AA sin incidencias.

Estas comprobaciones visuales complementan las pruebas funcionales de navegación, formularios y conservación de contexto; no las sustituyen.
