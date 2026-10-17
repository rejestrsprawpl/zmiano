<?php
// Jednorazowa instalacja: odszyfrowuje PIN-y i dane z setup.blob kluczem z linku.
// Działa tylko, gdy config.php jeszcze nie istnieje. Bez prawidłowego linku nic nie zrobi.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$dir = __DIR__;
function done(string $msg, bool $ok = false): void {
  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
     . '<body style="font:18px/1.5 system-ui,sans-serif;padding:32px 20px;max-width:520px;margin:auto">'
     . '<p style="color:' . ($ok ? '#3d6b1f' : '#a33') . '">' . htmlspecialchars($msg) . '</p>'
     . ($ok ? '<p><a href="./">Przejdź do grafiku</a></p>' : '') . '</body>';
  exit;
}
if (is_file("$dir/config.php")) done('Instalacja jest już zrobiona. Ten link nic więcej nie zmieni.', true);
$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $t)) { sleep(1); done('Nieprawidłowy link instalacyjny.'); }
$raw = base64_decode((string)@file_get_contents("$dir/setup.blob"), true);
if ($raw === false || strlen($raw) < 29) done('Brak pliku z danymi instalacji.');
if (!function_exists('openssl_decrypt')) done('Serwer nie ma modułu OpenSSL.');
$nonce = substr($raw, 0, 12); $tag = substr($raw, -16); $ct = substr($raw, 12, -16);
$plain = openssl_decrypt($ct, 'aes-256-gcm', hash('sha256', $t, true), OPENSSL_RAW_DATA, $nonce, $tag);
if ($plain === false) { sleep(1); done('Nieprawidłowy link instalacyjny.'); }
$p = json_decode($plain, true);
if (!is_array($p) || !isset($p['config'])) done('Uszkodzone dane instalacji.');
if (!is_dir("$dir/data")) mkdir("$dir/data", 0750, true);
foreach (($p['data'] ?? []) as $name => $content) {
  if (!preg_match('/^\d{4}-\d{2}\.php$/', (string)$name)) continue;
  if (!is_file("$dir/data/$name")) file_put_contents("$dir/data/$name", $content, LOCK_EX);
}
if (file_put_contents("$dir/config.php", $p['config'], LOCK_EX) === false) done('Nie mogę zapisać pliku konfiguracji. Sprawdź uprawnienia folderu.');
done('Gotowe. PIN-y i dane z listopada są na serwerze.', true);
