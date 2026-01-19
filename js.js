// js.js (corregido)
const aud = new Audio();
const d = document;
let ctx, src, anl;
let filters = [];
let tracks = [], show = [];
let idx = -1;
let shuf = 0, loop = 0;
let ani;

// PERSISTENCIA
let eqGains = JSON.parse(localStorage.getItem('eqGains') || 'null') || new Array(10).fill(0);
let pls = JSON.parse(localStorage.getItem('pls') || '{}');
let favs = JSON.parse(localStorage.getItem('favs') || '[]');

const eqConfig = [
    {freq: 32, label: '32'}, {freq: 64, label: '64'}, {freq: 125, label: '125'},
    {freq: 250, label: '250'}, {freq: 500, label: '500'}, {freq: 1000, label: '1K'},
    {freq: 2000, label: '2K'}, {freq: 4000, label: '4K'}, {freq: 8000, label: '8K'},
    {freq: 16000, label: '16K'}
];

function initEqualizerUI() {
    const b = d.getElementById('eq-bands');
    if (!b) return; // protección: si el elemento no existe, salimos sin romper
    b.innerHTML = '';
    eqConfig.forEach((band, i) => {
        const c = d.createElement('div');
        c.className = 'slider-container';

        const inp = d.createElement('input');
        inp.type = 'range'; inp.min = -12; inp.max = 12;
        inp.value = eqGains[i] || 0;
        inp.className = 'slider-v';
        inp.oninput = (ev) => updateEqGain(i, ev.target.value);

        const l = d.createElement('span');
        l.className = 'slider-label';
        l.textContent = band.label;

        c.appendChild(inp); c.appendChild(l);
        b.appendChild(c);
    });
}

function debounce(fn, wait) {
    let t;
    return function(...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), wait);
    };
}
const debouncedFilter = debounce(filter, 200);

// La inicialización de DOM y handlers que necesitan elementos se colocan aquí
d.addEventListener('DOMContentLoaded', () => {
    // UI que depende del DOM
    initEqualizerUI();
    init();

    // SEEK slider (proteger si no existe)
    const seekEl = d.getElementById('seek');
    if (seekEl) {
        seekEl.oninput = (e) => {
            if (aud.duration) aud.currentTime = (e.target.value / 1000) * aud.duration;
        };
    }

    // VOLUME slider (selector robusto: busca en la caja .vol-slider-box)
    const volInput = d.querySelector('.vol-slider-box input[type="range"]');
    if (volInput) {
        volInput.addEventListener('input', (e) => {
            aud.volume = parseFloat(e.target.value);
        });
        // asegurar que el audio use el valor inicial del control
        aud.volume = parseFloat(volInput.value) || 1;
    }

    // Actualizar estado de favorito botón si existe
    try { updFav(); } catch(e) {}

    // Asegurar que el botón play exista antes de cualquier manipulación
    const playBtn = d.getElementById('play');
    if (playBtn && !playBtn.hasAttribute('data-init')) {
        // marca init para evitar dobles inicializaciones
        playBtn.setAttribute('data-init', '1');
    }
});

// --- Lógica principal ---
async function init() {
    const loader = d.getElementById('loader');
    if (loader) loader.style.display = 'block';
    try {
        const r = await fetch('?action=scan');
        const j = await r.json();
        if (j.status !== 'ok') {
            const cfg = d.getElementById('cfg-modal');
            if (cfg) cfg.style.display = 'grid';
        } else {
            tracks = j.data;
            show = [...tracks];
            render();
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    if (loader) loader.style.display = 'none';
}

async function savePath() {
    const pathEl = d.getElementById('m-path');
    const path = pathEl ? pathEl.value : '';
    try {
        const r = await fetch('?action=config', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ path })
        });
        const j = await r.json();
        if (j.status === 'ok') location.reload();
        else alert('Error: ' + (j.message));
    } catch (e) { alert('Error: ' + e.message); }
}

function render() {
    const tb = d.getElementById('list');
    if (!tb) return;
    tb.innerHTML = '';

    const starSvg = '<svg class="ico-sm" style="color:var(--a)" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg> ';

    show.forEach((t, i) => {
        const tr = d.createElement('tr');
        if (i === idx) tr.className = 'active';

        const tdNum = d.createElement('td'); tdNum.textContent = (i + 1);
        const tdName = d.createElement('td');
        if (favs.includes(t.id)) tdName.innerHTML = starSvg + t.name;
        else tdName.textContent = t.name;

        const tdSize = d.createElement('td'); tdSize.textContent = `${t.size}MB`;

        tr.appendChild(tdNum); tr.appendChild(tdName); tr.appendChild(tdSize);
        tr.onclick = () => load(i);
        tb.appendChild(tr);
    });
    renderPls();
}

function filter() {
    const qEl = d.getElementById('search');
    const q = qEl ? qEl.value.toLowerCase() : '';
    show = tracks.filter(t => t.name.toLowerCase().includes(q));
    render();
}

function initAudioContext() {
    if (ctx) return;
    try {
        const AC = window.AudioContext || window.webkitAudioContext;
        ctx = new AC();
        setupAudioGraph();
    } catch (e) {}
}

function setupAudioGraph() {
    if (!ctx) return;
    if (!src) {
        try { src = ctx.createMediaElementSource(aud); } catch(e){ return; }
    }
    if (filters.length > 0) return;

    anl = ctx.createAnalyser();
    anl.fftSize = 256;

    filters = [];
    let last = src;

    eqConfig.forEach((band, i) => {
        const f = ctx.createBiquadFilter();
        f.type = 'peaking';
        f.frequency.value = band.freq;
        f.Q.value = band.freq < 250 ? 0.7 : (band.freq < 2000 ? 1.0 : 1.4);
        f.gain.value = parseFloat(eqGains[i]) || 0;
        filters.push(f);
        last.connect(f);
        last = f;
    });

    last.connect(anl);
    anl.connect(ctx.destination);
    if(!ani) vis();
}

function updateEqGain(i, val) {
    const g = parseFloat(val) || 0;
    eqGains[i] = g;
    localStorage.setItem('eqGains', JSON.stringify(eqGains));
    if (ctx && filters[i]) filters[i].gain.setTargetAtTime(g, ctx.currentTime, 0.1);
}

function load(i) {
    if (i < 0 || i >= show.length) return;
    if (!ctx) initAudioContext();
    else if (ctx.state === 'suspended') ctx.resume();

    const card = d.getElementById('c-card');
    if (card) card.classList.remove('flipped');

    idx = i;
    render();

    const t = show[idx];
    aud.crossOrigin = 'anonymous';
    aud.src = `?stream_id=${encodeURIComponent(t.id)}`;

    fetchTrackInfo(t.id);

    const cArt = d.getElementById('cover-art');
    if (cArt) {
        const tempImg = new Image();
        cArt.style.opacity = '0';
        tempImg.onload = function() {
            cArt.style.backgroundImage = `url('${tempImg.src}')`;
            cArt.style.opacity = '1';
        };
        tempImg.onerror = function() {
            cArt.style.opacity = '0';
        };
        tempImg.src = `?action=cover&id=${encodeURIComponent(t.id)}`;
    }

    aud.play().then(() => { if(!filters.length) setupAudioGraph(); }).catch(()=>{});

    document.title = t.name;
    const nameEl = d.getElementById('t-name');
    if (nameEl) nameEl.textContent = t.name;

    updatePlayBtnState(true);

    const activeEl = d.querySelector('.active');
    if (activeEl) activeEl.scrollIntoView({behavior: "smooth", block: "center"});
}

function toggle() {
    if (!ctx) initAudioContext();
    else if (ctx.state === 'suspended') ctx.resume();

    if (aud.paused) {
        aud.play();
        updatePlayBtnState(true);
    } else {
        aud.pause();
        updatePlayBtnState(false);
    }
}

function updatePlayBtnState(isPlaying) {
    const btn = d.getElementById('play');
    if (!btn) return;
    const playIcon = '<svg class="ico-lg" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
    const pauseIcon = '<svg class="ico-lg" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
    btn.innerHTML = isPlaying ? pauseIcon : playIcon;
    if(isPlaying) btn.setAttribute('data-state', 'pause');
    else btn.removeAttribute('data-state');
}

function play(dir) {
    if (!show.length) return;
    let n;
    if (shuf) n = Math.floor(Math.random() * show.length);
    else n = idx + dir;

    if (loop && n >= show.length) n = 0;
    if (n >= 0 && n < show.length) load(n);
}

function toggleShuffle() {
    shuf = !shuf;
    const el = d.getElementById('btn-shuf');
    if (el) el.classList.toggle('act', shuf);
}
function toggleLoop() {
    loop = !loop;
    const el = d.getElementById('btn-loop');
    if (el) el.classList.toggle('act', loop);
}
function togglePlaylist() {
    const el = d.getElementById('pl-menu');
    if (el) el.classList.toggle('open');
}
function toggleEq() {
    const e = d.getElementById('eq-p');
    if (!e) return;
    e.style.display = e.style.display === 'block' ? 'none' : 'block';
}

// Eventos del audio (usar guardas porque DOM puede no tener los IDs en algunos casos)
aud.ontimeupdate = () => {
    const curEl = d.getElementById('cur');
    if (curEl) curEl.innerText = fmt(aud.currentTime);
    const seekEl = d.getElementById('seek');
    if (seekEl && !seekEl.matches(':active')) {
       seekEl.value = (aud.currentTime / (aud.duration || 1)) * 1000;
    }
};
aud.onloadedmetadata = () => {
    const durEl = d.getElementById('dur');
    if (durEl) durEl.innerText = fmt(aud.duration);
};
aud.onended = () => { if (loop && !shuf) load(idx); else play(1); };

function fmt(s) {
    if (!isFinite(s)) return '0:00';
    s = Math.floor(s);
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return `${m}:${sec.toString().padStart(2, '0')}`;
}

function vis() {
    const cv = d.getElementById('cvs');
    if (!anl) { ani = requestAnimationFrame(vis); return; }
    if (!cv) { ani = requestAnimationFrame(vis); return; }

    const cx = cv.getContext('2d');
    const w = cv.offsetWidth;
    const h = cv.offsetHeight;
    const dpr = window.devicePixelRatio || 1;

    if (cv.width !== Math.floor(w * dpr) || cv.height !== Math.floor(h * dpr)) {
        cv.width = Math.floor(w * dpr); cv.height = Math.floor(h * dpr);
        cx.scale(dpr, dpr);
    }

    const buf = anl.frequencyBinCount;
    const dat = new Uint8Array(buf);

    function draw() {
        ani = requestAnimationFrame(draw);
        anl.getByteFrequencyData(dat);
        cx.clearRect(0, 0, w, h);

        cx.beginPath();
        cx.moveTo(0, h);
        for (let i = 0; i < buf; i++) {
            const v = dat[i] / 255.0;
            const y = h - (v * h * 0.5);
            cx.lineTo((i/buf)*w, y);
        }
        cx.lineTo(w, h);
        cx.fillStyle = 'rgba(88, 166, 255, 0.2)';
        cx.fill();

        cx.beginPath();
        for (let i = 0; i < buf; i++) {
             const v = dat[i] / 255.0;
             cx.lineTo((i/buf)*w, h - (v * h * 0.5));
        }
        cx.strokeStyle = '#58a6ff';
        cx.lineWidth = 2;
        cx.stroke();
    }
    cancelAnimationFrame(ani); draw();
}

function fav() {
    if (idx < 0) return;
    const id = show[idx].id;
    const i = favs.indexOf(id);
    if (i === -1) favs.push(id); else favs.splice(i, 1);
    localStorage.setItem('favs', JSON.stringify(favs));
    try { updFav(); } catch(e) {}
    render();
}
function updFav() {
    const btn = d.getElementById('fav-btn');
    if (!btn) return;
    const emptyStar = '<svg class="ico" viewBox="0 0 24 24"><path d="M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z"/></svg>';
    const fullStar = '<svg class="ico" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
    if (idx >= 0 && show[idx] && favs.includes(show[idx].id)) {
        btn.innerHTML = fullStar; btn.classList.add('act');
    } else {
        btn.innerHTML = emptyStar; btn.classList.remove('act');
    }
}

function newPl() {
    const n = prompt('Nombre Playlist:');
    if (n && n.trim() && !pls[n]) {
        pls[n] = []; localStorage.setItem('pls', JSON.stringify(pls));
        renderPls();
    }
}
function addToPl(n) {
    if (idx < 0) return;
    const id = show[idx].id;
    if (!pls[n].includes(id)) {
        pls[n].push(id); localStorage.setItem('pls', JSON.stringify(pls));
        alert('Añadido a ' + n);
    }
}
function playPl(n) {
    show = tracks.filter(t => pls[n].includes(t.id));
    if (show.length > 0) { idx = 0; render(); load(0); }
}

function renderPls() {
    const c = d.getElementById('pl-list');
    const f = d.getElementById('fav-list');
    if (c) c.innerHTML = '';
    if (f) f.innerHTML = '';

    const plusIcon = '<svg class="ico" style="width:16px;height:16px" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>';

    Object.keys(pls).forEach(k => {
        if (!c) return;
        const div = d.createElement('div'); div.className = 'list-item';
        div.innerHTML = `<span><b>${k}</b> <small>(${pls[k].length})</small></span>`;
        const btn = d.createElement('button');
        btn.innerHTML = plusIcon;
        btn.className = 'btn'; btn.style.height='28px';
        btn.onclick = (e) => { e.stopPropagation(); addToPl(k); };
        div.appendChild(btn); div.onclick = () => playPl(k);
        c.appendChild(div);
    });

    tracks.filter(t => favs.includes(t.id)).forEach((t) => {
        if (!f) return;
        const el = d.createElement('div'); el.className = 'list-item';
        el.textContent = t.name;
        el.onclick = () => {
            const realIdx = tracks.findIndex(x => x.id === t.id);
            if(realIdx !== -1) { show = [...tracks]; idx = realIdx; render(); load(idx); }
        };
        f.appendChild(el);
    });
}

d.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT') return;
    if (e.key === ' ') { e.preventDefault(); toggle(); }
    if (e.key === 'ArrowRight') aud.currentTime += 5;
    if (e.key === 'ArrowLeft') aud.currentTime -= 5;
});

d.addEventListener('click', () => { if (ctx && ctx.state === 'suspended') ctx.resume(); }, {once:true});

function flipCover() {
    const c = d.getElementById('c-card');
    if (!c) return;
    c.classList.toggle('flipped');
}

async function fetchTrackInfo(id) {
    ['codec','bitrate','rate','bits','size'].forEach(k => {
        const el = d.getElementById('m-'+k);
        if(el) el.textContent = '--';
    });

    try {
        const r = await fetch(`?action=info&id=${encodeURIComponent(id)}`);
        const j = await r.json();
        if (j.status === 'ok') {
            const i = j.data;
            const setIf = (sel, txt) => { const el = d.getElementById(sel); if (el) el.textContent = txt; };
            setIf('m-codec', i.codec);
            setIf('m-bitrate', i.bitrate);
            setIf('m-rate', i.rate);
            setIf('m-bits', i.bits);
            setIf('m-size', i.size_fmt);
        }
    } catch(e) {
        console.error("Error cargando info técnica", e);
    }
}
