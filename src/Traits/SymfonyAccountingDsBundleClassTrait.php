<?php

namespace Wexample\SymfonyAccountingDs\Traits;

use Wexample\SymfonyAccountingDs\WexampleSymfonyAccountingDsBundle;
use Wexample\SymfonyHelpers\Traits\BundleClassTrait;

trait SymfonyAccountingDsBundleClassTrait
{
    use BundleClassTrait;

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyAccountingDsBundle::class;
    }
}
