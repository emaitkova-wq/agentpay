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

		// ─── Cash out: fetch available balance ────────────────────────────
		var $balance = $('#ap-balance');
		if ($balance.length) {
			$.post(ClearWalletSetup.ajaxUrl, { action: 'clearwallet_balance', nonce: ClearWalletSetup.nonce })
				.done(function (res) {
					if (res && res.success) {
						$balance.text(res.data.usdc).data('usdc', res.data.usdc);
						var $fee = $('#ap-cashout-fee');
						if ($fee.length && res.data.reserved && parseFloat(res.data.reserved) > 0) {
							$fee.text(' · ' + res.data.reserved + ' USDC reserved for the 1% fee');
						}
					} else {
						$balance.text('—');
					}
				})
				.fail(function () { $balance.text('—'); });
		}

		// ─── Cash out: Max fills the amount with the available balance ────
		$body.on('click', '#ap-withdraw-max', function () {
			var v = $('#ap-balance').data('usdc');
			if (v) $('#ap-withdraw-amount').val(v);
		});

		// ─── Cash out: send to Coinbase ───────────────────────────────────
		$body.on('click', '#ap-btn-withdraw', function () {
			var $btn    = $(this);
			var $status = $('#ap-withdraw-status');
			var $spin   = $btn.siblings('.ap-spinner');
			var to      = ($('#ap-withdraw-to').val() || '').trim();
			var amount  = ($('#ap-withdraw-amount').val() || '').trim();

			if (!/^0x[0-9a-fA-F]{40}$/.test(to)) {
				$status.removeClass('is-success').addClass('is-error')
					.text('Enter a valid 0x Coinbase deposit address (Base network).');
				return;
			}
			if (!(parseFloat(amount) > 0)) {
				$status.removeClass('is-success').addClass('is-error')
					.text('Enter an amount of USDC to send.');
				return;
			}
			if (!window.confirm('Send ' + amount + ' USDC to ' + to + ' on Base? This cannot be undone.')) return;

			$status.removeClass('is-success is-error').text(ClearWalletSetup.strings.sending || 'Sending…');
			$spin.addClass('is-active');
			$btn.prop('disabled', true);

			$.post(ClearWalletSetup.ajaxUrl, {
				action: 'clearwallet_withdraw',
				nonce:  ClearWalletSetup.nonce,
				to:     to,
				amount: amount
			})
				.done(function (res) {
					if (res && res.success) {
						$status.addClass('is-success').empty()
							.append($('<span>').text(res.data.message || 'Sent.'));
						if (res.data && res.data.tx_url) {
							$status.append(' ').append(
								$('<a>', { href: res.data.tx_url, target: '_blank', rel: 'noopener', text: 'View transaction' })
							);
						}
					} else {
						$status.addClass('is-error')
							.text((res && res.data && res.data.message) ? res.data.message : 'Something went wrong.');
					}
				})
				.fail(function (xhr) {
					var msg = 'Network error.';
					try { var r = JSON.parse(xhr.responseText); if (r && r.data && r.data.message) msg = r.data.message; } catch (e) {}
					$status.addClass('is-error').text(msg);
				})
				.always(function () {
					$spin.removeClass('is-active');
					$btn.prop('disabled', false);
				});
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
