<?php
namespace TQK_WPSEO\Schema;

defined('ABSPATH') || exit;

/** S3 — Schema node factories (core types; Woo/Local/News in S6+). */
class Nodes {

    public static function organization(): array {
        $name = trim((string) \TQK_WPSEO\Admin\Settings::get('business_name', ''));
        // Local SEO: business profile → LocalBusiness NAP when configured.
        if ($name !== '' && \TQK_WPSEO\Core\Modules::is_active('local-seo')) {
            $node = [
                '@type' => 'LocalBusiness',
                '@id' => home_url('/#organization'),
                'name' => $name,
                'url' => home_url('/'),
            ];
            $desc = trim((string) \TQK_WPSEO\Admin\Settings::get('business_description', ''));
            if ($desc !== '') {
                $node['description'] = mb_substr($desc, 0, 300);
            }
            $phone = trim((string) \TQK_WPSEO\Admin\Settings::get('business_phone', ''));
            if ($phone !== '') {
                $node['telephone'] = $phone;
            }
            $addr = trim((string) \TQK_WPSEO\Admin\Settings::get('business_address', ''));
            if ($addr !== '') {
                $node['address'] = \TQK_WPSEO\Local\Address::postal($addr);
            }
            $hours = trim((string) \TQK_WPSEO\Admin\Settings::get('business_hours', ''));
            if ($hours !== '') {
                $node['openingHours'] = $hours;
            }
            return $node;
        }
        $node = [
            '@type' => 'Organization',
            '@id' => home_url('/#organization'),
            'name' => get_bloginfo('name'),
            'url' => home_url('/'),
        ];
        $logo = self::logo();
        if ($logo !== []) {
            $node['logo'] = $logo;
        }
        return $node;
    }

    /**
     * Site logo as ImageObject: the theme's custom logo, else the site icon.
     * Google shows it in results and the knowledge panel.
     */
    public static function logo(): array {
        $id = function_exists('get_theme_mod') ? (int) get_theme_mod('custom_logo') : 0;
        $src = $id > 0 ? wp_get_attachment_image_src($id, 'full') : false;
        if (is_array($src) && !empty($src[0])) {
            return ['@type' => 'ImageObject', 'url' => (string) $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2]];
        }
        $icon = function_exists('get_site_icon_url') ? (string) get_site_icon_url(512) : '';
        return $icon !== '' ? ['@type' => 'ImageObject', 'url' => $icon, 'width' => 512, 'height' => 512] : [];
    }

    /** ISO 8601 with timezone (Google rejects "2026-03-21 19:04:20"). */
    public static function iso(string $mysql_date): string {
        if ($mysql_date === '' || str_starts_with($mysql_date, '0000')) {
            return '';
        }
        if (function_exists('wp_timezone')) {
            try {
                return (new \DateTimeImmutable($mysql_date, wp_timezone()))->format(DATE_W3C);
            } catch (\Exception $e) {
                return '';
            }
        }
        $ts = strtotime($mysql_date);
        return $ts ? gmdate(DATE_W3C, $ts) : '';
    }

    public static function website(): array {
        return [
            '@type' => 'WebSite',
            '@id' => home_url('/#website'),
            'url' => home_url('/'),
            'name' => get_bloginfo('name'),
            'publisher' => ['@id' => home_url('/#organization')],
        ];
    }

    /** @param string $type WebPage, or CollectionPage for archives. */
    public static function webpage(array $data, string $type = 'WebPage'): array {
        $url = (string) (($data['canonical'] ?? '') !== '' ? $data['canonical'] : home_url('/'));
        $node = [
            '@type' => $type,
            '@id' => trailingslash($url) . '#webpage',
            'url' => $url,
            'name' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'isPartOf' => ['@id' => home_url('/#website')],
            // Dropped by Graph::prune_refs() when the page has no breadcrumb node.
            'breadcrumb' => ['@id' => trailingslash($url) . '#breadcrumb'],
        ];
        if (($data['og_image'] ?? '') !== '') {
            $node['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => (string) $data['og_image']];
        }
        return $node;
    }

    public static function article($post, array $data, string $type = 'Article'): array {
        $node = [
            '@type' => $type,
            '@id' => trailingslash($data['canonical'] ?? '') . '#article',
            'headline' => mb_substr((string) ($data['title'] ?? ''), 0, 110),
            'description' => $data['description'] ?? '',
            'datePublished' => self::iso((string) ($post->post_date ?? '')),
            'dateModified' => self::iso((string) ($post->post_modified ?? '')),
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', (int) ($post->post_author ?? 0)),
            ],
            'publisher' => ['@id' => home_url('/#organization')],
            'isPartOf' => ['@id' => trailingslash($data['canonical'] ?? '') . '#webpage'],
            'mainEntityOfPage' => ['@id' => trailingslash($data['canonical'] ?? '') . '#webpage'],
        ];
        // Featured image, else the share image (default / first in content).
        if (($data['og_image'] ?? '') !== '') {
            $node['image'] = [(string) $data['og_image']];
        }
        return $node;
    }

    /** WooCommerce product node (only when product data exists). */
    public static function product($post, array $data): array {
        $id = (int) ($post->ID ?? 0);
        $price = trim((string) get_post_meta($id, '_price', true));
        // "Giá: Liên hệ" products (empty or 0 price, very common in B2B
        // shops): Product without offers is an error in Search Console, and
        // price 0 shows as "Free". Default: emit no Product node at all.
        if (self::no_price($price)) {
            $mode = (string) \TQK_WPSEO\Admin\Settings::get('product_no_price', 'skip');
            if ($mode !== 'no_offers') {
                return [];
            }
            $price = '';
        }
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        $stock = (string) get_post_meta($id, '_stock_status', true);
        $node = [
            '@type' => 'Product',
            '@id' => trailingslash($data['canonical'] ?? '') . '#product',
            'name' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'url' => $data['canonical'] ?? '',
            'sku' => (string) get_post_meta($id, '_sku', true),
        ];
        if ($price !== '') {
            $node['offers'] = [
                '@type' => 'Offer',
                'price' => $price,
                'priceCurrency' => $currency,
                'availability' => 'https://schema.org/' . ($stock === 'outofstock' ? 'OutOfStock' : 'InStock'),
            ];
        }
        return $node;
    }

    public static function no_price(string $price): bool {
        return $price === '' || !is_numeric($price) || (float) $price <= 0;
    }

    /** @param array $trail Each: [label, url]. */
    public static function breadcrumb_list(array $trail, string $page_url): array {
        $items = [];
        $pos = 1;
        foreach ($trail as [$label, $url]) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => $label,
                'item' => $url,
            ];
        }
        return [
            '@type' => 'BreadcrumbList',
            '@id' => trailingslash($page_url) . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }
}

function trailingslash(string $url): string {
    return rtrim($url, '/') . '/';
}
