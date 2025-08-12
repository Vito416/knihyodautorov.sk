// /admin/js/invoices.js
document.addEventListener('DOMContentLoaded', function () {
  // potvrdzovacie dialógy pre akcie ktoré prepíšu faktúry
  document.querySelectorAll('a[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      var msg = el.getAttribute('data-confirm') || 'Naozaj pokračovať?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // print tlačidlo (ak existuje)
  var printBtn = document.querySelector('.invoice-print');
  if (printBtn) {
    printBtn.addEventListener('click', function () {
      window.print();
    });
  }
});