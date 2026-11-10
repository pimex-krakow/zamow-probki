# PIMEX — Zamów próbki 5.0.0

Wersja przebudowuje przygotowanie katalogu i interfejs próbek. Zachowuje
sprawdzaną osobno logikę koszyka, podatków i zapisu metadanych zamówienia.
Nie jest powiązana wykonawczo ze starszą porównywarką.

## Koszt żądań

- Załadowanie wtyczki rejestruje hooki. Nie pobiera list produktów.
- Zmiana produktu zapisuje rewizję danych. Nie uruchamia pracy w tle.
- Publiczna strona odczytuje kompletny, ograniczony rozmiarem katalog z opcji
  bez autoload. Brak katalogu daje komunikat; nie powoduje jego budowy.
- Filtry, wyszukiwanie i stronicowanie działają lokalnie. W DOM znajdują się
  cztery rzędy kafelków, a nie cały katalog. Na stronie próbek nie ma API cen,
  sprawdzania dostępności w pętli, żądań AJAX ani odpytywania serwera.
- Dodanie zestawu jest jednym POST-em z nonce. Sprawdza najwyżej 50 wybranych
  źródeł i do 50 istniejących pozycji próbek; nie enumeruje wariantów produktu.
- Przycisk przy produkcie i kontrola koszyka nie uruchamiają budowy katalogu.

## Przygotowanie katalogu

Tylko administrator z `manage_woocommerce` i poprawnym nonce może rozpocząć
pracę przez `admin-post.php`. Jego otwarty panel wykonuje po jednym żądaniu,
z przerwą 600 ms. Brak automatycznego ponawiania po błędzie.

Zapytania używają kursora `ID > poprzednie_ID`, limitu 10 i górnej granicy ID
ustalonej przy rozpoczęciu. Produkty wariantowe nie wywołują `get_children()`;
warianty są odczytywane jako osobne źródła, w kolejnych partiach. Budżet czasu
jest kontrolowany po każdym źródle. Ograniczone dane postępu i wynik przechowywane
są w osobnych opcjach bez autoload. Wynik jest publikowany jednym zapisem,
dopiero po ukończeniu. Poprzednia kompletna wersja pozostaje w użyciu podczas
budowy i po jej błędzie. Rewizja chroni przed opublikowaniem wyniku po zmianie
produktów w trakcie pracy.

Blokada jest uzyskiwana atomowym `add_option`. Usunięcie blokady porównuje jej
wartość bezpośrednio w bazie. Wygasła blokada nie uprawnia starego procesu do
nadpisania postępu nowego procesu. To blokada przygotowania katalogu, a nie
blokada całego sklepu.

## Dane i migracja

Opcja `pimex_samples_1`, produkt techniczny oznaczony `_pimex_samples_owned`,
shortcode `[pimex_probki]`, wyłączenia oraz metadane pozycji `pimex_sample`
są zachowane. Osobna flaga `pimex_samples_v5_live` wymaga świadomego włączenia
przyjmowania zamówień w nowej wersji. Nie jest wykonywany import ani masowa
modyfikacja istniejących produktów i zamówień.

Nowe opcje mają prefiks `pimex_v5_`; katalog i blokada mają sufiks lokalizacji.
Nie ma odinstalowania usuwającego dane. Wycofanie kodu i przywracanie starej
bazy to różne operacje — przywrócenie bazy może usunąć nowe zamówienia.

Klucz próbki obejmuje ID rodzica, kolor oraz profil/szerokość/grubość/strukturę.
Długości jednego wzoru są scalane; różni rodzice zawsze pozostają oddzielni.
Starsze wyłączenia i grupy odcieni są uwzględniane jako zgodność danych.

## Weryfikacja i ograniczenia

`TESTY.txt` opisuje rzeczywiście uruchomione testy. Izolowane atrapy WooCommerce
nie dowodzą zgodności z każdą wtyczką sklepu. Brak pomiaru obciążenia na MyDevil
oraz pełnego testu integracyjnego na kopii sklepu. Zmiana architektury usuwa
konkretne nieograniczone ścieżki pracy; nie dowodzi przyczyny wcześniejszych 502.
