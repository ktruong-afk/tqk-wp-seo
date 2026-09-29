<?php
namespace TQK_WPSEO\Local;

defined('ABSPATH') || exit;

use TQK_WPSEO\Analyzer\Text;

/**
 * Vietnamese addresses after the 2025 administrative reform (Resolution
 * 202/2025/QH15, in force 1 July 2025): 63 provinces became 34, and the
 * district level (quận / huyện / thị xã) was abolished.
 *
 * Old addresses in LocalBusiness schema, footers and contact pages are
 * still everywhere. This class spots them and proposes the new province.
 * Ward (phường / xã) mergers are far too many to bundle, so for those it
 * only flags the district part and asks for a manual check.
 */
class Address {

    /** Old province (absorbed) => new province it now belongs to. */
    public static function merged(): array {
        return [
            'Hà Giang' => 'Tuyên Quang',
            'Yên Bái' => 'Lào Cai',
            'Bắc Kạn' => 'Thái Nguyên',
            'Vĩnh Phúc' => 'Phú Thọ',
            'Hòa Bình' => 'Phú Thọ',
            'Bắc Giang' => 'Bắc Ninh',
            'Thái Bình' => 'Hưng Yên',
            'Hải Dương' => 'Hải Phòng',
            'Hà Nam' => 'Ninh Bình',
            'Nam Định' => 'Ninh Bình',
            'Quảng Bình' => 'Quảng Trị',
            'Quảng Nam' => 'Đà Nẵng',
            'Kon Tum' => 'Quảng Ngãi',
            'Bình Định' => 'Gia Lai',
            'Ninh Thuận' => 'Khánh Hòa',
            'Đắk Nông' => 'Lâm Đồng',
            'Bình Thuận' => 'Lâm Đồng',
            'Phú Yên' => 'Đắk Lắk',
            'Bình Dương' => 'TP. Hồ Chí Minh',
            'Bà Rịa - Vũng Tàu' => 'TP. Hồ Chí Minh',
            'Bình Phước' => 'Đồng Nai',
            'Long An' => 'Tây Ninh',
            'Sóc Trăng' => 'Cần Thơ',
            'Hậu Giang' => 'Cần Thơ',
            'Bến Tre' => 'Vĩnh Long',
            'Trà Vinh' => 'Vĩnh Long',
            'Tiền Giang' => 'Đồng Tháp',
            'Bạc Liêu' => 'Cà Mau',
            'Kiên Giang' => 'An Giang',
        ];
    }

    /** The 34 provincial units in force since 1 July 2025. */
    public static function current(): array {
        return ['Hà Nội', 'Huế', 'Lai Châu', 'Điện Biên', 'Sơn La', 'Lạng Sơn', 'Quảng Ninh', 'Thanh Hóa', 'Nghệ An', 'Hà Tĩnh', 'Cao Bằng',
            'Tuyên Quang', 'Lào Cai', 'Thái Nguyên', 'Phú Thọ', 'Bắc Ninh', 'Hưng Yên', 'Hải Phòng', 'Ninh Bình', 'Quảng Trị', 'Đà Nẵng',
            'Quảng Ngãi', 'Gia Lai', 'Khánh Hòa', 'Lâm Đồng', 'Đắk Lắk', 'TP. Hồ Chí Minh', 'Đồng Nai', 'Tây Ninh', 'Cần Thơ', 'Vĩnh Long',
            'Đồng Tháp', 'Cà Mau', 'An Giang'];
    }

    /**
     * @return array{old:?string, new:?string, district:bool, messages:string[]}
     */
    public static function check(string $address): array {
        $out = ['old' => null, 'new' => null, 'district' => false, 'messages' => []];
        $norm = Text::normalize($address);
        if ($norm === '') {
            return $out;
        }
        foreach (self::merged() as $old => $new) {
            // "Bà Rịa - Vũng Tàu" is also written "Bà Rịa Vũng Tàu" / "BR-VT".
            $variants = [Text::normalize($old)];
            if ($old === 'Bà Rịa - Vũng Tàu') {
                $variants[] = 'br vt';
                $variants[] = 'vung tau';
            }
            foreach ($variants as $v) {
                if (str_contains(' ' . $norm . ' ', ' ' . $v . ' ')) {
                    $out['old'] = $old;
                    $out['new'] = $new;
                    $out['messages'][] = sprintf(__('"%1$s" merged into %2$s on 1 July 2025: update the address.', 'tqk-wpseo'), $old, $new);
                    break 2;
                }
            }
        }
        // District level abolished. "Quận 1", "Q. Bình Thạnh", "Huyện Củ Chi", "TX. ...".
        if (preg_match('/(?:^|[\s,])(quận|huyện|thị xã|q\.|h\.|tx\.)\s*[\p{L}\d]/iu', $address)) {
            $out['district'] = true;
            $out['messages'][] = __('District level (quận / huyện / thị xã) was abolished on 1 July 2025: use the new ward (phường / xã) + province.', 'tqk-wpseo');
        }
        return $out;
    }

    /** New province named in an address, for addressRegion. '' when none. */
    public static function region(string $address): string {
        $norm = ' ' . Text::normalize($address) . ' ';
        foreach (self::current() as $p) {
            $n = Text::normalize(str_replace('TP. ', '', $p));
            if (str_contains($norm, ' ' . $n . ' ')) {
                return $p;
            }
        }
        if (str_contains($norm, ' tp hcm ') || str_contains($norm, ' sai gon ')) {
            return 'TP. Hồ Chí Minh';
        }
        return '';
    }

    /** PostalAddress for schema: street + region + country. */
    public static function postal(string $address): array {
        $node = ['@type' => 'PostalAddress', 'streetAddress' => $address];
        $region = self::region($address);
        if ($region !== '') {
            $node['addressRegion'] = $region;
        }
        if (self::looks_vietnamese($address)) {
            $node['addressCountry'] = 'VN';
        }
        return $node;
    }

    private static function looks_vietnamese(string $address): bool {
        return self::region($address) !== '' || (bool) preg_match('/(phường|xã|đường|việt nam|vietnam)/iu', $address);
    }
}
