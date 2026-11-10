<?php
// Tylko skonfigurowana strona próbek: nagłówek i stopka motywu, bez panelu bloga.
defined('ABSPATH') || exit;
get_header();
?>
<main id="main" class="pimex-samples-page" style="width:100%;max-width:1400px;margin:0 auto;padding:24px 16px">
<?php while (have_posts()) : the_post(); ?>
    <?php the_content(); ?>
<?php endwhile; ?>
</main>
<?php get_footer(); ?>
