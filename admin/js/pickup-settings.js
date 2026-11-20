(function ($) {
	'use strict';

	if (typeof window.assPickupSettings === 'undefined') {
		return;
	}

	var cfg = window.assPickupSettings;

	$(function () {
		var $button = $('#ass-sms-test-send');
		var $result = $('#ass-sms-test-result');

		$('#ass-bulkgate-token-remove').on('click', function (event) {
			if (!window.confirm($(this).data('confirm'))) {
				event.preventDefault();
			}
		});

		$button.on('click', function () {
			$button.prop('disabled', true);
			$result.removeClass('is-ok is-error').text(cfg.i18n.sending);

			$.post(cfg.ajaxUrl, {
				action: 'ass_pickup_test_sms',
				nonce: cfg.nonce,
				number: $('#ass-sms-test-number').val()
			}).done(function (response) {
				var message = response && response.data && response.data.message ? response.data.message : cfg.i18n.failed;
				$result.addClass(response && response.success ? 'is-ok' : 'is-error').text(message);
			}).fail(function (xhr) {
				var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : cfg.i18n.failed;
				$result.addClass('is-error').text(message);
			}).always(function () {
				$button.prop('disabled', false);
			});
		});
	});
})(jQuery);
