// how-it-works.js
document.addEventListener('DOMContentLoaded', function(){
  const steps = document.querySelectorAll('.hiw-step');
  if ('IntersectionObserver' in window) {
    const obs = new IntersectionObserver((entries, o) => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          e.target.classList.add('hiw-step--visible');
          o.unobserve(e.target);
        }
      });
    }, { threshold: 0.18 });
    steps.forEach(s => obs.observe(s));
  } else {
    steps.forEach(s => s.classList.add('hiw-step--visible'));
  }
});
