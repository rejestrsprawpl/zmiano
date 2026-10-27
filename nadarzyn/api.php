<?php
// Grafik Nadarzyn – API. Dane trzymane w plikach data/RRRR-MM.php
declare(strict_types=1);
require __DIR__ . '/config.php';

session_set_cookie_params([
  'lifetime' => 60 * 60 * 24 * 60,
  'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/', // osobna sesja dla każdego lokalu
  'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 60));
session_name('zmiano_sess');
date_default_timezone_set('Europe/Warsaw');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DATA_DIR = __DIR__ . '/data';
const GUARD = "<?php exit; ?>\n"; // gdyby .htaccess nie działał, plik i tak niczego nie pokaże
const RHYTHMS = ['1+1','2+1','2+2','3+1','3+2','3+3','4+1','4+2','4+3'];

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$action = $_GET['action'] ?? ($in['action'] ?? '');

function me(): ?array { return $_SESSION['who'] ?? null; }
function needLogin(): array { $m = me(); if (!$m) fail('Zaloguj się ponownie.', 401); return $m; }
function needAdmin(): array { $m = needLogin(); if (empty($m['admin'])) fail('Tylko manager może to zrobić.', 403); return $m; }
function monthOk($m): string { if (!is_string($m) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) fail('Zły miesiąc.'); return $m; }
function daysIn(string $m): int { [$y, $mo] = array_map('intval', explode('-', $m)); return (int)date('t', mktime(0, 0, 0, $mo, 1, $y)); }
function dayList($a, int $n): array {
  if (!is_array($a)) return [];
  $r = [];
  foreach ($a as $d) { $d = (int)$d; if ($d >= 1 && $d <= $n) $r[$d] = $d; }
  sort($r); return array_values($r);
}
function numOrNull($v): ?int { if ($v === null || $v === '') return null; return max(0, min(31, (int)$v)); }

function load(string $m): array {
  $f = DATA_DIR . "/$m.php";
  if (!is_file($f)) return ['prefs' => new stdClass(), 'plan' => null, 'closed' => [], 'deadline' => null];
  $d = json_decode(substr((string)file_get_contents($f), strlen(GUARD)), true) ?: [];
  return ['prefs' => $d['prefs'] ?? new stdClass(), 'plan' => $d['plan'] ?? null, 'closed' => $d['closed'] ?? [], 'deadline' => $d['deadline'] ?? null];
}
// zapis pod blokadą pliku, żeby dwie osoby naraz nie nadpisały sobie danych
function update(string $m, callable $fn): array {
  if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
  $fh = fopen(DATA_DIR . "/$m.php", 'c+');
  if (!$fh) fail('Nie mogę zapisać danych na serwerze.', 500);
  flock($fh, LOCK_EX);
  $raw = stream_get_contents($fh);
  $d = $raw ? (json_decode(substr($raw, strlen(GUARD)), true) ?: []) : [];
  $d += ['prefs' => [], 'plan' => null];
  if (!is_array($d['prefs'])) $d['prefs'] = [];
  $d = $fn($d);
  $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) { flock($fh, LOCK_UN); fclose($fh); fail('Nie udało się zapisać. Spróbuj ponownie.', 500); }
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, GUARD . $json);
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  return $d;
}

// licznik nieudanych logowań (data/attempts.php), zapis pod blokadą pliku
function attempts(callable $fn): void {
  if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
  $fh = fopen(DATA_DIR . '/attempts.php', 'c+');
  if (!$fh) return;
  flock($fh, LOCK_EX);
  $raw = stream_get_contents($fh);
  $a = $raw ? (json_decode(substr($raw, strlen(GUARD)), true) ?: []) : [];
  $a = $fn($a);
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, GUARD . json_encode($a));
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
}

switch ($action) {
  case 'login':
    $who = (string)($in['who'] ?? ''); $pin = (string)($in['pin'] ?? '');
    if ($who !== 'szef' && !isset($USERS[$who])) fail('Zły PIN.', 401);
    // blokada po nieudanych próbach: 5 prób na osobę i 20 z jednego adresu IP na 15 minut
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
    $now = time(); $win = 15 * 60;
    $locked = false;
    attempts(function ($a) use ($who, $ip, $now, $win, &$locked) {
      foreach ($a as $k => $list) { $a[$k] = array_values(array_filter($list, fn($t) => $t > $now - $win)); if (!$a[$k]) unset($a[$k]); }
      if (count($a["u:$who"] ?? []) >= 5 || count($a["ip:$ip"] ?? []) >= 20) $locked = true;
      return $a;
    });
    if ($locked) fail('Za dużo błędnych prób. Spróbuj ponownie za 15 minut.', 429);
    $ok = ($who === 'szef') ? hash_equals($ADMIN_PIN, $pin) : hash_equals((string)$USERS[$who]['pin'], $pin);
    if (!$ok) {
      attempts(function ($a) use ($who, $ip, $now) { $a["u:$who"][] = $now; $a["ip:$ip"][] = $now; return $a; });
      sleep(1); fail('Zły PIN.', 401);
    }
    attempts(function ($a) use ($who) { unset($a["u:$who"]); return $a; });
    session_regenerate_id(true);
    $_SESSION['who'] = $who === 'szef'
      ? ['id' => 'szef', 'name' => 'Manager', 'admin' => true]
      : ['id' => $who, 'name' => $USERS[$who]['name'], 'admin' => false];
    out(['me' => me()]);

  case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => true]);

  case 'me':
    out(['me' => me()]);

  case 'state':
    needLogin();
    out(load(monthOk($_GET['month'] ?? '')));

  case 'savePrefs':
    $m = needLogin(); $month = monthOk($in['month'] ?? '');
    $person = (string)($in['person'] ?? '');
    if (!isset($USERS[$person])) fail('Nie ma takiej osoby.');
    if (!$m['admin'] && $m['id'] !== $person) fail('Możesz zmieniać tylko swoje preferencje.', 403);
    $dl = load($month)['deadline'];
    if (!$m['admin'] && $dl && date('Y-m-d') > $dl) fail('Termin wpisywania minął (' . date('d.m', strtotime($dl)) . '). Zmiany zgłoś managerowi.', 403);
    $n = daysIn($month);
    $mn = numOrNull($in['daysMin'] ?? null); $mx = numOrNull($in['daysMax'] ?? null);
    if ($mn !== null && $mx !== null && $mn > $mx) [$mn, $mx] = [$mx, $mn];
    $rh = in_array($in['rhythm'] ?? null, RHYTHMS, true) ? $in['rhythm'] : null;
    $want = dayList($in['want'] ?? [], $n);
    $blocked = array_values(array_diff(dayList($in['blocked'] ?? [], $n), $want));
    $p = ['month' => $month, 'person' => $person, 'daysMin' => $mn, 'daysMax' => $mx, 'rhythm' => $rh,
      'blocked' => $blocked, 'want' => $want, 'note' => (function_exists('mb_substr') ? mb_substr(trim((string)($in['note'] ?? '')), 0, 500) : substr(trim((string)($in['note'] ?? '')), 0, 1000)),
      'updatedAt' => (int)round(microtime(true) * 1000)];
    update($month, function ($d) use ($person, $p) { $d['prefs'][$person] = $p; return $d; });
    out(['ok' => true, 'pref' => $p]);

  case 'savePlan':
    needAdmin(); $month = monthOk($in['month'] ?? '');
    $n = daysIn($month); $days = $in['days'] ?? null;
    if (!is_array($days) || count($days) !== $n) fail('Grafik ma złą liczbę dni.');
    $clean = [];
    foreach ($days as $x) {
      if ($x === null) { $clean[] = null; continue; }
      $row = [];
      foreach (['s', 'k', 'd', 'w'] as $k) { $v = (string)($x[$k] ?? ''); if (!isset($USERS[$v])) fail('Nieznana osoba w grafiku.'); $row[$k] = $v; }
      $clean[] = $row;
    }
    $plan = ['month' => $month, 'days' => $clean, 'savedAt' => (int)round(microtime(true) * 1000)];
    update($month, function ($d) use ($plan) { $d['plan'] = $plan; return $d; });
    out(['ok' => true, 'plan' => $plan]);

  case 'setDeadline':
    needAdmin(); $month = monthOk($in['month'] ?? '');
    $dl = $in['deadline'] ?? null;
    if ($dl !== null && $dl !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dl)) fail('Zła data.');
    $dl = $dl ?: null;
    update($month, function ($d) use ($dl) { $d['deadline'] = $dl; return $d; });
    out(['ok' => true, 'deadline' => $dl]);

  case 'setClosed':
    needAdmin(); $month = monthOk($in['month'] ?? '');
    $closed = dayList($in['closed'] ?? [], daysIn($month));
    update($month, function ($d) use ($closed) { $d['closed'] = $closed; return $d; });
    out(['ok' => true, 'closed' => $closed]);

  case 'clearMonth':
    needAdmin(); $month = monthOk($in['month'] ?? '');
    $cp = !empty($in['prefs']); $cl = !empty($in['plan']);
    if (!$cp && !$cl) fail('Nic nie wybrano.');
    update($month, function ($d) use ($cp, $cl) { if ($cp) $d['prefs'] = []; if ($cl) $d['plan'] = null; return $d; });
    out(['ok' => true]);

  default:
    fail('Nieznana akcja.', 404);
}
