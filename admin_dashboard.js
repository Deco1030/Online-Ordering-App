// Admin dashboard sidebar collapse (separate from client JS)
(function(){
  const sidebar = document.getElementById('adminSidebar');
  const toggle = document.getElementById('adminSidebarToggle');
  if(!sidebar || !toggle) return;

  const key = 'otx_admin_sidebar_collapsed';

  const apply = (collapsed)=>{
    sidebar.classList.toggle('is-collapsed', !!collapsed);
    try{ localStorage.setItem(key, collapsed ? '1' : '0'); }catch(e){}
  };

  let initial=false;
  try{ initial = localStorage.getItem(key) === '1'; }catch(e){ initial=false; }
  apply(initial);

  toggle.addEventListener('click', ()=>{
    const now = !sidebar.classList.contains('is-collapsed');
    apply(now);
  });
})();

// Admin dashboard: AJAX refresh + Chart.js
(function(){
  const root = window.OTX_ADMIN || null;
  if(!root) return;

  const basePath = root.basePath || '';

  // Dark mode toggle
  const themeKey = 'otx_admin_theme';
  const body = document.body;
  const themeToggle = document.getElementById('themeToggle');

  const applyTheme = (mode)=>{
    const isDark = mode === 'dark';
    body.classList.toggle('theme-dark', isDark);
    try{ localStorage.setItem(themeKey, isDark ? 'dark' : 'light'); }catch(e){}
  };

  let initialTheme = 'light';
  try{ initialTheme = localStorage.getItem(themeKey) || 'light'; }catch(e){}
  applyTheme(initialTheme);

  if(themeToggle){
    themeToggle.addEventListener('click', ()=>{
      const nowDark = !body.classList.contains('theme-dark');
      applyTheme(nowDark ? 'dark' : 'light');
    });
  }

  const statusEl = document.getElementById('adminAjaxStatus');
  const setLoading = (loading)=>{
    if(!statusEl) return;
    if(loading){
      statusEl.classList.add('d-flex');
    }
  };

  const safeText = (v)=> (v === null || v === undefined) ? '' : String(v);

  const charts = {
    orders: null,
    revenue: null,
    shipmentPie: null
  };

  const getSeed = ()=> window.OTX_ADMIN_SEED || {};

  const renderChartsFromPayload = (chartsPayload)=>{
    const labels = chartsPayload?.monthsLabels || [];

    // Orders line
    try{
      const ctx1 = document.getElementById('ordersChart');
      if(ctx1){
        if(charts.orders) charts.orders.destroy();
        charts.orders = new Chart(ctx1, {
          type: 'line',
          data: {
            labels,
            datasets: [{
              label: 'Orders',
              data: chartsPayload.ordersPerMonth || [],
              borderColor: 'rgba(245,158,11,0.95)',
              backgroundColor: 'rgba(245,158,11,0.15)',
              tension: 0.35,
              fill: true,
              pointRadius: 3
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
              x: { grid: { display: false }, ticks: { color: '#52637a' } },
              y: { grid: { color: 'rgba(2,6,23,0.06)' }, ticks: { color: '#52637a' } }
            }
          }
        });
      }
    }catch(e){}

    // Revenue chart
    try{
      const ctx2 = document.getElementById('revenueChart');
      if(ctx2){
        if(charts.revenue) charts.revenue.destroy();
        charts.revenue = new Chart(ctx2, {
          type: 'bar',
          data: {
            labels,
            datasets: [{
              label: 'Revenue',
              data: chartsPayload.revenuePerMonth || [],
              borderRadius: 10,
              backgroundColor: 'rgba(59,130,246,0.35)',
              borderColor: 'rgba(59,130,246,0.95)'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
              x: { grid: { display: false }, ticks: { color: '#52637a' } },
              y: { grid: { color: 'rgba(2,6,23,0.06)' }, ticks: { color: '#52637a' } }
            }
          }
        });
      }
    }catch(e){}

    // Shipment pie
    try{
      const ctx3 = document.getElementById('shipmentPieChart');
      if(ctx3){
        if(charts.shipmentPie) charts.shipmentPie.destroy();
        const pieLabels = chartsPayload.shipmentPieLabels || [];
        const pieValues = chartsPayload.shipmentPieValues || [];
        charts.shipmentPie = new Chart(ctx3, {
          type: 'pie',
          data: {
            labels: pieLabels,
            datasets: [{
              data: pieValues,
              backgroundColor: [
                'rgba(245,158,11,0.8)',
                'rgba(37,99,235,0.75)',
                'rgba(251,146,60,0.75)',
                'rgba(34,197,94,0.75)',
                'rgba(239,68,68,0.75)'
              ],
              borderColor: 'rgba(255,255,255,0.95)'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { position: 'bottom', labels: { color: '#52637a' } }
            }
          }
        });
      }
    }catch(e){}
  };

  const loadDashboardCharts = async ()=>{
    const url = `${basePath}/dashboard_charts.php?ts=${Date.now()}`;
    try{
      const res = await fetch(url, { method: 'GET', credentials: 'same-origin' });
      if(!res.ok) throw new Error('charts request failed');
      const payload = await res.json();
      renderChartsFromPayload(payload);
    }catch(e){
      // fallback to seed
      renderChartsFromPayload(getSeed());
    }
  };

  const loadDashboardData = async ()=>{
    const url = `${basePath}/dashboard_data.php?ts=${Date.now()}`;

    try{
      const res = await fetch(url, { method: 'GET', credentials: 'same-origin' });
      if(!res.ok) throw new Error('data request failed');
      const payload = await res.json();

      // counts
      const counts = payload?.counts || {};
      const orders = counts.orders || {};
      const shipments = counts.shipments || {};
      const customers = counts.customers || {};
      const revenue = counts.revenue || {};

      const setNum = (id, v)=>{ const el = document.getElementById(id); if(el) el.textContent = safeText(v); };

      setNum('statOrdersTotal', orders.total ?? 0);
      setNum('statOrdersPending', orders.pending ?? 0);
      setNum('statOrdersProcessing', orders.processing ?? 0);
      setNum('statOrdersDelivered', orders.delivered ?? 0);

      setNum('statShipmentsActive', shipments.active ?? 0);
      setNum('statShipmentsTransit', shipments.in_transit ?? 0);
      setNum('statShipmentsDelivered', shipments.delivered ?? 0);
      setNum('statShipmentsCancelled', shipments.cancelled ?? 0);

      setNum('statCustomersTotal', customers.total ?? 0);
      setNum('statCustomersNew', customers.new_this_month ?? 0);

      setNum('statRevenueTotal', (revenue.total ?? 0));
      setNum('statRevenueMonthly', (revenue.monthly ?? 0));

      // shipment overview
      setNum('trkActive', shipments.active ?? 0);
      setNum('trkInTransit', shipments.in_transit ?? 0);

      // performance metrics
      const perf = payload?.performance || {};
      const deliveryRate = perf.delivery_success_rate ?? 0;
      const satisfaction = perf.customer_satisfaction_rate ?? 0;
      const avgDays = perf.avg_delivery_time_days ?? 0;
      const growth = perf.monthly_growth_rate ?? 0;

      const metricSet = (textId, barId, value, max)=>{
        const el = document.getElementById(textId);
        if(el){
          const suffix = (textId === 'metricAvgTime') ? ' days' : '%';
          el.textContent = (textId === 'metricAvgTime') ? `${value} days` : `${value}%`;
        }
        const bar = document.getElementById(barId);
        if(bar){
          const m = max || 100;
          const w = Math.max(0, Math.min(100, (Number(value) / m) * 100));
          bar.style.width = `${w}%`;
        }
      };

      metricSet('metricDelivery','metricDeliveryBar', deliveryRate, 100);
      metricSet('metricSatisfaction','metricSatisfactionBar', satisfaction, 100);
      metricSet('metricGrowth','metricGrowthBar', growth, 100);
      metricSet('metricAvgTime','metricAvgTimeBar', avgDays, 30);

      // tables: recent orders
      const ordersTbody = document.querySelector('#recentOrdersTable tbody');
      if(ordersTbody){ /* not wired in current markup */ }

    }catch(e){
      // best-effort; keep server seed
      return;
    }
  };

  const bootInitial = ()=>{
    // Render charts from seed instantly
    renderChartsFromPayload(getSeed());

    // Kick off AJAX refresh
    loadDashboardData();
    loadDashboardCharts();

    // periodic refresh
    setInterval(()=>{
      loadDashboardData();
      loadDashboardCharts();
    }, 60 * 1000);
  };

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', bootInitial);
  }else{
    bootInitial();
  }
})();


