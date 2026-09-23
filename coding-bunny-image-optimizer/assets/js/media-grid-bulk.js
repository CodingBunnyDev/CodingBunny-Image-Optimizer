(function() {
    'use strict';
    
    if (typeof wp === 'undefined' || !wp.media) {
        return;
    }

    let buttonAdded = false;
    let initAttempts = 0;
    const maxInitAttempts = 50;
    
    function addOptimizeButton() {
        const bulkToolbar = document.querySelector('.media-toolbar.wp-filter.media-toolbar-mode-select');
        const secondaryToolbar = bulkToolbar ? bulkToolbar.querySelector('.media-toolbar-secondary') : null;
        const deleteButton = secondaryToolbar ? secondaryToolbar.querySelector('.delete-selected-button') : null;
        
        if (bulkToolbar && 
            secondaryToolbar && 
            deleteButton && 
            !buttonAdded && 
            bulkToolbar.classList.contains('media-toolbar-mode-select')) {
            
            const optimizeButton = document.createElement('button');
            optimizeButton.className = 'button media-button button-primary button-large cbio-optimize-selected';
            optimizeButton.textContent = cbioBulkMediaGrid.label;
            
            optimizeButton.addEventListener('click', function(e) {
                e.preventDefault();
                optimizeSelectedImages();
            });
            
            deleteButton.insertAdjacentElement('afterend', optimizeButton);
            buttonAdded = true;
        }
    }
    
    function optimizeSelectedImages() {
        const selectedAttachments = document.querySelectorAll('.attachment.selected');
        
        if (selectedAttachments.length === 0) {
            alert('Please select at least one image to optimize.');
            return;
        }
        
        const imageIds = [];
        selectedAttachments.forEach(function(attachment) {
            const dataId = attachment.getAttribute('data-id');
            if (dataId) {
                imageIds.push(dataId);
            }
        });
        
        if (imageIds.length === 0) {
            alert('No valid images selected.');
            return;
        }
        
        const optimizeBtn = document.querySelector('.cbio-optimize-selected');
        optimizeBtn.disabled = true;
        optimizeBtn.textContent = 'Optimizing...';
        
        const formData = new FormData();
        formData.append('action', 'convert_to_webp_multiple');
        formData.append('nonce', cbioBulkMediaGrid.nonce);
        imageIds.forEach(function(id) {
            formData.append('ids[]', id);
        });
        
        fetch(cbioBulkMediaGrid.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            optimizeBtn.disabled = false;
            optimizeBtn.textContent = cbioBulkMediaGrid.label;
            
            if (data.success) {
                const results = data.data.results;
                const message = `Optimization completed!\n` +
                              `Success: ${results.success}\n` +
                              `Skipped: ${results.skipped}\n` +
                              `Failed: ${results.failed}`;
                alert(message);
                
                if (wp.media.frame) {
                    wp.media.frame.content.get().collection.props.set({ignore: (+ new Date())});
                }
            } else {
                alert('Error: ' + (data.data || 'Operation failed'));
            }
        })
        .catch(function() {
            optimizeBtn.disabled = false;
            optimizeBtn.textContent = cbioBulkMediaGrid.label;
            alert('Server communication error.');
        });
    }
    
    function removeOptimizeButton() {
        const button = document.querySelector('.cbio-optimize-selected');
        if (button) {
            button.remove();
        }
        buttonAdded = false;
    }
    
    function initMediaGridObserver() {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    const target = mutation.target;
                    
                    if (target.classList.contains('media-toolbar') && target.classList.contains('media-toolbar-mode-select')) {
                        setTimeout(addOptimizeButton, 200);
                    }
                    
                    if (target.classList.contains('media-toolbar') && !target.classList.contains('media-toolbar-mode-select')) {
                        removeOptimizeButton();
                    }
                }
                
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === Node.ELEMENT_NODE && node.classList.contains('media-toolbar-secondary')) {
                            const parentToolbar = node.closest('.media-toolbar');
                            if (parentToolbar && parentToolbar.classList.contains('media-toolbar-mode-select')) {
                                setTimeout(addOptimizeButton, 200);
                            }
                        }
                    });
                }
            });
        });
        
        const mediaFrame = document.querySelector('.media-frame');
        if (mediaFrame) {
            observer.observe(mediaFrame, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class']
            });
        }
    }
    
    function waitForMediaFrameAndInit() {
        if (initAttempts >= maxInitAttempts) {
            return;
        }
        
        const mediaFrame = document.querySelector('.media-frame');
        if (mediaFrame && wp.media && wp.media.frame) {
            initMediaGridObserver();
            
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('bulk-select-button')) {
                    setTimeout(function() {
                        const bulkToolbar = document.querySelector('.media-toolbar.media-toolbar-mode-select');
                        if (bulkToolbar) {
                            addOptimizeButton();
                        } else {
                            removeOptimizeButton();
                        }
                    }, 300);
                }
            });
        } else {
            initAttempts++;
            setTimeout(waitForMediaFrameAndInit, 100);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForMediaFrameAndInit);
    } else {
        waitForMediaFrameAndInit();
    }
    
})();