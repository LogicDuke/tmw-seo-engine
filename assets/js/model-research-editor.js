/* global TMWSEOModelResearch, wp */
/**
 * Model Research — Gutenberg block-editor fallback save.
 *
 * Mirrors the structure of platform-profiles-editor.js exactly.
 *
 * Why this exists:
 * The block editor does not reliably submit classic metabox POST data on Save.
 * This script intercepts the Gutenberg save event, reads the Model Research
 * metabox fields directly from the DOM, and POSTs them to
 * admin-ajax.php?action=tmwseo_save_model_research so the data persists even
 * when the classic save_post handler never receives it.
 *
 * IDs used (already present via field_text / field_textarea / field_number helpers):
 *   #tmwseo_research_display_name
 *   #tmwseo_research_aliases
 *   #tmwseo_research_bio
 *   #tmwseo_research_platform_names
 *   #tmwseo_research_social_urls
 *   #tmwseo_research_country
 *   #tmwseo_research_language
 *   #tmwseo_research_source_urls
 *   #tmwseo_research_confidence
 *   #tmwseo_research_notes
 *   #tmwseo_editor_seed_summary
 *   #tmwseo_editor_seed_tags
 *   #tmwseo_editor_seed_platform_notes
 *   #tmwseo_editor_seed_confirmed_facts
 *   #tmwseo_editor_seed_avoid_claims
 *   #tmwseo_editor_seed_tone_hint
 *   #tmwseo_seed_external_bio              (v5.8.7)
 *   #tmwseo_seed_external_turn_ons         (v5.8.7)
 *   #tmwseo_seed_external_private_chat     (v5.8.7)
 */
(function () {
  if (typeof wp === 'undefined' || !wp.data || !wp.data.select) return;

  var select   = wp.data.select;
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

  /** Read the current text/textarea/number value for a given element ID. */
  function val(id) {
    var el = document.getElementById(id);
    return el ? (el.value || '').trim() : '';
  }

  /**
   * Collect all Model Research metabox fields from the DOM.
   * Returns null if none of the core fields are found (metabox not rendered).
   */
  function readFieldsFromDOM() {
    // Guard: if the primary identifier field doesn't exist the metabox is absent.
    if (!document.getElementById('tmwseo_research_aliases') &&
        !document.getElementById('tmwseo_research_display_name')) {
      return null;
    }

    return {
      display_name:   val('tmwseo_research_display_name'),
      aliases:        val('tmwseo_research_aliases'),
      bio:            val('tmwseo_research_bio'),
      platform_names: val('tmwseo_research_platform_names'),
      social_urls:    val('tmwseo_research_social_urls'),
      country:        val('tmwseo_research_country'),
      language:       val('tmwseo_research_language'),
      source_urls:    val('tmwseo_research_source_urls'),
      confidence:     val('tmwseo_research_confidence'),
      notes:          val('tmwseo_research_notes'),
      editor_seed_summary:         val('tmwseo_editor_seed_summary'),
      editor_seed_tags:            val('tmwseo_editor_seed_tags'),
      editor_seed_platform_notes:  val('tmwseo_editor_seed_platform_notes'),
      editor_seed_confirmed_facts: val('tmwseo_editor_seed_confirmed_facts'),
      editor_seed_avoid_claims:    val('tmwseo_editor_seed_avoid_claims'),
      editor_seed_tone_hint:       val('tmwseo_editor_seed_tone_hint'),
      // v5.8.7: 3 simple Model Research evidence textareas (humanized at generation time).
      seed_external_bio:           val('tmwseo_seed_external_bio'),
      seed_external_turn_ons:      val('tmwseo_seed_external_turn_ons'),
      seed_external_private_chat:  val('tmwseo_seed_external_private_chat'),
    };
  }

  function hasAnyData(fields) {
    if (!fields) return false;
    return Object.keys(fields).some(function (k) { return fields[k] !== ''; });
  }

  function sendAjaxSave() {
    if (!TMWSEOModelResearch || !TMWSEOModelResearch.ajaxUrl || !TMWSEOModelResearch.nonce) return;
    if (currentPostType() !== 'model') return;

    var postId = currentPostId();
    if (!postId) return;

    var fields = readFieldsFromDOM();
    if (!hasAnyData(fields)) return; // Nothing to save — skip

    var form = new window.FormData();
    form.append('action',      'tmwseo_save_model_research');
    form.append('_ajax_nonce', TMWSEOModelResearch.nonce);
    form.append('post_id',     String(postId));

    // Append each field individually so the PHP handler reads them as $_POST keys.
    Object.keys(fields).forEach(function (k) {
      form.append(k, fields[k]);
    });

    window.fetch(TMWSEOModelResearch.ajaxUrl, {
      method:      'POST',
      credentials: 'same-origin',
      body:        form,
    }).catch(function () {
      // Silent — never disrupt the normal save flow.
    });
  }

  // ── Subscribe to the editor save lifecycle ────────────────────────────────
  // Mirrors the subscribe pattern from platform-profiles-editor.js exactly.
  var wasSaving     = false;
  var sentThisSave  = false;

  subscribe(function () {
    if (currentPostType() !== 'model') return;

    var saving = isSaving();

    // Fire at the START of a manual save so fields are captured while the form
    // is still in the DOM (before any potential re-render).
    if (!wasSaving && saving && !isAutosaving()) {
      sentThisSave = true;
      sendAjaxSave();
    }

    // Fire again at the END of a save as a best-effort confirmation, but only
    // if we didn't already send at the start (avoids duplicate sends).
    if (wasSaving && !saving && !isAutosaving()) {
      if (!sentThisSave) {
        sendAjaxSave();
      }
      sentThisSave = false;
    }

    wasSaving = saving;
  });
})();
