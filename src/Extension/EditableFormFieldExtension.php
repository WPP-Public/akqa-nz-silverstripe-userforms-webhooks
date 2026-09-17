<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Extension;

use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookPayloadBuilder;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataExtension;

/**
 * Adds an overridable webhook JSON key to each editable form field.
 *
 * @property string $WebhookKey
 * @extends DataExtension<\SilverStripe\UserForms\Model\EditableFormField>
 */
class EditableFormFieldExtension extends DataExtension
{
    private static $db = [
        'WebhookKey' => 'Varchar(255)',
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        if ($this->owner->config()->get('literal')) {
            return;
        }

        $fields->addFieldToTab(
            'Root.Webhooks',
            TextField::create(
                'WebhookKey',
                _t(__CLASS__ . '.WEBHOOK_KEY', 'Webhook JSON key')
            )->setDescription(_t(
                __CLASS__ . '.WEBHOOK_KEY_DESCRIPTION',
                'Optional override for the JSON property name. Defaults to the field name as lowerCamelCase (e.g. firstName). '
                . 'Use dot syntax for nested objects (e.g. customer.firstName) and bracket indexes for arrays '
                . '(e.g. responses[0].question, responses[0].answer).'
            ))->setAttribute('placeholder', $this->getDefaultWebhookKey())
        );
    }

    /**
     * Resolve the webhook payload key for this field.
     *
     * Uses the CMS override when set, otherwise lowerCamelCase of the field Name.
     */
    public function resolveWebhookKey(): string
    {
        $override = trim((string) $this->owner->getField('WebhookKey'));
        if ($override !== '') {
            return $override;
        }

        return $this->getDefaultWebhookKey();
    }

    public function getDefaultWebhookKey(): string
    {
        /** @var WebhookPayloadBuilder $builder */
        $builder = Injector::inst()->get(WebhookPayloadBuilder::class);

        return $builder->nameToLowerCamelCase((string) $this->owner->Name);
    }
}
