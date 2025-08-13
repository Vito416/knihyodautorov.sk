// /eshop/js/eshop.js
// Malé utility pro eshop: AJAX helper + progressive enhancement.
// Nepřepisuje inline script v cart.php - doplňkový soubor.

(function(){
  'use strict';

  /**
   * Jednoduchý POST pomocí fetch + fallback pro starší prohlížeče (form submit).
   * @param {string} url
   * @param {Object} data
   * @returns {Promise<Response>}
   */
  function post(url, data) {
    if (window.fetch) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data).toString()
      });
    } else {
      // fallback: vytvoříme a odešleme form
      return new Promise(function(resolve, reject){
        var form = document.createElement('form');
        form.method = 'post';
        form.action = url;
        form.style.display = 'none';
        Object.keys(data).forEach(function(k){
          var i = document.createElement('input');
          i.type = 'hidden';
          i.name = k;
          i.value = data[k];
          form.appendChild(i);
        });
        document.body.appendChild(form);
        form.submit();
        // nelze detekovat výsledek - resolve hned
        resolve(new Response(null, {status: 200}));
      });
    }
  }

  // expose do global prostoru (pokud potřebuješ)
  window.Eshop = window.Eshop || {};
  window.Eshop.api = {
    post: post
  };

})();