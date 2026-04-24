/* global TMWSEOVerifiedLinks, wp */
/**
 * Verified External Links — Gutenberg persistence bridge.
 *
 * Captures grouped metabox rows from the DOM and saves them through admin-ajax
 * during manual block-editor saves so newly added/reordered rows persist.
 */
(function () {
  if (typeof wp === 'undefined' || !wp.data || !wp.data.select) return;

  var select = wp.data.select;
  var subscribe = wp.data.subscribe;

  function currentPostType() {
    try { return select('core/editor').getCurrentPostType(); } catch (e) { return null; }
  }

  function currentPostId() {
    try { return select('core/editor').getCurrentPostId(); } catch (e) { return null; }
  }

  function isSaving() {
    try { return select('core/editor').isSavingPost(); } catch (e) { return false; }
  }

  function isAutosaving() {
    try { return select('core/editor').isAutosavingPost(); } catch (e) { return false; }
  }

  function rowValue(row, idx, field) {
    var el = row.querySelector('[name="tmwseo_vl[' + idx + '][' + field + ']"], [name="tmwseo_vl[' + idx + '][' + field + '][]"]');
    if (!el) return '';
    if (el.type === 'checkbox') {
      return el.checked ? '1' : '';
    }
    return (el.value || '').trim();
  }

  function readRowsFromDOM() {
    var rows = [];
    var mainRows = document.querySelectorAll('tbody[id^="tmwseo-vl-rows-"] tr.tmwseo-vl-row');
    if (!mainRows.length) return null;

    mainRows.forEach(function (row) {
      var idx = row.getAttribute('data-idx');
      if (idx === null || idx === '') return;

      rows.push({
        type: rowValue(row, idx, 'type'),
        url: rowValue(row, idx, 'url'),
        label: rowValue(row, idx, 'label'),
        activity_level: rowValue(row, idx, 'activity_level'),
        activity_note: rowValue(row, idx, 'activity_note'),
        is_active: rowValue(row, idx, 'is_active'),
        is_primary: rowValue(row, idx, 'is_primary'),
        use_affiliate: rowValue(row, idx, 'use_affiliate'),
        affiliate_network: rowValue(row, idx, 'affiliate_network'),
        added_at: rowValue(row, idx, 'added_at'),
        promoted_from: rowValue(row, idx, 'promoted_from'),
        source_url: rowValue(row, idx, 'source_url'),
        outbound_type: rowValue(row, idx, 'outbound_type')
      });
    });

    return rows;
  }

  function sendAjaxSave() {
    if (!TMWSEOVerifiedLinks || !TMWSEOVerifiedLinks.ajaxUrl || !TMWSEOVerifiedLinks.nonce) return;
    if (currentPostType() !== 'model') return;

    var postId = currentPostId();
    if (!postId) return;

    var rows = readRowsFromDOM();
    if (rows === null) return;

    var form = new window.FormData();
    form.append('action', 'tmwseo_save_verified_links');
    form.append('_ajax_nonce', TMWSEOVerifiedLinks.nonce);
    form.append('post_id', String(postId));
    form.append('rows', JSON.stringify(rows));

    window.fetch(TMWSEOVerifiedLinks.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: form
    }).catch(function () {
      // Silent fallback; never interrupt editor save flow.
    });
  }

  var wasSaving = false;
  var sentThisSave = false;

  subscribe(function () {
    if (currentPostType() !== 'model') return;

    var saving = isSaving();

    if (!wasSaving && saving && !isAutosaving()) {
      sentThisSave = true;
      sendAjaxSave();
    }

    if (wasSaving && !saving && !isAutosaving()) {
      if (!sentThisSave) {
        sendAjaxSave();
      }
      sentThisSave = false;
    }

    wasSaving = saving;
  });
})();
