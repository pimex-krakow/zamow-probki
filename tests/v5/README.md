# Testy wtyczki próbek 5

To testy izolowane, nie instalacja WordPressa ani WooCommerce.

Przy PHP CLI 7.4 lub 8.3:

```sh
php tests/v5/contracts.php
php tests/v5/browser-fixture.php
```

Następnie, przy zainstalowanym pakiecie Node `playwright` i Chromium
`/usr/bin/chromium`:

```sh
node tests/v5/browser.cjs
```

W środowisku przygotowania paczki użyto PHP WASM 7.4 i 8.3 zamiast PHP CLI.
Fixture przeglądarki powstaje w `/tmp/pimex-v5-fixture.html`. Test przeglądarki
przechwytuje żądania i używa wyłącznie lokalnych danych, bez kontaktu ze sklepem.

`fixture.php` implementuje atrapy API. Kontrakty chronią logikę i ograniczenia
pracy, lecz nie mogą wykryć wszystkich różnic rzeczywistego WooCommerce.
Zakres wdrożeniowej kontroli integracyjnej opisuje instrukcja paczki.
