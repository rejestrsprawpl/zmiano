<?php
// Zmiano – API kont, lokali i grafików. Dane w plikach app/data/* (poza repozytorium).
declare(strict_types=1);
date_default_timezone_set('Europe/Warsaw');

const DATA = __DIR__ . '/data';
const GUARD = "<?php exit; ?>\n";
// sha256 jednorazowego tokenu do założenia pierwszego konta administratora
const ADMIN_SETUP_HASH = 'ade6e83267bab4afc0d818ff9b5220214caec4391e00f6761f41fffd68d46cb2';
const MAIL_FROM = 'Zmiano <kontakt@zmiano.pl>';
const SITE = 'https://zmiano.pl';
const RHYTHMS = ['1+1','2+1','2+2','3+1','3+2','3+3','4+1','4+2','4+3'];

session_name('zmiano_app');
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 60, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 60));
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ---------- narzędzia ---------- */
function out($d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c = 400): void { out(['error' => $m], $c); }
function str($v, int $max = 120): string { $v = trim((string)$v); return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max); }
function rid(string $p): string { return $p . bin2hex(random_bytes(5)); }
function now(): int { return (int)round(microtime(true) * 1000); }

function fpath(string $name): string { return DATA . '/' . $name . '.php'; }
function readf(string $name, $def = []) {
  $f = fpath($name); if (!is_file($f)) return $def;
  $d = json_decode(substr((string)file_get_contents($f), strlen(GUARD)), true);
  return $d ?? $def;
}
// zapis pod blokadą pliku
function withf(string $name, callable $fn, $def = []) {
  if (!is_dir(DATA)) mkdir(DATA, 0750, true);
  $fh = fopen(fpath($name), 'c+'); if (!$fh) fail('Nie mogę zapisać danych na serwerze.', 500);
  flock($fh, LOCK_EX);
  $raw = stream_get_contents($fh);
  $d = $raw ? (json_decode(substr($raw, strlen(GUARD)), true) ?? $def) : $def;
  $res = $fn($d);
  [$d, $ret] = is_array($res) && array_key_exists('__ret', $res) ? [$res['d'], $res['__ret']] : [$res, null];
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, GUARD . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  return $ret ?? $d;
}
function mailto(string $to, string $subject, string $body): void {
  $h = "From: " . MAIL_FROM . "\r\nContent-Type: text/plain; charset=utf-8\r\n";
  @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h);
}

/* ---------- ograniczanie prób ---------- */
function limited(string $key, int $max): bool {
  $now = time(); $win = 900; $hit = false;
  withf('attempts', function ($a) use ($key, $max, $now, $win, &$hit) {
    foreach ($a as $k => $l) { $a[$k] = array_values(array_filter($l, fn($t) => $t > $now - $win)); if (!$a[$k]) unset($a[$k]); }
    $hit = count($a[$key] ?? []) >= $max; return $a;
  });
  return $hit;
}
function failAttempt(string ...$keys): void { $now = time(); withf('attempts', function ($a) use ($keys, $now) { foreach ($keys as $k) $a[$k][] = $now; return $a; }); }
function clearAttempt(string $key): void { withf('attempts', function ($a) use ($key) { unset($a[$key]); return $a; }); }

/* ---------- konta ---------- */
function users(): array { return readf('users', []); }
function userById(?string $id): ?array { foreach (users() as $u) if ($u['id'] === $id) return $u; return null; }
function userByEmail(string $e): ?array { foreach (users() as $u) if ($u['email'] === $e) return $u; return null; }
function me(): ?array { $u = userById($_SESSION['uid'] ?? null); return $u; }
function pubUser(?array $u): ?array { return $u ? ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'status' => $u['status'], 'lokal' => $u['lokal'] ?? null] : null; }
function needUser(): array { $u = me(); if (!$u) fail('Zaloguj się.', 401); return $u; }
function needAdmin(): array { $u = needUser(); if ($u['role'] !== 'admin') fail('Brak uprawnień.', 403); return $u; }
function admins(): array { return array_values(array_filter(users(), fn($u) => $u['role'] === 'admin')); }

/* ---------- lokale ---------- */
function slugOk($s): string { $s = (string)$s; if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $s)) fail('Nie ma takiego lokalu.', 404); return $s; }
function lokal(string $slug): ?array { $l = readf('lokal-' . $slug, null); return $l ?: null; }
function slugify(string $name): string {
  $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z'];
  $s = strtr(function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name), $map);
  $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
  $s = substr($s ?: 'lokal', 0, 32);
  if (strlen($s) < 3) $s .= '-lokal';
  $base = $s; $i = 2;
  while (is_file(fpath('lokal-' . $s)) || in_array($s, ['app', 'panel', 'nadarzyn', 'l', 'admin'], true)) $s = $base . '-' . $i++;
  return $s;
}
function canManage(array $u, array $l): bool { return $u['role'] === 'admin' || ($l['owner'] ?? '') === $u['id']; }
function newPin(array $taken): string { do { $p = (string)random_int(1000, 9999); } while (in_array($p, $taken, true)); return $p; }
function monthOk($m): string { if (!is_string($m) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) fail('Zły miesiąc.'); return $m; }
function daysIn(string $m): int { [$y, $mo] = array_map('intval', explode('-', $m)); return (int)date('t', mktime(0, 0, 0, $mo, 1, $y)); }
function dayList($a, int $n): array { if (!is_array($a)) return []; $r = []; foreach ($a as $d) { $d = (int)$d; if ($d >= 1 && $d <= $n) $r[$d] = $d; } sort($r); return array_values($r); }
function numOrNull($v): ?int { if ($v === null || $v === '') return null; return max(0, min(31, (int)$v)); }
// konfiguracja lokalu widoczna dla pracowników (bez PIN-ów)
function pubLokal(array $l, bool $withPins = false): array {
  $staff = array_map(function ($s) use ($withPins) { if (!$withPins) unset($s['pin']); return $s; }, array_values(array_filter($l['staff'] ?? [], fn($s) => !empty($s['active']))));
  return ['slug' => $l['slug'], 'name' => $l['name'], 'city' => $l['city'] ?? '', 'status' => $l['status'], 'positions' => $l['positions'] ?? [], 'staff' => $staff, 'rules' => $l['rules'] ?? []];
}
// kto jest zalogowany w lokalu: 'boss' (właściciel/admin) albo id pracownika
function who(array $l): ?array {
  $u = me();
  if ($u && $u['status'] === 'active' && canManage($u, $l)) return ['boss' => true, 'id' => 'szef', 'name' => $u['name']];
  $sid = $_SESSION['emp'][$l['slug']] ?? null;
  foreach ($l['staff'] ?? [] as $s) if ($s['id'] === $sid && !empty($s['active'])) return ['boss' => !empty($s['manager']), 'staff' => true, 'id' => $s['id'], 'name' => $s['name']];
  return null;
}
function needWho(array $l): array { $w = who($l); if (!$w) fail('Zaloguj się ponownie.', 401); return $w; }
function needBoss(array $l): array { $w = needWho($l); if (!$w['boss']) fail('Tylko manager grafiku może to zrobić.', 403); return $w; }
function monthData(string $slug, string $m): array {
  $d = readf("m-$slug-$m", []);
  return ['prefs' => (object)($d['prefs'] ?? []), 'plan' => $d['plan'] ?? null, 'closed' => $d['closed'] ?? [], 'deadline' => $d['deadline'] ?? null];
}
function updMonth(string $slug, string $m, callable $fn): void { withf("m-$slug-$m", function ($d) use ($fn) { $d += ['prefs' => [], 'plan' => null, 'closed' => [], 'deadline' => null]; if (!is_array($d['prefs'])) $d['prefs'] = []; return $fn($d); }); }

/* ---------- walidacja konfiguracji lokalu (z panelu) ---------- */
function cleanConfig(array $in, array $old): array {
  $pos = []; $posIds = [];
  foreach (array_slice((array)($in['positions'] ?? []), 0, 12) as $p) {
    $name = str($p['name'] ?? '', 40); if ($name === '') continue;
    $id = preg_match('/^p[a-z0-9]{3,12}$/', (string)($p['id'] ?? '')) ? $p['id'] : rid('p');
    $short = strtoupper(str($p['short'] ?? '', 2)) ?: strtoupper(substr($name, 0, 1));
    $pos[] = ['id' => $id, 'name' => $name, 'short' => $short, 'count' => max(1, min(6, (int)($p['count'] ?? 1)))];
    $posIds[] = $id;
  }
  if (!$pos) fail('Dodaj przynajmniej jedno stanowisko.');
  $oldStaff = []; foreach ($old['staff'] ?? [] as $s) $oldStaff[$s['id']] = $s;
  $staff = []; $pins = []; $usedIds = [];
  foreach (array_slice((array)($in['staff'] ?? []), 0, 60) as $s) {
    $name = str($s['name'] ?? '', 40); if ($name === '') continue;
    $id = (preg_match('/^s[a-z0-9]{3,12}$/', (string)($s['id'] ?? '')) && !in_array($s['id'], $usedIds, true)) ? $s['id'] : rid('s');
    $usedIds[] = $id;
    $pin = $oldStaff[$id]['pin'] ?? null;
    if (!empty($s['newPin']) || !$pin || in_array($pin, $pins, true)) $pin = newPin($pins);
    $pins[] = $pin;
    $mn = numOrNull($s['min'] ?? null); $mx = numOrNull($s['max'] ?? null);
    if ($mn !== null && $mx !== null && $mn > $mx) [$mn, $mx] = [$mx, $mn];
    $staff[] = ['id' => $id, 'name' => $name, 'pin' => $pin,
      'positions' => array_values(array_intersect((array)($s['positions'] ?? []), $posIds)),
      'min' => $mn, 'max' => $mx,
      'noWd' => array_values(array_unique(array_filter(array_map('intval', (array)($s['noWd'] ?? [])), fn($w) => $w >= 0 && $w <= 6))),
      'picksOwn' => !empty($s['picksOwn']), 'manager' => !empty($s['manager']), 'active' => true];
  }
  $ids = array_column($staff, 'id');
  $r = (array)($in['rules'] ?? []);
  $pairs = [];
  foreach (array_slice((array)($r['pairs'] ?? []), 0, 30) as $p) {
    if (in_array($p['a'] ?? '', $ids, true) && in_array($p['b'] ?? '', $ids, true) && $p['a'] !== $p['b'] && in_array($p['bPos'] ?? '', $posIds, true))
      $pairs[] = ['a' => $p['a'], 'b' => $p['b'], 'bPos' => $p['bPos']];
  }
  $apart = [];
  foreach (array_slice((array)($r['apart'] ?? []), 0, 30) as $p) {
    if (in_array($p['a'] ?? '', $ids, true) && in_array($p['b'] ?? '', $ids, true) && $p['a'] !== $p['b']) $apart[] = ['a' => $p['a'], 'b' => $p['b']];
  }
  $open = array_values(array_unique(array_filter(array_map('intval', (array)($r['openWd'] ?? [0,1,2,3,4,5,6])), fn($w) => $w >= 0 && $w <= 6)));
  return ['name' => str($in['name'] ?? $old['name'], 60) ?: $old['name'], 'positions' => $pos, 'staff' => $staff,
    'rules' => ['maxRun' => max(1, min(14, (int)($r['maxRun'] ?? 5))), 'openWd' => $open ?: [0,1,2,3,4,5,6], 'pairs' => $pairs, 'apart' => $apart]];
}

/* ---------- akcje ---------- */
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$action = (string)($_GET['action'] ?? '');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');

switch ($action) {

  /* ===== konta ===== */
  case 'me':
    $u = me(); $l = $u && ($u['lokal'] ?? null) ? lokal($u['lokal']) : null;
    out(['user' => pubUser($u), 'needsSetup' => !admins(), 'lokal' => $l ? pubLokal($l, true) : null]);

  case 'setup':  // pierwsze konto administratora (jednorazowo)
    if (admins()) fail('Konto administratora już istnieje.');
    if (!hash_equals(ADMIN_SETUP_HASH, hash('sha256', (string)($in['token'] ?? '')))) { sleep(1); fail('Zły kod instalacyjny.', 403); }
    // przechodzi dalej jak rejestracja, ale z rolą admin
  case 'register':
    $isSetup = $action === 'setup';
    if (limited("reg:$ip", 8)) fail('Za dużo prób. Spróbuj za 15 minut.', 429);
    $email = strtolower(str($in['email'] ?? '', 120));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Podaj poprawny adres e-mail.');
    $pass = (string)($in['password'] ?? '');
    if (strlen($pass) < 8) fail('Hasło musi mieć co najmniej 8 znaków.');
    $name = str($in['name'] ?? '', 60); if ($name === '') fail('Podaj imię i nazwisko.');
    if (!$isSetup) {
      if (empty($in['terms'])) fail('Zaakceptuj regulamin i zasady przetwarzania danych.');
      $lname = str($in['lokalName'] ?? '', 60); if ($lname === '') fail('Podaj nazwę lokalu.');
    }
    if (userByEmail($email)) { failAttempt("reg:$ip"); fail('Konto z tym adresem już istnieje. Zaloguj się albo zresetuj hasło.'); }
    $uid = rid('u'); $slug = null; $city = str($in['city'] ?? '', 40);
    if (!$isSetup) {
      $slug = slugify($lname . ($city ? ' ' . $city : ''));
      withf('lokal-' . $slug, fn($d) => ['slug' => $slug, 'name' => $lname, 'city' => $city,
        'owner' => $uid, 'status' => 'pending', 'createdAt' => now(),
        'positions' => [['id' => rid('p'), 'name' => 'Kuchnia', 'short' => 'K', 'count' => 1], ['id' => rid('p'), 'name' => 'Sala', 'short' => 'S', 'count' => 1]],
        'staff' => [], 'rules' => ['maxRun' => 5, 'openWd' => [0,1,2,3,4,5,6], 'pairs' => [], 'apart' => []]], null);
    }
    $u = ['id' => $uid, 'email' => $email, 'name' => $name, 'phone' => str($in['phone'] ?? '', 30),
      'hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => $isSetup ? 'admin' : 'owner',
      'status' => $isSetup ? 'active' : 'pending', 'lokal' => $slug, 'staffCount' => str($in['staffCount'] ?? '', 10), 'createdAt' => now()];
    withf('users', function ($d) use ($u) { $d[] = $u; return $d; });
    session_regenerate_id(true); $_SESSION['uid'] = $uid;
    if (!$isSetup) {
      foreach (admins() as $a) mailto($a['email'], "Nowe konto w Zmiano: {$u['name']}",
        "Nowy lokal czeka na akceptację.\n\nLokal: $lname ($city)\nOsoba: {$u['name']}\nE-mail: $email\nTelefon: {$u['phone']}\nLiczba pracowników: {$u['staffCount']}\n\nAkceptacja: " . SITE . "/panel");
    }
    out(['user' => pubUser($u)]);

  case 'login':
    $email = strtolower(str($in['email'] ?? '', 120));
    if (limited("pw:$email", 6) || limited("pwip:$ip", 30)) fail('Za dużo błędnych prób. Spróbuj ponownie za 15 minut.', 429);
    $u = userByEmail($email);
    if (!$u || !password_verify((string)($in['password'] ?? ''), $u['hash'])) { failAttempt("pw:$email", "pwip:$ip"); sleep(1); fail('Zły e-mail albo hasło.', 401); }
    clearAttempt("pw:$email");
    session_regenerate_id(true); $_SESSION['uid'] = $u['id'];
    out(['user' => pubUser($u)]);

  case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => true]);

  case 'resetRequest':
    $email = strtolower(str($in['email'] ?? '', 120));
    if (limited("rr:$ip", 5)) fail('Za dużo prób. Spróbuj za 15 minut.', 429);
    failAttempt("rr:$ip");
    $u = userByEmail($email);
    if ($u) {
      $tok = bin2hex(random_bytes(20));
      withf('resets', function ($d) use ($u, $tok) { $d = array_filter($d, fn($r) => $r['exp'] > time() && $r['uid'] !== $u['id']); $d[] = ['uid' => $u['id'], 'hash' => hash('sha256', $tok), 'exp' => time() + 3600]; return array_values($d); });
      mailto($u['email'], 'Zmiano: ustaw nowe hasło', "Cześć {$u['name']},\n\nkliknij w link, żeby ustawić nowe hasło (ważny 1 godzinę):\n" . SITE . "/panel#reset=$tok\n\nJeśli to nie Ty, zignoruj tę wiadomość.");
    }
    out(['ok' => true]); // ta sama odpowiedź, czy konto istnieje, czy nie

  case 'resetConfirm':
    $tok = (string)($in['token'] ?? ''); $pass = (string)($in['password'] ?? '');
    if (strlen($pass) < 8) fail('Hasło musi mieć co najmniej 8 znaków.');
    $r = null; foreach (readf('resets', []) as $x) if (hash_equals($x['hash'], hash('sha256', $tok)) && $x['exp'] > time()) $r = $x;
    if (!$r) fail('Link wygasł albo jest nieprawidłowy. Poproś o nowy.');
    withf('users', function ($d) use ($r, $pass) { foreach ($d as &$u) if ($u['id'] === $r['uid']) $u['hash'] = password_hash($pass, PASSWORD_DEFAULT); return $d; });
    withf('resets', fn($d) => array_values(array_filter($d, fn($x) => $x['uid'] !== $r['uid'])));
    session_regenerate_id(true); $_SESSION['uid'] = $r['uid'];
    out(['user' => pubUser(userById($r['uid']))]);

  /* ===== panel lokalu ===== */
  case 'lokalSave':
    $u = needUser();
    $slug = slugOk($in['slug'] ?? ''); $l = lokal($slug); if (!$l || !canManage($u, $l)) fail('Nie ma takiego lokalu.', 404);
    $cfg = cleanConfig((array)($in['config'] ?? []), $l);
    $l = withf('lokal-' . $slug, function ($d) use ($cfg) { $d = array_merge($d, $cfg); $d['updatedAt'] = now(); return $d; });
    out(['lokal' => pubLokal($l, true)]);

  case 'lokalGet':
    $u = needUser(); $slug = slugOk($_GET['slug'] ?? ''); $l = lokal($slug);
    if (!$l || !canManage($u, $l)) fail('Nie ma takiego lokalu.', 404);
    out(['lokal' => pubLokal($l, true)]);

  /* ===== administrator ===== */
  case 'adminList':
    needAdmin();
    $list = [];
    foreach (users() as $x) {
      if ($x['role'] === 'admin') continue;
      $l = $x['lokal'] ? lokal($x['lokal']) : null;
      $list[] = pubUser($x) + ['phone' => $x['phone'] ?? '', 'staffCount' => $x['staffCount'] ?? '', 'createdAt' => $x['createdAt'] ?? 0,
        'lokalName' => $l['name'] ?? '', 'city' => $l['city'] ?? '', 'staffNow' => count($l['staff'] ?? [])];
    }
    usort($list, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
    out(['users' => $list]);

  case 'adminSetStatus':
    needAdmin();
    $id = (string)($in['id'] ?? ''); $st = (string)($in['status'] ?? '');
    if (!in_array($st, ['active', 'rejected', 'pending'], true)) fail('Zły status.');
    $t = userById($id); if (!$t || $t['role'] === 'admin') fail('Nie ma takiego konta.', 404);
    withf('users', function ($d) use ($id, $st) { foreach ($d as &$u) if ($u['id'] === $id) $u['status'] = $st; return $d; });
    if ($t['lokal']) withf('lokal-' . $t['lokal'], function ($d) use ($st) { $d['status'] = $st; return $d; });
    if ($st === 'active' && $t['status'] !== 'active')
      mailto($t['email'], 'Zmiano: Twoje konto jest aktywne', "Cześć {$t['name']},\n\nTwoje konto w Zmiano jest gotowe. Zaloguj się i dodaj pracowników:\n" . SITE . "/panel\n\nZespół Zmiano");
    out(['ok' => true]);

  /* ===== strona grafiku lokalu ===== */
  case 'gInfo':
    $l = lokal(slugOk($_GET['slug'] ?? ''));
    if (!$l) fail('Nie ma takiego lokalu.', 404);
    if ($l['status'] !== 'active') fail('Ten lokal nie jest jeszcze aktywny.', 403);
    $p = pubLokal($l);
    $p['staff'] = array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'positions' => $s['positions'], 'manager' => !empty($s['manager'])], $p['staff']);
    out(['lokal' => $p, 'me' => who($l)]);

  case 'gLogin':
    $l = lokal(slugOk($in['slug'] ?? '')); if (!$l || $l['status'] !== 'active') fail('Nie ma takiego lokalu.', 404);
    $sid = (string)($in['staff'] ?? ''); $key = "pin:{$l['slug']}:$sid";
    if (limited($key, 5) || limited("pinip:$ip", 20)) fail('Za dużo błędnych prób. Spróbuj ponownie za 15 minut.', 429);
    foreach ($l['staff'] as $s) {
      if ($s['id'] === $sid && !empty($s['active']) && hash_equals((string)$s['pin'], (string)($in['pin'] ?? ''))) {
        clearAttempt($key); session_regenerate_id(true); $_SESSION['emp'][$l['slug']] = $sid;
        out(['me' => who($l)]);
      }
    }
    failAttempt($key, "pinip:$ip"); sleep(1); fail('Zły PIN.', 401);

  case 'gLogout':
    $slug = slugOk($in['slug'] ?? ''); unset($_SESSION['emp'][$slug]); out(['ok' => true]);

  case 'gState':
    $l = lokal(slugOk($_GET['slug'] ?? '')); if (!$l) fail('Nie ma takiego lokalu.', 404);
    $w = needWho($l);
    $full = pubLokal($l); // konfiguracja bez PIN-ów
    out(['lokal' => $full, 'me' => $w] + monthData($l['slug'], monthOk($_GET['month'] ?? '')));

  case 'gSavePrefs':
    $l = lokal(slugOk($in['slug'] ?? '')); if (!$l) fail('Nie ma takiego lokalu.', 404);
    $w = needWho($l); $m = monthOk($in['month'] ?? ''); $person = (string)($in['person'] ?? '');
    if (!in_array($person, array_column($l['staff'], 'id'), true)) fail('Nie ma takiej osoby.');
    if (!$w['boss'] && $w['id'] !== $person) fail('Możesz zmieniać tylko swoje preferencje.', 403);
    $dl = monthData($l['slug'], $m)['deadline'];
    if (!$w['boss'] && $dl && date('Y-m-d') > $dl) fail('Termin wpisywania minął (' . date('d.m', strtotime($dl)) . '). Zmiany zgłoś managerowi.', 403);
    $n = daysIn($m);
    $mn = numOrNull($in['daysMin'] ?? null); $mx = numOrNull($in['daysMax'] ?? null);
    if ($mn !== null && $mx !== null && $mn > $mx) [$mn, $mx] = [$mx, $mn];
    $want = dayList($in['want'] ?? [], $n);
    $p = ['person' => $person, 'daysMin' => $mn, 'daysMax' => $mx, 'rhythm' => in_array($in['rhythm'] ?? null, RHYTHMS, true) ? $in['rhythm'] : null,
      'want' => $want, 'blocked' => array_values(array_diff(dayList($in['blocked'] ?? [], $n), $want)), 'note' => str($in['note'] ?? '', 500), 'updatedAt' => now()];
    updMonth($l['slug'], $m, function ($d) use ($person, $p) { $d['prefs'][$person] = $p; return $d; });
    out(['pref' => $p]);

  case 'gSavePlan':
    $l = lokal(slugOk($in['slug'] ?? '')); if (!$l) fail('Nie ma takiego lokalu.', 404);
    needBoss($l); $m = monthOk($in['month'] ?? ''); $n = daysIn($m);
    $days = $in['days'] ?? null; if (!is_array($days) || count($days) !== $n) fail('Grafik ma złą liczbę dni.');
    $ids = array_column($l['staff'], 'id'); $clean = [];
    foreach ($days as $x) {
      if (!is_array($x)) { $clean[] = null; continue; }
      $row = [];
      foreach ($x as $slot => $sid) { if (preg_match('/^p[a-z0-9]{3,12}#\d$/', (string)$slot) && ($sid === null || in_array($sid, $ids, true))) $row[$slot] = $sid; }
      $clean[] = $row;
    }
    $plan = ['days' => $clean, 'savedAt' => now()];
    updMonth($l['slug'], $m, function ($d) use ($plan) { $d['plan'] = $plan; return $d; });
    out(['plan' => $plan]);

  case 'gSetClosed':
    $l = lokal(slugOk($in['slug'] ?? '')); if (!$l) fail('Nie ma takiego lokalu.', 404);
    needBoss($l); $m = monthOk($in['month'] ?? ''); $closed = dayList($in['closed'] ?? [], daysIn($m));
    updMonth($l['slug'], $m, function ($d) use ($closed) { $d['closed'] = $closed; return $d; });
    out(['closed' => $closed]);

  case 'gSetDeadline':
    $l = lokal(slugOk($in['slug'] ?? '')); if (!$l) fail('Nie ma takiego lokalu.', 404);
    needBoss($l); $m = monthOk($in['month'] ?? '');
    $dl = $in['deadline'] ?? null; if ($dl && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dl)) fail('Zła data.');
    $dl = $dl ?: null;
    updMonth($l['slug'], $m, function ($d) use ($dl) { $d['deadline'] = $dl; return $d; });
    out(['deadline' => $dl]);

  case 'gClear':
    $l = lokal(slugOk($in['slug'] ?? '')); if (!$l) fail('Nie ma takiego lokalu.', 404);
    needBoss($l); $m = monthOk($in['month'] ?? '');
    $cp = !empty($in['prefs']); $cl = !empty($in['plan']);
    updMonth($l['slug'], $m, function ($d) use ($cp, $cl) { if ($cp) $d['prefs'] = []; if ($cl) $d['plan'] = null; return $d; });
    out(['ok' => true]);

  default:
    fail('Nieznana akcja.', 404);
}
