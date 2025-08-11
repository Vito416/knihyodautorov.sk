// /admin/js/admin-charts.js
document.addEventListener('DOMContentLoaded', function(){
  try {
    const ctx = document.getElementById('ordersChart');
    if (!ctx) return;
    const d = window._dashboardData || {months:[], counts:[], sums:[]};
    new Chart(ctx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: d.months,
        datasets: [
          { label: 'Počet objednávok', data: d.counts, yAxisID: 'y', backgroundColor: 'rgba(52,36,20,0.85)' },
          { label: 'Tržby (€)', data: d.sums, yAxisID: 'y1', type: 'line', borderColor: 'rgba(207,155,58,0.95)', backgroundColor: 'rgba(207,155,58,0.25)', fill: true }
        ]
      },
      options: {
        responsive: true,
        scales: {
          y: { type: 'linear', position: 'left', title: {display:true, text:'Objednávky'} },
          y1: { type:'linear', position:'right', grid: { drawOnChartArea:false }, title:{display:true, text:'Tržby (€)'} }
        },
        plugins: { legend: { position: 'bottom' } }
      }
    });
  } catch(e) { console.error(e); }
});