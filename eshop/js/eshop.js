// /eshop/js/eshop.js
// AJAX + interakcie pre eshop
document.addEventListener('DOMContentLoaded', function(){
  const promoGrid = document.getElementById('promoGrid');
  const searchInput = document.getElementById('eshopSearch');
  const searchBtn = document.getElementById('eshopSearchBtn');
  const checkoutBooks = document.getElementById('checkoutBooks');
  const checkoutForm = document.getElementById('eshopCheckout');
  const checkoutResult = document.getElementById('checkoutResult');

  // stav vybranych knih (id => true)
  let selected = {};

  function renderCards(items){
    promoGrid.innerHTML = '';
    items.forEach(it => {
      const card = document.createElement('div');
      card.className = 'promo-card';
      card.innerHTML = `
        <div class="card-inner">
          <img class="promo-cover" src="${it.obrazok}" onerror="this.onerror=null;this.src='/assets/books-imgFB.png'">
          <div class="promo-info">
            <h3 class="promo-title">${escapeHtml(it.nazov)}</h3>
            <div style="display:flex;justify-content:space-between;align-items:center">
              <p class="promo-author">${escapeHtml(it.autor || '')}</p>
              <p class="promo-price">${Number(it.cena).toFixed(2)} €</p>
            </div>
            <p class="promo-desc">${escapeHtml(it.popis || '').slice(0,160)}</p>
            <div class="promo-actions">
              <button class="btn btn-view" data-id="${it.id}">Zobraziť</button>
              <button class="btn btn-buy" data-id="${it.id}">Pridať do košíka</button>
            </div>
          </div>
        </div>
      `;
      promoGrid.appendChild(card);
    });

    // attach events
    promoGrid.querySelectorAll('.btn-buy').forEach(b=>{
      b.addEventListener('click', e=>{
        const id = e.currentTarget.dataset.id;
        selected[id] = true;
        refreshCheckoutBooks();
      });
    });
    promoGrid.querySelectorAll('.btn-view').forEach(b=>{
      b.addEventListener('click', e=>{
        const id = e.currentTarget.dataset.id;
        // pre jednoduchosť otvoríme modal s detailom (môžeme AJAX volať detail endpoint)
        // TODO: implement detail fetch
        alert('Detail knihy ID: ' + id);
      });
    });
  }

  function refreshCheckoutBooks(){
    const ids = Object.keys(selected);
    if(ids.length===0){
      checkoutBooks.innerText = 'Nie sú vybrané žiadne knihy.';
      checkoutBooks.setAttribute('aria-hidden','true');
    } else {
      checkoutBooks.setAttribute('aria-hidden','false');
      checkoutBooks.innerHTML = 'Vybrané: ' + ids.map(i=>'Kniha #'+i).join(', ') + `<input type="hidden" name="books[]" value="${ids.join(',')}">`;
    }
  }

  function fetchPromo(limit=4){
    fetch('/eshop/eshop.php?ajax=promo&limit=' + limit)
      .then(r=>r.json())
      .then(data=>{
        if(data.items) renderCards(data.items);
      }).catch(err=>console.error(err));
  }

  // search - jednoduché filtrovanie (môžeme doplniť search endpoint)
  searchBtn.addEventListener('click', ()=>{
    const q = (searchInput.value || '').trim();
    // ak q prázdne -> znovu náhodné
    if(q==='') return fetchPromo(4);
    // jednoduché vyhľadávanie: voláme serverový endpoint /books.php?ajax=1&q=...
    fetch('/partials/books.php?ajax=1&q=' + encodeURIComponent(q) + '&limit=8')
      .then(r=>r.json()).then(json=>{
        if(json.items) renderCards(json.items);
      }).catch(()=>fetchPromo(4));
  });

  // automatická rotácia promo (každých 8s nové)
  fetchPromo(4);
  setInterval(()=>fetchPromo(4), 8000);

  // checkout submit
  if(checkoutForm){
    checkoutForm.addEventListener('submit', function(e){
      e.preventDefault();
      const f = new FormData(checkoutForm);
      // books[] forma je string s id1,id2,... nebo můžeme poslát každý zvlášť
      let booksRaw = f.getAll('books[]')[0] || '';
      let arr = [];
      if(booksRaw.indexOf(',')>-1) arr = booksRaw.split(',').map(x=>x.trim()).filter(Boolean);
      else if(booksRaw!=='') arr = [booksRaw];
      if(arr.length===0){ checkoutResult.innerText = 'Vyber knihu pred vytvorením objednávky.'; return; }
      // připrav payload
      // send ids as array: books[]=id1&books[]=id2 ...
      const payload = new URLSearchParams();
      payload.append('action','create_order');
      payload.append('email', document.getElementById('checkoutEmail').value.trim());
      arr.forEach(id=>payload.append('books[]', id));

      fetch('/eshop/eshop.php', { method:'POST', body: payload })
        .then(r=>r.json())
        .then(json=>{
          if(json.error){ checkoutResult.innerText = json.error; return; }
          checkoutResult.innerHTML = 'Objednávka vytvorená. Číslo faktúry: ' + (json.invoice_number || '-') + '. <br>Skontroluj e-mail a sekciu faktúr.';
          // clear selection
          selected = {}; refreshCheckoutBooks();
        }).catch(err=>{
          console.error(err);
          checkoutResult.innerText = 'Chyba komunikácie so serverom.';
        });
    });
  }

  // helpers
  function escapeHtml(s){
    if(!s) return '';
    return s.replace(/[&<>"'`]/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;', '`':'&#96;'}[m]; });
  }
});
