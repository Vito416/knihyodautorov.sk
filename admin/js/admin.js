// /admin/js/admin.js
document.addEventListener('DOMContentLoaded', function(){
  const flashes = document.querySelectorAll('.flash');
  setTimeout(()=> flashes.forEach(el=> el.style.display='none'), 6000);
});
