(function ($) {
	'use strict';
	if (typeof $ !== 'function') return;

	$(document).ready(function () {
		var $body = $('body');

		// ─── Modal open/close ─────────────────────────────────────────────
		$body.on('click', '.ap-help-trigger', function (e) {
			e.preventDefault();
			var id = $(this).data('target');
			$('#ap-modal-backdrop').prop('hidden', false);
			$('#' + id).prop('hidden', false);
			// Reset LOUD ack state every time it opens.
			if ('help-wallet-secret' === id) {
				$('#ap-loud-ack').prop('checked', false);
				$('#ap-loud-open').attr('aria-disabled', 'true');
			}
		});

		$body.on('click', '.ap-modal-close, .ap-modal-close-btn, #ap-modal-backdrop', function () {
			$('.ap-modal').prop('hidden', true);
			$('#ap-modal-backdrop').prop('hidden', true);
		});

		$(document).on('keydown', function (e) {
			if (27 === e.keyCode) {
				$('.ap-modal').prop('hidden', true);
				$('#ap-modal-backdrop').prop('hidden', true);
			}
		});

		// ─── LOUD modal — gate the Open Portal button behind the checkbox.
		$body.on('change', '#ap-loud-ack', function () {
			$('#ap-loud-open').attr('aria-disabled', this.checked ? 'false' : 'true');
		});

		// ─── AJAX: test connection ────────────────────────────────────────
		$body.on('click', '#ap-btn-test', function () {
			run({
				action:   'clearwallet_test_connection',
				form:     '#ap-create-form',
				status:   '#ap-create-status',
				spinner:  $(this).siblings('.ap-spinner'),
				busyText: ClearWalletSetup.strings.testing
			});
		});

		// ─── AJAX: create wallet ──────────────────────────────────────────
		$body.on('click', '#ap-btn-create', function () {
			run({
				action:   'clearwallet_create_wallet',
				form:     '#ap-create-form',
				status:   '#ap-create-status',
				spinner:  $(this).siblings('.ap-spinner'),
				busyText: ClearWalletSetup.strings.creating
			});
		});

		// ─── AJAX: attach existing wallet ─────────────────────────────────
		$body.on('click', '#ap-btn-use-existing', function () {
			run({
				action:   'clearwallet_use_existing',
				form:     '#ap-existing-form',
				status:   '#ap-existing-status',
				spinner:  $(this).siblings('.ap-spinner'),
				busyText: ClearWalletSetup.strings.attaching
			});
		});

		// ─── Copy receiving address ───────────────────────────────────────
		$body.on('click', '#ap-copy-address', function () {
			var addr = $(this).data('address');
			var $btn = $(this);
			navigator.clipboard.writeText(addr).then(function () {
				var orig = $btn.html();
				$btn.html('<span class="dashicons dashicons-yes"></span> ' + ClearWalletSetup.strings.copied);
				setTimeout(function () { $btn.html(orig); }, 1800);
			});
		});

		// ─── Disconnect ───────────────────────────────────────────────────
		$body.on('click', '#ap-btn-disconnect', function () {
			if (!window.confirm(ClearWalletSetup.strings.confirm_disconnect)) return;
			$.post(ClearWalletSetup.ajaxUrl, {
				action: 'clearwallet_disconnect',
				nonce:  ClearWalletSetup.nonce
			}, function () { window.location.reload(); });
		});

		// ─── Shared AJAX runner ───────────────────────────────────────────
		function run(opts) {
			var $form    = $(opts.form);
			var $status  = $(opts.status);
			var $spinner = opts.spinner;
			var $buttons = $form.find('button');

			$status.removeClass('is-success is-error').text(opts.busyText);
			$spinner.addClass('is-active');
			$buttons.prop('disabled', true);

			var data = $form.serializeArray().reduce(function (acc, f) { acc[f.name] = f.value; return acc; }, {});
			data.action = opts.action;
			data.nonce  = ClearWalletSetup.nonce;

			$.post(ClearWalletSetup.ajaxUrl, data)
				.done(function (res) {
					if (res && res.success) {
						$status.addClass('is-success').text(res.data.message || 'Done.');
						if (res.data && res.data.reload) {
							setTimeout(function () { window.location.reload(); }, 600);
						}
					} else {
						var msg = (res && res.data && res.data.message) ? res.data.message : 'Something went wrong.';
						$status.addClass('is-error').text(msg);
					}
				})
				.fail(function (xhr) {
					var msg = 'Network error.';
					try {
						var r = JSON.parse(xhr.responseText);
						if (r && r.data && r.data.message) msg = r.data.message;
					} catch (e) {}
					$status.addClass('is-error').text(msg);
				})
				.always(function () {
					$spinner.removeClass('is-active');
					$buttons.prop('disabled', false);
				});
		}
	});
})(jQuery);
