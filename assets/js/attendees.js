/**
 * Tickets Sharp: saves the attendee details page as it is filled in.
 *
 * Details were only stored when the form was submitted, so going back to the
 * event page (to change the number of tickets) lost whatever had been typed.
 * This sends the form to admin-ajax (tsharp_attendee_draft) shortly after
 * typing stops, and once more as the page is left. Nothing is validated
 * there; continuing to checkout still checks every answer.
 */
(function () {
	'use strict';

	var form = document.querySelector('form.tsharp-attendees[data-draft-url]');
	if (!form || !window.FormData || !window.URLSearchParams) {
		return;
	}

	var url = form.getAttribute('data-draft-url');
	var timer = null;
	var dirty = false;

	function body() {
		var data = new URLSearchParams(new FormData(form));
		data.set('action', 'tsharp_attendee_draft');
		return data;
	}

	function send(leaving) {
		window.clearTimeout(timer);
		timer = null;
		if (!dirty) {
			return;
		}
		dirty = false;

		var data = body();
		if (leaving && navigator.sendBeacon && navigator.sendBeacon(url, data)) {
			return;
		}
		if (window.fetch) {
			window.fetch(url, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(function () {
				dirty = true;
			});
		}
	}

	function changed() {
		dirty = true;
		window.clearTimeout(timer);
		timer = window.setTimeout(function () {
			send(false);
		}, 600);
	}

	form.addEventListener('input', changed);
	form.addEventListener('change', changed);

	// The form's own submit saves (and validates) everything.
	form.addEventListener('submit', function () {
		window.clearTimeout(timer);
		dirty = false;
	});

	window.addEventListener('pagehide', function () {
		send(true);
	});
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			send(true);
		}
	});
}());
