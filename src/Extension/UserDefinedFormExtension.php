<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Extension;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\HasManyList;

/**
 * Adds webhook configuration to UserDefinedForm.
 *
 * @property bool $EnableWebhooks
 * @method HasManyList<EditableWebhook> Webhooks()
 * @extends DataExtension<\SilverStripe\UserForms\Model\UserDefinedForm>
 */
class UserDefinedFormExtension extends DataExtension
{
    private static $db = [
        'EnableWebhooks' => 'Boolean',
    ];

    private static $has_many = [
        'Webhooks' => EditableWebhook::class . '.Form',
    ];

    private static $cascade_deletes = [
        'Webhooks',
    ];

    private static $cascade_duplicates = [
        'Webhooks',
    ];

    private static $defaults = [
        'EnableWebhooks' => false,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('Webhooks');

        $fields->findOrMakeTab(
            'Root.Webhooks',
            _t(__CLASS__ . '.WEBHOOKS_TAB', 'Webhooks')
        );

        $fields->addFieldToTab(
            'Root.Webhooks',
            CheckboxField::create(
                'EnableWebhooks',
                _t(__CLASS__ . '.ENABLE_WEBHOOKS', 'Enable webhooks for this form')
            )->setDescription(_t(
                __CLASS__ . '.ENABLE_WEBHOOKS_DESCRIPTION',
                'When enabled, matching webhooks below will fire after each submission.'
            ))
        );

        $config = GridFieldConfig_RecordEditor::create(10);
        $config->getComponentByType(GridFieldAddNewButton::class)
            ?->setButtonName(_t(__CLASS__ . '.ADD_WEBHOOK', 'Add Webhook'));

        $webhooks = GridField::create(
            'Webhooks',
            _t(__CLASS__ . '.WEBHOOKS', 'Webhooks'),
            $this->owner->Webhooks(),
            $config
        );

        $fields->addFieldToTab('Root.Webhooks', $webhooks);
    }
}
