(function() {
    'use strict';

    const CONFIG = {
        BATCH_SIZE: 50,
        RETRY_ATTEMPTS: 3,
        RETRY_DELAY: 2000,
        REQUEST_TIMEOUT: 60000,
        PROGRESS_FADE_DELAY: 3000
    };

    const state = {
        processing: false,
        totalFixed: 0,
        totalDetached: 0,
        currentOffset: 0,
        total: 0,
        retryCount: 0,
        abortController: null
    };

    let elements = {};

    function initElements() {
        elements = {
            fixButton: document.getElementById('cbio_fix_attachments'),
            resultDiv: document.getElementById('cbio_fix_attachments_result'),
            progressDiv: document.getElementById('cbio_fix_attachments_progress'),
            progressBar: document.getElementById('cbio_fix_attachments_progress_bar'),
            progressText: document.getElementById('cbio_fix_attachments_progress_text'),
            cancelButton: document.getElementById('cbio_fix_attachments_cancel')
        };
        return elements.fixButton !== null;
    }

    function updateProgress(percent, message) {
        if (elements.progressBar) {
            elements.progressBar.style.width = `${Math.min(percent, 100)}%`;
            elements.progressBar.setAttribute('aria-valuenow', percent);
        }
        if (elements.progressText) {
            elements.progressText.textContent = message;
        }
    }

    function showResult(success, message) {
        if (!elements.resultDiv) return;
        const color = success ? '#46b450' : '#dc3232';
        const icon = success ? '✓' : '✗';
        elements.resultDiv.innerHTML = `<span style="color: ${color}">${icon} ${escapeHtml(message)}</span>`;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function resetState() {
        state.processing = false;
        state.totalFixed = 0;
        state.totalDetached = 0;
        state.currentOffset = 0;
        state.total = 0;
        state.retryCount = 0;
        if (state.abortController) {
            state.abortController.abort();
            state.abortController = null;
        }
    }

    function setProcessingUI(isProcessing) {
        if (elements.fixButton) {
            elements.fixButton.disabled = isProcessing;
            elements.fixButton.setAttribute('aria-busy', isProcessing);
        }
        if (elements.progressDiv) {
            elements.progressDiv.style.display = isProcessing ? 'block' : 'none';
            elements.progressDiv.style.opacity = '1';
        }
        if (elements.cancelButton) {
            elements.cancelButton.style.display = isProcessing ? 'inline-block' : 'none';
        }
    }

    async function processBatch(offset = 0, attempt = 1) {
        if (!state.processing) return;

        state.abortController = new AbortController();
        const { signal } = state.abortController;

        const timeoutId = setTimeout(() => {
            if (state.abortController) {
                state.abortController.abort();
            }
        }, CONFIG.REQUEST_TIMEOUT);

        try {
            const formData = new URLSearchParams({
                action: 'cbio_fix_attachments_batch',
                nonce: cbioFixAttachments.nonce,
                offset: offset,
                total: state.total,
                batch_size: CONFIG.BATCH_SIZE
            });

            const response = await fetch(cbioFixAttachments.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData,
                signal: signal,
                credentials: 'same-origin'
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.data?.message || 'Unknown error');
            }

            const data = result.data;

            state.totalFixed += parseInt(data.fixed) || 0;
            state.totalDetached += parseInt(data.detached) || 0;
            state.currentOffset = data.offset;
            state.total = data.total;
            state.retryCount = 0;

            const progress = data.progress || (data.total > 0 ? Math.round((data.offset / data.total) * 100) : 0);
            updateProgress(progress, data.message || 'Processing...');

            if (data.done) {
                handleComplete();
            } else {
                if ('requestIdleCallback' in window) {
                    requestIdleCallback(() => processBatch(data.offset), { timeout: 100 });
                } else {
                    setTimeout(() => processBatch(data.offset), 10);
                }
            }
        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError' && !state.processing) {
                return;
            }

            if (attempt < CONFIG.RETRY_ATTEMPTS && state.processing) {
                state.retryCount++;
                updateProgress(
                    state.total > 0 ? Math.round((offset / state.total) * 100) : 0,
                    `Connection issue, retrying (${attempt}/${CONFIG.RETRY_ATTEMPTS})...`
                );
                await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY * attempt));
                return processBatch(offset, attempt + 1);
            }

            handleError(error.message || 'Network error occurred');
        }
    }

    function handleComplete() {
        state.processing = false;
        setProcessingUI(false);
        showResult(true, `Completed! ${state.totalFixed} attachments fixed, ${state.totalDetached} detached.`);
        updateProgress(100, 'Process completed successfully!');
        setTimeout(() => {
            if (elements.progressDiv) {
                elements.progressDiv.style.transition = 'opacity 0.3s ease';
                elements.progressDiv.style.opacity = '0';
                setTimeout(() => {
                    elements.progressDiv.style.display = 'none';
                    elements.progressDiv.style.opacity = '1';
                    elements.progressDiv.style.transition = '';
                }, 300);
            }
        }, CONFIG.PROGRESS_FADE_DELAY);
    }

    function handleError(message) {
        state.processing = false;
        setProcessingUI(false);
        showResult(false, `Error: ${message}`);
        if (state.currentOffset > 0 && elements.resultDiv) {
            elements.resultDiv.innerHTML += ` <button type="button" id="cbio_resume_fix" class="button button-small">Resume from ${state.currentOffset}</button>`;
            document.getElementById('cbio_resume_fix')?.addEventListener('click', () => {
                startProcessing(state.currentOffset);
            });
        }
    }

    function cancelProcessing() {
        if (state.abortController) {
            state.abortController.abort();
        }
        state.processing = false;
        setProcessingUI(false);
        showResult(false, `Cancelled. Processed ${state.currentOffset} of ${state.total}. Fixed: ${state.totalFixed}, Detached: ${state.totalDetached}`);
    }

    function startProcessing(fromOffset = 0) {
        if (state.processing) return;
        state.processing = true;
        state.currentOffset = fromOffset;
        if (fromOffset === 0) {
            state.totalFixed = 0;
            state.totalDetached = 0;
            state.total = 0;
        }
        setProcessingUI(true);
        showResult(true, 'Processing...');
        updateProgress(0, 'Starting...');
        processBatch(fromOffset);
    }

    function init() {
        if (!initElements()) return;

        elements.fixButton.addEventListener('click', function(e) {
            e.preventDefault();
            if (state.processing) return;
            if (!confirm(cbioFixAttachments.confirm_message || 'Are you sure you want to fix all image attachments? This process may take some time.')) {
                return;
            }
            resetState();
            startProcessing();
        });

        if (elements.cancelButton) {
            elements.cancelButton.addEventListener('click', function(e) {
                e.preventDefault();
                if (state.processing) {
                    cancelProcessing();
                }
            });
        }

        window.addEventListener('beforeunload', function(e) {
            if (state.processing) {
                e.preventDefault();
                e.returnValue = 'Processing is still in progress. Are you sure you want to leave?';
                return e.returnValue;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();