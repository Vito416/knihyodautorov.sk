// support-impact.js - drobné animácie progress / counters
document.addEventListener('DOMContentLoaded', function(){
  // animácia počítadiel - len pre vizuál
  const nums = document.querySelectorAll('.si-number');
  nums.forEach(n => {
    const val = n.textContent.trim();
    // len jednoduché efekty, nerobíme komplexné parsingy
    n.style.opacity = 0;
    setTimeout(()=>{ n.style.transition = 'opacity 420ms ease, transform 420ms ease'; n.style.opacity = 1; n.style.transform = 'translateY(0)'; }, 200);
  });
});
