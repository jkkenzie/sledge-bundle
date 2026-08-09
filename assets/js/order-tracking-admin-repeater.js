(function () {
	'use strict';

	function ot_escHtml(ot_value) {
		return String(ot_value)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;');
	}

	function ot_safe_parse_json(ot_raw) {
		try {
			return JSON.parse(ot_raw);
		} catch (ot_e) {
			return [];
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var ot_app = document.getElementById('order-tracking-app');
		if (!ot_app) return;

		var ot_hiddenInput = document.getElementById('order_tracking_entries');
		if (!ot_hiddenInput) return;

		var ot_entriesRaw = ot_app.dataset.entries || '[]';
		var ot_entries = ot_safe_parse_json(ot_entriesRaw);
		if (!Array.isArray(ot_entries)) {
			ot_entries = [];
		}

		function ot_sync_hidden_input() {
			ot_hiddenInput.value = JSON.stringify(ot_entries);
		}

		function ot_render() {
			var ot_html = '';
			ot_html += '<table class="widefat striped">';
			ot_html += '<tbody>';

			for (var ot_i = 0; ot_i < ot_entries.length; ot_i++) {
				var ot_entry = ot_entries[ot_i] || {};
				var ot_location = ot_entry.location || '';
				var ot_status = ot_entry.status || '';
				var ot_time = ot_entry.time || '';

				var ot_removeLabel = (window.otOrderTrackingI18n && window.otOrderTrackingI18n.remove) ? window.otOrderTrackingI18n.remove : 'Remove';

				// Sidebar is narrow: stack inputs vertically to avoid tiny columns.
				ot_html += '<tr>';
				ot_html += '<td>';
				ot_html += '<div style="display:flex;flex-direction:column;gap:6px;">';
				ot_html += '<input type="text" style="width: 100%; box-sizing: border-box;" data-index="' + ot_i + '" data-field="location" value="' + ot_escHtml(ot_location) + '" />';
				ot_html += '<input type="text" style="width: 100%; box-sizing: border-box;" data-index="' + ot_i + '" data-field="status" value="' + ot_escHtml(ot_status) + '" />';
				ot_html += '<input type="datetime-local" style="width: 100%; box-sizing: border-box;" data-index="' + ot_i + '" data-field="time" value="' + ot_escHtml(ot_time) + '" />';
				ot_html += '<button type="button" class="button ot-remove-entry" data-index="' + ot_i + '" style="width:100%;">' + ot_escHtml(ot_removeLabel) + '</button>';
				ot_html += '</div>';
				ot_html += '</td>';
				ot_html += '</tr>';
			}

			ot_html += '</tbody>';
			ot_html += '</table>';

			var ot_addLabel = (window.otOrderTrackingI18n && window.otOrderTrackingI18n.add_tracking_entry) ? window.otOrderTrackingI18n.add_tracking_entry : '+ Add tracking entry';
			ot_html += '<p style="margin-top: 10px;">';
			ot_html += '<button type="button" class="button ot-add-tracking-entry">' + ot_escHtml(ot_addLabel) + '</button>';
			ot_html += '</p>';

			ot_app.innerHTML = ot_html;
		}

		function ot_upsert_index(ot_index) {
			if (!ot_entries[ot_index]) {
				ot_entries[ot_index] = { location: '', status: '', time: '' };
			}
		}

		ot_app.addEventListener('input', function (ot_event) {
			var ot_target = ot_event.target;
			if (!ot_target || !ot_target.matches('input[data-index][data-field]')) return;

			var ot_index = parseInt(ot_target.dataset.index, 10);
			var ot_field = ot_target.dataset.field;
			if (isNaN(ot_index) || !ot_field) return;

			ot_upsert_index(ot_index);
			ot_entries[ot_index][ot_field] = ot_target.value;
			ot_sync_hidden_input();
		});

		ot_app.addEventListener('click', function (ot_event) {
			var ot_target = ot_event.target;
			if (!ot_target) return;

			if (ot_target.matches('button.ot-add-tracking-entry')) {
				var ot_newEntry = { location: '', status: '', time: '' };

				// Keep the seeded Destination step at the end.
				var ot_last = ot_entries.length ? ot_entries[ot_entries.length - 1] : null;
				var ot_destinationStatus = (window.otOrderTrackingI18n && window.otOrderTrackingI18n.destination_status) ? window.otOrderTrackingI18n.destination_status : 'Destination';
				if (
					ot_last &&
					(ot_last.status === 'Destination' || ot_last.status === ot_destinationStatus) &&
					(ot_last.time === '' || ot_last.time === null)
				) {
					ot_entries.splice(ot_entries.length - 1, 0, ot_newEntry);
				} else {
					ot_entries.push(ot_newEntry);
				}

				ot_render();
				ot_sync_hidden_input();
				return;
			}

			if (ot_target.matches('button.ot-remove-entry')) {
				var ot_removeIndex = parseInt(ot_target.dataset.index, 10);
				if (isNaN(ot_removeIndex)) return;

				ot_entries.splice(ot_removeIndex, 1);
				ot_render();
				ot_sync_hidden_input();
			}
		});

		ot_render();
		ot_sync_hidden_input();
	});
})();

