/**
 * Tickets Sharp: the "Ticket e-mail image" box on the event edit screen.
 * Opens the media library and stores the chosen attachment's ID.
 */
(function ($) {
	'use strict';

	$(function () {
		var $box = $('.tsharp-ticket-image');
		if (!$box.length || typeof wp === 'undefined' || !wp.media) {
			return;
		}

		var labels = window.tsharpTicketImage || {};
		var frame = null;

		$box.on('click', '.tsharp-ticket-image__choose', function (event) {
			event.preventDefault();

			if (!frame) {
				frame = wp.media({
					title: labels.title || 'Ticket e-mail image',
					button: { text: labels.button || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				});

				frame.on('select', function () {
					var image = frame.state().get('selection').first().toJSON();
					var url = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;

					$box.find('.tsharp-ticket-image__id').val(image.id);
					$box.find('.tsharp-ticket-image__preview').attr('src', url).show();
					$box.find('.tsharp-ticket-image__remove').show();
				});
			}

			frame.open();
		});

		$box.on('click', '.tsharp-ticket-image__remove', function (event) {
			event.preventDefault();

			$box.find('.tsharp-ticket-image__id').val('');
			$box.find('.tsharp-ticket-image__preview').attr('src', '').hide();
			$(this).hide();
		});
	});
})(jQuery);
