(function () {
    const bindQueueButton = (button, options = {}) => {
        if (!button || button.dataset.bound === '1') {
            return;
        }

        button.dataset.bound = '1';

        button.addEventListener('click', async () => {
            const controls = button.closest('.tmwseo-generate-controls') || document;
            const strategyField = controls.querySelector('[data-tmwseo-generate-strategy]') || document.getElementById('tmwseo-generate-strategy');
            const insertBlockField = controls.querySelector('[data-tmwseo-generate-insert-block]') || document.getElementById('tmwseo-generate-insert-block');
            const postId = button.dataset.postId;
            const nonce = button.dataset.nonce;
            const ajaxUrl = button.dataset.ajaxUrl;

            if (!postId || !nonce || !ajaxUrl) {
                return;
            }

            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = options.loadingText || 'Generating...';

            const formData = new URLSearchParams();
            formData.append('action', 'tmwseo_generate_now');
            formData.append('post_id', postId);
            formData.append('nonce', nonce);
            formData.append('strategy', strategyField ? strategyField.value : 'openai');
            const insertBlockValue = options.insertBlockValue !== undefined ? options.insertBlockValue : (insertBlockField && insertBlockField.checked ? '1' : '0');
            formData.append('insert_block', insertBlockValue);
            if (options.refreshKeywordsOnly) {
                formData.append('refresh_keywords_only', '1');
            }

            try {
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body: formData.toString(),
                });

                const rawText = await response.text();
                let data = null;
                try {
                    data = rawText ? JSON.parse(rawText) : null;
                } catch (parseError) {
                    // Server returned HTML or PHP output instead of JSON.
                    // This is usually a PHP notice/warning bleeding before the response.
                    // The ob_start() wrapper in the AJAX handler should prevent this,
                    // but surface a clear message if it still happens.
                    let errMsg = 'Server returned non-JSON output. Check the PHP error log.';
                    if (rawText) {
                        const flat = rawText.replace(/\s+/g, ' ').trim();
                        const looksLikePhp = /Warning:|Notice:|Fatal error:|Parse error:|<(?!a[ >])/i.test(flat);
                        if (looksLikePhp) {
                            errMsg = 'PHP error during generation — check debug log. Snippet: ' + flat.slice(0, 120);
                        } else {
                            errMsg = flat.slice(0, 180) || errMsg;
                        }
                    }
                    throw new Error(errMsg);
                }
                if (!response.ok || !data || !data.success) {
                    const message = data && data.data && data.data.message ? data.data.message : 'Request failed.';
                    throw new Error(message);
                }

                const successMessage = data && data.data && data.data.message
                    ? data.data.message
                    : (options.successText || 'Queued. Refresh in a few seconds.');

                if (window.wp && wp.data) {
                    const rankMathCsv = data && data.data && data.data.rank_math_focus_keyword
                        ? data.data.rank_math_focus_keyword
                        : '';
                    if (rankMathCsv) {
                        try {
                            const rankMathStore = wp.data.dispatch('rank-math');
                            if (rankMathStore && typeof rankMathStore.updateKeywords === 'function') {
                                rankMathStore.updateKeywords(rankMathCsv);
                            }
                        } catch (rankMathError) {
                            // Rank Math's editor store is optional and absent on some screens.
                        }
                    }
                    wp.data.dispatch('core/notices').createNotice('success', successMessage, {
                        type: 'snackbar',
                        isDismissible: true,
                    });
                }

                if (data && data.data && data.data.reload) {
                    window.setTimeout(() => {
                        window.location.reload();
                    }, 900);
                }
            } catch (error) {
                const message = error && error.message ? error.message : 'Request failed.';
                if (window.wp && wp.data) {
                    wp.data.dispatch('core/notices').createNotice('error', message, {
                        type: 'snackbar',
                        isDismissible: true,
                    });
                }
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    };

    const bindRerunPreviewPhrasesButton = (button) => {
        if (!button || button.dataset.bound === '1') {
            return;
        }

        button.dataset.bound = '1';

        button.addEventListener('click', async () => {
            const postId   = button.dataset.postId;
            const nonce    = button.dataset.nonce;
            const ajaxUrl  = button.dataset.ajaxUrl;

            if (!postId || !nonce || !ajaxUrl) {
                return;
            }

            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Re-running...';

            const formData = new URLSearchParams();
            formData.append('action',   'tmwseo_rerun_model_preview_phrases');
            formData.append('post_id',  postId);
            formData.append('nonce',    nonce);

            try {
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body: formData.toString(),
                });

                const rawText = await response.text();
                let data = null;
                try {
                    data = rawText ? JSON.parse(rawText) : null;
                } catch (parseError) {
                    let errMsg = 'Server returned non-JSON output. Check the PHP error log.';
                    if (rawText) {
                        const flat = rawText.replace(/\s+/g, ' ').trim();
                        errMsg = flat.slice(0, 180) || errMsg;
                    }
                    throw new Error(errMsg);
                }

                if (!response.ok || !data || !data.success) {
                    const message = data && data.data && data.data.message ? data.data.message : 'Request failed.';
                    throw new Error(message);
                }

                const successMessage = (data && data.data && data.data.message)
                    ? data.data.message
                    : 'Preview phrases rebuilt.';

                if (window.wp && wp.data) {
                    wp.data.dispatch('core/notices').createNotice('success', successMessage, {
                        type: 'snackbar',
                        isDismissible: true,
                    });
                }

                if (data && data.data && data.data.reload) {
                    window.setTimeout(() => {
                        window.location.reload();
                    }, 900);
                }
            } catch (error) {
                const message = error && error.message ? error.message : 'Request failed.';
                if (window.wp && wp.data) {
                    wp.data.dispatch('core/notices').createNotice('error', message, {
                        type: 'snackbar',
                        isDismissible: true,
                    });
                }
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    };


    const registerGutenbergGeneratePanel = () => {
        const config = window.tmwseoGenerateSidebar || null;
        if (!config || !config.postId || config.registered) {
            return;
        }
        if (!window.wp || !wp.plugins || !wp.editPost || !wp.element) {
            return;
        }

        const el = wp.element.createElement;
        const PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
        const registerPlugin = wp.plugins.registerPlugin;

        if (!PluginDocumentSettingPanel || typeof registerPlugin !== 'function') {
            return;
        }

        config.registered = true;
        const strategyOptions = [
            el('option', { value: 'template', key: 'template' }, 'Template'),
            el('option', { value: 'openai', key: 'openai' }, config.hasOpenAI ? 'OpenAI (if configured)' : 'OpenAI (not configured)'),
            el('option', { value: 'claude', key: 'claude' }, config.hasClaude ? 'Claude (Anthropic)' : 'Claude (not configured)'),
        ];

        const TmwGeneratePanel = () => el(
            PluginDocumentSettingPanel,
            {
                name: 'tmwseo-generate-sidebar',
                title: 'TMW Generate',
                className: 'tmwseo-generate-sidebar-panel',
            },
            el('div', { className: 'tmwseo-generate-controls' },
                el('p', { className: 'tmwseo-mb-field' },
                    el('label', { htmlFor: 'tmwseo-generate-sidebar-strategy' }, 'Strategy'),
                    el('select', {
                        id: 'tmwseo-generate-sidebar-strategy',
                        style: { width: '100%' },
                        defaultValue: config.defaultStrategy || 'template',
                        'data-tmwseo-generate-strategy': '1',
                    }, strategyOptions)
                ),
                el('p', { style: { margin: '0 0 4px' } },
                    el('label', {},
                        el('input', {
                            type: 'checkbox',
                            id: 'tmwseo-generate-sidebar-insert-block',
                            value: '1',
                            defaultChecked: config.insertBlockDefault !== false,
                            'data-tmwseo-generate-insert-block': '1',
                        }),
                        ' Insert content block'
                    )
                ),
                config.modelHelp ? el('p', { className: 'tmwseo-mb-help', style: { marginTop: 0 } }, config.modelHelp) : null,
                el('div', { className: 'tmwseo-mb-btn-stack' },
                    el('button', {
                        type: 'button',
                        id: 'tmwseo-generate-sidebar-btn',
                        className: 'button button-primary',
                        'data-post-id': String(config.postId),
                        'data-nonce': config.nonce || '',
                        'data-ajax-url': config.ajaxUrl || '',
                    }, 'Generate')
                )
            )
        );

        registerPlugin('tmwseo-generate-sidebar', { render: TmwGeneratePanel });
    };

    const setup = () => {
        registerGutenbergGeneratePanel();

        const generateButton              = document.getElementById('tmwseo-generate-btn');
        const generateSidebarButton       = document.getElementById('tmwseo-generate-sidebar-btn');
        const refreshKeywordsButton       = document.getElementById('tmwseo-refresh-keywords-btn');
        const rerunPreviewPhrasesButton   = document.getElementById('tmwseo-rerun-preview-phrases-btn');

        bindQueueButton(generateButton, {
            loadingText: 'Generating...',
            successText: 'SEO generated. Reloading...',
        });

        bindQueueButton(generateSidebarButton, {
            loadingText: 'Generating...',
            successText: 'SEO generated. Reloading...',
        });

        bindQueueButton(refreshKeywordsButton, {
            loadingText: 'Refreshing...',
            successText: 'Keyword refresh queued. Reload in a few seconds.',
            insertBlockValue: '0',
            refreshKeywordsOnly: true,
        });

        bindRerunPreviewPhrasesButton(rerunPreviewPhrasesButton);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }

    const observer = new MutationObserver(setup);
    observer.observe(document.body, { childList: true, subtree: true });
})();
