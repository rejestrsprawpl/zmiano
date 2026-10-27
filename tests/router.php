<?php
// Router dla php -S naśladujący .htaccess (tylko do testów)
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];
if (preg_match('#^/(app|nadarzyn)/data(/|$)#', $u) || preg_match('#(^|/)config(\.example)?\.php$#', $u) || $u === '/backup.php') { http_response_code(403); echo 'Forbidden'; return true; }
if (preg_match('#^/panel/?$#', $u)) { readfile("$root/app/panel.html"); return true; }
if (preg_match('#^/l/[a-z0-9-]+/?$#', $u)) { readfile("$root/app/grafik.html"); return true; }
$f = $root . $u;
if (is_file($f) && substr($f, -4) === '.php') { chdir(dirname($f)); include $f; return true; }
return false;
