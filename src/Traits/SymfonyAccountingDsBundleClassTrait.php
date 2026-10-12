<?php

namespace Wexample\SymfonyAccountingDs\Traits;

use Wexample\SymfonyHelpers\Traits\BundleClassTrait;
use Wexample\SymfonyAccountingDs\WexampleSymfonyAccountingDsBundle;

trait SymfonyAccountingDsBundleClassTrait
{
    use BundleClassTrait;

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyAccountingDsBundle::class;
    }
}
