// /admin/js/admin.js
document.addEventListener('DOMContentLoaded', function(){

  // hamburger (mobile)
  var hamb = document.getElementById('admin-hamburger');
  var navList = document.getElementById('admin-nav-list');
  if (hamb && navList) {
    hamb.addEventListener('click', function(){
      var expanded = this.getAttribute('aria-expanded') === 'true';
      this.setAttribute('aria-expanded', (!expanded).toString());
      if (navList.style.display === 'block') navList.style.display = '';
      else navList.style.display = 'block';
    });
  }

  // SMTP test button (AJAX)
  var smtpBtn = document.getElementById('smtp-test-btn');
  if (smtpBtn) {
    smtpBtn.addEventListener('click', function(e){
      e.preventDefault();
      var url = this.dataset.url || '/admin/actions/smtp-test.php';
      var btn = this;
      btn.disabled = true;
      var old = btn.innerText;
      btn.innerText = 'Testujem...';
      fetch(url, { method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'}})
        .then(function(resp){
          if (!resp.ok) throw new Error('Chyba servera: ' + resp.status);
          return resp.json();
        })
        .then(function(json){
          if (json && json.ok) {
            alert('SMTP OK: ' + (json.message || 'Test prebehol úspešne.'));
          } else {
            alert('SMTP chyba: ' + (json && json.error ? json.error : JSON.stringify(json)));
          }
        })
        .catch(function(err){
          alert('Nepodarilo sa otestovať SMTP: ' + err.message);
        })
        .finally(function(){
          btn.disabled = false;
          btn.innerText = old;
        });
    });
  }

  // simple mini-chart render - bar chart from data attributes
  var mini = document.getElementById('dashboard-mini-chart');
  if (mini) {
    var books = parseInt(mini.dataset.books || 0,10);
    var authors = parseInt(mini.dataset.authors || 0,10);
    var users = parseInt(mini.dataset.users || 0,10);
    var orders = parseInt(mini.dataset.orders || 0,10);
    var arr = [books, authors, users, orders];
    var max = Math.max.apply(null, arr.concat([1]));
    // create bars
    var container = document.createElement('div');
    container.style.display = 'flex';
    container.style.alignItems = 'end';
    container.style.gap = '8px';
    container.style.height = '72px';
    ['Knihy','Autori','Užívatelia','Objednávky'].forEach(function(label,i){
      var val = arr[i];
      var barWrap = document.createElement('div');
      barWrap.style.flex = '1';
      barWrap.style.display = 'flex';
      barWrap.style.flexDirection = 'column';
      barWrap.style.alignItems = 'center';
      var bar = document.createElement('div');
      bar.style.width = '20px';
      bar.style.height = Math.round((val/max) * 64) + 'px';
      bar.style.background = 'linear-gradient(180deg, rgba(248,231,176,0.95), rgba(192,138,46,0.9))';
      bar.style.borderRadius = '6px';
      bar.style.boxShadow = 'inset 0 2px 6px rgba(0,0,0,0.35)';
      var lbl = document.createElement('div');
      lbl.style.fontSize = '11px';
      lbl.style.color = 'rgba(255,255,255,0.6)';
      lbl.style.marginTop = '8px';
      lbl.innerText = label;
      barWrap.appendChild(bar);
      barWrap.appendChild(lbl);
      container.appendChild(barWrap);
    });
    mini.appendChild(container);
  }

});
