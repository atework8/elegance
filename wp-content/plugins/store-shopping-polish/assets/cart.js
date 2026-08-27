(function () {
	'use strict';
	var config = window.storeCartPolish || {};
	var scheduled = false;

	function isDigitalPath(path) {
		return (config.digitalPaths || []).some(function (digitalPath) {
			return path === digitalPath || path.replace(/\/$/, '') === digitalPath.replace(/\/$/, '');
		});
	}

	function enhanceCart() {
		var hasDigital = false;
		var hasPhysical = false;
		document.querySelectorAll('.wc-block-cart-item__product').forEach(function (product) {
			var link = product.querySelector('a.wc-block-components-product-name');
			if (!link) return;
			var digital = isDigitalPath(new URL(link.href, window.location.href).pathname);
			hasDigital = hasDigital || digital;
			hasPhysical = hasPhysical || !digital;
			var badge = product.querySelector('.store-cart-type');
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'store-cart-type';
				link.insertAdjacentElement('afterend', badge);
			}
			badge.classList.toggle('store-cart-type--digital', digital);
			badge.textContent = digital ? config.digitalLabel : config.physicalLabel;

			var row = product.closest('.wc-block-cart-items__row');
			var remove = row && row.querySelector('.wc-block-cart-item__remove-link, button[aria-label*="Remove"]');
			if (remove && !remove.getAttribute('aria-label')) {
				remove.setAttribute('aria-label', 'Remove ' + link.textContent.trim() + ' from cart');
			}
		});

		var totals = document.querySelector('.wp-block-woocommerce-cart-totals-block');
		if (totals && (hasDigital || hasPhysical)) {
			var context = totals.querySelector('.store-cart-context');
			if (!context) {
				context = document.createElement('div');
				context.className = 'store-cart-context';
				context.setAttribute('role', 'note');
				totals.insertBefore(context, totals.firstChild);
			}
			context.textContent = hasDigital && hasPhysical ? config.mixedContext : (hasDigital ? config.digitalContext : config.physicalContext);
		}

		var emptyTitle = document.querySelector('.wc-block-cart__empty-cart__title');
		if (emptyTitle && !emptyTitle.parentElement.querySelector('.store-empty-cart-action')) {
			var emptyAction = document.createElement('div');
			emptyAction.className = 'store-empty-cart-action';
			var emptyText = document.createElement('p');
			emptyText.textContent = config.emptyText;
			var continueLink = document.createElement('a');
			continueLink.className = 'wp-element-button';
			continueLink.href = config.shopUrl;
			continueLink.textContent = config.continueText;
			emptyAction.appendChild(emptyText);
			emptyAction.appendChild(continueLink);
			emptyTitle.insertAdjacentElement('afterend', emptyAction);
		}
	}

	function scheduleEnhancement() {
		if (scheduled) return;
		scheduled = true;
		window.requestAnimationFrame(function () {
			scheduled = false;
			enhanceCart();
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		scheduleEnhancement();
		new MutationObserver(scheduleEnhancement).observe(document.body, { childList: true, subtree: true });
		document.addEventListener('change', function (event) {
			var input = event.target.closest && event.target.closest('.wc-block-components-quantity-selector__input');
			if (!input || input.dataset.storeSanitizing === 'true') return;
			var minimum = Math.max(1, parseInt(input.min || '1', 10));
			var value = parseInt(input.value, 10);
			if (!Number.isFinite(value) || value < minimum) {
				input.dataset.storeSanitizing = 'true';
				input.value = minimum;
				input.setAttribute('aria-invalid', 'true');
				var status = document.querySelector('.store-cart-quantity-status');
				if (!status) {
					status = document.createElement('p');
					status.className = 'store-cart-quantity-status screen-reader-text';
					status.setAttribute('role', 'status');
					status.setAttribute('aria-live', 'polite');
					document.body.appendChild(status);
				}
				status.textContent = config.quantityReset;
				input.dispatchEvent(new Event('input', { bubbles: true }));
				window.setTimeout(function () {
					input.removeAttribute('aria-invalid');
					delete input.dataset.storeSanitizing;
				}, 0);
			}
		}, true);
	});
})();
