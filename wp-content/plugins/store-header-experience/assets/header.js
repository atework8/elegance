(() => {
  const template = document.querySelector('#store-header-utilities');
  if (!template) return;
  document.querySelectorAll('#header [data-device] [data-items="primary"]').forEach((items) => {
    const cart = items.querySelector('[data-id="cart"]');
    if (!cart) return;
    const utilities = template.content.cloneNode(true);
    cart.before(utilities);
  });
  document.querySelectorAll('.store-wishlist-utility').forEach((button) => {
    button.addEventListener('click', (event) => event.preventDefault());
  });
})();
