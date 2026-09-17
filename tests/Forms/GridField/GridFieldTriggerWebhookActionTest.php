<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Forms\GridField;

use Akqa\SilverStripe\UserFormsWebhooks\Forms\GridField\GridFieldTriggerWebhookAction;
use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Model\SubmittedWebhook;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\UserDefinedForm;

class GridFieldTriggerWebhookActionTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testGetActionsIncludesTriggerWebhook(): void
    {
        $component = new GridFieldTriggerWebhookAction();
        $grid = GridField::create('WebhookResults', 'Results', SubmittedWebhook::get(), GridFieldConfig::create());

        $this->assertSame(['triggerwebhook'], $component->getActions($grid));
    }

    public function testCanTriggerRequiresEditableParentAndEndpoint(): void
    {
        $form = UserDefinedForm::create([
            'Title' => 'Contact',
            'EnableWebhooks' => true,
        ]);
        $form->write();

        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hooks/crm',
            'Enabled' => true,
            'FormID' => $form->ID,
            'FormClass' => UserDefinedForm::class,
        ]);
        $webhook->write();

        $submittedForm = SubmittedForm::create([
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $submittedForm->write();

        $result = SubmittedWebhook::create([
            'WebhookID' => $webhook->ID,
            'SubmittedFormID' => $submittedForm->ID,
            'WebhookTitle' => 'CRM',
            'EndpointURL' => 'https://example.com/hooks/crm',
            'Success' => false,
            'StatusCode' => 500,
        ]);
        $result->write();

        $this->logInWithPermission('ADMIN');
        $this->assertTrue($result->canTrigger());

        $webhook->EndpointURL = '';
        $webhook->write();

        $reloaded = SubmittedWebhook::get()->byID($result->ID);
        $this->assertFalse($reloaded->canTrigger());
    }
}
