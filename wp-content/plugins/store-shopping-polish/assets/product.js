(function ($) {
	'use strict';
	$(function () {
		$('.variations_form').each(function () {
			var $form = $(this);
			$form.find('.single_variation_wrap').attr({
				'aria-live': 'polite',
				'aria-atomic': 'true'
			});
			$form.find('select').each(function () {
				var $select = $(this);
				var label = $select.closest('tr').find('label').text().trim();
				if (label && !$select.attr('aria-label')) {
					$select.attr('aria-label', label);
				}
			});
			$form.on('found_variation reset_data hide_variation', function () {
				window.setTimeout(function () {
					var disabled = $form.find('.single_add_to_cart_button').hasClass('disabled');
					$form.find('.single_add_to_cart_button').attr('aria-disabled', disabled ? 'true' : 'false');
				}, 0);
			});
		});
	});
})(jQuery);
