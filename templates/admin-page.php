<?php
/**
 * Shared admin shell. Variables provided by View::page(): $title, $content, $actions.
 *
 * @var string $title
 * @var string $content
 * @var string $actions
 */
defined('ABSPATH') || exit;
?>
<div class="wrap tqk-wpseo">
  <div class="tqk-wpseo-head">
    <div class="tqk-wpseo-brandrow">
      <?php echo \TQK_WPSEO\Admin\View::mark(); // phpcs:ignore ?>
      <div>
        <p class="tqk-wpseo-brand" style="margin:0">
          <?php esc_html_e('TQK WP SEO', 'tqk-wp-seo'); ?>
          <span class="tqk-wpseo-ver"><?php echo esc_html(TQK_WPSEO_VERSION); ?></span>
        </p>
        <h1><?php echo esc_html($title); ?></h1>
      </div>
    </div>
    <?php if ($actions !== '') : ?>
      <div class="tqk-wpseo-actions"><?php echo $actions; // phpcs:ignore ?></div>
    <?php endif; ?>
  </div>
  <div class="tqk-wpseo-body">
    <?php echo $content; // phpcs:ignore ?>
  </div>
</div>
