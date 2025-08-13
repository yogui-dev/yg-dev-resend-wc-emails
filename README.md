# YG_DEV - Reenviar Correos WooCommerce

Reenvía en bloque correos de WooCommerce para pedidos dentro de un rango de fechas, con filtros por estados, tipos de correo y exclusiones (por ejemplo, pagos contraentrega `cod`). Incluye modo simulación para evaluar el impacto antes de enviar.

- Autor: Yogui Dev
- Versión: 1.0.3
- Licencia: GPLv2 or later
- Requiere: WordPress + WooCommerce activos
- Archivo principal: `yg-dev-resend-wc-emails.php`

## Repositorio

Repositorio oficial: https://github.com/yogui-dev/yg-dev-resend-wc-emails

## Características

- Reenvío masivo por rango de fechas (inicio y término) usando la zona horaria del sitio.
- Filtro por estados de pedido (ej.: pending, on-hold, processing, completed, etc.).
- Selección de tipos de correo a reenviar:
  - Nuevo pedido (admin)
  - Pedido en espera (cliente)
  - Pedido en procesamiento (cliente)
  - Pedido completado (cliente)
  - Pedido fallido (cliente)
  - Pedido cancelado (cliente)
  - Factura/Detalles de pedido (cliente)
  - Pedido reembolsado (cliente)
- Exclusión de métodos de pago `cod` (por defecto activada).
- Modo simulación (no envía; solo calcula cuántos correos se enviarían).
- Opción para reenviar “Nuevo pedido (admin)” solo si no fue enviado antes, validando el meta `_new_order_email_sent`.

## Requisitos

- WordPress compatible con WooCommerce actual.
- WooCommerce activo: el menú y la pantalla solo se registran si WooCommerce está cargado.
- Permisos de usuario: `manage_woocommerce` (administración de WooCommerce).

## Instalación

1. Copia la carpeta del plugin `yg-dev-resend-wc-emails/` dentro de `wp-content/plugins/`.
2. Asegúrate de que el archivo principal `yg-dev-resend-wc-emails.php` está en esa carpeta.
3. Activa el plugin desde el panel de WordPress en “Plugins”.
4. Verás el submenú en: WooCommerce → Reenviar Emails.

## Uso

1. Ve a `WooCommerce → Reenviar Emails`.
2. Define el rango de fechas (Inicio y Término). El selector usa formato `datetime-local`.
3. Selecciona los estados de pedido a incluir.
4. Marca los tipos de correo que deseas reenviar.
5. Opcional:
   - Excluir pagos en efectivo (cod).
   - Solo reenviar “Nuevo pedido (admin)” si no fue enviado antes.
   - Activar “Simular (no envía, solo calcula)” para revisar resultados antes de hacer envíos.
6. Haz clic en “Ejecutar”.

Al finalizar, verás un resumen con:
- Pedidos afectados.
- Conteo de correos por tipo.
- Errores (si los hubo).
- Indicador si fue simulación.

## Detalles técnicos

- Pantalla y lógica principal en `yg-dev-resend-wc-emails.php`, función `yg_dev_resend_wc_emails_admin_page()`.
- Consulta de pedidos con `wc_get_orders()` filtrando por `status`, `date_created` y `meta_query` (para excluir `cod`).
- Se usa `WC()->mailer()->emails` y se dispara `->trigger($order_id)` de las clases de correo de WooCommerce, p. ej.:
  - `WC_Email_New_Order`
  - `WC_Email_Customer_On_Hold_Order`
  - `WC_Email_Customer_Processing_Order`
  - `WC_Email_Customer_Completed_Order`
  - `WC_Email_Failed_Order`
  - `WC_Email_Cancelled_Order`
  - `WC_Email_Customer_Invoice`
  - `WC_Email_Customer_Refunded_Order`
- Solo se enviarán correos que estén habilitados en WooCommerce → Ajustes → Correos electrónicos (`is_enabled()`).
- Conversión de fechas a formato MySQL respetando la zona horaria del sitio: `em_resend_wc_emails_to_mysql_datetime()`.

## Compatibilidad y notas

- Este plugin respeta la habilitación de emails en WooCommerce. Si un email está desactivado en los ajustes, no se enviará aunque lo marques aquí.
- El tipo “Factura/Detalles de pedido (cliente)” puede requerir parámetros adicionales en ciertas versiones de WooCommerce. Aquí se activa con el `trigger($order_id)` estándar.
- El valor por defecto del campo “Inicio” está configurado en el código como `2025-08-12T09:00`, y “Término” usa la hora actual del sitio.

## Permisos y seguridad

- Solo accesible a usuarios con capacidad `manage_woocommerce`.
- Se utiliza `check_admin_referer` para proteger el envío del formulario en la pantalla de administración.

## Solución de problemas

- “No se encontraron pedidos”: revisa rango de fechas, estados seleccionados o si estás excluyendo `cod` y tus pedidos son de ese método de pago.
- “No se envían correos”: verifica en WooCommerce → Ajustes → Correos electrónicos que el tipo esté habilitado. Revisa también logs de WooCommerce/SMTP.
- “Demora o timeouts”: el plugin intenta remover límites (`set_time_limit(0)`), pero en servidores con restricciones podrías necesitar reducir el rango o usar el modo simulación primero.

## Roadmap (ideas)

- Seleccionar/excluir métodos de envío.
- Paginación por lotes para sitios con muchos pedidos.
- Registro detallado a archivo/log.

## Changelog

- 1.0.2
  - Agregado: tabla de previsualización siempre visible bajo el formulario. Muestra, según los filtros actuales, una fila por Pedido x Tipo de correo a enviar. Respeta estados, rango de fechas, exclusión `cod`, tipos seleccionados, habilitación de emails en WooCommerce y la regla opcional "Nuevo pedido (admin) solo si no fue enviado antes".

- 1.0.1
  - Agregado: tabla de "Órdenes filtradas" en el resumen con columnas ID, #Pedido, Fecha, Estado, Total, Pago y Email.

- 1.0.0
  - Versión inicial: reenvío por rango de fechas, filtros por estado, tipos de correo, exclusión `cod`, modo simulación y regla para “Nuevo pedido (admin)” si no fue enviado antes.

## Licencia

Este plugin se distribuye bajo la licencia GPLv2 o posterior.

---

Por Yogui Dev — creando herramientas simples y útiles para tiendas con WooCommerce.

Si este plugin te ayudó, considera dejar una ⭐ en el repositorio:
https://github.com/yogui-dev/yg-dev-resend-wc-emails
¡Gracias por apoyar el software libre!
