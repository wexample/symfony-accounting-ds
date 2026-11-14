<?php

namespace Wexample\SymfonyAccountingDs\Helper;

use Wexample\SymfonyMoney\Helper\MoneyHelper;

/**
 * What a reader types in a field of the books — `1 234,50`, `1.234,50`,
 * `12,5 %` — read as the books count it. Never through a float: a float
 * multiplied by 100 drops a cent here and there, and `1.234,50` read as
 * a float is 1.234.
 */
class TypedAmountHelper
{
    /**
     * A percentage carries two decimals in basis points (12,5 % → 1250), as
     * a currency of two decimals does in minor units: read the same way.
     */
    private const string TWO_DECIMALS = 'EUR';

    /**
     * An amount as typed, in the minor units of its currency; nothing typed, null.
     */
    public static function minor(
        string $typed,
        string $currencyCode
    ): ?int {
        return '' === trim($typed) ? null : MoneyHelper::fromDecimal($typed, $currencyCode);
    }

    /**
     * A percentage as typed — `12,5`, `12.5 %` — in basis points; nothing typed, null.
     */
    public static function basisPoints(string|int|float|null $typed): ?int
    {
        if (null === $typed || '' === trim((string) $typed)) {
            return null;
        }

        // The sign `%` and spaces are dropped with anything else not a digit.
        return MoneyHelper::fromDecimal($typed, self::TWO_DECIMALS);
    }
}
