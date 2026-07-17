/* global TMWSEOPublicProfileImport */
(function () {
  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
      return;
    }
    callback();
  }

  ready(function () {
    var button = document.getElementById('tmwseo-public-profile-import');
    var input = document.getElementById('tmwseo_public_profile_source_url');
    var result = document.getElementById('tmwseo-public-profile-import-result');
    if (!button || !input || !result || !window.TMWSEOPublicProfileImport) return;

    button.addEventListener('click', function () {
      var form = new window.FormData();
      form.append('action', 'tmwseo_public_profile_import');
      form.append('post_id', button.dataset.postId || '');
      form.append('nonce', button.dataset.nonce || '');
      form.append('source_url', input.value || '');
      button.disabled = true;
      result.textContent = 'Validating source URL…';

      window.fetch(TMWSEOPublicProfileImport.ajaxUrl, {
        method: 'POST', credentials: 'same-origin', body: form
      }).then(function (response) {
        return response.json();
      }).then(function (payload) {
        var data = payload && payload.data ? payload.data : {};
        result.textContent = data.message || 'The profile import request could not be completed.';
      }).catch(function () {
        result.textContent = 'The profile import request could not be completed.';
      }).finally(function () {
        button.disabled = false;
      });
    });
  });
})();
