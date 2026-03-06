(function () {
    const setup = () => {
        const button = document.getElementById('tmwseo-generate-btn');
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
            button.textContent = 'Generating...';

            const formData = new URLSearchParams();
            formData.append('action', 'tmwseo_generate_now');
            formData.append('post_id', postId);
            formData.append('nonce', nonce);
            formData.append('strategy', strategyField ? strategyField.value : 'openai');
            formData.append('insert_block', insertBlockField && insertBlockField.checked ? '1' : '0');

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
                    wp.data.dispatch('core/notices').createNotice('success', 'Queued. Refresh in a few seconds.', {
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }

    const observer = new MutationObserver(setup);
    observer.observe(document.body, { childList: true, subtree: true });
})();
