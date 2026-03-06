/* global TMWSEOPlatformProfiles, wp */
(function () {
  if (typeof wp === 'undefined' || !wp.data || !wp.data.select) return;

  var select = wp.data.select;
  var subscribe = wp.data.subscribe;

  function currentPostType() {
    try {
      return select('core/editor').getCurrentPostType();
    } catch (e) {
      return null;
    }
  }

  function currentPostId() {
    try {
      return select('core/editor').getCurrentPostId();
    } catch (e) {
      return null;
    }
  }

  function isSaving() {
    try {
      return select('core/editor').isSavingPost();
    } catch (e) {
      return false;
    }
  }

  function isAutosaving() {
    try {
      return select('core/editor').isAutosavingPost();
    } catch (e) {
      return false;
    }
  }

  function readProfilesFromDOM() {
    var links = {};
    var inputs = document.querySelectorAll('input[name^="tmwseo_platform["]');
    inputs.forEach(function (inp) {
      var m = inp.name.match(/^tmwseo_platform\[([^\]]+)\]$/);
      if (!m) return;
      links[m[1]] = (inp.value || '').trim();
    });

    // Metabox uses tmwseo_platform_primary.
    var primarySel = document.querySelector('select[name="tmwseo_platform_primary"]');
    var primary = primarySel ? (primarySel.value || '') : '';

    return { links: links, primary: primary };
  }

  function sendAjaxSave() {
    if (!TMWSEOPlatformProfiles || !TMWSEOPlatformProfiles.ajaxUrl || !TMWSEOPlatformProfiles.nonce) return;
    if (currentPostType() !== 'model') return;

    var postId = currentPostId();
    if (!postId) return;

    var payload = readProfilesFromDOM();

    // If nothing is set, don't spam ajax.
    var hasAny = false;
    Object.keys(payload.links || {}).forEach(function (k) {
      if (payload.links[k]) hasAny = true;
    });
    if (!hasAny && !payload.primary) return;

    var form = new window.FormData();
    form.append('action', 'tmwseo_save_platform_profiles');
    form.append('_ajax_nonce', TMWSEOPlatformProfiles.nonce);
    form.append('post_id', String(postId));
    form.append('primary', String(payload.primary || ''));
    form.append('links', JSON.stringify(payload.links || {}));

    window.fetch(TMWSEOPlatformProfiles.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: form
    }).catch(function () {
      // silent
    });
  }

  var wasSaving = false;
  var sentThisSave = false;

  subscribe(function () {
    if (currentPostType() !== 'model') return;

    var saving = isSaving();

    // When a manual save starts, send a quick AJAX snapshot so the links
    // survive even if the editor re-renders or navigation happens.
    if (!wasSaving && saving && !isAutosaving()) {
      sentThisSave = true;
      sendAjaxSave();
    }

    // Detect the moment saving finishes (and ignore autosaves)
    if (wasSaving && !saving && !isAutosaving()) {
      // Also send at the end, as a best-effort confirmation.
      if (!sentThisSave) {
        sendAjaxSave();
      }
      sentThisSave = false;
    }

    wasSaving = saving;
  });
})();
