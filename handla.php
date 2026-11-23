<?php
/**
 * handla.php
 * -----------------------------------------------------------------------
 * Handlingslistor för MinSida. Kräver inloggning (samma som ll2.php).
 *
 * Vyer:
 *   handla.php              – alla listor, skapa ny lista
 *   handla.php?lista=ID     – en lista: lägga till varor, förslag, Börja handla
 *   handla.php?varor=1      – varuregister med statistik + butiker
 *   handla.php?kostnad=1    – kostnadsuppföljning
 *
 * Så fungerar det:
 *  - En lista återanvänds. Varje "Börja handla" → "Klar" blir ett
 *    handlingstillfälle med datum, butik, avbockade varor (i ordning) och
 *    kostnad (frivilligt).
 *  - Avbockade varor försvinner från listan när man trycker Klar. Det som
 *    inte bockades av (eller "fanns inte") ligger kvar till nästa gång.
 *  - Statistik (senast köpt, intervall, plockordning, butiker) bygger bara
 *    på tillfällen från listor som INTE är tillfälliga.
 *  - Livesynk: listan har ett versionsnummer; webbläsaren frågar med några
 *    sekunders mellanrum om det ändrats och hämtar då nytt läge.
 *  - Offline: ändringar läggs i en kö i webbläsaren och skickas när nätet
 *    är tillbaka.
 *
 * Tabeller: se handla.sql
 * -----------------------------------------------------------------------
 */

require __DIR__ . '/common.php';

const HANDLA_PAGE  = 'handla.php';
const SALLAN_DAGAR = 28;     // köps mer sällan än så → "sällanköp"
const SV_MAN_KORT  = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
const SV_MAN_LANG  = ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];

// ============================================================
// TEXTHJÄLP
// ============================================================

// Trimmar, slår ihop mellanslag, kortar och gör första bokstaven stor.
function cleanName(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    $s = mb_substr($s, 0, $max);
    return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

function cleanText(string $s, int $max): string {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, $max);
}

// "845,50 kr" → 845.5   ""/"–" → null
function parseCost($raw): ?float {
    $s = str_replace([' ', "\u{00A0}", 'kronor', 'kr', 'KR', 'Kr', ':-', '–', '-'], '', (string)$raw);
    if ($s === '') {
        return null;
    }
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s)) {
        throw new InvalidArgumentException('Kostnaden ska vara ett belopp, t.ex. 845 eller 845,50.');
    }
    return round((float)$s, 2);
}

function fmtKr(?float $x): string {
    if ($x === null) {
        return '–';
    }
    $dec = abs($x - round($x)) > 0.004 ? 2 : 0;
    return number_format($x, $dec, ',', ' ') . ' kr';
}

function svDate(?string $d, bool $time = false): string {
    if (!$d) {
        return '';
    }
    $ts = strtotime($d);
    $s = (int)date('j', $ts) . ' ' . SV_MAN_KORT[(int)date('n', $ts) - 1];
    if (date('Y', $ts) !== date('Y')) {
        $s .= ' ' . date('Y', $ts);
    }
    return $time ? $s . ' ' . date('H:i', $ts) : $s;
}

function ordinal(int $n): string {
    $a = in_array($n % 10, [1, 2], true) && !in_array($n % 100, [11, 12], true);
    return $n . ($a ? ':a' : ':e');
}

// 7 → "var 7:e dag", 14 → "var 2:a vecka", 60 → "var 2:a månad"
function intervalText(float $d): string {
    if ($d < 1.5) {
        return 'varje dag';
    }
    if ($d < 10.5) {
        return 'var ' . ordinal((int)round($d)) . ' dag';
    }
    if ($d < 56) {
        $w = (int)round($d / 7);
        return $w <= 1 ? 'varje vecka' : 'var ' . ordinal($w) . ' vecka';
    }
    $m = (int)round($d / 30.44);
    return $m <= 1 ? 'varje månad' : 'var ' . ordinal($m) . ' månad';
}

function agoText(float $days): string {
    $d = (int)floor($days);
    if ($d <= 0) {
        return 'i dag';
    }
    if ($d === 1) {
        return 'i går';
    }
    if ($d < 14) {
        return "för $d dagar sedan";
    }
    if ($d < 60) {
        return 'för ' . (int)round($d / 7) . ' veckor sedan';
    }
    return 'för ' . (int)round($d / 30.44) . ' månader sedan';
}

// 0.0–1.0 → var i butiken varan brukar plockas
function orderText(float $rel): string {
    $p = (int)round($rel * 100);
    if ($rel < 0.25) {
        return "tidigt ($p %)";
    }
    if ($rel < 0.75) {
        return "i mitten ($p %)";
    }
    return "sent ($p %)";
}

function nowMs(): int {
    return (int)round(microtime(true) * 1000);
}

// ============================================================
// DATABASHJÄLP
// ============================================================

function getList(int $id, int $uid): ?array {
    return dbOne(
        'SELECT l.*, b.Namn AS ButikNamn
         FROM HandlaListor l LEFT JOIN HandlaButiker b ON b.ID = l.ButikID
         WHERE l.ID = ? AND l.UserID = ?',
        'ii', [$id, $uid]
    );
}

function getActiveTrip(array $list): ?array {
    if (empty($list['AktivtTillfalleID'])) {
        return null;
    }
    return dbOne("SELECT * FROM HandlaTillfallen WHERE ID = ? AND Status = 'pagar'", 'i', [(int)$list['AktivtTillfalleID']]);
}

function bumpList(int $listId): void {
    dbRun('UPDATE HandlaListor SET Version = Version + 1, Andrad = ? WHERE ID = ?', 'si', [nowSql(), $listId]);
}

function findOrCreateVara(int $uid, string $namn): int {
    dbRun(
        'INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (?, ?, \'\', ?)
         ON DUPLICATE KEY UPDATE ID = LAST_INSERT_ID(ID)',
        'iss', [$uid, $namn, nowSql()]
    );
    return (int)mysqli_insert_id(getDb());
}

function findOrCreateButik(int $uid, string $namn): int {
    dbRun(
        'INSERT INTO HandlaButiker (UserID, Namn) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE ID = LAST_INSERT_ID(ID)',
        'is', [$uid, $namn]
    );
    return (int)mysqli_insert_id(getDb());
}

// Läser butiksvalet från ett formulär: butik = ID, '' (ingen) eller '__ny' + ny_butik.
function resolveButik(int $uid): ?int {
    $val = (string)($_POST['butik'] ?? '');
    if ($val === '__ny') {
        $namn = cleanName((string)($_POST['ny_butik'] ?? ''), 100);
        if ($namn === '') {
            throw new InvalidArgumentException('Skriv namnet på den nya butiken.');
        }
        return findOrCreateButik($uid, $namn);
    }
    $id = (int)$val;
    if ($id > 0 && dbOne('SELECT ID FROM HandlaButiker WHERE ID = ? AND UserID = ?', 'ii', [$id, $uid])) {
        return $id;
    }
    return null;
}

function allButiker(int $uid): array {
    return array_map(
        fn($r) => ['id' => (int)$r['ID'], 'namn' => $r['Namn']],
        dbAll('SELECT ID, Namn FROM HandlaButiker WHERE UserID = ? ORDER BY Namn', 'i', [$uid])
    );
}

// ============================================================
// STATISTIK (bara från tillfällen som inte är tillfälliga)
// ============================================================

/**
 * Per vara: [
 *   'count'      => antal köp,
 *   'last'       => 'Y-m-d' senast köpt,
 *   'interval'   => snitt dagar mellan köp (senaste ~10 köpdagarna) eller null,
 *   'stores'     => [butikID => antal köp], störst först,
 *   'storeOrder' => [butikID => snitt 0–1 av var den plockas]
 * ]
 */
function itemStats(int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    $rows = dbAll(
        "SELECT k.VaraID, t.ButikID, DATE(t.Avslutad) AS Dag, k.OrdningRel
         FROM HandlaKop k JOIN HandlaTillfallen t ON t.ID = k.TillfalleID
         WHERE t.UserID = ? AND t.Status = 'klar' AND t.Tillfallig = 0
         ORDER BY t.Avslutad, k.Ordning",
        'i', [$uid]
    );
    $s = [];
    foreach ($rows as $r) {
        $v = (int)$r['VaraID'];
        $b = (int)$r['ButikID'];
        $s[$v]['days'][$r['Dag']] = true;
        $s[$v]['count'] = ($s[$v]['count'] ?? 0) + 1;
        $s[$v]['stores'] = $s[$v]['stores'] ?? [];
        if ($b) {
            $s[$v]['stores'][$b] = ($s[$v]['stores'][$b] ?? 0) + 1;
            $s[$v]['order'][$b][] = (float)$r['OrdningRel'];
        }
    }
    foreach ($s as $v => $x) {
        $days = array_keys($x['days']);
        $recent = array_slice($days, -11);
        $n = count($recent);
        $orders = [];
        foreach ($x['order'] ?? [] as $b => $list) {
            $list = array_slice($list, -10);
            $orders[$b] = array_sum($list) / count($list);
        }
        arsort($x['stores']);
        $s[$v] = [
            'count'      => $x['count'],
            'last'       => end($days),
            'interval'   => $n >= 2 ? (strtotime(end($recent)) - strtotime($recent[0])) / 86400 / ($n - 1) : null,
            'stores'     => $x['stores'],
            'storeOrder' => $orders,
        ];
    }
    return $cache[$uid] = $s;
}

/**
 * Sorteringsnyckel (0–1) per vara för en butik: varans egen plockordning,
 * annars snittet för dess avdelning i butiken, annars null (okänd).
 */
function orderKeys(int $uid, ?int $butik): array {
    $stats = itemStats($uid);
    $varor = dbAll('SELECT ID, Avdelning FROM HandlaVaror WHERE UserID = ?', 'i', [$uid]);
    $avd = [];
    if ($butik) {
        foreach ($varor as $v) {
            $o = $stats[(int)$v['ID']]['storeOrder'][$butik] ?? null;
            if ($o !== null && $v['Avdelning'] !== '') {
                $avd[mb_strtolower($v['Avdelning'])][] = $o;
            }
        }
        foreach ($avd as $k => $list) {
            $avd[$k] = array_sum($list) / count($list);
        }
    }
    $keys = [];
    foreach ($varor as $v) {
        $id = (int)$v['ID'];
        $o = $butik ? ($stats[$id]['storeOrder'][$butik] ?? null) : null;
        if ($o === null && $v['Avdelning'] !== '') {
            $o = $avd[mb_strtolower($v['Avdelning'])] ?? null;
        }
        $keys[$id] = $o;
    }
    return $keys;
}

function itemHint(?array $st): string {
    if (!$st) {
        return 'Inte köpt än';
    }
    $p = ['senast ' . svDate($st['last'])];
    if ($st['interval'] !== null) {
        $p[] = 'brukar köpas ' . intervalText($st['interval']);
    }
    return implode(' · ', $p);
}

// Förslag för en lista i en butik: dags att köpa, sällanköp och övrigt.
function suggestions(int $uid, int $listId, int $butik): array {
    $stats = itemStats($uid);
    $onList = array_flip(array_map('intval', array_column(
        dbAll('SELECT VaraID FROM HandlaRader WHERE ListaID = ?', 'i', [$listId]), 'VaraID'
    )));
    $names = array_column(dbAll('SELECT ID, Namn FROM HandlaVaror WHERE UserID = ?', 'i', [$uid]), 'Namn', 'ID');
    $today = strtotime(date('Y-m-d'));
    $due = $rare = $other = [];

    foreach ($stats as $v => $st) {
        if (isset($onList[$v]) || empty($st['stores'][$butik]) || !isset($names[$v])) {
            continue;
        }
        $here  = $st['stores'][$butik];
        $total = array_sum($st['stores']);
        $isTop = array_key_first($st['stores']) === $butik;
        $since = ($today - strtotime($st['last'])) / 86400;
        $iv    = $st['interval'];
        $item  = ['namn' => $names[$v], 'score' => 0, 'text' => ''];

        if ($iv !== null && $iv > 0 && ($isTop || $here / $total >= 0.25)) {
            $item['score'] = $since / $iv;
            $item['text'] = 'brukar köpas ' . intervalText($iv) . ' · senast ' . agoText($since);
            if ($iv < SALLAN_DAGAR && $since >= $iv * 0.75) {
                $due[] = $item;
                continue;
            }
            if ($iv >= SALLAN_DAGAR && $since >= $iv * 0.85) {
                $rare[] = $item;
                continue;
            }
        }
        $item['score'] = $here;
        $item['text'] = $here . ' ' . ($here === 1 ? 'gång' : 'gånger') . ' här · senast ' . agoText($since);
        $other[] = $item;
    }
    $byScore = fn($a, $b) => $b['score'] <=> $a['score'];
    usort($due, $byScore);
    usort($rare, $byScore);
    usort($other, $byScore);
    $strip = fn($list) => array_map(fn($i) => ['namn' => $i['namn'], 'text' => $i['text']], $list);
    return ['due' => $strip($due), 'rare' => $strip($rare), 'other' => $strip(array_slice($other, 0, 40))];
}

// Allt webbläsaren behöver om en lista.
function listState(array $list, int $uid): array {
    $listId = (int)$list['ID'];
    $trip   = getActiveTrip($list);
    $butik  = $trip ? (int)$trip['ButikID'] : (int)$list['ButikID'];
    $stats  = itemStats($uid);
    $keys   = orderKeys($uid, $butik ?: null);

    $rows = dbAll(
        'SELECT r.ID, r.VaraID, v.Namn, v.Avdelning, r.Antal, r.Kommentar, r.Status, r.BockadMs
         FROM HandlaRader r JOIN HandlaVaror v ON v.ID = r.VaraID
         WHERE r.ListaID = ?',
        'i', [$listId]
    );
    usort($rows, function ($a, $b) use ($keys) {
        $ka = $keys[(int)$a['VaraID']] ?? null;
        $kb = $keys[(int)$b['VaraID']] ?? null;
        if (($ka === null) !== ($kb === null)) {
            return $ka === null ? 1 : -1;
        }
        if ($ka !== null && $ka != $kb) {
            return $ka <=> $kb;
        }
        if (($a['Avdelning'] === '') !== ($b['Avdelning'] === '')) {
            return $a['Avdelning'] === '' ? 1 : -1;
        }
        return strcmp(mb_strtolower($a['Avdelning'] . '|' . $a['Namn']), mb_strtolower($b['Avdelning'] . '|' . $b['Namn']));
    });

    $out = [];
    foreach ($rows as $i => $r) {
        $vid = (int)$r['VaraID'];
        $out[] = [
            'id'        => (int)$r['ID'],
            'namn'      => $r['Namn'],
            'avdelning' => $r['Avdelning'],
            'antal'     => $r['Antal'],
            'kommentar' => $r['Kommentar'],
            'status'    => $r['Status'],
            'ms'        => $r['BockadMs'] !== null ? (int)$r['BockadMs'] : null,
            'sort'      => $i,
            'hint'      => (int)$list['Tillfallig'] === 1 ? '' : itemHint($stats[$vid] ?? null),
        ];
    }

    $undo = null;
    if (!$trip) {
        $last = dbOne(
            "SELECT ID, Avslutad FROM HandlaTillfallen WHERE ListaID = ? AND Status = 'klar' ORDER BY Avslutad DESC, ID DESC LIMIT 1",
            'i', [$listId]
        );
        if ($last) {
            $undo = ['id' => (int)$last['ID'], 'datum' => svDate($last['Avslutad'], true)];
        }
    }

    $sugg = null;
    if (!$trip && (int)$list['Tillfallig'] === 0 && $butik) {
        $sugg = suggestions($uid, $listId, $butik);
    }

    return [
        'version' => (int)$list['Version'],
        'list' => [
            'id'         => $listId,
            'namn'       => $list['Namn'],
            'butik_id'   => $list['ButikID'] !== null ? (int)$list['ButikID'] : null,
            'butik'      => $list['ButikNamn'] ?? '',
            'tillfallig' => (int)$list['Tillfallig'] === 1,
        ],
        'trip' => $trip ? [
            'id'       => (int)$trip['ID'],
            'butik_id' => $trip['ButikID'] !== null ? (int)$trip['ButikID'] : null,
            'butik'    => $trip['ButikNamn'],
            'kostnad'  => $trip['Kostnad'] !== null ? (float)$trip['Kostnad'] : null,
            'startad'  => svDate($trip['Startad'], true),
        ] : null,
        'rows'        => $out,
        'undo'        => $undo,
        'suggestions' => $sugg,
    ];
}

// En ändring på en lista (från kön i webbläsaren).
function applyOp(array $o, array $list, int $uid, ?array $trip): array {
    $listId = (int)$list['ID'];
    $type = (string)($o['type'] ?? '');

    if ($type === 'add') {
        $namn = cleanName((string)($o['namn'] ?? ''), 150);
        if ($namn === '') {
            return ['success' => false, 'error' => 'Skriv namnet på varan.'];
        }
        $varaId = findOrCreateVara($uid, $namn);
        $antal = cleanText((string)($o['antal'] ?? ''), 50);
        $row = dbOne('SELECT ID FROM HandlaRader WHERE ListaID = ? AND VaraID = ?', 'ii', [$listId, $varaId]);
        if ($row) {
            $rowId = (int)$row['ID'];
            if ($antal !== '') {
                dbRun('UPDATE HandlaRader SET Antal = ? WHERE ID = ?', 'si', [$antal, $rowId]);
            }
        } else {
            dbRun(
                "INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, Tillagd) VALUES (?, ?, ?, '', 'kvar', ?)",
                'iiss', [$listId, $varaId, $antal, nowSql()]
            );
            $rowId = (int)mysqli_insert_id(getDb());
        }
        bumpList($listId);
        return ['success' => true, 'row_id' => $rowId];
    }

    $rowId = (int)($o['row'] ?? 0);
    $row = dbOne('SELECT ID, VaraID FROM HandlaRader WHERE ID = ? AND ListaID = ?', 'ii', [$rowId, $listId]);
    if (!$row) {
        return ['success' => true, 'ignored' => true];   // borttagen på en annan enhet
    }

    switch ($type) {
        case 'remove':
            dbRun('DELETE FROM HandlaRader WHERE ID = ?', 'i', [$rowId]);
            break;
        case 'edit':
            dbRun(
                'UPDATE HandlaRader SET Antal = ?, Kommentar = ? WHERE ID = ?',
                'ssi', [cleanText((string)($o['antal'] ?? ''), 50), cleanText((string)($o['kommentar'] ?? ''), 255), $rowId]
            );
            if (array_key_exists('avdelning', $o)) {
                dbRun(
                    'UPDATE HandlaVaror SET Avdelning = ? WHERE ID = ? AND UserID = ?',
                    'sii', [cleanName((string)$o['avdelning'], 100), (int)$row['VaraID'], $uid]
                );
            }
            break;
        case 'check':
            if (!$trip) {
                return ['success' => true, 'ignored' => true];
            }
            $ms = (int)($o['ms'] ?? 0);
            if ($ms <= 0) {
                $ms = nowMs();
            }
            dbRun("UPDATE HandlaRader SET Status = 'bockad', BockadMs = ? WHERE ID = ?", 'ii', [$ms, $rowId]);
            break;
        case 'missing':
            if (!$trip) {
                return ['success' => true, 'ignored' => true];
            }
            dbRun("UPDATE HandlaRader SET Status = 'saknas', BockadMs = NULL WHERE ID = ?", 'i', [$rowId]);
            break;
        case 'uncheck':
        case 'unmissing':
            dbRun("UPDATE HandlaRader SET Status = 'kvar', BockadMs = NULL WHERE ID = ?", 'i', [$rowId]);
            break;
        default:
            return ['success' => false, 'error' => 'Okänd ändring.'];
    }
    bumpList($listId);
    return ['success' => true];
}

function isDuplicateError(Throwable $e): bool {
    return $e instanceof mysqli_sql_exception && $e->getCode() === 1062;
}

// ============================================================
// INLOGGNING – utan inloggning visas ingenting
// ============================================================
if (!isLoggedIn()) {
    if (isset($_POST['action'])) {
        jsonResponse(['success' => false, 'error' => 'Du är inte inloggad längre. Ladda om sidan och logga in.'], 401);
    }
    renderLoginPage('Handla');
    exit;
}

$uid = currentUserId();

// ============================================================
// AJAX
// ============================================================
if (isset($_POST['action'])) {
    startJsonApi();
    $db = getDb();
    $action = (string)$_POST['action'];

    try {
        // ---------------- Listor ----------------
        if ($action === 'create_list') {
            $namn = cleanName((string)($_POST['namn'] ?? ''), 150);
            if ($namn === '') {
                jsonResponse(['success' => false, 'error' => 'Ge listan ett namn.']);
            }
            $butik = resolveButik($uid);
            $tillf = !empty($_POST['tillfallig']) ? 1 : 0;
            $now = nowSql();
            dbRun(
                'INSERT INTO HandlaListor (UserID, Namn, ButikID, Tillfallig, Version, Skapad, Andrad) VALUES (?, ?, ?, ?, 1, ?, ?)',
                'isiiss', [$uid, $namn, $butik, $tillf, $now, $now]
            );
            jsonResponse(['success' => true, 'id' => (int)mysqli_insert_id($db)]);
        }

        // Övriga list-åtgärder kräver en lista man äger.
        $listActions = ['state', 'version', 'op', 'start', 'finish', 'cancel', 'undo_finish', 'update_list', 'delete_list'];
        if (in_array($action, $listActions, true)) {
            $list = getList((int)($_POST['lista'] ?? 0), $uid);
            if (!$list) {
                jsonResponse(['success' => false, 'gone' => true, 'error' => 'Listan finns inte längre.']);
            }
            $listId = (int)$list['ID'];
            $trip = getActiveTrip($list);

            if ($action === 'version') {
                jsonResponse(['success' => true, 'version' => (int)$list['Version']]);
            }

            if ($action === 'state') {
                jsonResponse(['success' => true, 'state' => listState($list, $uid)]);
            }

            if ($action === 'op') {
                $o = json_decode((string)($_POST['op'] ?? ''), true);
                if (!is_array($o)) {
                    jsonResponse(['success' => false, 'error' => 'Ogiltig ändring.']);
                }
                jsonResponse(applyOp($o, $list, $uid, $trip));
            }

            if ($action === 'start') {
                if ($trip) {
                    jsonResponse(['success' => true, 'already' => true]);
                }
                $butik = resolveButik($uid);
                $butikNamn = $butik ? (string)dbOne('SELECT Namn FROM HandlaButiker WHERE ID = ?', 'i', [$butik])['Namn'] : '';
                mysqli_begin_transaction($db);
                dbRun(
                    "INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad)
                     VALUES (?, ?, ?, ?, ?, ?, 'pagar', ?)",
                    'iisisis', [$uid, $listId, $list['Namn'], $butik, $butikNamn, (int)$list['Tillfallig'], nowSql()]
                );
                $tripId = (int)mysqli_insert_id($db);
                dbRun('UPDATE HandlaListor SET AktivtTillfalleID = ? WHERE ID = ?', 'ii', [$tripId, $listId]);
                dbRun("UPDATE HandlaRader SET Status = 'kvar', BockadMs = NULL WHERE ListaID = ?", 'i', [$listId]);
                bumpList($listId);
                mysqli_commit($db);
                jsonResponse(['success' => true, 'butiker' => allButiker($uid)]);
            }

            if ($action === 'finish') {
                if (!$trip) {
                    jsonResponse(['success' => false, 'error' => 'Det finns ingen pågående handling (avslutad på en annan enhet?).']);
                }
                $kostnad = parseCost($_POST['kostnad'] ?? '');
                mysqli_begin_transaction($db);
                $checked = dbAll(
                    "SELECT VaraID, Antal, Kommentar FROM HandlaRader WHERE ListaID = ? AND Status = 'bockad' ORDER BY BockadMs, ID",
                    'i', [$listId]
                );
                $n = count($checked);
                if ($n === 0 && $kostnad === null) {
                    // Inget köpt och ingen kostnad: inget att spara.
                    dbRun('DELETE FROM HandlaTillfallen WHERE ID = ?', 'i', [(int)$trip['ID']]);
                } else {
                    foreach ($checked as $i => $c) {
                        $rel = $n > 1 ? $i / ($n - 1) : 0.5;
                        dbRun(
                            'INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES (?, ?, ?, ?, ?, ?)',
                            'iissid', [(int)$trip['ID'], (int)$c['VaraID'], $c['Antal'], $c['Kommentar'], $i + 1, $rel]
                        );
                    }
                    dbRun(
                        "UPDATE HandlaTillfallen SET Status = 'klar', Avslutad = ?, Kostnad = ? WHERE ID = ?",
                        'sdi', [nowSql(), $kostnad, (int)$trip['ID']]
                    );
                }
                dbRun("DELETE FROM HandlaRader WHERE ListaID = ? AND Status = 'bockad'", 'i', [$listId]);
                dbRun("UPDATE HandlaRader SET Status = 'kvar', BockadMs = NULL WHERE ListaID = ?", 'i', [$listId]);
                dbRun('UPDATE HandlaListor SET AktivtTillfalleID = NULL WHERE ID = ?', 'i', [$listId]);
                bumpList($listId);
                mysqli_commit($db);
                jsonResponse(['success' => true, 'antal' => $n, 'saved' => $n > 0 || $kostnad !== null]);
            }

            if ($action === 'cancel') {
                mysqli_begin_transaction($db);
                if ($trip) {
                    dbRun('DELETE FROM HandlaTillfallen WHERE ID = ?', 'i', [(int)$trip['ID']]);
                }
                dbRun('UPDATE HandlaListor SET AktivtTillfalleID = NULL WHERE ID = ?', 'i', [$listId]);
                dbRun("UPDATE HandlaRader SET Status = 'kvar', BockadMs = NULL WHERE ListaID = ?", 'i', [$listId]);
                bumpList($listId);
                mysqli_commit($db);
                jsonResponse(['success' => true]);
            }

            if ($action === 'undo_finish') {
                if ($trip) {
                    jsonResponse(['success' => false, 'error' => 'Avsluta eller avbryt den pågående handlingen först.']);
                }
                $last = dbOne(
                    "SELECT * FROM HandlaTillfallen WHERE ListaID = ? AND UserID = ? AND Status = 'klar' ORDER BY Avslutad DESC, ID DESC LIMIT 1",
                    'ii', [$listId, $uid]
                );
                if (!$last) {
                    jsonResponse(['success' => false, 'error' => 'Det finns ingen handling att ångra.']);
                }
                $tripId = (int)$last['ID'];
                mysqli_begin_transaction($db);
                $kop = dbAll('SELECT VaraID, Antal, Kommentar, Ordning FROM HandlaKop WHERE TillfalleID = ? ORDER BY Ordning', 'i', [$tripId]);
                foreach ($kop as $k) {
                    dbRun(
                        "INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd)
                         VALUES (?, ?, ?, ?, 'bockad', ?, ?)
                         ON DUPLICATE KEY UPDATE Status = 'bockad', BockadMs = VALUES(BockadMs)",
                        'iissis', [$listId, (int)$k['VaraID'], $k['Antal'], $k['Kommentar'], (int)$k['Ordning'], nowSql()]
                    );
                }
                dbRun('DELETE FROM HandlaKop WHERE TillfalleID = ?', 'i', [$tripId]);
                dbRun("UPDATE HandlaTillfallen SET Status = 'pagar', Avslutad = NULL WHERE ID = ?", 'i', [$tripId]);
                dbRun('UPDATE HandlaListor SET AktivtTillfalleID = ? WHERE ID = ?', 'ii', [$tripId, $listId]);
                bumpList($listId);
                mysqli_commit($db);
                jsonResponse(['success' => true]);
            }

            if ($action === 'update_list') {
                $namn = cleanName((string)($_POST['namn'] ?? ''), 150);
                if ($namn === '') {
                    jsonResponse(['success' => false, 'error' => 'Ge listan ett namn.']);
                }
                $butik = resolveButik($uid);
                $tillf = !empty($_POST['tillfallig']) ? 1 : 0;
                dbRun(
                    'UPDATE HandlaListor SET Namn = ?, ButikID = ?, Tillfallig = ? WHERE ID = ?',
                    'siii', [$namn, $butik, $tillf, $listId]
                );
                bumpList($listId);
                jsonResponse(['success' => true, 'butiker' => allButiker($uid)]);
            }

            if ($action === 'delete_list') {
                mysqli_begin_transaction($db);
                if ($trip) {
                    dbRun('DELETE FROM HandlaTillfallen WHERE ID = ?', 'i', [(int)$trip['ID']]);
                }
                dbRun('DELETE FROM HandlaListor WHERE ID = ?', 'i', [$listId]);
                mysqli_commit($db);
                jsonResponse(['success' => true]);
            }
        }

        // ---------------- Varor ----------------
        if (in_array($action, ['vara_update', 'vara_merge', 'vara_delete'], true)) {
            $varaId = (int)($_POST['vara'] ?? 0);
            if (!dbOne('SELECT ID FROM HandlaVaror WHERE ID = ? AND UserID = ?', 'ii', [$varaId, $uid])) {
                jsonResponse(['success' => false, 'error' => 'Varan finns inte.']);
            }

            if ($action === 'vara_update') {
                $namn = cleanName((string)($_POST['namn'] ?? ''), 150);
                if ($namn === '') {
                    jsonResponse(['success' => false, 'error' => 'Varan måste ha ett namn.']);
                }
                try {
                    dbRun(
                        'UPDATE HandlaVaror SET Namn = ?, Avdelning = ? WHERE ID = ?',
                        'ssi', [$namn, cleanName((string)($_POST['avdelning'] ?? ''), 100), $varaId]
                    );
                } catch (Throwable $e) {
                    if (isDuplicateError($e)) {
                        jsonResponse(['success' => false, 'error' => "Det finns redan en vara som heter \"$namn\". Använd \"Slå ihop\" i stället."]);
                    }
                    throw $e;
                }
                jsonResponse(['success' => true]);
            }

            if ($action === 'vara_merge') {
                $target = (int)($_POST['target'] ?? 0);
                if ($target === $varaId || !dbOne('SELECT ID FROM HandlaVaror WHERE ID = ? AND UserID = ?', 'ii', [$target, $uid])) {
                    jsonResponse(['success' => false, 'error' => 'Välj en annan vara att slå ihop med.']);
                }
                mysqli_begin_transaction($db);
                // Ligger båda på samma lista: behåll målvarans rad.
                dbRun(
                    'DELETE r1 FROM HandlaRader r1
                     JOIN HandlaRader r2 ON r2.ListaID = r1.ListaID AND r2.VaraID = ?
                     WHERE r1.VaraID = ?',
                    'ii', [$target, $varaId]
                );
                dbRun('UPDATE HandlaRader SET VaraID = ? WHERE VaraID = ?', 'ii', [$target, $varaId]);
                dbRun('UPDATE HandlaKop SET VaraID = ? WHERE VaraID = ?', 'ii', [$target, $varaId]);
                dbRun('DELETE FROM HandlaVaror WHERE ID = ?', 'i', [$varaId]);
                mysqli_commit($db);
                jsonResponse(['success' => true]);
            }

            if ($action === 'vara_delete') {
                dbRun('DELETE FROM HandlaVaror WHERE ID = ?', 'i', [$varaId]);
                jsonResponse(['success' => true]);
            }
        }

        // ---------------- Butiker ----------------
        if ($action === 'butik_rename' || $action === 'butik_delete') {
            $butikId = (int)($_POST['butik_id'] ?? 0);
            if (!dbOne('SELECT ID FROM HandlaButiker WHERE ID = ? AND UserID = ?', 'ii', [$butikId, $uid])) {
                jsonResponse(['success' => false, 'error' => 'Butiken finns inte.']);
            }
            if ($action === 'butik_rename') {
                $namn = cleanName((string)($_POST['namn'] ?? ''), 100);
                if ($namn === '') {
                    jsonResponse(['success' => false, 'error' => 'Butiken måste ha ett namn.']);
                }
                try {
                    dbRun('UPDATE HandlaButiker SET Namn = ? WHERE ID = ?', 'si', [$namn, $butikId]);
                } catch (Throwable $e) {
                    if (isDuplicateError($e)) {
                        jsonResponse(['success' => false, 'error' => "Det finns redan en butik som heter \"$namn\"."]);
                    }
                    throw $e;
                }
                dbRun('UPDATE HandlaTillfallen SET ButikNamn = ? WHERE ButikID = ?', 'si', [$namn, $butikId]);
            } else {
                dbRun('DELETE FROM HandlaButiker WHERE ID = ?', 'i', [$butikId]);
            }
            jsonResponse(['success' => true]);
        }

        // ---------------- Handlingstillfällen ----------------
        if (in_array($action, ['trip_detail', 'trip_cost', 'trip_delete'], true)) {
            $tripId = (int)($_POST['trip'] ?? 0);
            $t = dbOne("SELECT * FROM HandlaTillfallen WHERE ID = ? AND UserID = ? AND Status = 'klar'", 'ii', [$tripId, $uid]);
            if (!$t) {
                jsonResponse(['success' => false, 'error' => 'Handlingen finns inte.']);
            }
            if ($action === 'trip_detail') {
                $items = dbAll(
                    'SELECT v.Namn, k.Antal, k.Kommentar FROM HandlaKop k JOIN HandlaVaror v ON v.ID = k.VaraID
                     WHERE k.TillfalleID = ? ORDER BY k.Ordning',
                    'i', [$tripId]
                );
                jsonResponse(['success' => true, 'trip' => [
                    'datum'      => svDate($t['Avslutad'], true),
                    'lista'      => $t['ListNamn'],
                    'butik'      => $t['ButikNamn'],
                    'tillfallig' => (int)$t['Tillfallig'] === 1,
                    'kostnad'    => $t['Kostnad'] !== null ? number_format((float)$t['Kostnad'], 2, ',', '') : '',
                    'items'      => array_map(fn($i) => ['namn' => $i['Namn'], 'antal' => $i['Antal'], 'kommentar' => $i['Kommentar']], $items),
                ]]);
            }
            if ($action === 'trip_cost') {
                dbRun('UPDATE HandlaTillfallen SET Kostnad = ? WHERE ID = ?', 'di', [parseCost($_POST['kostnad'] ?? ''), $tripId]);
                jsonResponse(['success' => true]);
            }
            if ($action === 'trip_delete') {
                dbRun('DELETE FROM HandlaTillfallen WHERE ID = ?', 'i', [$tripId]);
                jsonResponse(['success' => true]);
            }
        }
    } catch (InvalidArgumentException $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()]);
    }

    jsonResponse(['success' => false, 'error' => 'Okänd åtgärd.'], 400);
}

// ============================================================
// GEMENSAMT FÖR VYERNA
// ============================================================
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$butiker = allButiker($uid);

function pageHead(string $title): void { ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f4f2">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="minsida.css">
</head>
<?php }

function handlaSubNav(string $current): void {
    $links = ['listor' => ['Listor', HANDLA_PAGE], 'varor' => ['Varor', HANDLA_PAGE . '?varor=1'], 'kostnad' => ['Kostnader', HANDLA_PAGE . '?kostnad=1']];
    echo '<div class="sub-nav">';
    foreach ($links as $key => [$label, $url]) {
        echo '<a href="' . h($url) . '"' . ($key === $current ? ' class="active"' : '') . '>' . h($label) . '</a>';
    }
    echo '</div>';
}

// Butiksval med "Ny butik…"
function butikSelect(string $id, array $butiker, ?int $selected, bool $allowNone = true): void { ?>
    <select id="<?= h($id) ?>" name="butik" class="butik-select">
        <?php if ($allowNone): ?><option value="">Ingen butik</option><?php endif; ?>
        <?php foreach ($butiker as $b): ?>
            <option value="<?= $b['id'] ?>"<?= $selected === $b['id'] ? ' selected' : '' ?>><?= h($b['namn']) ?></option>
        <?php endforeach; ?>
        <option value="__ny">+ Ny butik…</option>
    </select>
    <input type="text" name="ny_butik" class="ny-butik" placeholder="Namn på ny butik" hidden maxlength="100">
<?php }

// Gemensamt skript: post() + "Ny butik…"-fält
function commonScript(): void { ?>
<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
async function post(fields) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    const r = await fetch('handla.php', { method: 'POST', body: fd, cache: 'no-store' });
    let data;
    try { data = await r.json(); } catch (e) { data = { success: false, error: 'Oväntat svar från servern (' + r.status + ').' }; }
    return { status: r.status, data };
}
function wireButikSelect(select) {
    const input = select.parentElement.querySelector('.ny-butik');
    const sync = () => {
        input.hidden = select.value !== '__ny';
        if (!input.hidden) input.focus();
    };
    select.addEventListener('change', sync);
    sync();
}
document.querySelectorAll('.butik-select').forEach(wireButikSelect);
document.querySelectorAll('.popup-overlay').forEach(o => o.addEventListener('click', e => { if (e.target === o) o.hidden = true; }));
document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => { document.getElementById(b.dataset.close).hidden = true; }));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.popup-overlay').forEach(o => o.hidden = true);
});
</script>
<?php }

// ============================================================
// VY: EN LISTA
// ============================================================
if (isset($_GET['lista'])) {
    $list = getList((int)$_GET['lista'], $uid);
    if (!$list) {
        pageHead('Listan finns inte – Handla');
        echo '<body><div class="page">';
        renderNav(HANDLA_PAGE);
        echo '<h1>Listan finns inte</h1><p><a href="' . HANDLA_PAGE . '">Till alla listor</a></p></div></body></html>';
        exit;
    }
    $state = listState($list, $uid);
    $varorNamn = array_column(dbAll('SELECT Namn FROM HandlaVaror WHERE UserID = ? ORDER BY Namn', 'i', [$uid]), 'Namn');
    $avdelningar = array_column(dbAll("SELECT DISTINCT Avdelning FROM HandlaVaror WHERE UserID = ? AND Avdelning <> '' ORDER BY Avdelning", 'i', [$uid]), 'Avdelning');
    pageHead($list['Namn'] . ' – Handla');
    require __DIR__ . '/handla-lista.inc.php';
    exit;
}

// ============================================================
// VY: VARUREGISTER
// ============================================================
if (isset($_GET['varor'])) {
    $stats = itemStats($uid);
    $butikNamn = array_column($butiker, 'namn', 'id');
    $varor = dbAll('SELECT ID, Namn, Avdelning FROM HandlaVaror WHERE UserID = ? ORDER BY Namn', 'i', [$uid]);
    $onLists = [];
    foreach (dbAll(
        'SELECT r.VaraID, l.Namn FROM HandlaRader r JOIN HandlaListor l ON l.ID = r.ListaID WHERE l.UserID = ? ORDER BY l.Namn',
        'i', [$uid]
    ) as $r) {
        $onLists[(int)$r['VaraID']][] = $r['Namn'];
    }
    $avdelningar = array_values(array_unique(array_filter(array_column($varor, 'Avdelning'))));
    sort($avdelningar);

    $rows = [];
    foreach ($varor as $v) {
        $id = (int)$v['ID'];
        $st = $stats[$id] ?? null;
        $perStore = [];
        foreach ($st['stores'] ?? [] as $b => $cnt) {
            $perStore[] = [
                'butik' => $butikNamn[$b] ?? 'Borttagen butik',
                'antal' => $cnt,
                'ordning' => isset($st['storeOrder'][$b]) ? orderText($st['storeOrder'][$b]) : '',
            ];
        }
        $rows[] = [
            'id'        => $id,
            'namn'      => $v['Namn'],
            'avdelning' => $v['Avdelning'],
            'antal'     => $st['count'] ?? 0,
            'senast'    => $st ? svDate($st['last']) : '',
            'senastSort'=> $st['last'] ?? '',
            'intervall' => ($st && $st['interval'] !== null) ? intervalText($st['interval']) : '',
            'intervallSort' => ($st && $st['interval'] !== null) ? $st['interval'] : null,
            'butiker'   => $perStore,
            'listor'    => $onLists[$id] ?? [],
        ];
    }
    pageHead('Varor – Handla');
    ?>
<body>
<div class="page">
    <?php renderNav(HANDLA_PAGE); handlaSubNav('varor'); ?>
    <h1>Varor</h1>
    <p class="info-text">Statistiken bygger på handlingar från listor som inte är tillfälliga. Klicka på en vara för att ändra, slå ihop eller ta bort den.</p>

    <div class="top-row">
        <div class="search-form"><input type="search" id="vara-filter" placeholder="Filtrera varor…"></div>
        <span class="muted" id="vara-count"></span>
    </div>

    <div class="table-wrap">
    <table class="inventory-table" id="vara-table">
        <thead><tr>
            <th data-sort="namn">Vara</th>
            <th data-sort="avdelning">Avdelning</th>
            <th data-sort="antal">Köpt</th>
            <th data-sort="senastSort">Senast</th>
            <th data-sort="intervallSort">Brukar köpas</th>
            <th>Butiker</th>
        </tr></thead>
        <tbody></tbody>
    </table>
    </div>

    <h2 class="section-title">Butiker</h2>
    <?php if (!$butiker): ?>
        <p class="muted">Inga butiker än. De skapas när du väljer "Ny butik…" på en lista.</p>
    <?php endif; ?>
    <div class="butik-list">
        <?php foreach ($butiker as $b): ?>
            <form class="butik-row" data-id="<?= $b['id'] ?>">
                <input type="text" value="<?= h($b['namn']) ?>" maxlength="100" aria-label="Butikens namn">
                <button type="submit" class="secondary-btn">Spara</button>
                <button type="button" class="danger-btn butik-del">Ta bort</button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div id="vara-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="vara-title">
        <button type="button" class="popup-close" data-close="vara-overlay" aria-label="Stäng">&times;</button>
        <h2 id="vara-title">Vara</h2>
        <form id="vara-form">
            <label>Namn <input type="text" id="vara-namn" maxlength="150" required></label>
            <label>Avdelning <input type="text" id="vara-avd" list="avd-options" maxlength="100" placeholder="t.ex. Frukt & grönt"></label>
            <button type="submit" class="primary-btn">Spara</button>
        </form>
        <div id="vara-stats" class="vara-stats"></div>
        <div class="merge-box">
            <label>Slå ihop med (den här varan försvinner, statistiken flyttas):
                <select id="vara-merge"></select>
            </label>
            <button type="button" id="vara-merge-btn" class="secondary-btn">Slå ihop</button>
        </div>
        <button type="button" id="vara-delete" class="danger-btn">Ta bort varan</button>
        <p class="popup-msg" id="vara-msg"></p>
    </div>
</div>
<datalist id="avd-options">
    <?php foreach ($avdelningar as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?>
</datalist>

<?php commonScript(); ?>
<script>
const VAROR = <?= json_encode($rows, $jsonFlags) ?>;
let sortKey = 'namn', sortDir = 1, current = null;
const tbody = document.querySelector('#vara-table tbody');
const filterEl = document.getElementById('vara-filter');

function cell(text, cls) {
    const td = document.createElement('td');
    td.textContent = text;
    if (cls) td.className = cls;
    return td;
}
function renderTable() {
    const q = filterEl.value.trim().toLowerCase();
    const list = VAROR.filter(v => !q || (v.namn + ' ' + v.avdelning).toLowerCase().includes(q));
    list.sort((a, b) => {
        let x = a[sortKey], y = b[sortKey];
        if (x === null || x === '') return 1;
        if (y === null || y === '') return -1;
        return (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'sv')) * sortDir;
    });
    tbody.innerHTML = '';
    if (!list.length) {
        const tr = document.createElement('tr');
        const td = cell(VAROR.length ? 'Inga varor matchar.' : 'Inga varor än. De skapas när du lägger till dem på en lista.', 'empty-row');
        td.colSpan = 6;
        tr.appendChild(td);
        tbody.appendChild(tr);
    }
    list.forEach(v => {
        const tr = document.createElement('tr');
        tr.className = 'clickable';
        tr.appendChild(cell(v.namn));
        tr.appendChild(cell(v.avdelning));
        tr.appendChild(cell(v.antal ? v.antal + ' ggr' : '–'));
        tr.appendChild(cell(v.senast || '–'));
        tr.appendChild(cell(v.intervall || '–'));
        tr.appendChild(cell(v.butiker.map(b => b.butik + ' ' + b.antal).join(', ') || '–'));
        tr.addEventListener('click', () => openVara(v));
        tbody.appendChild(tr);
    });
    document.getElementById('vara-count').textContent = list.length + ' av ' + VAROR.length + ' varor';
}
document.querySelectorAll('#vara-table th[data-sort]').forEach(th => th.addEventListener('click', () => {
    if (sortKey === th.dataset.sort) sortDir = -sortDir; else { sortKey = th.dataset.sort; sortDir = 1; }
    renderTable();
}));
filterEl.addEventListener('input', renderTable);

function openVara(v) {
    current = v;
    document.getElementById('vara-title').textContent = v.namn;
    document.getElementById('vara-namn').value = v.namn;
    document.getElementById('vara-avd').value = v.avdelning;
    document.getElementById('vara-msg').textContent = '';
    const st = document.getElementById('vara-stats');
    st.innerHTML = '';
    const lines = [];
    lines.push(v.antal ? 'Köpt ' + v.antal + ' gånger, senast ' + v.senast + '.' : 'Inte köpt än (eller bara från tillfälliga listor).');
    if (v.intervall) lines.push('Brukar köpas ' + v.intervall + '.');
    if (v.listor.length) lines.push('Ligger på: ' + v.listor.join(', ') + '.');
    lines.forEach(t => { const p = document.createElement('p'); p.textContent = t; st.appendChild(p); });
    if (v.butiker.length) {
        const ul = document.createElement('ul');
        v.butiker.forEach(b => {
            const li = document.createElement('li');
            li.textContent = b.butik + ': ' + b.antal + ' ggr' + (b.ordning ? ', plockas ' + b.ordning : '');
            ul.appendChild(li);
        });
        st.appendChild(ul);
    }
    const sel = document.getElementById('vara-merge');
    sel.innerHTML = '<option value="">Välj vara…</option>';
    VAROR.filter(x => x.id !== v.id).forEach(x => {
        const o = document.createElement('option');
        o.value = x.id;
        o.textContent = x.namn;
        sel.appendChild(o);
    });
    document.getElementById('vara-overlay').hidden = false;
}

async function varaAction(fields) {
    const msg = document.getElementById('vara-msg');
    msg.textContent = 'Sparar…';
    try {
        const res = await post(Object.assign({ vara: current.id }, fields));
        if (res.data.success) location.reload();
        else msg.textContent = res.data.error || 'Något gick fel.';
    } catch (e) { msg.textContent = 'Nätverksfel: ' + e.message; }
}
document.getElementById('vara-form').addEventListener('submit', e => {
    e.preventDefault();
    varaAction({ action: 'vara_update', namn: document.getElementById('vara-namn').value, avdelning: document.getElementById('vara-avd').value });
});
document.getElementById('vara-merge-btn').addEventListener('click', () => {
    const sel = document.getElementById('vara-merge');
    if (!sel.value) { document.getElementById('vara-msg').textContent = 'Välj vilken vara den ska slås ihop med.'; return; }
    const target = sel.options[sel.selectedIndex].textContent;
    if (confirm('Slå ihop "' + current.namn + '" med "' + target + '"?\n\n"' + current.namn + '" försvinner och all statistik flyttas till "' + target + '".')) {
        varaAction({ action: 'vara_merge', target: sel.value });
    }
});
document.getElementById('vara-delete').addEventListener('click', () => {
    if (confirm('Ta bort "' + current.namn + '"?\n\nVaran försvinner från alla listor och all statistik för den raderas. Det går inte att ångra.')) {
        varaAction({ action: 'vara_delete' });
    }
});

document.querySelectorAll('.butik-row').forEach(form => {
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const res = await post({ action: 'butik_rename', butik_id: form.dataset.id, namn: form.querySelector('input').value });
        if (res.data.success) location.reload(); else alert(res.data.error || 'Något gick fel.');
    });
    form.querySelector('.butik-del').addEventListener('click', async () => {
        const namn = form.querySelector('input').value;
        if (!confirm('Ta bort butiken "' + namn + '"?\n\nListor som hör till den blir utan butik, och plockordningen för butiken försvinner. Kostnaderna finns kvar.')) return;
        const res = await post({ action: 'butik_delete', butik_id: form.dataset.id });
        if (res.data.success) location.reload(); else alert(res.data.error || 'Något gick fel.');
    });
});

renderTable();
</script>
</body>
</html>
<?php
    exit;
}

// ============================================================
// VY: KOSTNADER
// ============================================================
if (isset($_GET['kostnad'])) {
    $grupp = in_array($_GET['grupp'] ?? '', ['tillfalle', 'vecka', 'manad', 'ar'], true) ? $_GET['grupp'] : 'tillfalle';
    $fButik = (int)($_GET['butik'] ?? 0);
    $fLista = (int)($_GET['lista'] ?? 0);
    $medTillf = ($_GET['tillf'] ?? '1') !== '0';

    $where = ["t.UserID = ?", "t.Status = 'klar'"];
    $types = 'i';
    $params = [$uid];
    if ($fButik) { $where[] = 't.ButikID = ?'; $types .= 'i'; $params[] = $fButik; }
    if ($fLista) { $where[] = 't.ListaID = ?'; $types .= 'i'; $params[] = $fLista; }
    if (!$medTillf) { $where[] = 't.Tillfallig = 0'; }
    $w = implode(' AND ', $where);

    // Nyckeltal
    $tot = dbOne(
        "SELECT MIN(t.Avslutad) AS Forsta,
                SUM(CASE WHEN YEAR(t.Avslutad) = YEAR(CURDATE()) THEN t.Kostnad END) AS IAr,
                SUM(CASE WHEN t.Avslutad >= ? THEN t.Kostnad END) AS Sum12v,
                AVG(t.Kostnad) AS SnittHandling,
                COUNT(*) AS Antal
         FROM HandlaTillfallen t WHERE $w",
        's' . $types, array_merge([date('Y-m-d H:i:s', strtotime('-84 days'))], $params)
    );
    $monthStart = date('Y-m-01', strtotime(date('Y-m-01') . ' -11 months'));
    $monthRows = dbAll(
        "SELECT DATE_FORMAT(t.Avslutad, '%Y-%m') AS M, SUM(t.Kostnad) AS Summa
         FROM HandlaTillfallen t WHERE $w AND t.Avslutad >= ? GROUP BY M",
        $types . 's', array_merge($params, [$monthStart])
    );
    $monthSums = array_column($monthRows, 'Summa', 'M');
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $key = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $months[$key] = (float)($monthSums[$key] ?? 0);
    }
    $first = $tot['Forsta'] ? strtotime($tot['Forsta']) : time();
    $daysSince = max(1, (time() - $first) / 86400);
    $weeksSpan = max(1, min(12, (int)ceil($daysSince / 7)));
    $monthsSpan = max(1, min(12, (int)ceil($daysSince / 30.44)));
    $snittVecka = $tot['Sum12v'] !== null ? (float)$tot['Sum12v'] / $weeksSpan : null;
    $snittManad = array_sum($months) > 0 ? array_sum($months) / $monthsSpan : null;
    $maxMonth = max(1, max($months));

    // Tabell
    if ($grupp === 'tillfalle') {
        $table = dbAll(
            "SELECT t.ID, t.Avslutad, t.ListNamn, t.ButikNamn, t.Tillfallig, t.Kostnad,
                    (SELECT COUNT(*) FROM HandlaKop k WHERE k.TillfalleID = t.ID) AS Varor
             FROM HandlaTillfallen t WHERE $w ORDER BY t.Avslutad DESC LIMIT 300",
            $types, $params
        );
    } else {
        $expr = ['vecka' => 'YEARWEEK(t.Avslutad, 3)', 'manad' => "DATE_FORMAT(t.Avslutad, '%Y-%m')", 'ar' => 'YEAR(t.Avslutad)'][$grupp];
        $table = dbAll(
            "SELECT $expr AS Grupp, COUNT(*) AS Antal, COUNT(t.Kostnad) AS MedKostnad, SUM(t.Kostnad) AS Summa
             FROM HandlaTillfallen t WHERE $w GROUP BY Grupp ORDER BY Grupp DESC LIMIT 300",
            $types, $params
        );
    }
    $groupLabel = function ($g) use ($grupp) {
        $g = (string)$g;
        if ($grupp === 'vecka') {
            return 'v. ' . (int)substr($g, 4) . ' ' . substr($g, 0, 4);
        }
        if ($grupp === 'manad') {
            return ucfirst(SV_MAN_LANG[(int)substr($g, 5, 2) - 1]) . ' ' . substr($g, 0, 4);
        }
        return $g;
    };
    $listor = dbAll('SELECT ID, Namn FROM HandlaListor WHERE UserID = ? ORDER BY Namn', 'i', [$uid]);

    pageHead('Kostnader – Handla');
    ?>
<body>
<div class="page">
    <?php renderNav(HANDLA_PAGE); handlaSubNav('kostnad'); ?>
    <h1>Kostnader</h1>

    <form method="get" class="filter-form">
        <input type="hidden" name="kostnad" value="1">
        <div class="filter-field">
            <label>Visa per</label>
            <select name="grupp" onchange="this.form.submit()">
                <?php foreach (['tillfalle' => 'Handlingstillfälle', 'vecka' => 'Vecka', 'manad' => 'Månad', 'ar' => 'År'] as $k => $l): ?>
                    <option value="<?= $k ?>"<?= $grupp === $k ? ' selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Butik</label>
            <select name="butik" onchange="this.form.submit()">
                <option value="0">Alla</option>
                <?php foreach ($butiker as $b): ?>
                    <option value="<?= $b['id'] ?>"<?= $fButik === $b['id'] ? ' selected' : '' ?>><?= h($b['namn']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Lista</label>
            <select name="lista" onchange="this.form.submit()">
                <option value="0">Alla</option>
                <?php foreach ($listor as $l): ?>
                    <option value="<?= (int)$l['ID'] ?>"<?= $fLista === (int)$l['ID'] ? ' selected' : '' ?>><?= h($l['Namn']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Tillfälliga listor</label>
            <select name="tillf" onchange="this.form.submit()">
                <option value="1"<?= $medTillf ? ' selected' : '' ?>>Räknas med</option>
                <option value="0"<?= !$medTillf ? ' selected' : '' ?>>Räknas inte</option>
            </select>
        </div>
    </form>

    <div class="stat-tiles">
        <div class="stat-tile"><span class="stat-label">I år</span><span class="stat-value"><?= fmtKr($tot['IAr'] !== null ? (float)$tot['IAr'] : null) ?></span></div>
        <div class="stat-tile"><span class="stat-label">Snitt per vecka</span><span class="stat-value"><?= fmtKr($snittVecka !== null ? round($snittVecka) : null) ?></span><span class="stat-note">senaste <?= $weeksSpan ?> v.</span></div>
        <div class="stat-tile"><span class="stat-label">Snitt per månad</span><span class="stat-value"><?= fmtKr($snittManad !== null ? round($snittManad) : null) ?></span><span class="stat-note">senaste <?= $monthsSpan ?> mån.</span></div>
        <div class="stat-tile"><span class="stat-label">Snitt per handling</span><span class="stat-value"><?= fmtKr($tot['SnittHandling'] !== null ? round((float)$tot['SnittHandling']) : null) ?></span><span class="stat-note"><?= (int)$tot['Antal'] ?> handlingar</span></div>
    </div>

    <h2 class="section-title">Senaste 12 månaderna</h2>
    <div class="month-chart" role="img" aria-label="Kostnad per månad de senaste 12 månaderna">
        <?php foreach ($months as $key => $sum): ?>
            <div class="month-col" title="<?= h(ucfirst(SV_MAN_LANG[(int)substr($key, 5, 2) - 1]) . ' ' . substr($key, 0, 4) . ': ' . fmtKr($sum)) ?>">
                <span class="month-val"><?= $sum > 0 ? h(number_format($sum / 1000, 1, ',', '')) . 'k' : '' ?></span>
                <span class="month-bar" style="height: <?= round($sum / $maxMonth * 100, 1) ?>%"></span>
                <span class="month-lbl"><?= SV_MAN_KORT[(int)substr($key, 5, 2) - 1] ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <h2 class="section-title">Per <?= ['tillfalle' => 'handlingstillfälle', 'vecka' => 'vecka', 'manad' => 'månad', 'ar' => 'år'][$grupp] ?></h2>
    <div class="table-wrap">
    <table class="inventory-table">
        <?php if ($grupp === 'tillfalle'): ?>
            <thead><tr><th>Datum</th><th>Lista</th><th>Butik</th><th>Varor</th><th class="num">Kostnad</th></tr></thead>
            <tbody>
            <?php if (!$table): ?><tr><td colspan="5" class="empty-row">Inga handlingar än.</td></tr><?php endif; ?>
            <?php foreach ($table as $t): ?>
                <tr class="clickable trip-row" data-id="<?= (int)$t['ID'] ?>">
                    <td><?= h(svDate($t['Avslutad'], true)) ?></td>
                    <td><?= h($t['ListNamn']) ?><?= (int)$t['Tillfallig'] === 1 ? ' <span class="badge">tillfällig</span>' : '' ?></td>
                    <td><?= h($t['ButikNamn'] ?: '–') ?></td>
                    <td><?= (int)$t['Varor'] ?></td>
                    <td class="num"><?= $t['Kostnad'] !== null ? fmtKr((float)$t['Kostnad']) : '<span class="muted">+ lägg till</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        <?php else: ?>
            <thead><tr><th>Period</th><th>Handlingar</th><th class="num">Summa</th><th class="num">Snitt/handling</th></tr></thead>
            <tbody>
            <?php if (!$table): ?><tr><td colspan="4" class="empty-row">Inga handlingar än.</td></tr><?php endif; ?>
            <?php foreach ($table as $t): $s = $t['Summa'] !== null ? (float)$t['Summa'] : null; ?>
                <tr>
                    <td><?= h($groupLabel($t['Grupp'])) ?></td>
                    <td><?= (int)$t['Antal'] ?><?= (int)$t['MedKostnad'] < (int)$t['Antal'] ? ' <span class="muted">(' . ((int)$t['Antal'] - (int)$t['MedKostnad']) . ' utan kostnad)</span>' : '' ?></td>
                    <td class="num"><?= fmtKr($s) ?></td>
                    <td class="num"><?= $s !== null && (int)$t['MedKostnad'] > 0 ? fmtKr(round($s / (int)$t['MedKostnad'])) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        <?php endif; ?>
    </table>
    </div>
</div>

<div id="trip-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="trip-title">
        <button type="button" class="popup-close" data-close="trip-overlay" aria-label="Stäng">&times;</button>
        <h2 id="trip-title">Handling</h2>
        <p id="trip-info" class="muted"></p>
        <form id="trip-form">
            <label>Kostnad (kr)
                <input type="text" id="trip-cost" inputmode="decimal" placeholder="t.ex. 845,50" autocomplete="off">
            </label>
            <button type="submit" class="primary-btn">Spara kostnad</button>
        </form>
        <h3 class="trip-items-title">Köpt</h3>
        <ol id="trip-items" class="trip-items"></ol>
        <button type="button" id="trip-delete" class="danger-btn">Ta bort handlingen</button>
        <p class="popup-msg" id="trip-msg"></p>
    </div>
</div>

<?php commonScript(); ?>
<script>
let tripId = null;
document.querySelectorAll('.trip-row').forEach(tr => tr.addEventListener('click', async () => {
    tripId = tr.dataset.id;
    const res = await post({ action: 'trip_detail', trip: tripId });
    if (!res.data.success) { alert(res.data.error || 'Något gick fel.'); return; }
    const t = res.data.trip;
    document.getElementById('trip-title').textContent = t.lista || 'Handling';
    document.getElementById('trip-info').textContent = t.datum + (t.butik ? ' · ' + t.butik : '') + (t.tillfallig ? ' · tillfällig lista' : '');
    document.getElementById('trip-cost').value = t.kostnad;
    const ol = document.getElementById('trip-items');
    ol.innerHTML = '';
    t.items.forEach(i => {
        const li = document.createElement('li');
        li.textContent = i.namn + (i.antal ? ' (' + i.antal + ')' : '') + (i.kommentar ? ' – ' + i.kommentar : '');
        ol.appendChild(li);
    });
    if (!t.items.length) ol.innerHTML = '<li class="muted">Inga avbockade varor.</li>';
    document.getElementById('trip-msg').textContent = '';
    document.getElementById('trip-overlay').hidden = false;
}));
document.getElementById('trip-form').addEventListener('submit', async e => {
    e.preventDefault();
    const res = await post({ action: 'trip_cost', trip: tripId, kostnad: document.getElementById('trip-cost').value });
    if (res.data.success) location.reload(); else document.getElementById('trip-msg').textContent = res.data.error || 'Något gick fel.';
});
document.getElementById('trip-delete').addEventListener('click', async () => {
    if (!confirm('Ta bort den här handlingen?\n\nKostnaden och statistiken från den försvinner. Det går inte att ångra.')) return;
    const res = await post({ action: 'trip_delete', trip: tripId });
    if (res.data.success) location.reload(); else document.getElementById('trip-msg').textContent = res.data.error || 'Något gick fel.';
});
</script>
</body>
</html>
<?php
    exit;
}

// ============================================================
// VY: ALLA LISTOR (start)
// ============================================================
$listor = dbAll(
    "SELECT l.ID, l.Namn, l.Tillfallig, l.AktivtTillfalleID, b.Namn AS Butik,
            (SELECT COUNT(*) FROM HandlaRader r WHERE r.ListaID = l.ID) AS Antal,
            (SELECT MAX(t.Avslutad) FROM HandlaTillfallen t WHERE t.ListaID = l.ID AND t.Status = 'klar') AS Senast
     FROM HandlaListor l LEFT JOIN HandlaButiker b ON b.ID = l.ButikID
     WHERE l.UserID = ?
     ORDER BY (l.AktivtTillfalleID IS NULL), l.Tillfallig, l.Namn",
    'i', [$uid]
);
pageHead('Handla');
?>
<body>
<div class="page">
    <?php renderNav(HANDLA_PAGE); handlaSubNav('listor'); ?>
    <h1>Handlingslistor</h1>

    <div class="top-row">
        <button type="button" id="new-list-btn" class="primary-btn">+ Ny lista</button>
    </div>

    <div class="list-cards">
        <?php if (!$listor): ?>
            <p class="empty-row">Inga listor än. Skapa till exempel "Veckohandling" kopplad till din vanliga butik.</p>
        <?php endif; ?>
        <?php foreach ($listor as $l): ?>
            <a class="list-card<?= $l['AktivtTillfalleID'] ? ' active-trip' : '' ?>" href="<?= HANDLA_PAGE ?>?lista=<?= (int)$l['ID'] ?>">
                <span class="list-card-title">
                    <?= h($l['Namn']) ?>
                    <?php if ($l['AktivtTillfalleID']): ?><span class="badge badge-dark">🛒 Handlar nu</span><?php endif; ?>
                    <?php if ((int)$l['Tillfallig'] === 1): ?><span class="badge">tillfällig</span><?php endif; ?>
                </span>
                <span class="list-card-meta">
                    <?= h($l['Butik'] ?: 'Ingen butik') ?> ·
                    <?= (int)$l['Antal'] ?> <?= (int)$l['Antal'] === 1 ? 'vara' : 'varor' ?>
                    <?php if ($l['Senast']): ?> · handlad <?= h(svDate($l['Senast'])) ?><?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div id="new-list-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="new-list-title">
        <button type="button" class="popup-close" data-close="new-list-overlay" aria-label="Stäng">&times;</button>
        <h2 id="new-list-title">Ny lista</h2>
        <form id="new-list-form">
            <label>Namn <input type="text" name="namn" maxlength="150" placeholder="t.ex. Veckohandling" required></label>
            <label>Butik <?php butikSelect('new-list-butik', $butiker, null); ?></label>
            <label class="check-label"><input type="checkbox" name="tillfallig" value="1"> Tillfällig lista – ingen varustatistik</label>
            <button type="submit" class="primary-btn">Skapa</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<?php commonScript(); ?>
<script>
document.getElementById('new-list-btn').addEventListener('click', () => {
    document.getElementById('new-list-overlay').hidden = false;
    document.querySelector('#new-list-form input[name=namn]').focus();
});
document.getElementById('new-list-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const msg = this.querySelector('.popup-msg');
    msg.textContent = 'Skapar…';
    const fields = Object.fromEntries(new FormData(this));
    fields.action = 'create_list';
    try {
        const res = await post(fields);
        if (res.data.success) location.href = 'handla.php?lista=' + res.data.id;
        else msg.textContent = res.data.error || 'Något gick fel.';
    } catch (err) { msg.textContent = 'Nätverksfel: ' + err.message; }
});
</script>
</body>
</html>
