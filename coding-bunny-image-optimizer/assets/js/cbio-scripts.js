document.addEventListener('DOMContentLoaded', function () {	
	// --- BULK OPTIMIZE ALL IMAGES ---
	var optimizeAllBtn = document.getElementById('cbio_optimize_all_images');
	var optimizeAllStatus = document.getElementById('cbio_optimize_all_images_status');
	var optimizeProgress = document.getElementById('cbio_optimize_progress');
	var optimizeProgressBar = document.getElementById('cbio_optimize_progress_bar');
	var optimizeProgressText = document. getElementById('cbio_optimize_progress_text');
	var bulkData = window.cb_bulk_options_data || {};

	// --- QUALITY PRESET BUTTONS (Lossy / Glossy / Lossless) ---
	document.querySelectorAll('.cbio-quality-checkbox').forEach(function (radio) {
		radio.addEventListener('change', function () {
			if (! this.checked) return;

			var targetId = this.name.replace('_preset', '');
			var targetInput = document.getElementById(targetId);

			if (targetInput) {
				targetInput.value = this.value;
			}
		});
	});

	if (typeof bulkData.show_bulk_options !== 'undefined' && ! bulkData.show_bulk_options) {
		if (optimizeAllBtn) {
			optimizeAllBtn.disabled = true;
			optimizeAllBtn.classList.add('cbio-disabled-btn');
		}
		if (optimizeAllStatus) optimizeAllStatus.style.display = '';
		if (optimizeProgress) optimizeProgress.style.display = 'none';
	}

	if (optimizeAllBtn && ! optimizeAllBtn.dataset.optimizeHandlerAttached && bulkData.show_bulk_options) {
		optimizeAllBtn.dataset.optimizeHandlerAttached = "1";

		optimizeAllBtn.addEventListener('click', function () {
			var confirmMsg = 'Are you sure you want to optimize all images? This process may take some time.';

			if (! confirm(confirmMsg)) {
				return;
			}

			optimizeAllBtn.disabled = true;
			optimizeAllStatus.textContent = 'Getting image count...';
			optimizeProgress.style.display = 'block';
			optimizeProgressBar. style.width = '0%';
			optimizeProgressText.textContent = 'Initializing...';

			var totalImages = 0;
			var processedImages = 0;
			var optimizedCount = 0;
			var skippedCount = 0;
			var failedCount = 0;
			var batchSize = parseInt(bulkData.batch_size) || 5;
			var currentOffset = 0;

			fetchTotalImages();

			function fetchTotalImages() {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', bulkData.ajax_url || window.ajaxurl, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.responseType = 'json';

				xhr.onload = function () {
					if (xhr.response && xhr.response.success && xhr.response.data) {
						totalImages = xhr.response.data.total;

						if (totalImages === 0) {
							optimizeAllBtn.disabled = false;
							optimizeAllStatus.textContent = 'No images found to optimize.';
							optimizeProgress.style.display = 'none';
							return;
						}

						optimizeAllStatus.textContent = 'Found ' + totalImages + ' images.  Starting optimization...';

						setTimeout(function() {
							processNextBatch();
						}, 500);

					} else {
						optimizeAllBtn.disabled = false;
						optimizeAllStatus.textContent = 'Error getting image count.';
						optimizeProgress.style.display = 'none';
					}
				};

				xhr. onerror = function () {
					optimizeAllBtn.disabled = false;
					optimizeAllStatus.textContent = 'Network error while fetching image count.';
					optimizeProgress.style.display = 'none';
				};

				var params = new URLSearchParams();
				params.append('action', 'cbio_get_total_images');
				params.append('nonce', bulkData. nonce || '');

				xhr.send(params.toString());
			}

			function processNextBatch() {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', bulkData.ajax_url || window.ajaxurl, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.responseType = 'json';

				xhr. onload = function () {
					if (xhr.response && xhr.response.success && xhr.response.data) {
						var batchIds = xhr.response.data.ids;

						if (batchIds. length === 0) {
							completeBulkOptimization();
							return;
						}

						optimizeBatch(batchIds);

					} else {
						optimizeAllBtn.disabled = false;
						optimizeAllStatus. textContent = 'Error fetching image batch.';
						optimizeProgress.style.display = 'none';
					}
				};

				xhr.onerror = function () {
					optimizeAllBtn.disabled = false;
					optimizeAllStatus.textContent = 'Network error during batch fetch.';
					optimizeProgress.style.display = 'none';
				};

				var params = new URLSearchParams();
				params.append('action', 'cbio_get_image_batch');
				params.append('nonce', bulkData.nonce || '');
				params.append('offset', currentOffset);

				xhr.send(params.toString());
			}

			function optimizeBatch(batchIds) {
				var batchXhr = new XMLHttpRequest();
				batchXhr.open('POST', bulkData.ajax_url || window.ajaxurl, true);
				batchXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				batchXhr.responseType = 'json';

				batchXhr.onload = function () {
					if (
						batchXhr.response &&
						batchXhr.response.success &&
						batchXhr.response.data &&
						batchXhr.response.data.results
					) {
						var stats = batchXhr.response. data.results;
						optimizedCount += stats.success || 0;
						skippedCount += stats.skipped || 0;
						failedCount += stats.failed || 0;
						processedImages += batchIds.length;

						var progress = Math.min(Math.round((processedImages / totalImages) * 100), 100);

						optimizeProgressBar.style.width = progress + '%';
						optimizeProgressText.textContent =
						'Processing: ' + processedImages + '/' + totalImages +
						' images (' + progress + '%) - ' +
						optimizedCount + ' optimized, ' +
						skippedCount + ' skipped, ' +
						failedCount + ' failed';
						optimizeAllStatus.textContent =
						'Optimizing images... (' + progress + '%)';

						currentOffset += batchSize;

						setTimeout(function () {
							processNextBatch();
						}, 300);

					} else {
						optimizeAllBtn.disabled = false;
						optimizeAllStatus.textContent =
						'Error processing batch: ' +
						(batchXhr.response && batchXhr. response.data && batchXhr.response.data.message
							? batchXhr. response.data.message
							: 'Unknown error');
							optimizeProgress.style.display = 'none';
						}
					};

					batchXhr.onerror = function () {
						optimizeAllBtn.disabled = false;
						optimizeAllStatus.textContent = 'Network error during optimization';
						optimizeProgress.style.display = 'none';
					};

					var batchParams = new URLSearchParams();
					batchParams.append('action', 'convert_to_webp_multiple');
					batchParams.append('nonce', bulkData. nonce || '');
					batchIds.forEach(function (id) {
						batchParams.append('ids[]', id);
					});

					batchXhr. send(batchParams.toString());
				}

				function completeBulkOptimization() {
					optimizeAllBtn.disabled = false;
					optimizeAllStatus.textContent =
					'Optimization completed!  ' +
					optimizedCount + ' images optimized, ' +
					skippedCount + ' skipped, ' +
					failedCount + ' failed. ';
					optimizeProgressBar.style.width = '100%';
					optimizeProgressText.textContent =
					'Completed: ' + processedImages + '/' + totalImages + ' images';

					updatePostImageUrls();
				}

				function updatePostImageUrls() {
					if (! bulkData.nonce) {
						console.error('[CBIO] No nonce available for URL update');
						finishOptimizationWithoutUrlUpdate();
						return;
					}

					optimizeAllStatus.textContent = 'Updating image URLs in posts and pages...';
					optimizeProgressText.textContent = 'Updating URLs...';

					var urlOffset = 0;
					var urlBatchSize = 20;
					var totalUrlUpdated = 0;
					var totalUrlProcessed = 0;
					var totalUrlPosts = 0;
					var consecutiveErrors = 0;
					var maxErrors = 3;

					processUrlBatch();

					function processUrlBatch() {
						var xhr = new XMLHttpRequest();
						xhr.open('POST', bulkData.ajax_url || window.ajaxurl, true);
						xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
						xhr.responseType = 'json';
						xhr.timeout = 30000;

						xhr.onload = function () {
							console.log('[CBIO] URL Update Response:', xhr.response);

							if (! xhr.response || !xhr.response.success) {
								consecutiveErrors++;

								var errorMsg = 'Unknown error';
								if (xhr.response && xhr.response.data) {
									errorMsg = xhr.response.data.message || xhr.response.data || errorMsg;
								}

								console.error('[CBIO] Error ' + consecutiveErrors + '/' + maxErrors + ':', errorMsg);

								if (consecutiveErrors >= maxErrors) {
									console.warn('[CBIO] Too many errors, skipping URL update');
									finishOptimizationWithoutUrlUpdate();
									return;
								}

								setTimeout(processUrlBatch, 1000);
								return;
							}

							consecutiveErrors = 0;

							if (! xhr.response.data) {
								finishOptimizationSuccess(0, 0);
								return;
							}

							var data = xhr.response.data;
							totalUrlUpdated += (data.updated || 0);
							totalUrlProcessed = data.processed || urlOffset;
							totalUrlPosts = data.total || 0;

							if (data.done === true) {
								finishOptimizationSuccess(totalUrlPosts, totalUrlUpdated);
								return;
							}

							if (totalUrlPosts > 0) {
								var urlProgress = Math.min(Math. round((totalUrlProcessed / totalUrlPosts) * 100), 100);
								optimizeProgressText.textContent = 
								'Updated ' + totalUrlProcessed + ' of ' + totalUrlPosts + ' posts (' + 
								urlProgress + '%) - ' + totalUrlUpdated + ' modified';
								optimizeProgressBar.style.width = urlProgress + '%';
							} else {
								optimizeProgressText.textContent = 'Checking posts...  (' + totalUrlProcessed + ' checked)';
							}

							urlOffset = data.next_offset || (urlOffset + urlBatchSize);
							setTimeout(processUrlBatch, 300);
						};

						xhr.onerror = function () {
							consecutiveErrors++;
							console.error('[CBIO] Network error ' + consecutiveErrors + '/' + maxErrors);

							if (consecutiveErrors >= maxErrors) {
								finishOptimizationWithoutUrlUpdate();
							} else {
								setTimeout(processUrlBatch, 1000);
							}
						};

						xhr.ontimeout = function() {
							consecutiveErrors++;
							console.error('[CBIO] Timeout ' + consecutiveErrors + '/' + maxErrors);

							if (consecutiveErrors >= maxErrors) {
								finishOptimizationWithoutUrlUpdate();
							} else {
								setTimeout(processUrlBatch, 1000);
							}
						};

						var params = new URLSearchParams();
						params.append('action', 'cbio_update_images_urls');
						params. append('nonce', bulkData.nonce);
						params.append('offset', urlOffset);

						xhr.send(params.toString());
					}

					function finishOptimizationSuccess(totalPosts, updatedPosts) {
						var message = 'All done! ' + optimizedCount + ' images optimized';
						if (totalPosts > 0) {
							message += ', ' + updatedPosts + ' posts updated';
						}

						optimizeAllStatus.textContent = message;
						optimizeProgressText.textContent = 'Process completed successfully!';
						optimizeProgressBar.style.width = '100%';

						setTimeout(function () {
							optimizeProgress.style. display = 'none';
						}, 3000);
					}

					function finishOptimizationWithoutUrlUpdate() {
						optimizeAllStatus. textContent = 
						'Optimization completed!  ' + optimizedCount + ' images optimized.  ' +
						'(URL update skipped - you may need to refresh pages manually)';
						optimizeProgressText.textContent = 'Images optimized successfully!';
						optimizeProgressBar.style.width = '100%';

						setTimeout(function () {
							optimizeProgress.style.display = 'none';
						}, 4000);
					}
				}
			});
		}

		// --- BULK ACTION SEPARATOR (Image Optimization) ---
		var separatorText = '--OPTIMIZER--';
		var actionValue = 'cbio_optimize_images';
		var showBulkOptions = typeof bulkData.show_bulk_options !== "undefined" ?  !!bulkData.show_bulk_options : true;

		var selects = document.querySelectorAll('select[name="action"], select[name="action2"]');
		selects.forEach(function(select) {
			for (var i = select.options. length - 1; i >= 0; i--) {
				if (select.options[i]. value === actionValue || select.options[i].value === 'cbio_separator') {
					select.remove(i);
				}
			}

			if (showBulkOptions) {
				var separator = document.createElement("option");
				separator.disabled = true;
				separator.value = "cbio_separator";
				separator.textContent = separatorText;
				separator.style.fontWeight = "bold";
				separator. style.color = "#555";
				separator.style.backgroundColor = "#f1f1f1";
				select.add(separator);

				var option = document.createElement("option");
				option.value = actionValue;
				option.textContent = (bulkData.optimize_label || "Optimize Selected Images");
				select.add(option);
			}
		});
	});