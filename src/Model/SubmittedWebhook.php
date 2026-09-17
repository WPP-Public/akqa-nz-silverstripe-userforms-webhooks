<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Stored result of a webhook request for a form submission.
 *
 * @property string $WebhookTitle
 * @property string $EndpointURL
 * @property int $StatusCode
 * @property string $RequestBody
 * @property string $ResponseBody
 * @property bool $Success
 * @property string $ErrorMessage
 * @property int $WebhookID
 * @property int $SubmittedFormID
 * @method EditableWebhook Webhook()
 * @method SubmittedForm SubmittedForm()
 */
class SubmittedWebhook extends DataObject
{
    private static $table_name = 'SubmittedWebhook';

    private static $singular_name = 'Webhook Result';

    private static $plural_name = 'Webhook Results';

    private static $default_sort = '"Created" DESC';

    private static $db = [
        'WebhookTitle' => 'Varchar(255)',
        'EndpointURL' => 'Varchar(2048)',
        'StatusCode' => 'Int',
        'RequestBody' => 'Text',
        'ResponseBody' => 'Text',
        'Success' => 'Boolean',
        'ErrorMessage' => 'Text',
    ];

    private static $has_one = [
        'Webhook' => EditableWebhook::class,
        'SubmittedForm' => SubmittedForm::class,
    ];

    private static $summary_fields = [
        'Created.Nice' => 'Fired',
        'WebhookTitle' => 'Webhook',
        'StatusCode' => 'Status',
        'Success.Nice' => 'Success',
        'EndpointURL' => 'Endpoint',
    ];

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName([
            'WebhookID',
            'SubmittedFormID',
        ]);

        $fields->addFieldsToTab('Root.Main', [
            ReadonlyField::create('WebhookTitle', _t(__CLASS__ . '.WEBHOOK', 'Webhook')),
            ReadonlyField::create('EndpointURL', _t(__CLASS__ . '.ENDPOINT', 'Endpoint')),
            ReadonlyField::create('StatusCode', _t(__CLASS__ . '.STATUS_CODE', 'Status code')),
            ReadonlyField::create('Success', _t(__CLASS__ . '.SUCCESS', 'Success')),
            TextareaField::create('RequestBody', _t(__CLASS__ . '.REQUEST_BODY', 'Request body'))
                ->setReadonly(true)
                ->setRows(12),
            TextareaField::create('ResponseBody', _t(__CLASS__ . '.RESPONSE_BODY', 'Response body'))
                ->setReadonly(true)
                ->setRows(12),
            TextareaField::create('ErrorMessage', _t(__CLASS__ . '.ERROR_MESSAGE', 'Error message'))
                ->setReadonly(true)
                ->setRows(4),
        ]);

        return $fields;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canView($member = null): bool
    {
        return (bool) $this->SubmittedForm()->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return (bool) $this->SubmittedForm()->canDelete($member);
    }

    /**
     * Whether a CMS user may manually re-fire this webhook result.
     */
    public function canTrigger($member = null): bool
    {
        $webhook = $this->Webhook();
        if (!$webhook || !$webhook->exists() || !$webhook->getResolvedEndpointURL()) {
            return false;
        }

        $submittedForm = $this->SubmittedForm();
        if (!$submittedForm || !$submittedForm->exists()) {
            return false;
        }

        $parent = $submittedForm->Parent();
        if ($parent && $parent->hasMethod('canEdit')) {
            return (bool) $parent->canEdit($member);
        }

        return (bool) $submittedForm->canView($member);
    }
}
