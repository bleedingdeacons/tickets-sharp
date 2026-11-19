/**
 * Tickets Sharp: fills the event page's "Get tickets" form from the basket.
 *
 * Event pages are often cached, so the basket is asked for after the page has
 * loaded rather than written into it. When the answer arrives, each ticket's
 * quantity shows what the basket holds for this event, Event Tickets' own
 * "tickets already in your cart" notice appears, and the form is marked so
 * that submitting it sets the basket to these numbers instead of adding them.
 * Until then (or if the request fails) the form adds, as it always has.
 */
(function ($) {
	'use strict';

	var itemSelector = '.tribe-tickets__tickets-item';
	var inputSelector = '.tribe-tickets__tickets-item-quantity-number-input';

	function fill($form) {
		var $marker = $form.find('input[name="tsharp_basket"]');
		var url = $marker.data('url');
		if (!$marker.length || !url) {
			return;
		}

		$marker.val('');

		$.post(url, { action: 'tsharp_basket', event: $form.data('post-id') }).done(function (response) {
			var tickets = response && response.success && response.data ? response.data.tickets || {} : null;
			if (!tickets) {
				return;
			}

			var total = 0;
			$.each(tickets, function (id, quantity) {
				total += parseInt(quantity, 10) || 0;
			});

			if (total > 0) {
				$form.find(itemSelector).each(function () {
					var $item = $(this);
					var $input = $item.find(inputSelector);
					if (!$input.length || $input.prop('disabled')) {
						return;
					}
					$input.val(parseInt(tickets[$item.data('ticket-id')], 10) || 0).trigger('change');
				});
			}

			$form.find('#tribe-tickets__notice__tickets-in-cart').toggle(total > 0);
			$marker.val('1');
		});
	}

	function init() {
		$('input[name="tsharp_basket"]').closest('form').each(function () {
			fill($(this));
		});
	}

	$(init);

	// Coming back to the event page with the browser's Back button can show
	// the page as it was left; ask again, since the basket may have changed.
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			init();
		}
	});
}(jQuery));
