<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Text/background pairs used by the theme must meet WCAG AA (4.5:1). Hex values come from tailwind-config.js and style.css. */
class ContrastTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function pairs(): array
    {
        $js = file_get_contents(__DIR__.'/../../public/assets/js/tailwind-config.js');
        $css = file_get_contents(__DIR__.'/../../public/assets/css/style.css');
        $t = fn (string $name) => preg_match('/"'.preg_quote($name, '/').'":\s*"(#[0-9a-fA-F]{6})"/', $js, $m) ? $m[1] : throw new \RuntimeException("token $name missing");
        // The hint colour lives in style.css (.field-hint)
        $hint = preg_match('/\.field-hint\s*\{[^}]*color:\s*(#[0-9a-fA-F]{6})/', $css, $m) ? $m[1] : throw new \RuntimeException('.field-hint colour missing');

        return [
            'body text on page' => [$t('on-surface'), $t('surface')],
            'secondary text on tinted panel' => [$t('on-surface-variant'), $t('surface-container-low')],
            'secondary text on table head' => [$t('on-surface-variant'), $t('surface-container')],
            'outline text on white' => [$t('outline'), '#ffffff'],
            'outline text on input fill' => [$t('outline'), '#f2f3ff'],
            'hint text on white' => [$hint, '#ffffff'],
            'hint text on input fill' => [$hint, '#f2f3ff'],
            'error on white' => [$t('error'), '#ffffff'],
            'error text on error container' => [$t('on-error-container'), $t('error-container')],
            'badge text on green badge' => [$t('on-secondary-fixed'), $t('secondary-fixed')],
            'primary link on white' => [$t('primary'), '#ffffff'],
            'white on primary button' => ['#ffffff', $t('primary')],
            'white on green button' => ['#ffffff', $t('secondary')],
            'readonly input text' => [$t('on-surface-variant'), '#f2f3ff'],
        ];
    }

    #[DataProvider('pairs')]
    public function test_pair_meets_aa(string $fg, string $bg): void
    {
        $this->assertGreaterThanOrEqual(4.5, self::ratio($fg, $bg), "$fg on $bg");
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, sscanf($hex, '#%02x%02x%02x'));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function ratio(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
