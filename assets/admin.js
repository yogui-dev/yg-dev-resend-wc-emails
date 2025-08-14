'use strict';
(function ($) {
  function collectFormData($form) {
    // Extraer los campos del formulario actual
    const data = {
      action: '', // se establece dinámicamente (start/step)
      nonce: YG_RESEND_CFG.nonce,
      start: $form.find('input[name="start"]').val() || '',
      end: $form.find('input[name="end"]').val() || '',
      statuses: [],
      emails: [],
      exclude_cod: $form.find('input[name="exclude_cod"]:checked').length ? 1 : 0,
      only_if_not_sent_admin: $form.find('input[name="only_if_not_sent_admin"]:checked').length ? 1 : 0,
      dry_run: $form.find('input[name="dry_run"]:checked').length ? 1 : 0,
      ignore_sent_flag: $form.find('input[name="ignore_sent_flag"]:checked').length ? 1 : 0,
    };

    $form.find('input[name="statuses[]"]:checked').each(function () {
      data.statuses.push($(this).val());
    });
    $form.find('input[name="emails[]"]:checked').each(function () {
      data.emails.push($(this).val());
    });

    return data;
  }

  function setProgress(pct, text) {
    $('#yg-resend-progress').show();
    $('#yg-resend-progress-bar').css('width', pct + '%');
    if (text) {
      $('#yg-resend-progress-text').text(text);
    }
  }

  function appendErrors(errors) {
    if (!errors || !errors.length) return;
    const $box = $('#yg-resend-errors');
    for (let i = 0; i < Math.min(errors.length, 10); i++) {
      const line = $('<div/>').text(errors[i]);
      $box.append(line);
    }
  }

  async function startJob($form) {
    const payload = collectFormData($form);
    payload.action = 'yg_resend_start';

    setProgress(0, YG_RESEND_CFG.i18n.starting || 'Iniciando...');
    $('#yg-resend-errors').empty();

    try {
      const resp = await $.post(YG_RESEND_CFG.ajaxurl, payload);
      if (!resp || !resp.success) {
        const msg = resp && resp.data && resp.data.message ? resp.data.message : (YG_RESEND_CFG.i18n.error || 'Error');
        setProgress(0, msg);
        return null;
      }
      const total = resp.data.total || 0;
      return { total };
    } catch (e) {
      setProgress(0, (YG_RESEND_CFG.i18n.error || 'Error') + ': ' + (e && e.message ? e.message : e));
      return null;
    }
  }

  async function stepJob($form, total, index, opts) {
    const batch = opts && opts.batch ? opts.batch : 20;
    const payload = collectFormData($form);
    payload.action = 'yg_resend_step';
    payload.nonce = YG_RESEND_CFG.nonce;
    payload.batch = batch;
    payload.index = index;
    try {
      const resp = await $.post(YG_RESEND_CFG.ajaxurl, payload);
      if (!resp || !resp.success) {
        const msg = resp && resp.data && resp.data.message ? resp.data.message : (YG_RESEND_CFG.i18n.error || 'Error');
        setProgress(0, msg);
        return { done: true, failed: true };
      }
      const d = resp.data || {};
      const idx = d.index || 0;
      const done = !!d.done;
      const sentCounts = d.sent_counts || {};
      const errors = d.errors || [];
      const lastOrderId = d.order_id || null;

      const pct = total > 0 ? Math.min(100, Math.round((idx / total) * 100)) : 0;
      let progressText = (YG_RESEND_CFG.i18n.processing || 'Procesando...') + ' ' + idx + ' / ' + total;
      if (lastOrderId) progressText += ' · ID ' + lastOrderId;
      setProgress(pct, progressText);
      appendErrors(errors);

      return { done, sentCounts, nextIndex: idx };
    } catch (e) {
      setProgress(0, (YG_RESEND_CFG.i18n.error || 'Error') + ': ' + (e && e.message ? e.message : e));
      return { done: true, failed: true };
    }
  }

  async function runAjaxFlow($form) {
    const started = await startJob($form);
    if (!started) return;
    let finished = false;
    const total = started.total;
    let index = 0;

    while (!finished) {
      const res = await stepJob($form, total, index, { batch: 1 });
      if (!res) break;
      if (res.done) {
        finished = true;
        break;
      }
      index = res.nextIndex || index;
      // Pequeña pausa para no saturar
      await new Promise((r) => setTimeout(r, 200));
    }

    setProgress(100, YG_RESEND_CFG.i18n.done || 'Listo');
  }

  $(document).ready(function () {
    const $btn = $('#yg-resend-ajax-btn');
    if (!$btn.length) return;
    $btn.on('click', function () {
      const $form = $(this).closest('form');
      $btn.prop('disabled', true);
      runAjaxFlow($form).finally(() => {
        $btn.prop('disabled', false);
      });
    });
  });
})(jQuery);
