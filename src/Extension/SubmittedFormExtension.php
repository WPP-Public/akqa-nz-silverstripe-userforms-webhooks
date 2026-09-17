<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Extension;

use Akqa\SilverStripe\UserFormsWebhooks\Forms\GridField\GridFieldTriggerWebhookAction;
use Akqa\SilverStripe\UserFormsWebhooks\Model\SubmittedWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookDispatcher;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionMenu;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\HasManyList;

/**
 * Fires webhooks after processing and surfaces results on submissions.
 *
 * @method HasManyList<SubmittedWebhook> WebhookResults()
 * @extends DataExtension<\SilverStripe\UserForms\Model\Submission\SubmittedForm>
 */
class SubmittedFormExtension extends DataExtension
{
    private static $has_many = [
        'WebhookResults' => SubmittedWebhook::class,
    ];

    private static $cascade_deletes = [
        'WebhookResults',
    ];

    /**
     * Hook invoked by UserDefinedFormController::process() after emails are sent.
     *
     * @param array $emailData
     * @param array $attachments
     */
    public function updateAfterProcess($emailData, $attachments): void
    {
        // Prefer original request data when available for condition matching
        $data = [];
        if (is_array($emailData) && isset($emailData['Fields'])) {
            foreach ($emailData['Fields'] as $field) {
                if (isset($field->Name)) {
                    $data[$field->Name] = $field->Value ?? null;
                }
            }
        }

        /** @var WebhookDispatcher $dispatcher */
        $dispatcher = Injector::inst()->get(WebhookDispatcher::class);
        $dispatcher->dispatch($this->owner, $data);
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('WebhookResults');

        $config = GridFieldConfig_RecordViewer::create();
        $config->addComponent(new GridFieldTriggerWebhookAction());
        $config->addComponent(GridField_ActionMenu::create());
        $grid = GridField::create(
            'WebhookResults',
            _t(__CLASS__ . '.WEBHOOK_RESULTS', 'Webhook results'),
            $this->owner->WebhookResults(),
            $config
        );

        $fields->addFieldToTab('Root.Webhooks', $grid);
        $fields->fieldByName('Root.Webhooks')?->setTitle(
            _t(__CLASS__ . '.WEBHOOKS_TAB', 'Webhooks')
        );
    }
}
