<link rel="stylesheet" href="/css/books.css" />

<section id="books" class="books-section">
  <div class="container books-container">

    <header class="books-header">
      <h2>Naše <span>knihy</span></h2>

      <div class="books-controls" role="toolbar" aria-label="Filter knih">
        <div class="filter-group" role="group" aria-label="Kategórie">
          <button class="filter-btn active" data-filter="*">Všetko</button>
          <button class="filter-btn" data-filter="novel">Romány</button>
          <button class="filter-btn" data-filter="poetry">Poézia</button>
          <button class="filter-btn" data-filter="nonfiction">Odborné</button>
        </div>

        <div class="filter-group" role="group" aria-label="Autor">
          <button class="filter-btn" data-filter="all-authors">Všetci autori</button>
          <button class="filter-btn" data-filter="sk">Slovenskí</button>
          <button class="filter-btn" data-filter="cz">Českí</button>
        </div>
      </div>
    </header>

    <div class="books-grid" id="booksGrid">
      <!--
        Príklad karty. Pre dynamické naplnenie v PHP nahraď nasledovný blok generovaním z DB.
        Každá karta má data-category (jedna zo: novel, poetry, nonfiction) a data-origin (sk, cz, other).
      -->

      <article class="book-card" data-category="novel" data-origin="sk" tabindex="0">
        <div class="card-inner">
          <div class="card-front">
            <img class="book-cover" data-src="assets/covers/book1.jpg" alt="Názov knihy 1 — obálka" />
            <div class="card-info">
              <h3 class="book-title">Názov knihy 1</h3>
              <p class="book-author">Ján Novák</p>
            </div>
            <div class="card-meta">
              <span class="badge">Román</span>
            </div>
          </div>

          <div class="card-back">
            <div class="back-content">
              <h3>Názov knihy 1</h3>
              <p class="short-desc">Krátky úvod, teaser text — niekoľko viet, ktoré čitateľa zaujmú.</p>
              <div class="back-actions">
                <button class="btn btn-outline open-detail" data-title="Názov knihy 1" data-author="Ján Novák" data-desc="Tu bude dlhší popis knihy..." data-cover="assets/covers/book1.jpg">Viac</button>
                <a href="pdf/kniha1.pdf" class="btn btn-primary" target="_blank" rel="noopener">Stiahnuť PDF</a>
              </div>
            </div>
          </div>
        </div>
      </article>

      <!-- Duplicate / sample cards -->
      <article class="book-card" data-category="poetry" data-origin="cz" tabindex="0">
        <div class="card-inner">
          <div class="card-front">
            <img class="book-cover" data-src="assets/covers/book2.jpg" alt="Názov knihy 2 — obálka" />
            <div class="card-info">
              <h3 class="book-title">Názov knihy 2</h3>
              <p class="book-author">Eva Kováčová</p>
            </div>
            <div class="card-meta">
              <span class="badge">Poézia</span>
            </div>
          </div>

          <div class="card-back">
            <div class="back-content">
              <h3>Názov knihy 2</h3>
              <p class="short-desc">Krátký popis poezie — jemné verše a atmosféra.</p>
              <div class="back-actions">
                <button class="btn btn-outline open-detail" data-title="Názov knihy 2" data-author="Eva Kováčová" data-desc="Dlhý popis knihy 2..." data-cover="assets/covers/book2.jpg">Viac</button>
                <a href="pdf/kniha2.pdf" class="btn btn-primary" target="_blank" rel="noopener">Stiahnuť PDF</a>
              </div>
            </div>
          </div>
        </div>
      </article>

      <article class="book-card" data-category="nonfiction" data-origin="sk" tabindex="0">
        <div class="card-inner">
          <div class="card-front">
            <img class="book-cover" data-src="assets/covers/book3.jpg" alt="Názov knihy 3 — obálka" />
            <div class="card-info">
              <h3 class="book-title">Názov knihy 3</h3>
              <p class="book-author">Peter Biely</p>
            </div>
            <div class="card-meta">
              <span class="badge">Odborné</span>
            </div>
          </div>

          <div class="card-back">
            <div class="back-content">
              <h3>Názov knihy 3</h3>
              <p class="short-desc">Krátky popis odborného textu — zhrnutie obsahu.</p>
              <div class="back-actions">
                <button class="btn btn-outline open-detail" data-title="Názov knihy 3" data-author="Peter Biely" data-desc="Dlhý popis knihy 3..." data-cover="assets/covers/book3.jpg">Viac</button>
                <a href="pdf/kniha3.pdf" class="btn btn-primary" target="_blank" rel="noopener">Stiahnuť PDF</a>
              </div>
            </div>
          </div>
        </div>
      </article>

      <!-- ... ďalšie karty podľa potreby -->
    </div>
  </div>

  <!-- Modal pre detail knihy -->
  <div id="bookModal" class="book-modal" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-inner" role="document">
      <button class="modal-close" aria-label="Zavrieť">&times;</button>
      <div class="modal-grid">
        <img id="modalCover" src="" alt="Obálka knihy" />
        <div class="modal-info">
          <h3 id="modalTitle">Názov knihy</h3>
          <p id="modalAuthor">Autor</p>
          <p id="modalDesc">Dlhý popis knihy...</p>
          <a id="modalDownload" class="btn btn-primary" href="#" target="_blank" rel="noopener">Stiahnuť PDF</a>
        </div>
      </div>
    </div>
  </div>
</section>

<script src="js/books.js" defer></script>