// /eshop/js/eshop.js
document.addEventListener('DOMContentLoaded', function(){
  // AJAX add to cart
  document.querySelectorAll('form[action="/eshop/actions/cart-add.php"]').forEach(function(f){
    f.addEventListener('submit', function(e){
      e.preventDefault();
      var data = new FormData(f);
      fetch(f.action, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r=>r.json()).then(function(json){
          if (json && json.ok) {
            // small feedback
            var btn = f.querySelector('button');
            var old = btn.innerHTML;
            btn.innerHTML = 'Pridané ✓';
            setTimeout(()=> btn.innerHTML = old, 1200);
            // optionally update cart counter
            document.querySelectorAll('.cart-count').forEach(function(el){ el.textContent = json.count; });
          } else {
            window.location = '/eshop/cart.php';
          }
        }).catch(function(){
          window.location = '/eshop/cart.php';
        });
    });
  });
});