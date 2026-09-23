document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('cbio_generate_bulk_alt_text');
    var status = document.getElementById('cbio_generate_bulk_alt_text_status');
    var progressWrap = document.getElementById('cbio_generate_bulk_alt_text_progress');
    var progressBar = document.getElementById('cbio_generate_bulk_alt_text_progress_bar');
    var progressText = document.getElementById('cbio_generate_bulk_alt_text_progress_text');

    if (btn && !btn.dataset.altHandlerAttached) {
        btn.dataset.altHandlerAttached = "1";
        btn.addEventListener('click', function () {
            var nonce = window.cbioAltTextAjax && window.cbioAltTextAjax.nonce ? window.cbioAltTextAjax.nonce : '';
            var i18n = window.cbioAltTextAjax && window.cbioAltTextAjax.i18n ? window.cbioAltTextAjax.i18n : {};
            var confirmMsg   = i18n.confirm   || 'Generate alt text for all images that do not have it?';
            var generatingMsg= i18n.generating|| 'Generating...';
            var generatedMsg = i18n.generated || 'Alt text generated for';
            var imagesMsg    = i18n.images    || 'images.';
            var errorMsg     = i18n.error     || 'Error generating alt text.';

            if (!confirm(confirmMsg)) return;

            btn.disabled = true;
            if (status) status.textContent = generatingMsg;
            if (progressBar) progressBar.style.width = "1%";
            if (progressText) progressText.textContent = generatingMsg;
            if (progressWrap) progressWrap.style.display = "block";

            function updateProgress(percent, message) {
                if (progressBar) progressBar.style.width = (Math.min(percent, 100)) + "%";
                if (progressText) progressText.textContent = message || "";
            }
            function hideProgress() {
                if (progressWrap) {
                    setTimeout(function () {
                        progressWrap.style.display = "none";
                        if (progressBar) progressBar.style.width = "0%";
                        if (progressText) progressText.textContent = "";
                    }, 1500);
                }
            }

            function batchRequest() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.responseType = 'json';
                xhr.onload = function () {
                    if (xhr.response && xhr.response.success) {
                        var data = xhr.response.data;
                        var progress = data && data.progress !== undefined ? data.progress : 0;
                        var total = data && data.batch_total ? data.batch_total : '';
                        var updated = data && data.updated ? data.updated : 0;
                        updateProgress(progress,
                            generatingMsg + " " + progress + "%..." + (total ? (" (" + updated + "/" + total + ")") : "")
                        );
                        if (data && data.completed) {
                            if (status) status.textContent = generatedMsg + " " + updated + " " + imagesMsg;
                            updateProgress(100, "Completed!");
                            setTimeout(function () {
                                hideProgress();
                                if (status) status.textContent = "";
                                location.reload();
                            }, 1700);
                            btn.disabled = false;
                        } else {
                            setTimeout(batchRequest, 120);
                        }
                    } else {
                        updateProgress(0, errorMsg);
                        if (status) status.textContent = errorMsg;
                        btn.disabled = false;
                        hideProgress();
                    }
                };
                xhr.onerror = function () {
                    updateProgress(0, errorMsg);
                    if (status) status.textContent = errorMsg;
                    btn.disabled = false;
                    hideProgress();
                };
                xhr.send("action=cbio_generate_bulk_alt_text&nonce=" + encodeURIComponent(nonce));
            }

            batchRequest();
        });
    }

    document.querySelectorAll('.cbio-generate-alt-btn').forEach(function (btn) {
        if (!btn.dataset.altSingleHandlerAttached) {
            btn.dataset.altSingleHandlerAttached = "1";
            btn.addEventListener('click', function () {
                var attachmentId = btn.getAttribute('data-attachment-id');
                var altDisplay = document.getElementById('cbio-alt-text-' + attachmentId);
                btn.disabled = true;
                var originalText = btn.textContent;
                btn.textContent = '...';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.cbioAltColumn.ajax_url, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.responseType = 'json';
                xhr.onload = function () {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    if (xhr.response && xhr.response.success && xhr.response.data && xhr.response.data.alt) {
                        if (altDisplay) altDisplay.textContent = xhr.response.data.alt;
                    } else {
                        alert(xhr.response && xhr.response.data && xhr.response.data.message ? xhr.response.data.message : 'Error');
                    }
                };
                xhr.onerror = function () {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    alert('Error');
                };
                var nonce = window.cbioAltColumn ? window.cbioAltColumn.nonce : '';
                xhr.send("action=cbio_generate_alt_text_single&attachment_id=" + encodeURIComponent(attachmentId) + "&nonce=" + encodeURIComponent(nonce));
            });
        }
    });
});