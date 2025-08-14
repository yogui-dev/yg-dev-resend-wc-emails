# Changelog

Todas las modificaciones relevantes de este proyecto se documentan en este archivo.

## [1.0.6] - 2025-08-14

- Arreglo: capturar `wp_die` durante el `trigger` de emails para evitar caída del flujo AJAX. Se convierte `wp_die` en Exception y se restaura el handler en `finally`.
- Arreglo: procesamiento determinístico al ordenar las consultas por fecha ascendente (`orderby=date`, `order=ASC`).
- Mejora: mayor resiliencia ante pedidos problemáticos y verificación de permisos antes de agregar notas al pedido.

## [1.0.5]

- Mejora: notas en el pedido tras reenvío con detalle de correos enviados, fecha y usuario.
- Mejora: progreso e ID de último pedido procesado en la UI (AJAX), y manejo de errores más robusto.
- Varios: limpieza de código y pequeñas mejoras de UX.

## [1.0.4]

- Nuevo: ejecución por AJAX con barra de progreso y listado de errores recientes.
- Mejora: control de lotes en endpoint `step` y respuesta con contadores por tipo de correo.

## [1.0.3]

- Fixes varios: conversor de fecha/hora, respeto de `is_enabled` en emails, nombre del botón submit, checkbox de exclusión `COD`.
- Docs: sección de repositorio en README.

## [1.0.2]

- Agregado: tabla de previsualización siempre visible bajo el formulario. Muestra, según los filtros actuales, una fila por Pedido x Tipo de correo a enviar. Respeta estados, rango de fechas, exclusión `cod`, tipos seleccionados, habilitación de emails en WooCommerce y la regla opcional "Nuevo pedido (admin) solo si no fue enviado antes".

## [1.0.1]

- Agregado: tabla de "Órdenes filtradas" en el resumen con columnas ID, #Pedido, Fecha, Estado, Total, Pago y Email.

## [1.0.0]

- Versión inicial: reenvío por rango de fechas, filtros por estado, tipos de correo, exclusión `cod`, modo simulación y regla para “Nuevo pedido (admin)” si no fue enviado antes.
