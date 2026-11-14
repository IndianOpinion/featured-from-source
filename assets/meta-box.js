/* Featured from source: the "Find image now" button. Uses the REST route
 * with the core REST nonce (wp.apiFetch adds it). Works in the block
 * editor and the classic editor. */
(function () {
	'use strict';

	function text(el, value) {
		if (el) {
			el.textContent = value;
		}
	}

	function onClick(event) {
		var button = event.target.closest('.ffs-run');
		if (!button) {
			return;
		}
		var box = button.closest('.ffs-box');
		if (!box || !window.wp || !window.wp.apiFetch) {
			return;
		}
		var result = box.querySelector('.ffs-result');
		var force = box.querySelector('.ffs-force');
		button.disabled = true;
		text(result, 'Working...');
		window.wp.apiFetch({
			path: box.getAttribute('data-path'),
			method: 'POST',
			data: { force: !!(force && force.checked) }
		}).then(function (response) {
			var record = response && response.record ? response.record : {};
			text(result, (record.outcome || '') + ': ' + (response.message || record.reason || ''));
			text(box.querySelector('.ffs-status'), 'Status: ' + (record.outcome || '') + ': ' + (response.message || ''));
		}).catch(function (error) {
			text(result, (error && error.message) ? error.message : 'The request failed.');
		}).then(function () {
			button.disabled = false;
		});
	}

	document.addEventListener('click', onClick);
}());
