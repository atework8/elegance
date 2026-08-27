(() => {
  const button = document.querySelector('.store-back-to-top');
  if (!button) return;
  const update = () => button.classList.toggle('is-visible', window.scrollY > 500);
  window.addEventListener('scroll', update, { passive: true });
  button.addEventListener('click', () => window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }));
  update();
})();
