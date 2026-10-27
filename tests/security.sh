#!/usr/bin/env bash
# Automatyczne testy bezpieczeństwa Zmiano. Uruchamiane przed każdym wdrożeniem.
# Użycie: bash tests/security.sh   (z katalogu repozytorium)
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
T=$(mktemp -d); SITE=$T/site; PORT=${PORT:-8799}; B="http://127.0.0.1:$PORT"
mkdir -p "$SITE" && cp -r "$REPO"/index.html "$REPO"/app "$REPO"/nadarzyn "$REPO"/backup.php "$SITE"/
rm -rf "$SITE"/app/data/*.php "$SITE"/nadarzyn/data/*.php
php "$REPO/tests/seed.php" "$SITE"
php -S 127.0.0.1:$PORT -t "$SITE" "$REPO/tests/router.php" >"$T/php.log" 2>&1 & PID=$!
trap 'kill $PID 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 1 50); do curl -s "$B/app/api.php?action=me" >/dev/null && break; sleep 0.1; done

FAIL=0; N=0
ok(){ N=$((N+1)); echo "  ok   $1"; }
bad(){ N=$((N+1)); FAIL=$((FAIL+1)); echo "  BŁĄD $1"; }
expect_has(){ if grep -q -- "$2" <<<"$3"; then ok "$1"; else bad "$1 (brak: $2)"; echo "       $3" | head -c 400; echo; fi; }
expect_not(){ if grep -q -- "$2" <<<"$3"; then bad "$1 (wyciek: $2)"; echo "       $3" | head -c 400; echo; else ok "$1"; fi; }
J='Content-Type: application/json'
post(){ curl -s -b "$T/$1" -c "$T/$1" -H "$J" -d "$3" "$B/app/api.php?action=$2"; }
get(){ curl -s -b "$T/$1" -c "$T/$1" "$B/app/api.php?action=$2"; }

echo "Zmiano: testy bezpieczeństwa"
# pliki danych
r=$(curl -s "$B/app/data/users.php"); expect_not "plik kont niedostępny z WWW" '\$2y\$' "$r"
r=$(curl -s "$B/app/data/lokal-lokal-a.php"); expect_not "plik lokalu niedostępny z WWW" '1111' "$r"
r=$(curl -s -o /dev/null -w '%{http_code}' "$B/backup.php"); [[ $r == 403 || $r == 404 ]] && ok "backup.php niedostępny z WWW" || bad "backup.php odpowiada $r"
r=$(cd "$SITE" && php -r 'chdir("/tmp");' && curl -s "$B/nadarzyn/config.php"); expect_not "config Nadarzyna niedostępny" "pin" "$r"
# ekran logowania (bez sesji)
r=$(get anon "gInfo&slug=lokal-a")
expect_has "gInfo działa" '"Anna"' "$r"
for k in '"pin"' '"email"' '"phone"' 'SEKRET' '"apart":\[{' '"manager"'; do expect_not "gInfo bez $k" "$k" "$r"; done
r=$(get anon "gState&slug=lokal-a&month=2030-01"); expect_has "gState wymaga logowania" 'Zaloguj' "$r"
# pracownik lokalu A
r=$(post anna "gLogin" '{"slug":"lokal-a","staff":"sannaa","pin":"1111"}'); expect_has "logowanie PIN-em" '"me"' "$r"
r=$(get anna "gState&slug=lokal-a&month=2030-01")
for k in 'SEKRET-a' '"pin"' '"phone"' 'annaa@' '"apart":\[{' '"min":5'; do expect_not "pracownik nie widzi $k" "$k" "$r"; done
r=$(post anna "gSavePlan" '{"slug":"lokal-a","month":"2030-01","days":[]}'); expect_has "pracownik nie zapisze grafiku" 'error' "$r"
r=$(post anna "gSavePrefs" '{"slug":"lokal-a","month":"2030-01","person":"sbartekа"}'); expect_has "pracownik nie zmieni cudzej dyspozycyjności" 'error' "$r"
r=$(post anna "gSavePrefs" '{"slug":"lokal-a","month":"2030-01","person":"sbarteka","want":[1]}'); expect_has "pracownik nie zmieni cudzej dyspozycyjności (2)" 'Możesz zmieniać tylko swoje' "$r"
r=$(post anna "gClear" '{"slug":"lokal-a","month":"2030-01","prefs":true}'); expect_has "pracownik nie wyczyści miesiąca" 'error' "$r"
r=$(get anna "gState&slug=lokal-b&month=2030-01"); expect_has "pracownik A nie wejdzie do lokalu B" 'Zaloguj' "$r"
r=$(get anna "lokalGet&slug=lokal-a"); expect_has "pracownik nie otworzy panelu" 'error' "$r"
r=$(post anna "adminList" '{}'); expect_has "pracownik nie zobaczy kont" 'error' "$r"
# manager PIN-em widzi lokal, ale nie PIN-y ani kontakty
r=$(post marta "gLogin" '{"slug":"lokal-a","staff":"smanaga","pin":"5555"}')
r=$(get marta "gState&slug=lokal-a&month=2030-01"); expect_has "manager widzi dyspozycyjność" 'SEKRET-a' "$r"
expect_not "manager nie widzi PIN-ów" '"pin"' "$r"; expect_not "manager nie widzi kontaktów" 'annaa@' "$r"
r=$(get marta "gState&slug=lokal-b&month=2030-01"); expect_has "manager A nie wejdzie do lokalu B" 'Zaloguj' "$r"
# właściciel A
r=$(post ownA "login" '{"email":"a@test.pl","password":"haslo-testowe-1"}'); expect_has "logowanie właściciela" '"user"' "$r"
r=$(get ownA "lokalGet&slug=lokal-b"); expect_has "właściciel A nie otworzy lokalu B" 'error' "$r"
r=$(post ownA "lokalSave" '{"slug":"lokal-b","config":{"name":"x","positions":[{"name":"X","count":1}],"staff":[],"rules":{}}}'); expect_has "właściciel A nie zmieni lokalu B" 'error' "$r"
r=$(get ownA "gState&slug=lokal-b&month=2030-01"); expect_has "właściciel A nie zobaczy grafiku B" 'Zaloguj' "$r"
r=$(post ownA "adminList" '{}'); expect_has "właściciel nie zobaczy listy kont" 'error' "$r"
r=$(post ownA "adminSetStatus" '{"id":"uowner000b","status":"rejected"}'); expect_has "właściciel nie zablokuje innych" 'error' "$r"
r=$(post ownA "setup" '{"token":"x","email":"z@z.pl","password":"12345678","name":"Z"}'); expect_has "drugi admin niemożliwy" 'error' "$r"
# zapis z polską literą nie kasuje lokalu
r=$(post ownA "lokalSave" '{"slug":"lokal-a","config":{"name":"Lokal a","positions":[{"id":"pkucha","name":"Łazienka","short":"","count":1}],"staff":[{"id":"sannaa","name":"Anna","positions":["pkucha"]}],"rules":{}}}')
r=$(get ownA "lokalGet&slug=lokal-a"); expect_has "zapis „Łazienka” nie kasuje danych" 'Łazienka' "$r"
# atak z obcej strony
r=$(curl -s -b "$T/ownA" -H "$J" -H 'Origin: https://zla-strona.example' -d '{}' "$B/app/api.php?action=logout"); expect_has "zapytanie z obcej domeny odrzucone" 'Niedozwolone' "$r"
# zgadywanie PIN-u
for i in $(seq 1 6); do r=$(post brute "gLogin" '{"slug":"lokal-a","staff":"sbarteka","pin":"0000"}'); done
expect_has "blokada po 5 złych PIN-ach" 'Za dużo' "$r"
# XSS: imię z HTML zapisuje się jako tekst (escapowane przy wyświetlaniu – sprawdzane w kodzie panelu)
r=$(post ownA "lokalSave" '{"slug":"lokal-a","config":{"name":"Lokal a","positions":[{"id":"pkucha","name":"K","count":1}],"staff":[{"id":"sannaa","name":"<img src=x onerror=alert(1)>","positions":["pkucha"]}],"rules":{}}}')
expect_has "dane z HTML przyjęte jako tekst" '<img' "$r"
grep -q 'innerHTML=`<span><span class="dot' "$REPO/app/grafik.html" && grep -q 'esc(s.name)' "$REPO/app/grafik.html" && ok "imiona w grafiku escapowane" || bad "brak esc() w grafiku"
# Nadarzyn
N1=$(curl -s -c "$T/n1" -b "$T/n1" -d '{"who":"omakasa","pin":"0000"}' "$B/nadarzyn/api.php?action=login")
curl -s -b "$T/n1" -d '{"month":"2030-01","person":"omakasa","want":[3],"note":"NOTKA-NADARZYN"}' "$B/nadarzyn/api.php?action=savePrefs" >/dev/null
curl -s -c "$T/n2" -b "$T/n2" -d '{"who":"wojtek","pin":"0000"}' "$B/nadarzyn/api.php?action=login" >/dev/null
r=$(curl -s -b "$T/n2" "$B/nadarzyn/api.php?action=state&month=2030-01"); expect_not "Nadarzyn: pracownik nie widzi cudzych notatek" 'NOTKA-NADARZYN' "$r"
r=$(curl -s -b "$T/n2" -d '{"month":"2030-01","person":"omakasa","want":[1]}' "$B/nadarzyn/api.php?action=savePrefs"); expect_has "Nadarzyn: nie zmieni cudzej dyspozycyjności" 'error' "$r"

echo "Wynik: $((N-FAIL))/$N testów przeszło."
[[ $FAIL -eq 0 ]]
