<?php

declare(strict_types=1);

use SilverStripe\Dev\TestOnly;

/**
 * Minimal Page stub so CMS can boot during module PHPUnit runs.
 */
class Page extends \SilverStripe\CMS\Model\SiteTree implements TestOnly
{
}
