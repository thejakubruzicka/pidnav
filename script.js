// script.js - dark single-page, používá přímé API + volitelný CORS proxy fallback
const el = id => document.getElementById(id);
const listEl = el('list'), statusEl = el('status'), noticeEl = el('notice');
const stationInput = el('stationInput'), searchBtn = el('searchBtn'), refreshBtn = el('refreshBtn');
const useProxy = el('useProxy'), stationNameEl = el('stationName'), lastUpdatedEl = el('lastUpdated');

let currentStation = '';
let poll = null;
let lastWasLive = false;

// malé demo fallbacky (pokud API neodpoví)
const MOCK = [
  { line: '21', destination: 'Hlavní nádraží', minutes: 3, delay: 0, carrier: 'Dopravní podnik Ústí n.L.' },
  { line: '104', destination: 'Most - centrum', minutes: 9, delay: 2, carrier: 'ARRIVA' }
];

// mapování dopravců -> přímé URL loga (doplň podle potřeby)
const LOGOS = {
  'ARRIVA': 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/4e/Arriva_Logo.svg/512px-Arriva_Logo.svg.png',
  'Dopravní podnik Ústí n.L.': 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7b/Dopravn%C3%AD_podnik_m%C4%9Bsta_%C3%9Ast%C3%AD_nad_Labem_logo.svg/512px-Dopravn%C3%AD_podnik_m%C4%9Bsta_%C3%9Ast%C3%AD_nad_Labem_logo.svg.png',
  'BusLine': 'https://www.busline.cz/assets/theme/images/logo-busline.png',
  'KOD': 'https://upload.wikimedia.org/wikipedia/commons/...' // doplň vlastní
};

// pomocná: odhadnout logo (fallback Clearbit)
function logoFor(name){
  if(!name) return placeholder();
  // najdi v mapě case-insensitive
  for(const k in LOGOS) if(k.toLowerCase() === (''+name).toLowerCase()) return LOGOS[k];
  // fallback: zkus clearbit podle jména (sanitizovat)
  const sanitized = (''+name).toLowerCase().replace(/[^a-z0-9]/g,'');
  if(sanitized.length >= 3) return 'https://logo.clearbit.com/' + sanitized + '.cz';
  return placeholder();
}
function placeholder(){ return 'https://upload.wikimedia.org/wikipedia/commons/3/3f/Placeholder_view_vector.svg' }

// pokusíme se volat několik možných endpointů (některé instalace tabule používají různé cesty)
async function tryFetchStation(stationId){
  const base = 'https://tabule.dopravauk.cz';
  const paths = [
    `/api/v1-tabule/basic/${stationId}`,
    `/api/station/${stationId}`,
    `/api/v1/${stationId}`,
    `/?mode=basic&data=api&station=${stationId}`
  ];
  const proxyPrefix = useProxy.checked ? 'https://corsproxy.io/?' : '';
  for(const p of paths){
    const url = proxyPrefix + base + p;
    try{
      const r = await fetch(url, {cache:'no-store'});
      if(!r.ok) throw new Error('non-200');
      const j = await r.json();
      // různá API vrací departures pod různými klíči - hledej obecně
      const departures = j.departures || j.data?.departures || j.items || j.result || (Array.isArray(j) ? j : null);
      if(Array.isArray(departures)) return { live:true, departures, raw:j, name: j.name || j.stationName || j.title || '' };
      // někdy je přímo v root s jinými názvy - zkus najít pole objektů
      for(const key in j){
        if(Array.isArray(j[key]) && j[key].length && typeof j[key][0] === 'object'){
          return { live:true, departures: j[key], raw:j, name:j.name || '' };
        }
      }
    }catch(e){
      // pokračuj k dalšímu path
    }
  }
  return null;
}

function clearList(){ listEl.innerHTML = ''; }
function renderNotFound(){ clearList(); listEl.innerHTML = `<div class="card"><div class="left"><div class="line">Žádné spoje</div></div></div>`; }

function renderDepartures(deps){
  clearList();
  if(!deps || deps.length === 0){ renderNotFound(); return; }
  deps.forEach(d=>{
    // normalize fields from various API shapes
    const line = d.line || d.route || d.number || d.product || d.vehicle || d.name || '—';
    const dest = d.headsign || d.destination || d.to || d.direction || d.cil || d.end || '—';
    const minutes = (d.departure != null) ? d.departure : (d.minutes != null ? d.minutes : (d.timeToDeparture != null ? d.timeToDeparture : null));
    const minutesTxt = (minutes !== null && minutes !== undefined) ? `${minutes} min` : (d.plannedTime || d.time || '—');
    const delay = d.delay || d.zpozdeni || d.delayMinutes || d.delay_min || 0;
    const carrier = d.carrier || d.operator || d.company || d.provider || '';
    const logo = logoFor(carrier);

    const card = document.createElement('div'); card.className = 'card';
    card.innerHTML = `
      <div class="left">
        <div class="logo"><img src="${logo}" alt="${carrier}" style="height:100%; width:auto" /></div>
        <div>
          <div class="line">${escapeHtml(line)}</div>
          <div class="dest">${escapeHtml(dest)}</div>
        </div>
      </div>
      <div class="right">
        <div class="time">${escapeHtml(minutesTxt)}</div>
        ${delay ? `<div class="delay">+${escapeHtml(delay)} min</div>` : ''}
      </div>
    `;
    listEl.appendChild(card);
  });
}

function escapeHtml(s){ if(s === null || s === undefined) return ''; return String(s).replace(/[&<>"'`]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;',"`":'&#96;'}[c])); }

async function loadStation(query){
  statusEl.textContent = 'Načítám…';
  noticeEl.classList.add('hidden');
  stationNameEl.textContent = '';
  lastUpdatedEl.textContent = '';
  clearList();

  // if query looks numeric -> treat as ID, otherwise try treat as name (name search not guaranteed)
  const isId = /^\d+$/.test(query.trim());
  let stationId = query.trim();

  // If name search: try to read main tabule page to find station ID (best-effort)
  if(!isId){
    // try to fetch search endpoint or page — many tabule installs don't expose search API cross-origin, so skip heavy attempts
    // fallback: set status and abort to ask for numeric ID
    statusEl.textContent = 'Vyhledávání podle názvu není spolehlivé přes veřejný web. Prosím použij ID zastávky (číselné).';
    return;
  }

  const result = await tryFetchStation(stationId);
  if(result && result.live){
    lastWasLive = true;
    statusEl.textContent = 'Živá data načtena.';
    stationNameEl.textContent = result.name ? `Zastávka: ${result.name}` : '';
    renderDepartures(result.departures);
    lastUpdatedEl.textContent = `Aktualizováno: ${new Date().toLocaleTimeString()}`;
    noticeEl.classList.add('hidden');
  } else {
    lastWasLive = false;
    statusEl.textContent = 'Nepodařilo se získat živá data — zobrazuji náhradní spoje.';
    renderDepartures(MOCK);
    noticeEl.classList.remove('hidden');
    lastUpdatedEl.textContent = `Aktualizováno (mock): ${new Date().toLocaleTimeString()}`;
  }
}

function startPolling(){
  if(poll) clearInterval(poll);
  poll = setInterval(()=>{ if(currentStation) loadStation(currentStation); }, 30000);
}

// UI events
searchBtn.addEventListener('click', ()=>{
  const q = stationInput.value.trim();
  if(!q) return;
  currentStation = q;
  loadStation(q);
});
refreshBtn.addEventListener('click', ()=>{ if(currentStation) loadStation(currentStation); });
stationInput.addEventListener('keydown', (e)=>{ if(e.key === 'Enter') searchBtn.click(); });

// initial
stationInput.value = '1737';
currentStation = '1737';
loadStation(currentStation);
startPolling();
