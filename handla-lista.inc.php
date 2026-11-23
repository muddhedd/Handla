<?php
/**
 * handla-lista.inc.php – vyn för en lista (inkluderas från handla.php?lista=ID)
 * Lista, förslag, "Börja handla"-läget, offline-kö och livesynk.
 */
if (!defined('HANDLA_PAGE')) {
    http_response_code(403);
    exit;
}
?>
<body class="handla-list-page">
<div class="page list-page">
    <?php renderNav(HANDLA_PAGE); handlaSubNav('listor'); ?>

    <div id="net-banner" class="net-banner" hidden></div>

    <div class="list-head">
        <div>
            <h1 id="list-title"></h1>
            <p id="list-sub" class="muted"></p>
        </div>
        <button type="button" id="settings-btn" class="icon-btn" title="Ändra lista" aria-label="Ändra lista">⚙</button>
    </div>

    <!-- Inte handlar -->
    <div id="plan-bar" class="action-bar">
        <button type="button" id="start-btn" class="primary-btn big-btn">🛒 Börja handla</button>
        <button type="button" id="undo-btn" class="link-btn" hidden></button>
    </div>

    <!-- Handlar -->
    <div id="shop-bar" class="action-bar shop-bar" hidden>
        <span id="shop-progress" class="shop-progress"></span>
        <button type="button" id="finish-btn" class="primary-btn">✓ Klar</button>
        <button type="button" id="cancel-btn" class="secondary-btn">Avbryt</button>
    </div>

    <form id="add-form" class="add-form" autocomplete="off">
        <input type="text" id="add-name" list="vara-options" placeholder="Lägg till vara…" maxlength="150" enterkeyhint="done">
        <input type="text" id="add-qty" placeholder="Antal" maxlength="50" class="qty-input">
        <button type="submit" class="primary-btn" aria-label="Lägg till">+</button>
    </form>

    <div id="rows"></div>

    <div id="suggestions"></div>
</div>

<datalist id="vara-options">
    <?php foreach ($varorNamn as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?>
</datalist>
<datalist id="avd-options">
    <?php foreach ($avdelningar as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?>
</datalist>

<div id="toast" class="toast" hidden></div>

<!-- Redigera rad -->
<div id="edit-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="edit-title">
        <button type="button" class="popup-close" data-close="edit-overlay" aria-label="Stäng">&times;</button>
        <h2 id="edit-title"></h2>
        <form id="edit-form">
            <label>Antal <input type="text" id="edit-qty" maxlength="50" placeholder="t.ex. 2 st"></label>
            <label>Kommentar <input type="text" id="edit-comment" maxlength="255" placeholder="t.ex. laktosfri"></label>
            <label>Avdelning (gäller varan på alla listor)
                <input type="text" id="edit-avd" list="avd-options" maxlength="100" placeholder="t.ex. Frukt &amp; grönt">
            </label>
            <button type="submit" class="primary-btn">Spara</button>
        </form>
        <button type="button" id="edit-remove" class="danger-btn">Ta bort från listan</button>
    </div>
</div>

<!-- Börja handla -->
<div id="start-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="start-title">
        <button type="button" class="popup-close" data-close="start-overlay" aria-label="Stäng">&times;</button>
        <h2 id="start-title">Börja handla</h2>
        <form id="start-form">
            <label>Var handlar du?
                <?php butikSelect('start-butik', $butiker, $list['ButikID'] !== null ? (int)$list['ButikID'] : null); ?>
            </label>
            <button type="submit" class="primary-btn big-btn">🛒 Kör!</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<!-- Klar -->
<div id="finish-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="finish-title">
        <button type="button" class="popup-close" data-close="finish-overlay" aria-label="Stäng">&times;</button>
        <h2 id="finish-title">Klar med handlingen</h2>
        <p id="finish-info"></p>
        <form id="finish-form">
            <label>Vad kostade det? (frivilligt)
                <input type="text" id="finish-cost" inputmode="decimal" placeholder="t.ex. 845,50" autocomplete="off">
            </label>
            <button type="submit" class="primary-btn big-btn">Avsluta handlingen</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<!-- Ändra lista -->
<div id="settings-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="settings-title">
        <button type="button" class="popup-close" data-close="settings-overlay" aria-label="Stäng">&times;</button>
        <h2 id="settings-title">Ändra lista</h2>
        <form id="settings-form">
            <label>Namn <input type="text" name="namn" maxlength="150" required></label>
            <label>Butik <?php butikSelect('settings-butik', $butiker, $list['ButikID'] !== null ? (int)$list['ButikID'] : null); ?></label>
            <label class="check-label"><input type="checkbox" name="tillfallig" value="1"> Tillfällig lista – ingen varustatistik</label>
            <button type="submit" class="primary-btn">Spara</button>
            <p class="popup-msg"></p>
        </form>
        <button type="button" id="delete-list-btn" class="danger-btn">Ta bort listan</button>
    </div>
</div>

<?php commonScript(); ?>
<script>
const LIST_ID = <?= (int)$list['ID'] ?>;
let state = <?= json_encode($state, $jsonFlags) ?>;
const QKEY = 'handla-kö-' + LIST_ID;

// ============================================================
// Kö för ändringar (fungerar utan nät)
// ============================================================
function loadQueue() {
    try { return JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) { return []; }
}
function saveQueue() {
    try { localStorage.setItem(QKEY, JSON.stringify(queue)); } catch (e) {}
}
let queue = loadQueue();
let tempId = Math.min(-1, ...queue.filter(o => o.temp).map(o => o.temp)) - 1;
let flushing = false;
let online = true;
let netMsg = '';
let wakeLock = null;
let editRow = null;

const $ = id => document.getElementById(id);
const andringar = n => n + (n === 1 ? ' ändring' : ' ändringar');

function capitalize(s) {
    s = s.trim().replace(/\s+/g, ' ');
    return s ? s.charAt(0).toLocaleUpperCase('sv') + s.slice(1) : s;
}

function applyLocal(o) {
    const rows = state.rows;
    const row = rows.find(r => r.id === o.row);
    switch (o.type) {
        case 'add': {
            const ex = rows.find(r => r.namn.toLowerCase() === o.namn.toLowerCase());
            if (ex) {
                if (o.antal) ex.antal = o.antal;
            } else {
                rows.push({ id: o.temp, namn: o.namn, avdelning: '', antal: o.antal || '', kommentar: '',
                            status: 'kvar', ms: null, sort: 100000 - o.temp, hint: '', pending: true });
            }
            if (state.suggestions) {
                for (const k of ['due', 'rare', 'other']) {
                    state.suggestions[k] = state.suggestions[k].filter(s => s.namn.toLowerCase() !== o.namn.toLowerCase());
                }
            }
            break;
        }
        case 'remove': state.rows = rows.filter(r => r.id !== o.row); break;
        case 'edit':
            if (row) { row.antal = o.antal; row.kommentar = o.kommentar; if (o.avdelning !== undefined) row.avdelning = o.avdelning; }
            break;
        case 'check': if (row) { row.status = 'bockad'; row.ms = o.ms; } break;
        case 'missing': if (row) { row.status = 'saknas'; row.ms = null; } break;
        case 'uncheck':
        case 'unmissing': if (row) { row.status = 'kvar'; row.ms = null; } break;
    }
}

function op(o) {
    if (o.type === 'add') o.temp = tempId--;
    applyLocal(o);
    queue.push(o);
    saveQueue();
    render();
    flush();
}

async function flush() {
    if (flushing || !queue.length) return;
    flushing = true;
    let sent = false;
    try {
        while (queue.length) {
            const o = queue[0];
            let res;
            try {
                res = await post({ action: 'op', lista: LIST_ID, op: JSON.stringify(o) });
            } catch (e) {
                setOnline(false);
                return;
            }
            if (res.status === 401) {
                setOnline(false, 'Du är utloggad. Logga in igen (ladda om sidan) så skickas ändringarna.');
                return;
            }
            if (res.status >= 500) {
                setOnline(false, 'Servern svarar inte just nu – försöker igen.');
                return;
            }
            if (res.data.gone) { location.href = 'handla.php'; return; }
            setOnline(true);
            queue.shift();
            if (o.type === 'add' && res.data.row_id) {
                const real = res.data.row_id;
                queue.forEach(q => { if (q.row === o.temp) q.row = real; });
                const r = state.rows.find(r => r.id === o.temp);
                if (r) { r.id = real; r.pending = false; }
            }
            if (!res.data.success) toast(res.data.error || 'Något gick fel.');
            saveQueue();
            sent = true;
        }
    } finally {
        flushing = false;
        renderNet();
    }
    if (sent) await refresh();
}

async function refresh() {
    if (queue.length) return;
    try {
        const res = await post({ action: 'state', lista: LIST_ID });
        if (res.data.gone) { location.href = 'handla.php'; return; }
        if (res.data.success && !queue.length) {
            state = res.data.state;
            setOnline(true);
            render();
        }
    } catch (e) {
        setOnline(false);
    }
}

// Väntar tills kön är tom (för åtgärder som kräver nät).
async function ensureSynced() {
    await flush();
    if (queue.length) {
        toast('Väntar på nät – ' + andringar(queue.length) + ' har inte skickats än.');
        return false;
    }
    return true;
}

// ============================================================
// Livesynk: fråga om versionen ändrats
// ============================================================
async function poll() {
    if (!document.hidden && !flushing) {
        if (queue.length) {
            flush();
        } else {
            try {
                const res = await post({ action: 'version', lista: LIST_ID });
                if (res.data.gone) { location.href = 'handla.php'; return; }
                setOnline(true);
                if (res.data.success && res.data.version !== state.version) await refresh();
            } catch (e) {
                setOnline(false);
            }
        }
    }
    setTimeout(poll, state.trip ? 3000 : 8000);
}
window.addEventListener('online', () => flush());
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) { flush(); refresh(); }
    updateWakeLock();
});

function setOnline(ok, msg) {
    online = ok;
    netMsg = msg || '';
    renderNet();
}
function renderNet() {
    const b = $('net-banner');
    if (online && !queue.length) { b.hidden = true; return; }
    b.hidden = false;
    b.textContent = netMsg || (online
        ? 'Skickar ' + andringar(queue.length) + '…'
        : '⚠ Ingen kontakt med servern. ' + (queue.length ? andringar(queue.length) + ' sparas i telefonen och skickas när nätet är tillbaka.' : ''));
}

// ============================================================
// Skärmen ska inte släckas medan man handlar
// ============================================================
async function updateWakeLock() {
    const want = !!state.trip && !document.hidden;
    if (want && !wakeLock && 'wakeLock' in navigator) {
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => { wakeLock = null; });
        } catch (e) {}
    } else if (!want && wakeLock) {
        wakeLock.release().catch(() => {});
        wakeLock = null;
    }
}

// ============================================================
// Rendering
// ============================================================
function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
}

function toast(text, actionLabel, action) {
    const t = $('toast');
    t.innerHTML = '';
    t.appendChild(el('span', '', text));
    if (actionLabel) {
        const b = el('button', 'link-btn', actionLabel);
        b.type = 'button';
        b.addEventListener('click', () => { t.hidden = true; action(); });
        t.appendChild(b);
    }
    t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { t.hidden = true; }, actionLabel ? 9000 : 4000);
}

function rowDetails(r, wrap) {
    const top = el('span', 'row-top');
    top.appendChild(el('span', 'row-name', r.namn));
    if (r.antal) top.appendChild(el('span', 'row-qty', r.antal));
    wrap.appendChild(top);
    if (r.kommentar) wrap.appendChild(el('span', 'row-comment', r.kommentar));
}

function renderRows() {
    const box = $('rows');
    box.innerHTML = '';
    const shopping = !!state.trip;
    const kvar = state.rows.filter(r => r.status === 'kvar').sort((a, b) => a.sort - b.sort);
    const saknas = state.rows.filter(r => r.status === 'saknas').sort((a, b) => a.sort - b.sort);
    const bockad = state.rows.filter(r => r.status === 'bockad').sort((a, b) => (a.ms || 0) - (b.ms || 0));

    if (!state.rows.length) {
        box.appendChild(el('p', 'empty-row', shopping
            ? 'Listan är tom. Lägg till det du plockar ovan.'
            : 'Listan är tom. Lägg till varor ovan' + (state.suggestions ? ' eller ur förslagen nedan.' : '.')));
        return;
    }

    const section = (title, rows, kind) => {
        if (!rows.length) return;
        if (title) box.appendChild(el('h2', 'rows-title', title));
        const ul = el('ul', 'item-list ' + kind);
        rows.forEach(r => ul.appendChild(shopping ? shopRow(r) : planRow(r)));
        box.appendChild(ul);
    };

    if (shopping) {
        section('', kvar, 'kvar');
        section('Fanns inte (' + saknas.length + ') – ligger kvar till nästa gång', saknas, 'saknas');
        section('I korgen (' + bockad.length + ')', bockad, 'bockad');
    } else {
        section('', kvar, 'kvar');
    }
}

function planRow(r) {
    const li = el('li', 'item' + (r.pending ? ' pending' : ''));
    const info = el('div', 'item-info');
    rowDetails(r, info);
    if (r.hint) info.appendChild(el('span', 'row-hint', r.hint));
    info.addEventListener('click', () => openEdit(r));
    li.appendChild(info);
    const edit = el('button', 'icon-btn', '✎');
    edit.type = 'button';
    edit.title = 'Ändra';
    edit.setAttribute('aria-label', 'Ändra ' + r.namn);
    edit.addEventListener('click', () => openEdit(r));
    const del = el('button', 'icon-btn', '×');
    del.type = 'button';
    del.title = 'Ta bort';
    del.setAttribute('aria-label', 'Ta bort ' + r.namn);
    del.addEventListener('click', () => {
        op({ type: 'remove', row: r.id });
        toast('"' + r.namn + '" togs bort.', 'Ångra', () => op({ type: 'add', namn: r.namn, antal: r.antal }));
    });
    li.appendChild(edit);
    li.appendChild(del);
    return li;
}

function shopRow(r) {
    const li = el('li', 'item shop ' + r.status + (r.pending ? ' pending' : ''));
    const main = el('button', 'check-area');
    main.type = 'button';
    main.appendChild(el('span', 'box', r.status === 'bockad' ? '✓' : (r.status === 'saknas' ? '–' : '')));
    const info = el('span', 'item-info');
    rowDetails(r, info);
    main.appendChild(info);
    main.addEventListener('click', () => {
        if (r.status === 'kvar') op({ type: 'check', row: r.id, ms: Date.now() });
        else if (r.status === 'bockad') op({ type: 'uncheck', row: r.id });
        else op({ type: 'unmissing', row: r.id });
    });
    li.appendChild(main);
    if (r.status === 'kvar') {
        const miss = el('button', 'miss-btn', 'Fanns inte');
        miss.type = 'button';
        miss.addEventListener('click', () => op({ type: 'missing', row: r.id }));
        li.appendChild(miss);
    }
    return li;
}

function renderSuggestions() {
    const box = $('suggestions');
    box.innerHTML = '';
    const s = state.suggestions;
    if (state.trip || state.list.tillfallig) return;
    const butik = state.list.butik;
    if (!s) {
        if (!butik) box.appendChild(el('p', 'muted small', 'Koppla listan till en butik (⚙) för att få förslag och varorna i din plockordning.'));
        return;
    }
    if (!s.due.length && !s.rare.length && !s.other.length) {
        box.appendChild(el('p', 'muted small', 'Förslag dyker upp här när du har handlat några gånger på ' + butik + '.'));
        return;
    }
    const group = (title, list, opts = {}) => {
        if (!list.length) return;
        const wrap = opts.collapsed ? el('details', 'sugg-group') : el('div', 'sugg-group');
        const head = el(opts.collapsed ? 'summary' : 'div', 'sugg-head');
        head.appendChild(el('span', 'sugg-title', title + ' (' + list.length + ')'));
        if (opts.addAll) {
            const all = el('button', 'link-btn', 'Lägg till alla');
            all.type = 'button';
            all.addEventListener('click', e => { e.preventDefault(); list.slice().forEach(i => op({ type: 'add', namn: i.namn })); });
            head.appendChild(all);
        }
        wrap.appendChild(head);
        const ul = el('ul', 'sugg-list');
        list.forEach(i => {
            const li = el('li', 'sugg-item');
            const info = el('div', 'item-info');
            info.appendChild(el('span', 'row-name', i.namn));
            info.appendChild(el('span', 'row-hint', i.text));
            li.appendChild(info);
            const add = el('button', 'add-sugg', '+');
            add.type = 'button';
            add.setAttribute('aria-label', 'Lägg till ' + i.namn);
            add.addEventListener('click', () => op({ type: 'add', namn: i.namn }));
            li.appendChild(add);
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        box.appendChild(wrap);
    };
    box.appendChild(el('h2', 'section-title', 'Förslag för ' + butik));
    group('Dags att köpa', s.due, { addAll: true });
    group('Sällanköp', s.rare);
    group('Annat du brukar köpa här', s.other, { collapsed: true });
}

function render() {
    const shopping = !!state.trip;
    document.body.classList.toggle('shopping', shopping);
    $('list-title').textContent = state.list.namn;
    const sub = [];
    if (shopping) {
        sub.push('Handlar på ' + (state.trip.butik || 'okänd butik') + ' sedan ' + state.trip.startad);
    } else {
        sub.push(state.list.butik || 'Ingen butik');
    }
    if (state.list.tillfallig) sub.push('tillfällig lista – ingen statistik');
    $('list-sub').textContent = sub.join(' · ');
    document.title = state.list.namn + ' – Handla';

    $('plan-bar').hidden = shopping;
    $('shop-bar').hidden = !shopping;
    $('settings-btn').hidden = shopping;
    $('add-name').placeholder = shopping ? 'Kom på något?' : 'Lägg till vara…';

    if (shopping) {
        const total = state.rows.length;
        const done = state.rows.filter(r => r.status === 'bockad').length;
        $('shop-progress').textContent = done + ' av ' + total + ' i korgen';
    }

    const undo = $('undo-btn');
    undo.hidden = shopping || !state.undo;
    if (state.undo) undo.textContent = '↶ Ångra senaste handlingen (' + state.undo.datum + ')';

    renderRows();
    renderSuggestions();
    renderNet();
    updateWakeLock();
}

// ============================================================
// Händelser
// ============================================================
$('add-form').addEventListener('submit', e => {
    e.preventDefault();
    const namn = capitalize($('add-name').value);
    if (!namn) return;
    op({ type: 'add', namn, antal: $('add-qty').value.trim() });
    $('add-name').value = '';
    $('add-qty').value = '';
    $('add-name').focus();
});

function openEdit(r) {
    editRow = r;
    $('edit-title').textContent = r.namn;
    $('edit-qty').value = r.antal;
    $('edit-comment').value = r.kommentar;
    $('edit-avd').value = r.avdelning || '';
    $('edit-overlay').hidden = false;
}
$('edit-form').addEventListener('submit', e => {
    e.preventDefault();
    op({ type: 'edit', row: editRow.id, antal: $('edit-qty').value.trim(), kommentar: $('edit-comment').value.trim(), avdelning: $('edit-avd').value.trim() });
    $('edit-overlay').hidden = true;
});
$('edit-remove').addEventListener('click', () => {
    const r = editRow;
    op({ type: 'remove', row: r.id });
    $('edit-overlay').hidden = true;
    toast('"' + r.namn + '" togs bort.', 'Ångra', () => op({ type: 'add', namn: r.namn, antal: r.antal }));
});

// ---- Börja handla ----
$('start-btn').addEventListener('click', () => {
    const sel = $('start-butik');
    sel.value = state.list.butik_id ? String(state.list.butik_id) : '';
    sel.dispatchEvent(new Event('change'));
    $('start-form').querySelector('.popup-msg').textContent = '';
    $('start-overlay').hidden = false;
});
$('start-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const msg = this.querySelector('.popup-msg');
    if (!(await ensureSynced())) return;
    msg.textContent = 'Startar…';
    const f = Object.fromEntries(new FormData(this));
    try {
        const res = await post({ action: 'start', lista: LIST_ID, butik: f.butik || '', ny_butik: f.ny_butik || '' });
        if (!res.data.success) { msg.textContent = res.data.error || 'Något gick fel.'; return; }
        if (res.data.butiker) updateButikSelects(res.data.butiker);
        $('start-overlay').hidden = true;
        await refresh();
    } catch (err) { msg.textContent = 'Ingen kontakt med servern – försök igen.'; }
});

// ---- Klar ----
$('finish-btn').addEventListener('click', () => {
    const done = state.rows.filter(r => r.status === 'bockad').length;
    const left = state.rows.length - done;
    $('finish-info').textContent = done + ' ' + (done === 1 ? 'vara' : 'varor') + ' i korgen.' +
        (left ? ' ' + left + ' ' + (left === 1 ? 'vara ligger' : 'varor ligger') + ' kvar på listan till nästa gång.' : '');
    $('finish-cost').value = state.trip.kostnad !== null ? String(state.trip.kostnad).replace('.', ',') : '';
    $('finish-form').querySelector('.popup-msg').textContent = '';
    $('finish-overlay').hidden = false;
    $('finish-cost').focus();
});
$('finish-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const msg = this.querySelector('.popup-msg');
    if (!(await ensureSynced())) { msg.textContent = 'Väntar på nät – försök igen om en stund.'; return; }
    msg.textContent = 'Sparar…';
    try {
        const res = await post({ action: 'finish', lista: LIST_ID, kostnad: $('finish-cost').value });
        if (!res.data.success) { msg.textContent = res.data.error || 'Något gick fel.'; return; }
        $('finish-overlay').hidden = true;
        await refresh();
        if (res.data.saved) toast('Handlingen är sparad (' + res.data.antal + ' varor).', 'Ångra', undoFinish);
        else toast('Inget var avbockat, så ingen handling sparades.');
    } catch (err) { msg.textContent = 'Ingen kontakt med servern – försök igen.'; }
});

// ---- Avbryt ----
$('cancel-btn').addEventListener('click', async () => {
    if (!confirm('Avbryta handlingen?\n\nAvbockningarna nollställs och ingenting sparas. Varorna ligger kvar på listan.')) return;
    if (!(await ensureSynced())) return;
    const res = await post({ action: 'cancel', lista: LIST_ID });
    if (res.data.success) await refresh(); else toast(res.data.error || 'Något gick fel.');
});

// ---- Ångra Klar ----
async function undoFinish() {
    if (!(await ensureSynced())) return;
    const res = await post({ action: 'undo_finish', lista: LIST_ID });
    if (res.data.success) { await refresh(); toast('Handlingen är öppnad igen.'); }
    else toast(res.data.error || 'Något gick fel.');
}
$('undo-btn').addEventListener('click', () => {
    if (confirm('Ångra handlingen ' + state.undo.datum + '?\n\nVarorna läggs tillbaka som avbockade och handlingen öppnas igen, så att du kan fortsätta eller trycka Klar på nytt.')) undoFinish();
});

// ---- Ändra / ta bort lista ----
function updateButikSelects(butiker) {
    document.querySelectorAll('.butik-select').forEach(sel => {
        const cur = sel.value;
        while (sel.options.length > 2) sel.remove(1);   // behåll "Ingen butik" och "+ Ny butik…"
        butiker.forEach(b => sel.insertBefore(new Option(b.namn, b.id), sel.options[sel.options.length - 1]));
        sel.value = cur === '__ny' ? '' : cur;
        sel.parentElement.querySelector('.ny-butik').value = '';
        sel.dispatchEvent(new Event('change'));
    });
}
$('settings-btn').addEventListener('click', () => {
    const f = $('settings-form');
    f.namn.value = state.list.namn;
    f.butik.value = state.list.butik_id ? String(state.list.butik_id) : '';
    f.butik.dispatchEvent(new Event('change'));
    f.tillfallig.checked = state.list.tillfallig;
    f.querySelector('.popup-msg').textContent = '';
    $('settings-overlay').hidden = false;
});
$('settings-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const msg = this.querySelector('.popup-msg');
    if (!(await ensureSynced())) return;
    const res = await post({
        action: 'update_list', lista: LIST_ID, namn: this.namn.value,
        butik: this.butik.value, ny_butik: this.ny_butik.value, tillfallig: this.tillfallig.checked ? 1 : 0
    });
    if (!res.data.success) { msg.textContent = res.data.error || 'Något gick fel.'; return; }
    updateButikSelects(res.data.butiker);
    $('settings-overlay').hidden = true;
    await refresh();
});
$('delete-list-btn').addEventListener('click', async () => {
    if (!confirm('Ta bort listan "' + state.list.namn + '"?\n\nVarorna på listan tas bort. Varuregistret, statistiken och kostnaderna finns kvar.')) return;
    if (!(await ensureSynced())) return;
    const res = await post({ action: 'delete_list', lista: LIST_ID });
    if (res.data.success) { try { localStorage.removeItem(QKEY); } catch (e) {} location.href = 'handla.php'; }
    else toast(res.data.error || 'Något gick fel.');
});

// ============================================================
// Start
// ============================================================
queue.forEach(applyLocal);   // ändringar som inte hann skickas förra gången
render();
flush();
setTimeout(poll, 3000);
</script>
</body>
</html>
