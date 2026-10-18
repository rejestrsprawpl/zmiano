# Zmiano

Grafik pracy dla gastronomii. Strona: https://zmiano.pl

## Struktura
- `index.html` – strona główna zmiano.pl
- `nadarzyn/` – aplikacja grafiku dla lokalu w Nadarzynie (PHP + pliki danych)

## Czego nie ma w repozytorium
- `*/config.php` – PIN-y lokalu (wzór: `config.example.php`)
- `*/data/` – preferencje i grafiki zapisane przez pracowników

Oba wgrywa się jednorazowo przez FTP. Wdrożenia z Gita ich nie nadpisują.

## Wdrożenie
Każdy push na `main` uruchamia GitHub Actions (`.github/workflows/deploy.yml`), które wysyłają pliki na hosting OVH przez FTP do katalogu `zmiano/`.
Dane FTP są w sekretach repozytorium: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
Pliki `*/config.php` i `*/data/` istnieją tylko na serwerze i nie są nadpisywane.

## Prawa autorskie
Zobacz `COPYRIGHT.md`. Wszelkie prawa zastrzeżone.
