<?php defined('ABSPATH') || exit; ?>
<pimex-samples-v5>
<template>
<style><?php echo file_get_contents(dirname(__DIR__).'/assets/view.css'); ?></style>
<section class="app" aria-label="Zamów próbki PIMEX">
<script type="application/json" class="config"><?php echo wp_json_encode($config, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
<header><h2>Sprawdź materiał przed zakupem</h2><p>Zaznacz próbki, które chcesz obejrzeć w swoim domu.</p></header>
<?php if (!$config['enabled']) : ?><p class="notice" role="status">Zamawianie próbek jest chwilowo wyłączone. Możesz przeglądać katalog.</p><?php endif; ?>
<div class="layout">
<aside>
<div class="groups" role="group" aria-label="Zastosowanie">
<button type="button" data-group="tarasy" aria-pressed="false">Deski tarasowe</button>
<button type="button" data-group="elewacje" aria-pressed="false">Deski elewacyjne</button>
<button type="button" data-group="lamele" aria-pressed="false">Lamele elewacyjne</button>
</div>
<details class="filters" open><summary>Filtry</summary><div class="filter-list"></div><button type="button" class="reset">Wyczyść filtry</button></details>
</aside>
<div class="catalog">
<div class="tools"><p class="results" aria-live="polite"></p><label class="search-label">Szukaj <input type="search" class="search" placeholder="Model, marka, kolor" aria-label="Szukaj modelu, marki lub koloru"></label></div>
<div class="cards"></div><p class="empty" hidden>Brak próbek spełniających te kryteria. Zmień lub wyczyść filtry.</p>
<nav class="pages" aria-label="Strony katalogu próbek"></nav>
<p class="photo-note">Zdjęcie przedstawia pełny produkt lub realizację. Otrzymasz próbkę materiału. Kolor na ekranie może różnić się od rzeczywistego.</p>
</div>
<form method="post" class="order" action="<?php echo esc_url(get_permalink((int)$o['page'])); ?>">
<?php wp_nonce_field('pimex_samples_order','pimex_samples_nonce'); ?>
<input type="hidden" name="pimex_samples_action" value="add"><input type="hidden" name="pimex_samples_ids" class="ids" value="[]">
<h3>Twój zestaw <span class="count">0</span></h3>
<p class="selected-empty">Zaznacz pierwszą próbkę.</p><ul class="selected"></ul>
<div class="totals" aria-live="polite"><p><span>Próbki</span><strong class="subtotal"></strong></p><p><span>Wysyłka w Polsce</span><strong class="shipping"></strong></p><p class="total"><span>Razem brutto</span><strong class="grand"></strong></p></div>
<p class="feedback" role="status" aria-live="polite"></p>
<button type="submit" class="submit" disabled>Dodaj zestaw do koszyka</button>
<button type="button" class="clear">Wyczyść zestaw</button>
<p class="note">Do <?php echo (int)$config['max']; ?> różnych próbek, po jednej sztuce każdego modelu i koloru. Wysyłka pełnych desek jest liczona oddzielnie.</p>
</form>
</div></section>
</template>
<noscript>Włącz JavaScript, aby wybrać próbki, lub skontaktuj się z PIMEX.</noscript>
</pimex-samples-v5>
