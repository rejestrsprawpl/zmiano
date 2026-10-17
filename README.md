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
Repozytorium jest podpięte pod hosting OVH (Git + webhook). Każdy push na `main` aktualizuje https://zmiano.pl.
