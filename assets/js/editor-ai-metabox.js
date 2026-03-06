(function () {
    const bindQueueButton = (button, options = {}) => {
        if (!button || button.dataset.bound === '1') {
            return;
        }

        button.dataset.bound = '1';

        const strategyField = document.getElementById('tmwseo-generate-strategy');
        const insertBlockField = document.getElementById('tmwseo-generate-insert-block');

        button.addEventListener('click', async () => {
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

                const data = await response.json();
                if (!response.ok || !data || !data.success) {
                    const message = data && data.data && data.data.message ? data.data.message : 'Request failed.';
                    throw new Error(message);
                }

                if (window.wp && wp.data) {
                    wp.data.dispatch('core/notices').createNotice('success', options.successText || 'Queued. Refresh in a few seconds.', {
                        type: 'snackbar',
                        isDismissible: true,
                    });
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

    const setup = () => {
        const generateButton = document.getElementById('tmwseo-generate-btn');
        const refreshKeywordsButton = document.getElementById('tmwseo-refresh-keywords-btn');

        bindQueueButton(generateButton, {
            loadingText: 'Generating...',
            successText: 'Queued. Refresh in a few seconds.',
        });

        bindQueueButton(refreshKeywordsButton, {
            loadingText: 'Refreshing keywords...',
            insertBlockValue: '0',
            refreshKeywordsOnly: true,
            successText: 'Keyword refresh queued. Refresh in a few seconds.',
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }

    const observer = new MutationObserver(setup);
    observer.observe(document.body, { childList: true, subtree: true });
})();
