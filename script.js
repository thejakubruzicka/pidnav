// script.js — vanilla JS, poll every 30s, search by station ID, proxy toggle, mock fallback and "data not live" notice.

const listEl = () => document.getElementById('list');
const statusEl = () => document.getElementById('status');
const stationInput = () => document.getElementById('stationInput');
const loadBtn = () => document.getElementById('loadBtn');
const refreshBtn = () => document.getElementById('refreshBtn');
const useProxyEl = () => document.getElementById('useProxy');
const noticeEl = () => document.getElementById('notice');
const stationNameEl = () => document.getElementById('stationName');
const lastUpdatedEl = () => document.getElementById('lastUpdated');

let currentStation = stationInput().value || '10533';
let pollTimer = null;
let lastDataWasLive = false;

// small demo fallback data (used when API fails)
const MOCK = [
  { line: '133', destination: 'Sídliště Malešice', minutes: 5, delay: 0, carrier: 'ARRIVA' },
  { line: '177', destination: 'Poliklinika Mazurská', minutes: 12, delay: 0, carrier: 'BusLine' },
];

// known carriers mapping -> logo URL (add more if needed)
const KNOWN_LOGOS = {
  'ARRIVA': 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/4e/Arriva_Logo.svg/512px-Arriva_Logo.svg.png',
  'BusLine': 'https://www.busline.cz/assets/theme/images/logo-busline.png',
  'Dopravní podnik města Ústí nad Labem': 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7b/Dopravn%C3%AD_podnik_m%C4%9Bsta_%C3%9Ast%C3%AD_nad_Labem_logo.svg/512px-Dopravn%C3%AD_podnik_m%C4%9Bsta_%C3%9Ast%C3%AD_nad_Labem_logo.svg.png'
};

function logoForCarrier(carrierName) {
  if(!carrierName) return '';
  if (KNOWN_LOGOS[carrierName]) return KNOWN_LOGOS[carrierName];
  // fallback: try clearbit with sanitized domain or show placeholder
  const guessed = carrierName.toLowerCase().replace(/[^a-z0-9]/g,'');
  if (guessed.length >= 3) {
    return 'https://logo.clearbit.com/' + guessed + '.cz';
  }
  return 'https://upload.wikimedia.org/wikipedia/commons/3/3f/Placeholder_view_vector.svg';
}

async function fetchData(stationId) {
  // Try two common endpoints used by tabule apps
  const basePaths = [
    `/api/v1-tabule/basic/${stationId}`,  // some frontends use this
    `/api/station/${stationId}`,         // other variants
    `/api/v1/${stationId}`               // safety
  ];
  const domain = 'https://tabule.dopravauk.cz';

  // If proxy toggle is on, prefix with corsproxy.io
  const useProxy = useProxyEl().checked;
  const proxyPrefix = useProxy ? 'https://corsproxy.io/?' : '';

  for (let path of basePaths) {
    const url = proxyPrefix + domain + path;
    try {
      const res = await fetch(url, {cache: 'no-store'});
      if (!res.ok) throw new Error('non-200');
      const json = await res.json();
      // try to detect departures array under possible keys
      const departures = json.departures || json.data || json.items || json.result || null;
      if (departures && Array.isArray(departures)) {
        return { live: true, departures, raw: json, stationName: json.name || json.stationName || '' };
      }
      // sometimes the API returns a different shape (older), try to parse fields
      if (json && Array.isArray(json)) {
        return { live: true, departures: json, raw: json, stationName: '' };
      }
    } catch (err) {
      // console.warn('fetch fail for', url, err);
    }
  }

  // if all fails, return null to indicate fallback
  return null;
}

function renderList(deps) {
  const root = listEl();
  root.innerHTML = '';
  if (!deps || deps.length === 0) {
    root.innerHTML = `<div class="card"><div class="left"><div class="line">Žádné spoje</div></div></div>`;
    return;
  }

  deps.forEach(d => {
    // normalize fields names (support different API shapes)
    const line = d.line || d.route || d.number || d.product || d.vehicle || d.jizdni_rad_item || '—';
    const dest = d.headsign || d.destination || d.to || d.direction || d.cil || '—';
    // minutes may be 'departure' (min), 'minutes', 'timeToDeparture' or planned time; handle a few forms
    const minutes = (d.departure != null) ? d.departure : (d.minutes != null ? d.minutes : (d.timeToDeparture != null ? d.timeToDeparture : (d.in != null ? d.in : null)));
    const minsTxt = (minutes || minutes === 0) ? `${minutes} min` : (d.plannedTime || d.time || '—');

    const delay = d.delay || d.zpozdeni || d.delayMinutes || d.delay_min || 0;
    const carrier = d.carrier || d.operator || d.lineCarrier || d.provider || d.company || '';

    const el = document.createElement('div');
    el.className = 'card';
    el.innerHTML = `
      <div class="left">
        <div class="logo"><img src="${logoForCarrier(carrier)}" style="height:100%; width:auto" alt="${carrier}" /></div>
        <div>
          <div class="line">${escapeHtml(line)}</div>
          <div class="dest">${escapeHtml(dest)}</div>
        </div>
      </div>
      <div class="right">
        <div class="time">${escapeHtml(minsTxt)}</div>
        ${delay ? `<div class="delay">+${escapeHtml(delay)} min</div>` : ''}
      </div>
    `;
    root.appendChild(el);
  });
}

function escapeHtml(s){
  if (!s && s !== 0) return '';
  return String(s).replace(/[&<>"'`]/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;',"`":'&#96;'
  })[c]);
}

async function loadStation(id, {showStatus=true} = {}) {
  if (showStatus) statusEl().textContent = 'Načítám...';
  noticeEl().classList.add('hidden');
  stationNameEl().textContent = '';
  lastUpdatedEl().textContent = '';

  const result = await fetchData(id);

  if (result && result.live) {
    // live data
    lastDataWasLive = true;
    statusEl().textContent = 'Živá data načtena.';
    stationNameEl().textContent = result.stationName ? `Zastávka: ${result.stationName}` : '';
    renderList(result.departures);
    lastUpdatedEl().textContent = `Aktualizováno: ${new Date().toLocaleTimeString()}`;
    noticeEl().classList.add('hidden');
  } else {
    // fallback
    lastDataWasLive = false;
    statusEl().textContent = 'Nepodařilo se načíst živá data — zobrazuji náhradní (ukázkové) spoje.';
    renderList(MOCK);
    noticeEl().classList.remove('hidden');
    lastUpdatedEl().textContent = `Aktualizováno (mock): ${new Date().toLocaleTimeString()}`;
  }
}

function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(() => loadStation(currentStation, {showStatus:false}), 30000);
}

// events
loadBtn().addEventListener('click', () => {
  const v = stationInput().value.trim();
  if (v) {
    currentStation = v;
    loadStation(currentStation);
  }
});
refreshBtn().addEventListener('click', () => loadStation(currentStation));

stationInput().addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    loadBtn().click();
  }
});

// initial load
loadStation(currentStation);
startPolling();
