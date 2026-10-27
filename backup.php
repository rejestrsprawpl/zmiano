<?php
/**
 * Zmiano – nocna kopia zapasowa danych.
 * Uruchamiane przez zadanie Cron w panelu OVH (Hosting → Zadania Cron), codziennie w nocy.
 * Pakuje app/data i nadarzyn/data do jednego archiwum, trzyma 30 ostatnich kopii
 * POZA katalogiem strony (niedostępne z internetu) i wysyła kopię mailem na BACKUP_EMAIL.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // tylko z crona, nigdy z przeglądarki

const BACKUP_EMAIL = 'chludzinski.michal@gmail.com';
const KEEP_DAYS = 30;
$root = __DIR__;
$dest = dirname($root) . '/zmiano-kopie';                // folder obok strony, poza www
$sources = ['app/data', 'nadarzyn/data'];

if (!is_dir($dest) && !mkdir($dest, 0700, true)) { fwrite(STDERR, "Brak folderu na kopie\n"); exit(1); }
$stamp = date('Y-m-d');
$files = 0;

if (class_exists('ZipArchive')) {
  $file = "$dest/zmiano-$stamp.zip";
  $z = new ZipArchive();
  if ($z->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "Nie da się utworzyć archiwum\n"); exit(1); }
  foreach ($sources as $src) foreach (glob("$root/$src/*.php") ?: [] as $f) { $z->addFile($f, "$src/" . basename($f)); $files++; }
  if (is_file("$root/nadarzyn/config.php")) { $z->addFile("$root/nadarzyn/config.php", 'nadarzyn/config.php'); $files++; }
  $z->close();
} else {
  $file = "$dest/zmiano-$stamp.tar";
  @unlink($file); @unlink("$file.gz");
  $t = new PharData($file);
  foreach ($sources as $src) foreach (glob("$root/$src/*.php") ?: [] as $f) { $t->addFile($f, "$src/" . basename($f)); $files++; }
  if (is_file("$root/nadarzyn/config.php")) { $t->addFile("$root/nadarzyn/config.php", 'nadarzyn/config.php'); $files++; }
  $t->compress(Phar::GZ); unset($t); @unlink($file); $file .= '.gz';
}
@chmod($file, 0600);

// sprzątanie: zostaw KEEP_DAYS najnowszych
$all = glob("$dest/zmiano-*") ?: []; rsort($all);
foreach (array_slice($all, KEEP_DAYS) as $old) @unlink($old);

// kopia poza serwerem: mail z załącznikiem
$size = filesize($file);
$ok = false;
if (BACKUP_EMAIL && $size < 15 * 1024 * 1024) {
  $b = 'zm' . bin2hex(random_bytes(8));
  $name = basename($file);
  $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nKopia danych Zmiano z $stamp. Plików: $files, rozmiar: " . round($size / 1024) . " KB.\r\n"
        . "Zawiera dane osobowe pracowników lokali – nie przekazuj dalej.\r\n\r\n"
        . "--$b\r\nContent-Type: application/octet-stream; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n"
        . chunk_split(base64_encode(file_get_contents($file))) . "--$b--\r\n";
  $ok = mail(BACKUP_EMAIL, "=?UTF-8?B?" . base64_encode("Kopia Zmiano $stamp") . "?=", $body,
    "From: Zmiano <kontakt@zmiano.pl>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"");
}
echo "OK $stamp: $files plików, " . round($size / 1024) . " KB, mail: " . ($ok ? 'wysłany' : 'NIE') . "\n";
