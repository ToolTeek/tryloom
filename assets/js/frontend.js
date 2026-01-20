/**
 * WooCommerce Try On Frontend JavaScript.
 *
 * @package WooCommerce_Try_On
 */

(function ($) {
	'use strict';

	// Initialize Try On.
	var TryloomUI = {
		/**
		 * Show error popup if enabled in settings.
		 *
		 * @param {string} message Error message to show.
		 */
		showErrorPopup: function (message) {
			if (tryloom_params.show_popup_errors) {
				alert(message);
			} else {
				// Log to console instead if popup errors are disabled
				console.error('[WooCommerce Try On]', message);
			}
		},

		/**
		 * Current uploaded file.
		 */
		uploadedFile: null,

		/**
		 * Current uploaded file URL.
		 */
		uploadedFileURL: null,

		/**
		 * Current uploaded file preview data URL.
		 */
		uploadedFilePreviewDataURL: null,

		/**
		 * Current object URL for fallback image loading (to cleanup later).
		 */
		currentObjectUrl: null,

		/**
		 * Original uploaded file URL for retry functionality.
		 */
		originalUploadedFileURL: null,

		/**
		 * Original uploaded file preview data URL for retry functionality.
		 */
		originalUploadedFilePreviewDataURL: null,

		/**
		 * Generation state key for localStorage.
		 */
		generationStateKey: 'tryloom_generation_state',

		/**
		 * Cached default photo URL (if any).
		 */
		defaultPhotoUrl: null,

		/**
		 * Initialize.
		 */
		init: function () {
			this.initTryOnButton();
			this.initPopup();
			this.initUpload();
			this.initAccountPage();
			this.initLightbox(); // Add lightbox initialization
			this.checkOngoingGeneration();
		},

		/**
		 * Initialize try-on button.
		 */
		initTryOnButton: function () {
			$(document).on('click', '.tryloom-button', function (e) {
				e.preventDefault();
				e.stopPropagation();

				var productId = $(this).data('product-id');
				TryloomUI.openPopup(productId);
			});
		},

		/**
		 * Initialize popup.
		 */
		initPopup: function () {
			// Close popup.
			$(document).on('click', '.tryloom-popup-close, .tryloom-close-popup', function () {
				TryloomUI.closePopup();
			});

			// Close popup when clicking outside.
			$(document).on('click', '.tryloom-popup', function (e) {
				if ($(e.target).hasClass('tryloom-popup')) {
					TryloomUI.closePopup();
				}
			});

			// Generate button - disable initially
			$('.tryloom-generate').prop('disabled', true);

			// Generate button.
			$(document).on('click', '.tryloom-generate', function () {
				TryloomUI.generateTryOn();
			});

			// Retry button.
			$(document).on('click', '.tryloom-retry', function () {
				TryloomUI.handleRetry();
			});

			// Retry icon button.
			$(document).on('click', '.tryloom-retry-icon', function () {
				TryloomUI.handleRetry();
			});

			// Download button.
			$(document).on('click', '.tryloom-download', function (e) {
				e.preventDefault();
				var downloadLink = $(this).attr('href');
				var filename = $(this).attr('download') || 'try-on.png';

				if (downloadLink) {
					// Create a temporary link and trigger download
					var link = document.createElement('a');
					link.href = downloadLink;
					link.download = filename;
					link.style.display = 'none';
					document.body.appendChild(link);
					link.click();
					document.body.removeChild(link);
				}
			});

			// Download icon button.
			$(document).on('click', '.tryloom-download-icon', function (e) {
				e.preventDefault();
				// Get download link from the icon itself or from the result image
				var downloadLink = $(this).attr('href');
				var filename = $(this).attr('download') || 'try-on.png';

				// If no href on icon, try to get from result image
				if (!downloadLink || downloadLink === '#') {
					var resultImg = $('.tryloom-result-image img');
					downloadLink = resultImg.attr('src');
					if (downloadLink) {
						// Extract filename from URL if possible
						var urlParts = downloadLink.split('/');
						if (urlParts.length > 0) {
							var urlFilename = urlParts[urlParts.length - 1];
							if (urlFilename && urlFilename.includes('.')) {
								filename = urlFilename;
							}
						}
					}
				}

				if (downloadLink && downloadLink !== '#') {
					// Create a temporary link and trigger download
					var link = document.createElement('a');
					link.href = downloadLink;
					link.download = filename;
					link.style.display = 'none';
					document.body.appendChild(link);
					link.click();
					document.body.removeChild(link);
				}
			});

			// Add to cart button - now just closes popup without adding to cart.
			$(document).on('click', '.tryloom-add-to-cart', function () {
				// Clear generation state.
				TryloomUI.clearGenerationState();

				// Close popup immediately.
				TryloomUI.closePopup();
			});
		},

		/**
		 * Initialize upload.
		 */
		initUpload: function () {
			var fileInput = $('#tryloom-file');

			// Upload area click.
			$(document).on('click', '.tryloom-upload-area', function (e) {
				e.preventDefault();
				fileInput.trigger('click');
			});

			// File input change.
			$(document).on('change', '#tryloom-file', function () {
				var file = this.files[0];
				if (file) {
					// Store the file
					TryloomUI.uploadedFile = file;

					// Preview the image
					var reader = new FileReader();
					reader.onload = function (e) {
						TryloomUI.uploadedFilePreviewDataURL = e.target.result;
						TryloomUI.originalUploadedFilePreviewDataURL = e.target.result;
						TryloomUI.renderUploadedPreview(e.target.result);
					};
					reader.readAsDataURL(file);

					// Auto-upload the file
					TryloomUI.uploadFile(file);
				}
			});

			// Remove image button.
			$(document).on('click', '.tryloom-remove-image', function (e) {
				e.stopPropagation();
				TryloomUI.uploadedFile = null;
				TryloomUI.uploadedFileURL = null;
				TryloomUI.uploadedFilePreviewDataURL = null;
				TryloomUI.originalUploadedFileURL = null;
				TryloomUI.originalUploadedFilePreviewDataURL = null;
				$('#tryloom-file').val('');
				$('.tryloom-upload-preview').html(
					'<div class="tryloom-upload-placeholder">' +
					'<img src="' + tryloom_params.plugin_url + 'assets/img/tryloom_upload_placeholder.png" alt="Upload" width="80" height="80" />' +
					'<p class="tryloom-upload-title">' + (tryloom_params.i18n.upload_your_photo || 'Upload your photo') + '</p>' +
					'<p class="tryloom-upload-subtitle">' + (tryloom_params.i18n.drag_and_drop || 'or drag and drop here.') + '</p>' +
					'</div>'
				);

				// Disable the generate button when image is removed
				$('.tryloom-generate').prop('disabled', true);
			});

			// Drag and drop.
			var uploadArea = $('.tryloom-popup-content'); // Drop on the whole popup for better UX
			uploadArea.on('dragover', function (e) {
				e.preventDefault();
				e.stopPropagation();
				$('.tryloom-upload-area').addClass('dragover');
			});

			uploadArea.on('dragleave', function (e) {
				e.preventDefault();
				e.stopPropagation();
				// Only remove class if leaving the popup content area
				if (e.target === this) {
					$('.tryloom-upload-area').removeClass('dragover');
				}
			});



			uploadArea.on('drop', function (e) {
				e.preventDefault();
				e.stopPropagation();
				$('.tryloom-upload-area').removeClass('dragover');

				var file = e.originalEvent.dataTransfer.files[0];
				if (file) {
					fileInput.prop('files', e.originalEvent.dataTransfer.files);
					fileInput.trigger('change');
				}
			});


		},

		/**
		 * Create a remove button element for the uploaded preview.
		 *
		 * @return {jQuery}
		 */
		createRemoveButton: function () {
			return $('<button>')
				.addClass('tryloom-remove-image')
				.attr('type', 'button')
				.attr('title', tryloom_params.i18n.remove_image || 'Remove image')
				.html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>');
		},

		/**
		 * Render uploaded preview with remove button.
		 *
		 * @param {string} src Image source URL or data URI.
		 */
		renderUploadedPreview: function (src) {
			if (!src) {
				return;
			}

			var previewWrapper = $('<div>').addClass('tryloom-preview');
			var img = $('<img>').attr('src', src);

			previewWrapper.append(img).append(this.createRemoveButton());
			$('.tryloom-upload-preview').html(previewWrapper);
		},

		/**
		 * Handle retry action restoring original upload.
		 */
		handleRetry: function () {
			this.clearGenerationState();

			if (this.originalUploadedFileURL || this.originalUploadedFilePreviewDataURL) {
				var previewSrc = this.originalUploadedFilePreviewDataURL || this.originalUploadedFileURL;
				this.renderUploadedPreview(previewSrc);
				this.uploadedFileURL = this.originalUploadedFileURL;
				this.uploadedFilePreviewDataURL = this.originalUploadedFilePreviewDataURL || previewSrc;
				$('.tryloom-generate').prop('disabled', false);
			} else if ($('.tryloom-preview img').length > 0) {
				$('.tryloom-generate').prop('disabled', false);
			}

			this.showStep(1);
		},

		/**
		 * Update download controls for the result image.
		 *
		 * @param {string|null} imageUrl
		 * @param {string} [filename]
		 */
		setDownloadLinks: function (imageUrl, filename) {
			var downloadBtn = $('.tryloom-download');
			var downloadIcon = $('.tryloom-download-icon');

			if (imageUrl) {
				// Bug R2-2: Ensure filename has a proper extension
				var downloadFilename = filename || '';

				// If no filename or no extension, try to extract from URL or use default
				if (!downloadFilename || !downloadFilename.match(/\.(jpg|jpeg|png|webp|gif)$/i)) {
					// Try to extract extension from URL
					var urlMatch = imageUrl.match(/\.(jpg|jpeg|png|webp|gif)(\?|$)/i);
					var ext = urlMatch ? urlMatch[1].toLowerCase() : 'jpg';

					// Build filename
					if (!downloadFilename) {
						downloadFilename = 'tryloom-try-on.' + ext;
					} else {
						downloadFilename = downloadFilename + '.' + ext;
					}
				}

				downloadBtn
					.attr('href', imageUrl)
					.attr('download', downloadFilename)
					.show();
				downloadIcon
					.attr('href', imageUrl)
					.attr('download', downloadFilename);
			} else {
				downloadBtn
					.hide()
					.attr('href', '')
					.removeAttr('download');
				downloadIcon
					.removeAttr('href')
					.removeAttr('download');
			}
		},

		/**
		 * Show spinner overlay while result image is loading.
		 */
		startResultImageLoading: function () {
			var container = $('.tryloom-result-image');
			var loader = container.find('.tryloom-result-loading');

			container.addClass('is-loading');
			loader.addClass('is-active');
		},

		/**
		 * Hide spinner overlay after result image completes loading.
		 */
		finishResultImageLoading: function () {
			var container = $('.tryloom-result-image');
			var loader = container.find('.tryloom-result-loading');

			container.removeClass('is-loading');
			loader.removeClass('is-active');
		},

		/**
		 * Display generated image with loading spinner and graceful error handling.
		 *
		 * @param {string} imageUrl
		 * @param {string} filename
		 * @param {{onLoad?: Function, onError?: Function}} [callbacks]
		 */
		displayResultImage: function (imageUrl, filename, callbacks) {
			callbacks = callbacks || {};

			var container = $('.tryloom-result-image');
			var resultImg = container.find('img');
			var self = this;

			if (!container.length) {
				if (callbacks.onError) {
					callbacks.onError();
				}
				return;
			}

			this.startResultImageLoading();
			this.setDownloadLinks(null);

			if (!imageUrl) {
				this.finishResultImageLoading();
				if (callbacks.onError) {
					callbacks.onError();
				}
				return;
			}

			// Clean up previous object URL if it exists
			if (this.currentObjectUrl) {
				try {
					URL.revokeObjectURL(this.currentObjectUrl);
				} catch (e) {
					// Ignore errors
				}
				this.currentObjectUrl = null;
			}

			resultImg.attr('src', '');

			var preloader = new Image();
			var loadTimeout;

			// Ensure image loads with credentials (cookies) for same-origin requests
			// This is important for authenticated image requests
			preloader.crossOrigin = undefined; // Explicitly undefined for same-origin

			// Add a timeout to detect if image loading is taking too long
			loadTimeout = setTimeout(function () {
				if (!preloader.complete || preloader.naturalWidth === 0) {
					console.error('Image load timeout:', imageUrl);
					if (preloader.onerror) {
						preloader.onerror();
					}
				}
			}, 30000); // 30 second timeout

			preloader.onload = function () {
				clearTimeout(loadTimeout);

				// Double-check image actually loaded
				if (preloader.naturalWidth === 0 || preloader.naturalHeight === 0) {
					console.error('Image loaded but has zero dimensions:', imageUrl);
					if (preloader.onerror) {
						preloader.onerror();
					}
					return;
				}

				resultImg.attr('src', imageUrl);
				self.setDownloadLinks(imageUrl, filename);
				self.finishResultImageLoading();

				if (callbacks.onLoad) {
					callbacks.onLoad();
				}
			};
			preloader.onerror = function (event) {
				clearTimeout(loadTimeout);
				console.error('Failed to load try-on result image:', imageUrl);

				// Attempt to force display the image even if preloader failed
				// This handles cases where CORS or specific browser behaviors might block programmatic loading
				// but the browser can still display the <img> tag correctly.
				resultImg.attr('src', imageUrl);
				self.setDownloadLinks(imageUrl, filename);
				self.finishResultImageLoading();

				// We don't call onError immediately here, we assume if the user can see it, it's fine.
				// If the image is truly broken, the browser will show a broken image icon.
				if (callbacks.onLoad) {
					callbacks.onLoad();
				}
			};

			// Set src after handlers are attached
			preloader.src = imageUrl;
		},

		/**
		 * Apply default photo state (used when loading popup with saved photo).
		 *
		 * @param {string} defaultPhotoUrl
		 */
		setDefaultPhotoState: function (defaultPhotoUrl) {
			if (!defaultPhotoUrl) {
				return;
			}

			this.defaultPhotoUrl = defaultPhotoUrl;
			this.renderUploadedPreview(defaultPhotoUrl);
			this.uploadedFileURL = defaultPhotoUrl;
			this.originalUploadedFileURL = defaultPhotoUrl;
			this.uploadedFilePreviewDataURL = defaultPhotoUrl;
			this.originalUploadedFilePreviewDataURL = defaultPhotoUrl;
			$('.tryloom-generate').prop('disabled', false);
		},

		/**
		 * Initialize account page.
		 */
		initAccountPage: function () {
			// Upload new photo button.
			$(document).on('click', '.tryloom-upload-photo-btn', function () {
				$('#tryloom-new-photo').trigger('click');
			});

			// Handle new photo upload.
			$(document).on('change', '#tryloom-new-photo', function () {
				var file = this.files[0];
				if (!file) {
					return;
				}

				// Prepare form data.
				var formData = new FormData();
				formData.append('action', 'tryloom_upload_account_photo');
				formData.append('nonce', tryloom_params.nonce);
				formData.append('image', file);
				formData.append('set_as_default', 'no');

				// Upload.
				$.ajax({
					url: tryloom_params.ajax_url,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function (response) {
						if (response.success) {
							// Reload page to show new photo.
							location.reload();
						} else {
							TryloomUI.showErrorPopup(response.data.message || 'Upload failed');
						}
					},
					error: function () {
						TryloomUI.showErrorPopup('Upload failed');
					}
				});

				// Reset file input.
				$(this).val('');
			});

			// Delete history item.
			$(document).on('click', '.tryloom-delete-history', function () {
				var historyId = $(this).data('id');
				var row = $(this).closest('tr');

				if (!confirm('Are you sure you want to delete this history item?')) {
					return;
				}

				$.ajax({
					url: tryloom_params.ajax_url,
					type: 'POST',
					data: {
						action: 'tryloom_delete_history',
						nonce: tryloom_params.nonce,
						history_id: historyId
					},
					success: function (response) {
						if (response.success) {
							row.fadeOut(300, function () {
								$(this).remove();
								// Check if table is empty.
								if ($('.tryloom-history-table tbody tr').length === 0) {
									$('.tryloom-history-table').replaceWith(
										'<p>' + tryloom_params.i18n.no_history + '</p>'
									);
									$('.tryloom-history-actions').remove();
								}
							});
						}
					}
				});
			});

			// Delete all history.
			$(document).on('click', '.tryloom-delete-all-history', function () {
				if (!confirm('Are you sure you want to delete ALL try-on history? This cannot be undone.')) {
					return;
				}

				$.ajax({
					url: tryloom_params.ajax_url,
					type: 'POST',
					data: {
						action: 'tryloom_delete_all_history',
						nonce: tryloom_params.nonce
					},
					success: function (response) {
						if (response.success) {
							$('.tryloom-history-table').fadeOut(300, function () {
								$(this).replaceWith(
									'<p>' + tryloom_params.i18n.no_history + '</p>'
								);
							});
							$('.tryloom-history-actions').fadeOut(300, function () {
								$(this).remove();
							});
						}
					}
				});
			});

			// Delete photo.
			$(document).on('click', '.tryloom-delete', function () {
				var photoId = $(this).data('id');
				var photoElement = $(this).closest('.tryloom-account-photo');

				if (confirm('Are you sure you want to delete this photo?')) {
					$.ajax({
						url: tryloom_params.ajax_url,
						type: 'POST',
						data: {
							action: 'tryloom_delete_photo',
							nonce: tryloom_params.nonce,
							photo_id: photoId
						},
						success: function (response) {
							if (response.success) {
								photoElement.fadeOut(300, function () {
									$(this).remove();
								});
							}
						}
					});
				}
			});

			// Set default photo.
			$(document).on('click', '.tryloom-set-default', function () {
				var photoId = $(this).data('id');
				var photoElement = $(this).closest('.tryloom-account-photo');

				$.ajax({
					url: tryloom_params.ajax_url,
					type: 'POST',
					data: {
						action: 'tryloom_set_default_photo',
						nonce: tryloom_params.nonce,
						photo_id: photoId
					},
					success: function (response) {
						if (response.success) {
							// Remove default class from all photos.
							$('.tryloom-account-photo').removeClass('is-default');
							$('.tryloom-default-label').remove();
							$('.tryloom-set-default').show();

							// Add default class to selected photo.
							photoElement.addClass('is-default');
							photoElement.find('.tryloom-set-default').hide().after('<span class="tryloom-default-label">Default Photo</span>');
						}
					}
				});
			});
		},

		/**
		 * Initialize lightbox.
		 */
		initLightbox: function () {
			// Create lightbox HTML
			var lightboxHTML = '<div class="tryloom-lightbox">' +
				'<span class="tryloom-lightbox-close">&times;</span>' +
				'<div class="tryloom-lightbox-content">' +
				'<img src="" alt="Zoomed Image">' +
				'</div>' +
				'</div>';

			$('body').append(lightboxHTML);

			// Open lightbox when clicking directly on result image
			$(document).on('click', '.tryloom-result-image img', function (e) {
				e.preventDefault();
				e.stopPropagation();

				if ($(e.target).closest('.tryloom-image-actions').length) {
					return;
				}

				var imgSrc = $(this).attr('src');

				if (imgSrc) {
					$('.tryloom-lightbox-content img').attr('src', imgSrc);
					$('.tryloom-lightbox').addClass('open');
					$('body').addClass('tryloom-scroll-lock'); // Prevent scrolling
				}
			});

			// Open lightbox for history table links
			$(document).on('click', '.tryloom-history-image', function (e) {
				e.preventDefault();
				e.stopPropagation();

				var imgSrc = $(this).attr('href') || $(this).find('img').attr('src');

				if (imgSrc) {
					$('.tryloom-lightbox-content img').attr('src', imgSrc);
					$('.tryloom-lightbox').addClass('open');
					$('body').addClass('tryloom-scroll-lock');
				}
			});

			// Close lightbox when clicking close button or outside image
			$(document).on('click', '.tryloom-lightbox-close, .tryloom-lightbox', function (e) {
				if (e.target === this) {
					$('.tryloom-lightbox').removeClass('open');
					$('body').removeClass('tryloom-scroll-lock'); // Re-enable scrolling
				}
			});

			// Close lightbox with Escape key
			$(document).on('keyup', function (e) {
				if (e.keyCode === 27) { // Escape key
					$('.tryloom-lightbox').removeClass('open');
					$('body').removeClass('tryloom-scroll-lock'); // Re-enable scrolling
				}
			});
		},

		/**
		 * Open popup.
		 *
		 * @param {number} productId Product ID.
		 */
		openPopup: function (productId) {
			// Disable page scrolling
			$('body').addClass('tryloom-scroll-lock');

			// Get the popup element
			var popup = $('#tryloom-popup');

			if (popup.length === 0) {
				console.error('Popup element not found!');
				return;
			}

			// Check for ongoing generation state.
			var savedState = TryloomUI.getGenerationState();
			if (savedState && savedState.productId == productId) {
				// Show popup with animation.
				popup.addClass('open');

				// Load variations.
				this.loadVariations(productId);

				var self = this;

				if (savedState.uploadedFileURL) {
					this.uploadedFileURL = savedState.uploadedFileURL;
				}
				if (savedState.originalUploadedFileURL) {
					this.originalUploadedFileURL = savedState.originalUploadedFileURL;
				}
				if (savedState.uploadedFilePreviewDataURL) {
					this.uploadedFilePreviewDataURL = savedState.uploadedFilePreviewDataURL;
				}
				if (savedState.originalUploadedFilePreviewDataURL) {
					this.originalUploadedFilePreviewDataURL = savedState.originalUploadedFilePreviewDataURL;
				}

				if (savedState.imageUrl) {
					$('.tryloom-add-to-cart')
						.data('product-id', savedState.productId)
						.data('variation-id', savedState.variationId);

					this.showStep(2);

					this.displayResultImage(savedState.imageUrl, savedState.filename, {
						onError: function () {
							TryloomUI.clearGenerationState();

							self.resetPopup();
							if (self.defaultPhotoUrl) {
								self.setDefaultPhotoState(self.defaultPhotoUrl);
							}
							self.showStep(1);
						}
					});
					return;
				} else if (savedState.generating) {
					// Show loading state if generation is still in progress
					$('.tryloom-loading-overlay').show();
					$('.tryloom-loading-overlay p').text('Generation in progress...');

					var previewSource = this.originalUploadedFilePreviewDataURL || this.originalUploadedFileURL || this.uploadedFilePreviewDataURL || this.uploadedFileURL;
					if (previewSource) {
						this.renderUploadedPreview(previewSource);
						$('.tryloom-generate').prop('disabled', false);
					}

					// Show step 1 with loading
					this.showStep(1);
					return;
				}
			}

			// Reset popup.
			this.resetPopup();

			var defaultPhotoImg = $('.tryloom-upload-preview .tryloom-preview img');
			if (defaultPhotoImg.length > 0) {
				this.setDefaultPhotoState(defaultPhotoImg.attr('src'));
			} else {
				this.defaultPhotoUrl = null;
			}

			// Show popup with animation.
			popup.addClass('open');

			// Load variations.
			this.loadVariations(productId);
		},

		/**
		 * Close popup.
		 */
		closePopup: function () {
			var popup = $('#tryloom-popup');
			popup.removeClass('open');

			// Re-enable page scrolling
			$('body').removeClass('tryloom-scroll-lock');
		},

		/**
		 * Reset popup.
		 */
		resetPopup: function () {
			// Clean up object URL if it exists
			if (this.currentObjectUrl) {
				try {
					URL.revokeObjectURL(this.currentObjectUrl);
				} catch (e) {
					// Ignore errors
				}
				this.currentObjectUrl = null;
			}

			// Reset file input.
			$('#tryloom-file').val('');

			// Reset upload area if no default photo is present in the original HTML.
			// The default photo is loaded via PHP, so we check if the preview div is empty.
			if (!$('.tryloom-upload-preview .tryloom-preview').length) {
				$('.tryloom-upload-preview').html(
					'<div class="tryloom-upload-placeholder">' +
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="64" height="64"><path fill="none" d="M0 0h24v24H0z"/><path d="M21 15v3h3v2h-3v3h-2v-3h-3v-2h3v-3h2zm.008-12c.548 0 .992.445.992.993v9.349A5.99 5.99 0 0 0 20 13V5H4v13.586l3.293-3.293a1 1 0 0 1 1.414 0L12 18.586l2.293-2.293a1 1 0 0 1 1.414 0l.293.293V13a5.99 5.99 0 0 0-2 .341V9a1 1 0 0 1 1-1h5zm-9.489 4.99a3.5 3.5 0 1 1-3.5 3.5 3.5 3.5 0 0 1 3.5-3.5zM4.003 3h16.995c.55 0 .997.446.997.996V13h-2V5H4v13.589l3.294-3.291a1 1 0 0 1 1.32-.084l.094.084 3.292 3.292 1.968-1.968a5.942 5.942 0 0 0 1.173 1.423l-3.141 3.141a1 1 0 0 1-1.32.084l-.094-.084L8 18.585l-5.293 5.294A1 1 0 0 1 1.999 24a.993.993 0 0 1-.996-.996V3.996A.996.996 0 0 1 1.997 3h.006z" fill="rgba(128,128,128,0.5)"/></svg>' +
					'<p>' + tryloom_params.i18n.upload_image + '</p>' +
					'</div>'
				);
			}

			// Reset variations.
			$('.tryloom-variations-container').html('<p class="tryloom-loading">' + tryloom_params.i18n.loading_variations + '</p>');

			// Reset save photo checkbox.
			$('#tryloom-save-photo').prop('checked', false);

			// Reset uploaded file URLs
			this.uploadedFileURL = null;
			this.originalUploadedFileURL = null;
			this.uploadedFilePreviewDataURL = null;
			this.originalUploadedFilePreviewDataURL = null;
			this.defaultPhotoUrl = null;

			// Reset result image state.
			this.setDownloadLinks(null);
			this.finishResultImageLoading();
			$('.tryloom-result-image img').attr('src', '');

			// Disable generate button
			$('.tryloom-generate').prop('disabled', true);

			// Show step 1.
			this.showStep(1);
		},

		/**
		 * Show step.
		 *
		 * @param {number} step Step number.
		 */
		showStep: function (step) {
			$('.tryloom-step').fadeOut(200, function () {
				$('.tryloom-step-' + step).fadeIn(300);
			});
		},

		/**
		 * Load variations.
		 *
		 * @param {number} productId Product ID.
		 */
		loadVariations: function (productId) {
			$.ajax({
				url: tryloom_params.ajax_url,
				type: 'POST',
				data: {
					action: 'tryloom_get_variations',
					product_id: productId,
					nonce: tryloom_params.nonce
				},
				success: function (response) {
					var container = $('.tryloom-variations-container');
					container.empty();

					if (response.success && response.data.variations && response.data.variations.length > 0) {
						var variations = response.data.variations;
						// Add variations.
						$.each(variations, function (index, variation) {
							var variationId = variation.variation_id;
							var variationName = variation.variation_description;
							var variationImage = variation.image.thumb_src;
							var variationTitle = variation.image.title;
							var variationAttributes = variation.attributes ? JSON.stringify(variation.attributes).replace(/"/g, '&quot;') : '{}';

							var variationHtml = '<div class="tryloom-variation" data-variation-id="' + variationId + '" data-product-id="' + productId + '" data-attributes="' + variationAttributes + '">' +
								'<div class="tryloom-variation-image">' +
								'<img src="' + variationImage + '" alt="' + variationTitle + '" />' +
								'</div>' +
								'<div class="tryloom-variation-details">' +
								'<div class="tryloom-variation-name">' + variationName + '</div>' +
								'<div class="tryloom-variation-price">' + (variation.price_html || '') + '</div>' +
								'</div>' +
								'</div>';

							container.append(variationHtml);
						});

						// Select first variation by default.
						$('.tryloom-variation').first().addClass('selected');

						// Variation click.
						$('.tryloom-variation').on('click', function () {
							$('.tryloom-variation').removeClass('selected');
							$(this).addClass('selected');
						});
					} else {
						// Simple product.
						$.ajax({
							url: tryloom_params.ajax_url,
							type: 'POST',
							data: {
								action: 'tryloom_get_product',
								product_id: productId,
								nonce: tryloom_params.nonce
							},
							success: function (response) {
								if (response.success && response.data) {
									var product = response.data;
									var productId = product.id || productId;
									var productName = product.name || '';
									var productImage = product.image || '';
									var productPriceHtml = product.price_html || '';

									var productHtml = '<div class="tryloom-variation selected" data-variation-id="0" data-product-id="' + productId + '">' +
										'<div class="tryloom-variation-image">' +
										'<img src="' + productImage + '" alt="' + productName + '" />' +
										'</div>' +
										'<div class="tryloom-variation-details">' +
										'<div class="tryloom-variation-name">' + productName + '</div>' +
										'<div class="tryloom-variation-price">' + productPriceHtml + '</div>' +
										'</div>' +
										'</div>';

									container.append(productHtml);
								} else {
									container.html('<p>' + tryloom_params.i18n.select_variant + '</p>');
								}
							}
						});
					}
				}
			});
		},

		/**
		 * Compress image before upload.
		 *
		 * @param {File} file File to compress.
		 * @param {Function} callback Callback function with compressed file.
		 */
		compressImage: function (file, callback) {
			// Only compress images
			if (!file.type.match(/image.*/)) {
				callback(file);
				return;
			}

			var reader = new FileReader();
			reader.onload = function (e) {
				var img = new Image();
				img.onload = function () {
					var canvas = document.createElement('canvas');
					var ctx = canvas.getContext('2d');
					var maxWidth = 1536;
					var maxHeight = 1536;
					var width = img.width;
					var height = img.height;

					// Calculate new dimensions
					if (width > height) {
						if (width > maxWidth) {
							height *= maxWidth / width;
							width = maxWidth;
						}
					} else {
						if (height > maxHeight) {
							width *= maxHeight / height;
							height = maxHeight;
						}
					}

					canvas.width = width;
					canvas.height = height;

					// Draw image on canvas
					ctx.drawImage(img, 0, 0, width, height);

					// Export compressed image
					canvas.toBlob(function (blob) {
						// Create new file from blob
						var compressedFile = new File([blob], file.name, {
							type: 'image/jpeg',
							lastModified: Date.now()
						});
						callback(compressedFile);
					}, 'image/jpeg', 0.85);
				};
				img.src = e.target.result;
			};
			reader.readAsDataURL(file);
		},

		/**
		 * Upload file with progress.
		 *
		 * @param {File} file File to upload.
		 */
		uploadFile: function (file) {
			// Show upload progress overlay
			var uploadArea = $('.tryloom-upload-area');
			var progressHTML = '<div class="tryloom-upload-progress-overlay">' +
				'<div class="tryloom-spinner"></div>' +
				'<p class="tryloom-progress-text">Uploading...</p>' +
				'</div>';

			uploadArea.append(progressHTML);


			// Compress image before upload
			TryloomUI.compressImage(file, function (compressedFile) {

				// Prepare form data
				var formData = new FormData();
				formData.append('action', 'tryloom_upload_photo');
				formData.append('nonce', tryloom_params.nonce);
				formData.append('image', compressedFile);

				// Upload with progress
				$.ajax({
					url: tryloom_params.ajax_url,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					xhr: function () {
						var xhr = new window.XMLHttpRequest();
						xhr.upload.addEventListener('progress', function (evt) {
							if (evt.lengthComputable) {
								var percentComplete = Math.round((evt.loaded / evt.total) * 100);
								var dashOffset = 226 - (226 * percentComplete / 100);
								$('.tryloom-progress-circle-fill').css('stroke-dashoffset', dashOffset);
								$('.tryloom-progress-percent').text(percentComplete + '%');
							}
						}, false);
						return xhr;
					},
					success: function (response) {
						$('.tryloom-upload-progress-overlay').fadeOut(300, function () {
							$(this).remove();
						});

						if (response.success) {
							TryloomUI.uploadedFileURL = response.data.file_url;
							// Always refresh original references for retry functionality
							TryloomUI.originalUploadedFileURL = response.data.file_url;
							if (TryloomUI.uploadedFilePreviewDataURL) {
								TryloomUI.originalUploadedFilePreviewDataURL = TryloomUI.uploadedFilePreviewDataURL;
							}



							// Enable the generate button after successful upload
							$('.tryloom-generate').prop('disabled', false);
						} else {
							TryloomUI.showErrorPopup(response.data.message || tryloom_params.i18n.error);
						}
					},
					error: function () {
						$('.tryloom-upload-progress-overlay').fadeOut(300, function () {
							$(this).remove();
						});
						// Disable generate button on upload error
						$('.tryloom-generate').prop('disabled', true);
						TryloomUI.showErrorPopup(tryloom_params.i18n.error);
					}
				});
			});
		},

		/**
		 * Generate try-on image.
		 */
		generateTryOn: function () {
			// Check if file is uploaded or using default photo.
			var hasUploadedFile = TryloomUI.uploadedFileURL !== null;
			var hasDefaultPhoto = $('.tryloom-preview img').length > 0 && !hasUploadedFile;

			if (!hasUploadedFile && !hasDefaultPhoto) {
				alert(tryloom_params.i18n.upload_image);
				return;
			}

			// Check if variation is selected.
			var selectedVariation = $('.tryloom-variation.selected');
			if (!selectedVariation.length) {
				alert(tryloom_params.i18n.select_variant);
				return;
			}

			// Get variation ID and product ID.
			var variationId = selectedVariation.data('variation-id');
			var productId = selectedVariation.data('product-id') || $('.tryloom-button').data('product-id');

			// Save generation state.
			TryloomUI.saveGenerationState({
				productId: productId,
				variationId: variationId,
				generating: true,
				uploadedFileURL: TryloomUI.uploadedFileURL,
				originalUploadedFileURL: TryloomUI.originalUploadedFileURL,
				timestamp: new Date().getTime()
			});

			// Reset Progress (Show overlay)
			$('.tryloom-loading-overlay').show();
			// Bug R2-7: Add scroll lock class to prevent scrolling during generation
			$('.tryloom-popup-body').addClass('is-generating');

			// Reset circular progress (r=34, circumference = 2*PI*34 ≈ 214)
			var circumference = 214;
			$('.tryloom-progress-ring-fill').css('stroke-dashoffset', circumference);

			// Dynamic Loading Messages
			var loopMessages = [
				{ time: 0, text: (tryloom_params.i18n.msg_measuring || 'Measuring avatar...') },
				{ time: 4000, text: (tryloom_params.i18n.msg_analyzing || 'Analyzing styles...') },
				{ time: 8000, text: (tryloom_params.i18n.msg_stitching || 'Stitching details...') },
				{ time: 12000, text: (tryloom_params.i18n.msg_finalizing || 'Finalizing...') }
			];

			// Set initial text
			$('.tryloom-loading-status').text(loopMessages[0].text);
			var startTime = new Date().getTime();

			// Clear previous intervals
			if (TryloomUI.textInterval) clearInterval(TryloomUI.textInterval);
			if (TryloomUI.progressInterval) clearInterval(TryloomUI.progressInterval);

			// Current simulated progress (0-95, waits at 95 for API)
			var currentProgress = 0;
			var targetProgress = 95; // Max before API responds

			// Easing function: returns speed multiplier based on current progress
			// 0-50%: fast (1.5x), 50-80%: medium (1.0x), 80-95%: slow (0.3x)
			function getSpeedMultiplier(progress) {
				if (progress < 50) {
					return 1.5; // Fast
				} else if (progress < 80) {
					return 0.8; // Medium
				} else {
					return 0.25; // Very slow crawl
				}
			}

			// Update progress ring UI (no percentage text)
			function updateProgressUI(percent) {
				var offset = circumference - (percent / 100) * circumference;
				$('.tryloom-progress-ring-fill').css('stroke-dashoffset', offset);
			}

			// Start progress animation
			TryloomUI.progressInterval = setInterval(function () {
				if (currentProgress < targetProgress) {
					var speed = getSpeedMultiplier(currentProgress);
					// Base increment of 0.5% per 50ms, modified by speed
					currentProgress += 0.35 * speed;
					if (currentProgress > targetProgress) {
						currentProgress = targetProgress;
					}
					updateProgressUI(currentProgress);
				}
			}, 50);

			// Start text cycling
			TryloomUI.textInterval = setInterval(function () {
				var elapsed = new Date().getTime() - startTime;
				// Find the appropriate message for the current elapsed time
				for (var i = loopMessages.length - 1; i >= 0; i--) {
					if (elapsed >= loopMessages[i].time) {
						$('.tryloom-loading-status').text(loopMessages[i].text);
						break;
					}
				}
			}, 500);

			// Store reference to jump to 100% when done
			TryloomUI.completeProgress = function () {
				if (TryloomUI.progressInterval) clearInterval(TryloomUI.progressInterval);
				updateProgressUI(100);
			};

			// Prepare data.
			var data = {
				action: 'tryloom_generate',
				nonce: tryloom_params.nonce,
				product_id: productId,
				variation_id: variationId
			};

			if (TryloomUI.uploadedFileURL && TryloomUI.uploadedFileURL.length > 0) {
				data.uploaded_file_url = TryloomUI.uploadedFileURL;
			} else {
				data.using_default_photo = 'yes';
			}

			// Send AJAX request.
			$.ajax({
				url: tryloom_params.ajax_url,
				type: 'POST',
				data: data,
				timeout: 120000, // 2 minutes timeout
				success: function (response) {
					// Stop Loops
					if (TryloomUI.progressInterval) clearInterval(TryloomUI.progressInterval);
					if (TryloomUI.textInterval) clearInterval(TryloomUI.textInterval);

					if (response.success) {
						// Jump to 100% immediately
						if (TryloomUI.completeProgress) {
							TryloomUI.completeProgress();
						}
						// Finalize loading state
						$('.tryloom-loading-status').text(tryloom_params.i18n.success_message || 'Complete!');

						// Slight delay to show 100% before hiding
						setTimeout(function () {
							$('.tryloom-loading-overlay').hide();
							$('.tryloom-popup-body').removeClass('is-generating');
							// ... proceed to result ...
							// Save generation state with result.
							TryloomUI.saveGenerationState({
								productId: productId,
								variationId: variationId,
								imageUrl: response.data.image_url,
								filename: response.data.filename,
								generating: false,
								uploadedFileURL: TryloomUI.uploadedFileURL,
								originalUploadedFileURL: TryloomUI.originalUploadedFileURL,
								timestamp: new Date().getTime()
							});

							// Set add to cart button data.
							$('.tryloom-add-to-cart')
								.data('product-id', response.data.product_id)
								.data('variation-id', response.data.variation_id);

							// Show step 2.
							TryloomUI.showStep(2);

							// Toggle result view
							TryloomUI.displayResultImage(response.data.image_url, response.data.filename, {});

						}, 800); // Wait for 100% animation
					} else {
						// Error
						$('.tryloom-loading-overlay').hide();
						$('.tryloom-popup-body').removeClass('is-generating');
						console.error('Generation failed:', response.data);

						TryloomUI.clearGenerationState();
						TryloomUI.showErrorPopup(response.data.message || tryloom_params.i18n.error);
					}
				},
				error: function (xhr, status, error) {
					if (TryloomUI.progressInterval) clearInterval(TryloomUI.progressInterval);
					if (TryloomUI.textInterval) clearInterval(TryloomUI.textInterval);

					console.error('AJAX Error:', status, error);

					// Hide loading overlay.
					$('.tryloom-loading-overlay').hide();
					$('.tryloom-popup-body').removeClass('is-generating');

					// Clear generation state on error.
					TryloomUI.clearGenerationState();

					// Show error.
					if (status === 'timeout') {
						TryloomUI.showErrorPopup('Request timed out. Please try again.');
					} else {
						TryloomUI.showErrorPopup(tryloom_params.i18n.error);
					}
				}
			});
		},

		/**
		 * Save generation state to localStorage.
		 *
		 * @param {Object} state Generation state.
		 */
		saveGenerationState: function (state) {
			try {
				localStorage.setItem(this.generationStateKey, JSON.stringify(state));
			} catch (e) {
				// localStorage not available.
			}
		},

		/**
		 * Get generation state from localStorage.
		 *
		 * @return {Object|null} Generation state or null.
		 */
		getGenerationState: function () {
			try {
				var state = localStorage.getItem(this.generationStateKey);
				return state ? JSON.parse(state) : null;
			} catch (e) {
				return null;
			}
		},

		/**
		 * Clear generation state from localStorage.
		 */
		clearGenerationState: function () {
			try {
				localStorage.removeItem(this.generationStateKey);
			} catch (e) {
				// localStorage not available.
			}
		},

		/**
		 * Check for ongoing generation state on page load.
		 */
		checkOngoingGeneration: function () {
			var savedState = TryloomUI.getGenerationState();

			// Clear any stuck generation states older than 5 minutes
			if (savedState && savedState.generating) {
				var currentTime = new Date().getTime();
				var stateTime = savedState.timestamp || 0;
				var fiveMinutes = 5 * 60 * 1000;

				if (currentTime - stateTime > fiveMinutes) {
					TryloomUI.clearGenerationState();
					return;
				}

				// Show a notification that generation is in progress
				var notification = $('<div class="tryloom-generation-notification" style="position: fixed; top: 20px; right: 20px; background: #667eea; color: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3); z-index: 999999; font-weight: 600;">' +
					'<p style="margin: 0 0 10px 0;">Generation in progress...</p>' +
					'<button class="tryloom-check-result" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600;">Check Result</button>' +
					'<button class="tryloom-dismiss-notification" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; margin-left: 10px;">Dismiss</button>' +
					'</div>');

				$('body').append(notification);

				// Handle check result button
				$('.tryloom-check-result').on('click', function () {
					TryloomUI.openPopup(savedState.productId);
					$('.tryloom-generation-notification').remove();
				});

				// Handle dismiss button
				$('.tryloom-dismiss-notification').on('click', function () {
					TryloomUI.clearGenerationState();
					$('.tryloom-generation-notification').remove();
				});

				// Auto-dismiss after 30 seconds
				setTimeout(function () {
					$('.tryloom-generation-notification').fadeOut(300, function () {
						$(this).remove();
					});
				}, 30000);
			}
		}
	};

	// Initialize on document ready.
	$(document).ready(function () {
		TryloomUI.init();
	});

})(jQuery);