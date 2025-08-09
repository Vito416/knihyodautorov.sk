// admin/js/admin-products.js
document.addEventListener('DOMContentLoaded', function(){
  const fileInputs = document.querySelectorAll('input[type="file"]');
  fileInputs.forEach(fi => {
    fi.addEventListener('change', function(e){
      const f = e.target.files[0];
      if (!f) return;
      if (f.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(ev){
          let p = fi.parentElement.querySelector('.preview-img');
          if (!p) {
            p = document.createElement('img');
            p.className = 'preview-img';
            p.style.width = '120px';
            p.style.height = '160px';
            p.style.objectFit = 'cover';
            p.style.marginTop = '10px';
            fi.parentElement.appendChild(p);
          }
          p.src = ev.target.result;
        };
        reader.readAsDataURL(f);
      }
    });
  });
});
