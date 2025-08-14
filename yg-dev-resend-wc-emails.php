<?php

/**
 * Plugin Name: YG_DEV - Reenviar Correos WooCommerce
 * Description: Reenvía correos de WooCommerce en bloque para un rango de fechas. Permite elegir tipos de email, estados, y excluir métodos de pago (p. ej., contraentrega/cod).
 * Version: 1.0.4
 * Author: Yogui Dev
 * Plugin URI: https://github.com/yogui-dev/yg-dev-resend-wc-emails
 * License: GPLv2 or later
 * Requires Plugins: woocommerce
 * Text Domain: yg-dev-resend-wc-emails
 */

// Salir si se accede directamente
if (! defined('ABSPATH')) {
  exit;
}

// Verificar que WooCommerce esté activo antes de registrar el menú
add_action('plugins_loaded', function () {
  // Si WooCommerce no está activo, no registrar nada
  if (! class_exists('WooCommerce')) {
    return;
  }

  // Registrar la página de administración en el menú de WooCommerce
  add_action('admin_menu', 'yg_dev_resend_wc_emails_register_menu');
});

/**
 * Registrar submenú bajo WooCommerce.
 */
function yg_dev_resend_wc_emails_register_menu()
{
  // Solo administradores de WooCommerce
  if (! current_user_can('manage_woocommerce')) return;

  // Agregar página bajo WooCommerce -> Reenviar Emails
  add_submenu_page(
    'woocommerce',
    __('Reenviar Emails', 'yg-dev-resend-wc-emails'),
    __('Reenviar Emails', 'yg-dev-resend-wc-emails'),
    'manage_woocommerce',
    'yg-dev-resend-wc-emails',
    'yg_dev_resend_wc_emails_admin_page'
  );
}

/**
 * Renderizar la pantalla de administración y procesar el formulario.
 */
function yg_dev_resend_wc_emails_admin_page()
{
  // Chequear permisos
  if (! current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'yg-dev-resend-wc-emails'));
  }

  // Valores por defecto del formulario
  // NOTA: datetime-local usa hora "local del navegador"; aquí solo precargamos texto ISO sin zona.
  $default_start = '2025-08-12T09:00'; // según lo solicitado
  $default_end   = current_time('Y-m-d\TH:i'); // ahora, en zona del sitio

  // Estados por defecto seleccionados
  $default_statuses = array('wc-pending', 'wc-on-hold', 'wc-processing', 'wc-completed');

  // Inicializar mensajes y resultados
  $messages = array();
  $results  = array();

  // Detectar si el método de pago Contraentrega (cod) está activo en WooCommerce
  $is_cod_active = false;
  if (function_exists('WC') && WC()) {
    $gateways = WC()->payment_gateways();
    if ($gateways && method_exists($gateways, 'get_available_payment_gateways')) {
      $available = $gateways->get_available_payment_gateways();
      if (is_array($available) && isset($available['cod'])) {
        $cod = $available['cod'];
        // Algunos gateways exponen ->enabled = 'yes' cuando está activo
        if (is_object($cod) && isset($cod->enabled) && 'yes' === $cod->enabled) {
          $is_cod_active = true;
        } else {
          // fallback: si existe en disponibles lo consideramos activo
          $is_cod_active = true;
        }
      }
    }
  }

  // =============================
  // Previsualización siempre visible (pedidos x correos según filtros actuales)
  // =============================
  $current_start_raw = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : $default_start;
  $current_end_raw   = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : $default_end;
  $current_start_mysql = yg_dev_resend_wc_emails_to_mysql_datetime($current_start_raw);
  $current_end_mysql   = yg_dev_resend_wc_emails_to_mysql_datetime($current_end_raw);
  $current_statuses = isset($_POST['statuses']) && is_array($_POST['statuses']) ? array_map('sanitize_text_field', wp_unslash($_POST['statuses'])) : $default_statuses;
  if (empty($current_statuses)) {
    $current_statuses = $default_statuses;
  }
  $current_emails = isset($_POST['emails']) && is_array($_POST['emails']) ? array_map('sanitize_text_field', wp_unslash($_POST['emails'])) : array('admin_new_order', 'customer_processing', 'customer_completed');
  $current_exclude_cod = ! empty($_POST['exclude_cod']);
  $current_only_if_not_sent_admin = ! empty($_POST['only_if_not_sent_admin']);

  $preview_email_rows = array();
  if ($current_start_mysql && $current_end_mysql && $current_start_mysql <= $current_end_mysql) {
    $preview_args = array(
      'status'       => $current_statuses,
      'date_created' => $current_start_mysql . '...' . $current_end_mysql,
      'limit'        => -1,
      'return'       => 'ids',
    );
    if ($current_exclude_cod && $is_cod_active) {
      $preview_args['meta_query'] = array(
        array(
          'key'     => '_payment_method',
          'value'   => array('cod'),
          'compare' => 'NOT IN',
        ),
      );
    } elseif ($current_exclude_cod && ! $is_cod_active) {
      $messages[] = array('type' => 'warning', 'text' => __('La opción "Excluir pagos en efectivo (cod)" fue ignorada porque el método no está activo en el sitio.', 'yg-dev-resend-wc-emails'));
    }

    $preview_order_ids = wc_get_orders($preview_args);
    if (! empty($preview_order_ids)) {
      $mailer = WC()->mailer();
      $emails_objects = $mailer ? $mailer->emails : array();
      $email_map = array(
        'admin_new_order'        => 'WC_Email_New_Order',
        'customer_on_hold'       => 'WC_Email_Customer_On_Hold_Order',
        'customer_processing'    => 'WC_Email_Customer_Processing_Order',
        'customer_completed'     => 'WC_Email_Customer_Completed_Order',
        'customer_failed'        => 'WC_Email_Failed_Order',
        'customer_cancelled'     => 'WC_Email_Cancelled_Order',
        'customer_invoice'       => 'WC_Email_Customer_Invoice',
        'customer_refunded'      => 'WC_Email_Customer_Refunded_Order',
      );

      // Solo emails que existen y están registrados en Woo
      $preview_keys = array();
      foreach ($current_emails as $k) {
        if (isset($email_map[$k]) && isset($emails_objects[$email_map[$k]])) {
          $preview_keys[] = $k;
        }
      }

      $email_labels = array(
        'admin_new_order'     => __('Nuevo pedido (admin)', 'yg-dev-resend-wc-emails'),
        'customer_on_hold'    => __('Pedido en espera (cliente)', 'yg-dev-resend-wc-emails'),
        'customer_processing' => __('Pedido en procesamiento (cliente)', 'yg-dev-resend-wc-emails'),
        'customer_completed'  => __('Pedido completado (cliente)', 'yg-dev-resend-wc-emails'),
        'customer_failed'     => __('Pedido fallido (cliente)', 'yg-dev-resend-wc-emails'),
        'customer_cancelled'  => __('Pedido cancelado (cliente)', 'yg-dev-resend-wc-emails'),
        'customer_invoice'    => __('Factura/Detalles de pedido (cliente)', 'yg-dev-resend-wc-emails'),
        'customer_refunded'   => __('Pedido reembolsado (cliente)', 'yg-dev-resend-wc-emails'),
      );

      foreach ($preview_order_ids as $oid) {
        $order = wc_get_order($oid);
        if (! $order) continue;

        // Regla opcional para admin_new_order no enviado antes
        $skip_admin = false;
        if ($current_only_if_not_sent_admin && in_array('admin_new_order', $preview_keys, true)) {
          $already = get_post_meta($oid, '_new_order_email_sent', true);
          $skip_admin = ('1' === strval($already));
        }

        foreach ($preview_keys as $k) {
          if ('admin_new_order' === $k && $skip_admin) {
            continue;
          }
          $class = $email_map[$k];
          if (! isset($emails_objects[$class]) || ! method_exists($emails_objects[$class], 'is_enabled')) {
            continue;
          }
          if (! $emails_objects[$class]->is_enabled()) {
            continue;
          }
          $date_created = $order->get_date_created();
          $preview_email_rows[] = array(
            'order_id'   => (int) $oid,
            'order_num'  => method_exists($order, 'get_order_number') ? $order->get_order_number() : (string) $oid,
            'date'       => $date_created ? $date_created->date('Y-m-d H:i') : '',
            'email_key'  => $k,
            'email_name' => isset($email_labels[$k]) ? $email_labels[$k] : $k,
          );
        }
      }
    }
  }

  // Procesar envío del formulario
  if (isset($_POST['em_resend_submit'])) {
    // Verificar nonce de seguridad
    check_admin_referer('em_resend_wc_emails_action', 'em_resend_wc_emails_nonce');

    // Asegurar tiempo suficiente para procesos largos
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    // Sanitizar entradas
    $start_raw = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : $default_start;
    $end_raw   = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : $default_end;

    // Convertir a formato MySQL "YYYY-mm-dd HH:ii:ss"
    $start_mysql = yg_dev_resend_wc_emails_to_mysql_datetime($start_raw);
    $end_mysql   = yg_dev_resend_wc_emails_to_mysql_datetime($end_raw);

    // Estados seleccionados
    $statuses = isset($_POST['statuses']) && is_array($_POST['statuses']) ? array_map('sanitize_text_field', wp_unslash($_POST['statuses'])) : $default_statuses;
    if (empty($statuses)) {
      $statuses = $default_statuses;
    }

    // Emails seleccionados
    $emails_selected = isset($_POST['emails']) && is_array($_POST['emails']) ? array_map('sanitize_text_field', wp_unslash($_POST['emails'])) : array();

    // Excluir contraentrega (cod)
    $exclude_cod = ! empty($_POST['exclude_cod']);

    // Ejecutar en modo "prueba" (no envía, solo lista)
    $dry_run = ! empty($_POST['dry_run']);

    // Solo reenviar "Nuevo pedido (admin)" si no se envió antes (meta _new_order_email_sent != '1')
    $only_if_not_sent_admin = ! empty($_POST['only_if_not_sent_admin']);

    // Validación básica de fechas
    if (! $start_mysql || ! $end_mysql) {
      $messages[] = array('type' => 'error', 'text' => __('Formato de fecha/hora inválido. Usa el selector.', 'yg-dev-resend-wc-emails'));
    } elseif ($start_mysql > $end_mysql) {
      $messages[] = array('type' => 'error', 'text' => __('La fecha de inicio no puede ser mayor que la de término.', 'yg-dev-resend-wc-emails'));
    } else {
      // Construir args de consulta de pedidos
      $args = array(
        'status'       => $statuses,
        'date_created' => $start_mysql . '...' . $end_mysql,
        'limit'        => -1,
        'return'       => 'ids',
      );

      // Excluir métodos de pago (solo si COD está activo)
      if ($exclude_cod && $is_cod_active) {
        $args['meta_query'] = array(
          array(
            'key'     => '_payment_method', // slug del método de pago
            'value'   => array('cod'),
            'compare' => 'NOT IN',
          ),
        );
      } elseif ($exclude_cod && ! $is_cod_active) {
        $messages[] = array('type' => 'warning', 'text' => __('Se solicitó excluir pagos en efectivo (cod), pero el método no está activo. La exclusión fue ignorada.', 'yg-dev-resend-wc-emails'));
      }

      // Obtener pedidos
      $order_ids = wc_get_orders($args);

      // Si no hay pedidos, mostrar aviso y terminar
      if (empty($order_ids)) {
        $messages[] = array('type' => 'warning', 'text' => __('No se encontraron pedidos en el rango indicado con los filtros aplicados.', 'yg-dev-resend-wc-emails'));
      } else {
        // Cargar mailer y mapa de emails soportados
        $mailer = WC()->mailer();
        $emails = $mailer ? $mailer->emails : array();

        // Mapa "clave de formulario" => "Clase de email de WooCommerce"
        $email_map = array(
          'admin_new_order'        => 'WC_Email_New_Order',
          'customer_on_hold'       => 'WC_Email_Customer_On_Hold_Order',
          'customer_processing'    => 'WC_Email_Customer_Processing_Order',
          'customer_completed'     => 'WC_Email_Customer_Completed_Order',
          'customer_failed'        => 'WC_Email_Failed_Order',
          'customer_cancelled'     => 'WC_Email_Cancelled_Order',
          'customer_invoice'       => 'WC_Email_Customer_Invoice', // puede requerir parámetros extra en algunas versiones
          'customer_refunded'      => 'WC_Email_Customer_Refunded_Order',
        );

        // Preparar contenedor para detalles de pedidos
        $orders_data = array();

        // Filtrar solo emails existentes
        $to_send_keys = array();
        foreach ($emails_selected as $key) {
          if (isset($email_map[$key]) && isset($emails[$email_map[$key]])) {
            $to_send_keys[] = $key;
          }
        }

        // Validar selección de emails
        if (empty($to_send_keys)) {
          $messages[] = array('type' => 'error', 'text' => __('Selecciona al menos un tipo de correo a reenviar.', 'yg-dev-resend-wc-emails'));
        } else {
          // Contadores de envío por tipo
          $sent_counts = array_fill_keys($to_send_keys, 0);

          // Recorrer pedidos, recopilar detalles y (opcionalmente) enviar
          foreach ($order_ids as $order_id) {
            // Recopilar detalles del pedido para la tabla
            $order = wc_get_order($order_id);
            if ($order) {
              $date_created = $order->get_date_created();
              $orders_data[] = array(
                'id'       => (int) $order_id,
                'number'   => method_exists($order, 'get_order_number') ? $order->get_order_number() : (string) $order_id,
                'date'     => $date_created ? $date_created->date('Y-m-d H:i') : '',
                'status'   => $order->get_status(),
                'total'    => wc_price($order->get_total()),
                'method'   => $order->get_payment_method(),
                'email'    => $order->get_billing_email(),
              );
            }
            // Si se seleccionó la regla "solo si no se envió admin nuevo pedido", comprobar meta
            if ($only_if_not_sent_admin && in_array('admin_new_order', $to_send_keys, true)) {
              $already = get_post_meta($order_id, '_new_order_email_sent', true);
              if ('1' === strval($already)) {
                // Saltar envío de admin_new_order para este pedido
                // Pero aún así podríamos enviar otros tipos si están marcados
                $skip_admin_new_order = true;
              } else {
                $skip_admin_new_order = false;
              }
            } else {
              $skip_admin_new_order = false;
            }

            foreach ($to_send_keys as $key) {
              // Saltar admin_new_order si corresponde
              if ('admin_new_order' === $key && $skip_admin_new_order) {
                continue;
              }

              $class = $email_map[$key];

              // Si el email no existe o el objeto no tiene trigger, continuar
              if (! isset($emails[$class]) || ! method_exists($emails[$class], 'trigger')) {
                continue;
              }

              // Respetar configuración de WooCommerce: no enviar si el email está deshabilitado
              if (method_exists($emails[$class], 'is_enabled') && ! $emails[$class]->is_enabled()) {
                continue;
              }

              if ($dry_run) {
                // En modo prueba, no enviar; solo contar que "se habría enviado"
                $sent_counts[$key]++;
                continue;
              }

              // IMPORTANTE: Si el email está deshabilitado en WooCommerce, is_enabled() será false y no se enviará.
              // Llamar al trigger de WooCommerce para este email y pedido.
              // Muchos correos aceptan solo $order_id; algunos aceptan más parámetros, pero el mínimo suele funcionar.
              try {
                $emails[$class]->trigger($order_id);
                $sent_counts[$key]++;
              } catch (Exception $e) {
                // Registrar error por pedido/email (solo en memoria para mostrar al final)
                $results['errors'][] = sprintf('Pedido #%d: %s (%s)', (int) $order_id, esc_html($e->getMessage()), esc_html($class));
              }
            }
          }

          // Guardar resumen
          $results['orders_total'] = count($order_ids);
          $results['sent_counts']  = $sent_counts;
          $results['dry_run']      = $dry_run;
          $results['orders']       = $orders_data;

          if ($dry_run) {
            $messages[] = array('type' => 'info', 'text' => __('Simulación completa: no se enviaron correos. Revisa el resumen para ver cuántos se enviarían.', 'yg-dev-resend-wc-emails'));
          } else {
            $messages[] = array('type' => 'success', 'text' => __('Proceso finalizado. Revisa el resumen de envíos.', 'yg-dev-resend-wc-emails'));
          }
        }
      }
    }
  }

  // Render de la página
  echo '<div class="wrap">';
  echo '<h1>' . esc_html__('Reenviar correos de WooCommerce', 'yg-dev-resend-wc-emails') . '</h1>';

  // Mensajes
  foreach ($messages as $m) {
    $class = 'notice-' . $m['type'];
    printf('<div class="notice %1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($m['text']));
  }

  // Formulario
  echo '<form method="post">';
  wp_nonce_field('em_resend_wc_emails_action', 'em_resend_wc_emails_nonce');

  echo '<table class="form-table" role="presentation">';

  // Rango de fechas
  echo '<tr><th scope="row">' . esc_html__('Rango de fechas', 'yg-dev-resend-wc-emails') . '</th><td>';
  $start_val = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : $default_start;
  $end_val   = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : $default_end;
  printf('<label>%s <input type="datetime-local" name="start" value="%s" required></label> ', esc_html__('Inicio', 'yg-dev-resend-wc-emails'), esc_attr($start_val));
  printf('<label style="margin-left:12px;">%s <input type="datetime-local" name="end" value="%s" required></label>', esc_html__('Término', 'yg-dev-resend-wc-emails'), esc_attr($end_val));
  echo '<p class="description">' . esc_html__('Usa la zona horaria configurada en tu sitio (Ajustes -> General).', 'yg-dev-resend-wc-emails') . '</p>';
  echo '</td></tr>';

  // Estados
  echo '<tr><th scope="row">' . esc_html__('Estados de pedido', 'yg-dev-resend-wc-emails') . '</th><td>';
  $all_statuses = wc_get_order_statuses();
  $selected_statuses = isset($_POST['statuses']) && is_array($_POST['statuses']) ? array_map('sanitize_text_field', wp_unslash($_POST['statuses'])) : $default_statuses;
  foreach ($all_statuses as $slug => $label) {
    $checked = in_array($slug, $selected_statuses, true) ? 'checked' : '';
    printf(
      '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="statuses[]" value="%s" %s> %s</label>',
      esc_attr($slug),
      $checked,
      esc_html($label)
    );
  }
  echo '</td></tr>';

  // Tipos de email
  echo '<tr><th scope="row">' . esc_html__('Tipos de correos a reenviar', 'yg-dev-resend-wc-emails') . '</th><td>';
  $email_options = array(
    'admin_new_order'     => __('Nuevo pedido (admin)', 'yg-dev-resend-wc-emails'),
    'customer_on_hold'    => __('Pedido en espera (cliente)', 'yg-dev-resend-wc-emails'),
    'customer_processing' => __('Pedido en procesamiento (cliente)', 'yg-dev-resend-wc-emails'),
    'customer_completed'  => __('Pedido completado (cliente)', 'yg-dev-resend-wc-emails'),
    'customer_failed'     => __('Pedido fallido (cliente)', 'yg-dev-resend-wc-emails'),
    'customer_cancelled'  => __('Pedido cancelado (cliente)', 'yg-dev-resend-wc-emails'),
    'customer_invoice'    => __('Factura/Detalles de pedido (cliente)', 'yg-dev-resend-wc-emails'),
    'customer_refunded'   => __('Pedido reembolsado (cliente)', 'yg-dev-resend-wc-emails'),
  );
  $selected_emails = isset($_POST['emails']) && is_array($_POST['emails']) ? array_map('sanitize_text_field', wp_unslash($_POST['emails'])) : array('admin_new_order', 'customer_processing', 'customer_completed');
  foreach ($email_options as $key => $label) {
    $checked = in_array($key, $selected_emails, true) ? 'checked' : '';
    printf(
      '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="emails[]" value="%s" %s> %s</label>',
      esc_attr($key),
      $checked,
      esc_html($label)
    );
  }
  echo '<p class="description">' . esc_html__('Solo se enviarán los correos que estén habilitados en WooCommerce → Ajustes → Correos electrónicos.', 'yg-dev-resend-wc-emails') . '</p>';
  echo '</td></tr>';

  // Exclusiones y opciones
  echo '<tr><th scope="row">' . esc_html__('Filtros adicionales', 'yg-dev-resend-wc-emails') . '</th><td>';
  // Por defecto marcado en la primera carga; respeta la selección del usuario tras enviar
  $exclude_cod_checked = (isset($_POST['exclude_cod']) || ! isset($_POST['em_resend_submit'])) ? 'checked' : '';
  if ($is_cod_active) {
    printf('<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="exclude_cod" value="1" %s> %s</label>', $exclude_cod_checked, esc_html__('Excluir pagos en efectivo (cod)', 'yg-dev-resend-wc-emails'));
  } else {
    // Deshabilitar la opción si COD no está activo
    printf('<label style="display:block;margin-bottom:6px;opacity:0.7;"><input type="checkbox" name="exclude_cod" value="1" disabled> %s</label>', esc_html__('Excluir pagos en efectivo (cod) — no disponible (método no activo)', 'yg-dev-resend-wc-emails'));
  }
  $only_not_sent_admin_checked = ! empty($_POST['only_if_not_sent_admin']) ? 'checked' : '';
  printf('<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="only_if_not_sent_admin" value="1" %s> %s</label>', $only_not_sent_admin_checked, esc_html__('Solo enviar "Nuevo pedido (admin)" si no fue enviado antes (_new_order_email_sent ≠ 1)', 'yg-dev-resend-wc-emails'));

  $dry_run_checked = ! empty($_POST['dry_run']) ? 'checked' : '';
  printf('<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="dry_run" value="1" %s> %s</label>', $dry_run_checked, esc_html__('Simular (no envía, solo calcula)', 'yg-dev-resend-wc-emails'));

  echo '</td></tr>';

  echo '</table>';

  // Botón enviar (con nombre para detectar el submit)
  submit_button(__('Ejecutar', 'yg-dev-resend-wc-emails'), 'primary', 'em_resend_submit');

  echo '</form>';

  // =============================
  // Vista previa: correos por enviar según filtros actuales
  // =============================
  echo '<h2 style="margin-top:20px;">' . esc_html__('Vista previa de correos por enviar', 'yg-dev-resend-wc-emails') . '</h2>';
  if (empty($preview_email_rows)) {
    echo '<p>' . esc_html__('No hay correos para previsualizar con los filtros actuales.', 'yg-dev-resend-wc-emails') . '</p>';
  } else {
    printf('<p>' . esc_html__('%d elementos en la vista previa (pedido x tipo de correo).', 'yg-dev-resend-wc-emails') . '</p>', count($preview_email_rows));
    echo '<div style="overflow:auto">';
    echo '<table class="widefat striped" style="margin-top:10px;">';
    echo '<thead><tr>'
      . '<th>' . esc_html__('ID', 'yg-dev-resend-wc-emails') . '</th>'
      . '<th>' . esc_html__('# Pedido', 'yg-dev-resend-wc-emails') . '</th>'
      . '<th>' . esc_html__('Fecha', 'yg-dev-resend-wc-emails') . '</th>'
      . '<th>' . esc_html__('Correo', 'yg-dev-resend-wc-emails') . '</th>'
      . '</tr></thead>';
    echo '<tbody>';
    foreach ($preview_email_rows as $r) {
      echo '<tr>'
        . '<td>' . (int) $r['order_id'] . '</td>'
        . '<td>' . esc_html($r['order_num']) . '</td>'
        . '<td>' . esc_html($r['date']) . '</td>'
        . '<td>' . esc_html($r['email_name']) . '</td>'
        . '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
  }

  // Mostrar resumen si existe
  if (! empty($results)) {
    echo '<hr />';
    echo '<h2>' . esc_html__('Resumen de ejecución', 'yg-dev-resend-wc-emails') . '</h2>';
    printf('<p><strong>%s</strong>: %d</p>', esc_html__('Pedidos afectados', 'yg-dev-resend-wc-emails'), isset($results['orders_total']) ? (int) $results['orders_total'] : 0);

    if (! empty($results['sent_counts'])) {
      echo '<ul style="list-style:disc;padding-left:20px;">';
      foreach ($results['sent_counts'] as $key => $count) {
        echo '<li>' . esc_html($key) . ': ' . (int) $count . '</li>';
      }
      echo '</ul>';
    }

    // Tabla de pedidos
    if (! empty($results['orders'])) {
      echo '<h3>' . esc_html__('Órdenes filtradas', 'yg-dev-resend-wc-emails') . '</h3>';
      echo '<div style="overflow:auto">';
      echo '<table class="widefat striped" style="margin-top:10px;">';
      echo '<thead><tr>'
        . '<th>' . esc_html__('ID', 'yg-dev-resend-wc-emails') . '</th>'
        . '<th>' . esc_html__('# Pedido', 'yg-dev-resend-wc-emails') . '</th>'
        . '<th>' . esc_html__('Fecha', 'yg-dev-resend-wc-emails') . '</th>'
        . '<th>' . esc_html__('Estado', 'yg-dev-resend-wc-emails') . '</th>'
        . '<th>' . esc_html__('Total', 'yg-dev-resend-wc-emails') . '</th>'
        . '<th>' . esc_html__('Pago', 'yg-dev-resend-wc-emails') . '</th>'
        . '<th>' . esc_html__('Email', 'yg-dev-resend-wc-emails') . '</th>'
        . '</tr></thead>';
      echo '<tbody>';
      foreach ($results['orders'] as $row) {
        echo '<tr>'
          . '<td>' . (int) $row['id'] . '</td>'
          . '<td>' . esc_html($row['number']) . '</td>'
          . '<td>' . esc_html($row['date']) . '</td>'
          . '<td>' . esc_html($row['status']) . '</td>'
          . '<td>' . wp_kses_post($row['total']) . '</td>'
          . '<td>' . esc_html($row['method']) . '</td>'
          . '<td>' . esc_html($row['email']) . '</td>'
          . '</tr>';
      }
      echo '</tbody></table>';
      echo '</div>';
    }

    if (! empty($results['errors'])) {
      echo '<h3>' . esc_html__('Errores', 'yg-dev-resend-wc-emails') . '</h3>';
      echo '<ul style="list-style:disc;padding-left:20px;">';
      foreach ($results['errors'] as $err) {
        echo '<li>' . wp_kses_post($err) . '</li>';
      }
      echo '</ul>';
    }

    if (! empty($results['dry_run'])) {
      echo '<p><em>' . esc_html__('Fue una simulación: no se envió ningún correo.', 'yg-dev-resend-wc-emails') . '</em></p>';
    }
  }

  echo '</div>';
}

/**
 * Convertir valor de input datetime-local (YYYY-mm-ddTHH:ii) a formato MySQL (YYYY-mm-dd HH:ii:ss)
 * usando la zona horaria del sitio de WordPress.
 *
 * @param string $val Cadena desde el input.
 * @return string|false Fecha en formato MySQL o false en error.
 */
function yg_dev_resend_wc_emails_to_mysql_datetime($val)
{
  // Validación rápida
  if (! is_string($val) || empty($val)) {
    return false;
  }

  // Reemplazar la T por espacio; si no trae segundos, añadimos :00
  $val = str_replace('T', ' ', $val);
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $val)) {
    $val .= ':00';
  }

  // Crear objeto DateTime en la zona del sitio
  $tz_string = get_option('timezone_string');
  if (empty($tz_string)) {
    // Fallback a offset si no hay timezone_string
    $offset = (float) get_option('gmt_offset', 0);
    $hours  = (int) $offset;
    $mins   = ($offset - $hours) * 60;
    $tz_string = sprintf('%+03d:%02d', $hours, absint($mins));
    $dt = date_create($val . ' ' . $tz_string);
  } else {
    $dt = date_create($val, new DateTimeZone($tz_string));
  }

  if (! $dt) {
    return false;
  }

  // Devolver en formato MySQL (sin convertir a GMT, WooCommerce admite rango local en date_created)
  return $dt->format('Y-m-d H:i:s');
}
