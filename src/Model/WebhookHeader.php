<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Model;

use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;

/**
 * Optional HTTP header sent with a webhook request.
 *
 * @property string $Name
 * @property string $Value
 * @property int $ParentID
 * @method EditableWebhook Parent()
 */
class WebhookHeader extends DataObject
{
    private static $table_name = 'UserFormsWebhookHeader';

    private static $singular_name = 'Webhook Header';

    private static $plural_name = 'Webhook Headers';

    private static $db = [
        'Name' => 'Varchar(255)',
        'Value' => 'Text',
    ];

    private static $has_one = [
        'Parent' => EditableWebhook::class,
    ];

    private static $summary_fields = [
        'Name' => 'Name',
        'Value' => 'Value',
    ];

    public function canCreate($member = null, $context = []): bool
    {
        $parent = $this->getCanCreateContext(func_get_args());
        if ($parent) {
            return (bool) $parent->canEdit($member);
        }

        return parent::canCreate($member);
    }

    /**
     * @param array $args
     * @return SiteTree|null
     */
    protected function getCanCreateContext($args)
    {
        if (isset($args[1]['Parent'])) {
            return $args[1]['Parent'];
        }

        if (Controller::has_curr() && Controller::curr() instanceof CMSMain) {
            return Controller::curr()->currentRecord();
        }

        return null;
    }

    public function canView($member = null): bool
    {
        return (bool) $this->Parent()->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return (bool) $this->Parent()->canEdit($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canEdit($member);
    }
}
