<?php
// Wzór konfiguracji. Na serwerze skopiuj jako config.php i wpisz prawdziwe PIN-y.
// config.php NIE trafia do repozytorium.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.example.php') { http_response_code(404); exit; }

$ADMIN_PIN = '000000';

$USERS = [
  'omakasa' => ['name' => 'Omakasa', 'pin' => '0000'],
  'wojtek'  => ['name' => 'Wojtek',  'pin' => '0000'],
  'marcin'  => ['name' => 'Marcin',  'pin' => '0000'],
  'janusz'  => ['name' => 'Janusz',  'pin' => '0000'],
  'bartek'  => ['name' => 'Bartek',  'pin' => '0000'],
  'piotrek' => ['name' => 'Piotrek', 'pin' => '0000'],
  'mariusz' => ['name' => 'Mariusz', 'pin' => '0000'],
  'zuzia'   => ['name' => 'Zuzia',   'pin' => '0000'],
  'marysia' => ['name' => 'Marysia', 'pin' => '0000'],
];
