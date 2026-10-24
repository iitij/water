<?php

namespace App\Support;

/**
 * تفقيط المبالغ بالعربية (خطة 2.0 — الدرس 4.4: كل مستند مالي يحمل تفقيطاً).
 *
 * يعتمد على `intl` (`NumberFormatter::SPELLOUT`) لأنه يتولّى صيغ العدد
 * العربية (مثنى/جمع/تمييز) بدقة، وإن لم يكن ممتد `intl` مثبّتاً في الخادم
 * تُعاد `null` بدل طباعة نص ناقص في مستند رسمي.
 */
final class ArabicNumber
{
    public const DEFAULT_CURRENCY_SUFFIX = 'ريال يمني لا غير';

    public static function spellout(
        float|int|string $amount,
        string $suffix = self::DEFAULT_CURRENCY_SUFFIX,
    ): ?string {
        if (! class_exists(\NumberFormatter::class)) {
            return null;
        }

        try {
            $formatter = new \NumberFormatter('ar', \NumberFormatter::SPELLOUT);
            $spoken = $formatter->format((float) $amount);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($spoken) || trim($spoken) === '') {
            return null;
        }

        return trim($spoken . ' ' . $suffix);
    }
}
