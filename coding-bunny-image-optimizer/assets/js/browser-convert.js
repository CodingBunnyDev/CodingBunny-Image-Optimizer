(function(){
	'use strict';

	if (
		typeof cbioBrowserConvertData === 'undefined' ||
		cbioBrowserConvertData.method !== 'browser' ||
		!cbioBrowserConvertData.enable_conversion ||
		cbioBrowserConvertData.enable_conversion === '0'
	) {
		return;
	}


	const config = {
		targetFormat: (cbioBrowserConvertData.format || 'webp').toLowerCase(),
		qualityWebp: Math.min(100, Math.max(0, cbioBrowserConvertData.quality_webp || 80)) / 100,
		qualityAvif: Math.min(100, Math.max(0, cbioBrowserConvertData.quality_avif || 60)) / 100,
		maxFileSize: 50 * 1024 * 1024,
		maxConcurrent: 3,
		batchDelay: 100
	};

	const batchState = {
		queue: [],
		processing: false,
		processed: 0,
		failed: 0,
		totalSavings: 0,
		startTime: null,
		activeConversions: new Set()
	};

	const formatSupport = {};

	async function testFormatSupport(format) {
		if (formatSupport[format] !== undefined) {
			return formatSupport[format];
		}

		try {
			const canvas = document.createElement('canvas');
			canvas.width = canvas.height = 1;
			const ctx = canvas.getContext('2d');
			ctx.fillStyle = 'red';
			ctx.fillRect(0, 0, 1, 1);

			const supported = await new Promise(resolve => {
				canvas.toBlob(blob => {
					resolve(blob && blob.type === 'image/' + format && blob.size > 0);
				}, 'image/' + format, 0.8);
			});

			formatSupport[format] = supported;
			return supported;

		} catch(e) {
			formatSupport[format] = false;
			return false;
		}
	}

	async function initializeFormatSupport() {
		const webpSupport = await testFormatSupport('webp');
		const avifSupport = await testFormatSupport('avif');
		return { webp: webpSupport, avif: avifSupport };
	}

	async function selectTargetFormat() {
		const support = await initializeFormatSupport();

		if (config.targetFormat === 'webp' && support.webp) {
			return 'webp';
		}

		if (config.targetFormat === 'avif' && support.avif) {
			return 'avif';
		}

		if (support.webp) {
			return 'webp';
		}

		if (support.avif) {
			return 'avif';
		}

		throw new Error('No modern image formats supported');
	}

	async function convertFile(originalFile) {
		const conversionId = `conv_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
		batchState.activeConversions.add(conversionId);

		try {
			if (!originalFile.name.match(/\.(jpe?g|png|gif|bmp|tiff?)$/i)) {
				return { converted: false, file: originalFile, reason: 'not_convertible' };
			}

			if (originalFile.size > config.maxFileSize) {
				return { converted: false, file: originalFile, reason: 'too_large' };
			}

			const targetFormat = await selectTargetFormat();

			if (originalFile.name.toLowerCase().endsWith('.' + targetFormat)) {
				return { converted: false, file: originalFile, reason: 'already_target_format' };
			}

			const dataUrl = await new Promise((resolve, reject) => {
				const reader = new FileReader();
				reader.onload = e => resolve(e.target.result);
				reader.onerror = () => reject(new Error('Read failed'));
				reader.readAsDataURL(originalFile);
			});

			const img = await new Promise((resolve, reject) => {
				const image = new Image();
				image.onload = () => resolve(image);
				image.onerror = () => reject(new Error('Image load failed'));
				image.src = dataUrl;
			});

			const baseQuality = targetFormat === 'webp' ? config.qualityWebp : config.qualityAvif;
			const qualityAttempts = [
				baseQuality,
				Math.max(0.1, baseQuality - 0.1),
				Math.max(0.1, baseQuality - 0.2),
				0.8,
				0.6,
				0.4
			];

			let bestResult = null;
			let smallestSize = originalFile.size;

			for (const quality of qualityAttempts) {
				try {
					const result = await attemptConversion(img, targetFormat, quality, originalFile);

					if (result && result.file.size < smallestSize) {
						bestResult = result;
						smallestSize = result.file.size;

						if (result.file.size < originalFile.size * 0.8) {
							break;
						}
					}
				} catch (e) {
					continue;
				}
			}

			if (bestResult) {
				return bestResult;
			}

			throw new Error('All conversion attempts failed');

		} catch (error) {
			return { 
				converted: false, 
				file: originalFile, 
				error: error.message 
			};
		} finally {
			batchState.activeConversions.delete(conversionId);
		}
	}

	async function attemptConversion(img, targetFormat, quality, originalFile) {
		return new Promise((resolve, reject) => {
			const canvas = document.createElement('canvas');

			canvas.width = img.naturalWidth;
			canvas.height = img.naturalHeight;

			const ctx = canvas.getContext('2d', {
				alpha: false,
				willReadFrequently: false
			});

			if (targetFormat === 'webp') {
				ctx.fillStyle = 'white';
				ctx.fillRect(0, 0, canvas.width, canvas.height);
			}

			ctx.imageSmoothingEnabled = true;
			ctx.imageSmoothingQuality = 'high';
			ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

			if (quality < 0.7) {
				const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
				const data = imageData.data;

				if (quality < 0.5) {
					for (let i = 0; i < data.length; i += 4) {
						if (i > 4 && i < data.length - 4) {
							data[i] = (data[i] + data[i-4] + data[i+4]) / 3;
							data[i+1] = (data[i+1] + data[i-3] + data[i+5]) / 3;
							data[i+2] = (data[i+2] + data[i-2] + data[i+6]) / 3;
						}
					}
					ctx.putImageData(imageData, 0, 0);
				}
			}

			const mimeType = 'image/' + targetFormat;

			canvas.toBlob(blob => {
				if (blob && blob.size > 0 && blob.type === mimeType) {
					const newName = originalFile.name.replace(/\.[^.]+$/, '') + '.' + targetFormat;
					const newFile = new File([blob], newName, { 
						type: mimeType,
						lastModified: Date.now()
					});

					const savings = ((1 - newFile.size / originalFile.size) * 100).toFixed(1);

					resolve({ 
						converted: true, 
						file: newFile, 
						originalFile: originalFile,
						format: targetFormat,
						quality: quality,
						actualQuality: quality,
						savings: parseFloat(savings),
						compressionRatio: newFile.size / originalFile.size
					});
				} else {
					reject(new Error(`${targetFormat} conversion failed at quality ${quality}`));
				}
			}, mimeType, quality);
		});
	}

	function interceptFormData(originalFormData) {
		const newFormData = new FormData();
		const filesToConvert = [];

		for (let [key, value] of originalFormData.entries()) {
			if (value instanceof File && value.name.match(/\.(jpe?g|png|gif|bmp|tiff?)$/i)) {
				filesToConvert.push({ key, file: value });
			} else {
				newFormData.append(key, value);
			}
		}

		return { newFormData, filesToConvert };
	}

	function setupXMLHttpRequestInterception() {
		const originalSend = XMLHttpRequest.prototype.send;
		const originalOpen = XMLHttpRequest.prototype.open;

		XMLHttpRequest.prototype.open = function(method, url, ...args) {
			this._interceptUrl = url;
			this._interceptMethod = method;
			return originalOpen.call(this, method, url, ...args);
		};

		XMLHttpRequest.prototype.send = function(data) {
			const self = this;

			if (data instanceof FormData && 
				(this._interceptUrl?.includes('/wp/v2/media') || 
				this._interceptUrl?.includes('async-upload.php') ||
				this._interceptUrl?.includes('upload.php'))) {

					const { newFormData, filesToConvert } = interceptFormData(data);

					if (filesToConvert.length > 0) {
						Promise.all(filesToConvert.map(async ({ key, file }) => {
							try {
								const result = await convertFile(file);
								return { key, file: result.converted ? result.file : file };
							} catch (error) {
								return { key, file };
							}
						})).then(results => {
							results.forEach(({ key, file }) => {
								newFormData.append(key, file);
							});

							originalSend.call(self, newFormData);
						}).catch(() => {
							originalSend.call(self, data);
						});

						return;
					}
				}

				return originalSend.call(this, data);
			};
		}

		function setupFetchInterception() {
			const originalFetch = window.fetch;

			window.fetch = function(url, options = {}) {
				if (options.method === 'POST' && 
					options.body instanceof FormData &&
					(url.includes('/wp/v2/media') || 
					url.includes('async-upload.php') ||
				url.includes('upload.php'))) {

					const { newFormData, filesToConvert } = interceptFormData(options.body);

					if (filesToConvert.length > 0) {
						return Promise.all(filesToConvert.map(async ({ key, file }) => {
							try {
								const result = await convertFile(file);
								return { key, file: result.converted ? result.file : file };
							} catch (error) {
								return { key, file };
							}
						})).then(results => {
							results.forEach(({ key, file }) => {
								newFormData.append(key, file);
							});

							const newOptions = {
								...options,
								body: newFormData
							};

							return originalFetch.call(this, url, newOptions);
						}).catch(() => {
							return originalFetch.call(this, url, options);
						});
					}
				}

				return originalFetch.call(this, url, options);
			};
		}

		function initializeFileMonitoring() {
			document.addEventListener('change', function(event) {
				if (event.target.type === 'file' && event.target.files && event.target.files.length > 0) {
					const files = Array.from(event.target.files);

					Promise.all(files.map(async (file) => {
						if (file.name.match(/\.(jpe?g|png|gif|bmp|tiff?)$/i)) {
							try {
								const result = await convertFile(file);
								return result.converted ? result.file : file;
							} catch (error) {
								return file;
							}
						}
						return file;
					})).then(convertedFiles => {
						const dt = new DataTransfer();
						convertedFiles.forEach(file => dt.items.add(file));
						event.target.files = dt.files;
					});
				}
			}, true);

			document.addEventListener('drop', function(event) {
				if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
					event.preventDefault();
					const files = Array.from(event.dataTransfer.files);

					Promise.all(files.map(async (file) => {
						if (file.name.match(/\.(jpe?g|png|gif|bmp|tiff?)$/i)) {
							try {
								const result = await convertFile(file);
								return result.converted ? result.file : file;
							} catch (error) {
								return file;
							}
						}
						return file;
					})).then(convertedFiles => {
						const uploadInput = document.getElementById('uploadInput');
						if (uploadInput) {
							const dt = new DataTransfer();
							convertedFiles.forEach(file => dt.items.add(file));
							uploadInput.files = dt.files;
						}
					});
				}
			}, true);

			document.addEventListener('dragover', function(event) {
				if (event.dataTransfer && event.dataTransfer.types.includes('Files')) {
					event.preventDefault();
					event.dataTransfer.dropEffect = 'copy';
				}
			}, true);

			document.addEventListener('dragenter', function(event) {
				if (event.dataTransfer && event.dataTransfer.types.includes('Files')) {
					event.preventDefault();
				}
			}, true);
		}

		async function initialize() {
			const support = await initializeFormatSupport();

			if (!support.webp && !support.avif) {
				return;
			}

			setupXMLHttpRequestInterception();
			setupFetchInterception();
			initializeFileMonitoring();

		}

		initialize();

	})();