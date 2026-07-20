/**
 * TMW SEO Engine — Rank Math Helper JS Bridge
 *
 * Purpose: editor convenience only. NOT a source of truth.
 *
 * This script:
 *   1. Prefills Rank Math snippet editor fields via wp.data dispatch (write-only).
 *   2. Handles "Sync Keywords" via AJAX through existing RankMathMapper.
 *   3. Handles "Refresh Checklist" via AJAX.
 *
 * Design rules:
 *   - Write-oriented: dispatches values INTO the RM store, never reads for correctness.
 *   - Safe if Rank Math is missing: checks for wp.data.select('rank-math') before dispatch.
 *   - Minimal: no DOM scraping, no subscription to RM store changes.
 *   - Loaded only on supported edit screens (controlled by PHP enqueue logic).
 *
 * @since 4.5.0
 */
(function ($) {
    'use strict';

    /* ──────────────────────────────────────────────
     * Rank Math wp.data bridge (write-only)
     * ────────────────────────────────────────────── */

    /**
     * Check if the Rank Math data store is available.
     */
    function hasRankMathStore() {
        return !!(window.wp && wp.data && wp.data.select && wp.data.dispatch && wp.data.select('rank-math'));
    }

    /**
     * Prefill Rank Math snippet editor fields from the panel's data attributes.
     * Write-only: dispatches values, does not read RM state.
     */
    function prefillSnippet() {
        if (!hasRankMathStore()) {
            alert('Rank Math editor is not loaded. Save the post and try again on a Gutenberg edit screen.');
            return;
        }

        var $data = $('#tmwseo-rm-prefill-data');
        if (!$data.length) {
            return;
        }

        var dispatch = wp.data.dispatch('rank-math');
        var title = $data.attr('data-title') || '';
        var desc = $data.attr('data-description') || '';
        var keyword = $data.attr('data-keyword') || '';

        if (title) {
            dispatch.updateSerpTitle(title);
            dispatch.updateTitle(title);
        }
        if (desc) {
            dispatch.updateSerpDescription(desc);
            dispatch.updateDescription(desc);
        }
        if (keyword) {
            dispatch.updateKeywords(keyword);
        }

        showNotice('success', 'Snippet fields prefilled. Check the Rank Math panel to verify.');
    }

    /* ──────────────────────────────────────────────
     * AJAX: Sync keywords
     * ────────────────────────────────────────────── */

    function syncKeywords(btn) {
        var $btn = $(btn);
        var postId = $btn.attr('data-post-id');
        if (!postId) return;

        $btn.prop('disabled', true).text('Syncing…');

        $.post(tmwseoRMHelper.ajaxUrl, {
            action: 'tmwseo_rm_helper_prefill',
            nonce: tmwseoRMHelper.nonce,
            post_id: postId,
            prefill_action: 'sync_keywords'
        }, function (response) {
            if (response && response.success) {
                showNotice('success', response.data.message || 'Keywords synced.');
                // Refresh checklist after sync.
                refreshChecklist(postId);
            } else {
                showNotice('error', (response && response.data && response.data.message) || 'Sync failed.');
            }
        }).fail(function () {
            showNotice('error', 'Request failed.');
        }).always(function () {
            $btn.prop('disabled', false).text('Sync Keywords to Rank Math');
        });
    }

    /* ──────────────────────────────────────────────
     * AJAX: Refresh checklist
     * ────────────────────────────────────────────── */

    function refreshChecklist(postId) {
        var $panel = $('#tmwseo-rm-checklist');
        if (!$panel.length) return;

        $panel.css('opacity', '0.5');

        $.post(tmwseoRMHelper.ajaxUrl, {
            action: 'tmwseo_rm_helper_refresh',
            nonce: tmwseoRMHelper.nonce,
            post_id: postId
        }, function (response) {
            if (response && response.success && response.data && response.data.html) {
                $panel.html(
                    '<p style="margin:0 0 6px"><strong>SEO Checklist</strong></p>' +
                    response.data.html
                );
            }
        }).always(function () {
            $panel.css('opacity', '1');
        });
    }

    /* ──────────────────────────────────────────────
     * WP notice helper (Gutenberg snackbar or fallback)
     * ────────────────────────────────────────────── */

    function showNotice(type, message) {
        if (window.wp && wp.data && wp.data.dispatch && wp.data.dispatch('core/notices')) {
            wp.data.dispatch('core/notices').createNotice(type, message, {
                type: 'snackbar',
                isDismissible: true
            });
        } else {
            alert(message);
        }
    }

    /* ──────────────────────────────────────────────
     * Event binding
     * ────────────────────────────────────────────── */

    function bindEvents() {
        // Prefill snippet (writes to RM editor store).
        $(document).on('click', '#tmwseo-rm-prefill-snippet', function (e) {
            e.preventDefault();
            prefillSnippet();
        });

        // Sync keywords via AJAX + RankMathMapper.
        $(document).on('click', '#tmwseo-rm-sync-keywords', function (e) {
            e.preventDefault();
            syncKeywords(this);
        });

        // Refresh checklist via AJAX.
        $(document).on('click', '#tmwseo-rm-refresh', function (e) {
            e.preventDefault();
            var postId = $(this).attr('data-post-id');
            if (postId) {
                $(this).prop('disabled', true).text('Refreshing…');
                refreshChecklist(postId);
                var $btn = $(this);
                setTimeout(function () {
                    $btn.prop('disabled', false).text('Refresh Checklist');
                }, 1500);
            }
        });
    }

    /* ──────────────────────────────────────────────
     * Init
     * ────────────────────────────────────────────── */

    $(document).ready(function () {
        bindEvents();
    });

})(jQuery);
