/**
 * WooCommerce Try On Admin JavaScript.
 *
 * @package WooCommerce_Try_On
 */

(function ($) {
	'use strict';

	$(document).ready(function () {

		// Initialize color picker with default color (Bug R2-9).
		$('.tryloom-admin__color-picker').wpColorPicker({
			defaultColor: '#552FBC'
		});

		// Initialize media uploader.
		$('.tryloom-admin__media-upload').on('click', function (e) {
			e.preventDefault();

			var button = $(this);
			var container = button.closest('.tryloom-admin__media-uploader');
			var preview = container.find('.tryloom-admin__media-preview');
			var input = container.find('input[type="hidden"]');

			var frame = wp.media({
				title: 'Select or Upload Media',
				button: {
					text: 'Use this media'
				},
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				preview.html('<img src="' + attachment.url + '" alt="" class="tryloom-admin__preview-img" />');
				input.val(attachment.id);
			});

			frame.open();
		});

		// Remove media.
		$('.tryloom-admin__media-remove').on('click', function (e) {
			e.preventDefault();

			var button = $(this);
			var container = button.closest('.tryloom-admin__media-uploader');
			var preview = container.find('.tryloom-admin__media-preview');
			var input = container.find('input[type="hidden"]');

			preview.html('');
			input.val('');
		});

		// Toggle shortcode display based on button placement selection.
		$('select[name="tryloom_button_placement"]').on('change', function () {
			var value = $(this).val();
			var description = $(this).next('.description');

			if ('shortcode' === value) {
				description.html(
					'Choose where to place the Try On button.<br>Use shortcode: <code>[tryloom]</code>'
				);
			} else {
				description.html('Choose where to place the Try On button.');
			}
		});

		// Initialize select2 for enhanced select fields.
		$('.wc-enhanced-select').select2();

	});

})(jQuery);
