<?php
/**
 * demo.php – sandlåda för Handla (används bara när AUTH_MODE = 'demo')
 * Exempeldatan ligger under UserID 0 (se sql/demo.sql) och kopieras till
 * varje ny besökare. Datumen flyttas så att senaste handlingen alltid var
 * för sex dagar sedan – då finns det förslag att visa direkt.
 */
if (!defined('AUTH_MODE')) {
    http_response_code(403);
    exit;
}

function demoClone(int $uid): void {
    $db = getDb();
    mysqli_begin_transaction($db);

    $max = dbOne("SELECT MAX(Avslutad) AS M FROM HandlaTillfallen WHERE UserID = 0 AND Status = 'klar'");
    $off = !empty($max['M']) ? (time() - 6 * 86400) - strtotime($max['M']) : 0;
    $shift = function ($d) use ($off) { return $d === null ? null : date('Y-m-d H:i:s', strtotime($d) + $off); };

    $butik = [];
    foreach (dbAll('SELECT ID, Namn FROM HandlaButiker WHERE UserID = 0 ORDER BY ID') as $b) {
        dbRun('INSERT INTO HandlaButiker (UserID, Namn) VALUES (?, ?)', 'is', [$uid, $b['Namn']]);
        $butik[(int)$b['ID']] = dbInsertId();
    }

    $vara = [];
    foreach (dbAll('SELECT ID, Namn, Avdelning, Skapad FROM HandlaVaror WHERE UserID = 0 ORDER BY ID') as $v) {
        dbRun(
            'INSERT INTO HandlaVaror (UserID, Namn, Avdelning, Skapad) VALUES (?, ?, ?, ?)',
            'isss', [$uid, $v['Namn'], $v['Avdelning'], $shift($v['Skapad'])]
        );
        $vara[(int)$v['ID']] = dbInsertId();
    }

    $lista = [];
    foreach (dbAll('SELECT * FROM HandlaListor WHERE UserID = 0 ORDER BY ID') as $l) {
        dbRun(
            'INSERT INTO HandlaListor (UserID, Namn, ButikID, Tillfallig, Version, AktivtTillfalleID, Skapad, Andrad)
             VALUES (?, ?, ?, ?, 1, NULL, ?, ?)',
            'isiiss', [$uid, $l['Namn'], $butik[(int)$l['ButikID']] ?? null, (int)$l['Tillfallig'], $shift($l['Skapad']), $shift($l['Andrad'])]
        );
        $lista[(int)$l['ID']] = dbInsertId();
    }

    $rader = dbAll(
        'SELECT r.* FROM HandlaRader r JOIN HandlaListor l ON l.ID = r.ListaID WHERE l.UserID = 0 ORDER BY r.ID'
    );
    foreach ($rader as $r) {
        if (!isset($lista[(int)$r['ListaID']], $vara[(int)$r['VaraID']])) {
            continue;
        }
        dbRun(
            "INSERT INTO HandlaRader (ListaID, VaraID, Antal, Kommentar, Status, BockadMs, Tillagd)
             VALUES (?, ?, ?, ?, 'kvar', NULL, ?)",
            'iisss', [$lista[(int)$r['ListaID']], $vara[(int)$r['VaraID']], $r['Antal'], $r['Kommentar'], $shift($r['Tillagd'])]
        );
    }

    $tillf = [];
    foreach (dbAll("SELECT * FROM HandlaTillfallen WHERE UserID = 0 AND Status = 'klar' ORDER BY ID") as $t) {
        dbRun(
            "INSERT INTO HandlaTillfallen (UserID, ListaID, ListNamn, ButikID, ButikNamn, Tillfallig, Status, Startad, Avslutad, Kostnad)
             VALUES (?, ?, ?, ?, ?, ?, 'klar', ?, ?, ?)",
            'iisisissd',
            [$uid, $lista[(int)$t['ListaID']] ?? null, $t['ListNamn'], $butik[(int)$t['ButikID']] ?? null, $t['ButikNamn'],
             (int)$t['Tillfallig'], $shift($t['Startad']), $shift($t['Avslutad']), $t['Kostnad'] !== null ? (float)$t['Kostnad'] : null]
        );
        $tillf[(int)$t['ID']] = dbInsertId();
    }

    $kop = dbAll(
        'SELECT k.* FROM HandlaKop k JOIN HandlaTillfallen t ON t.ID = k.TillfalleID
         WHERE t.UserID = 0 ORDER BY k.ID'
    );
    foreach ($kop as $k) {
        if (!isset($tillf[(int)$k['TillfalleID']], $vara[(int)$k['VaraID']])) {
            continue;
        }
        dbRun(
            'INSERT INTO HandlaKop (TillfalleID, VaraID, Antal, Kommentar, Ordning, OrdningRel) VALUES (?, ?, ?, ?, ?, ?)',
            'iissid', [$tillf[(int)$k['TillfalleID']], $vara[(int)$k['VaraID']], $k['Antal'], $k['Kommentar'], (int)$k['Ordning'], (float)$k['OrdningRel']]
        );
    }

    mysqli_commit($db);
}

function demoPurge(array $uids): void {
    $in = demoIdList($uids);
    dbRun("DELETE FROM HandlaTillfallen WHERE UserID IN ($in)");   // köpen försvinner automatiskt
    dbRun("DELETE FROM HandlaListor WHERE UserID IN ($in)");       // listraderna försvinner automatiskt
    dbRun("DELETE FROM HandlaVaror WHERE UserID IN ($in)");
    dbRun("DELETE FROM HandlaButiker WHERE UserID IN ($in)");
}
