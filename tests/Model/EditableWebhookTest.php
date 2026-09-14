<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Model;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Model\WebhookCondition;
use Akqa\SilverStripe\UserFormsWebhooks\Model\WebhookHeader;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\UserDefinedForm;

class EditableWebhookTest extends SapphireTest
{
    protected static $fixture_file = null;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        EditableWebhook::class,
        WebhookCondition::class,
        WebhookHeader::class,
    ];

    public function testCanSendWithoutRulesWhenEnabled(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hook',
            'Enabled' => true,
        ]);

        $this->assertTrue($webhook->canSend(['anything' => 'value']));
    }

    public function testCanSendReturnsFalseWhenDisabled(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hook',
            'Enabled' => false,
        ]);

        $this->assertFalse($webhook->canSend([]));
    }

    public function testCanSendReturnsFalseWithoutEndpoint(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'Enabled' => true,
            'EndpointURL' => '',
        ]);

        $this->assertFalse($webhook->canSend([]));
    }

    public function testCanSendWithEqualsCondition(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Contact']);
        $form->write();

        $field = EditableTextField::create([
            'Name' => 'enquiryType',
            'Title' => 'Enquiry type',
            'ParentID' => $form->ID,
        ]);
        $field->write();

        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hook',
            'Enabled' => true,
            'CustomRulesCondition' => 'And',
            'FormID' => $form->ID,
            'FormClass' => UserDefinedForm::class,
        ]);
        $webhook->write();

        $rule = WebhookCondition::create([
            'ParentID' => $webhook->ID,
            'ConditionFieldID' => $field->ID,
            'ConditionOption' => 'Equals',
            'ConditionValue' => 'Sales',
        ]);
        $rule->write();

        $this->assertTrue($webhook->canSend(['enquiryType' => 'Sales']));
        $this->assertFalse($webhook->canSend(['enquiryType' => 'Support']));
    }

    public function testGetHeaderMapIncludesDefaultsAndCustomHeaders(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hook',
        ]);
        $webhook->write();

        WebhookHeader::create([
            'ParentID' => $webhook->ID,
            'Name' => 'X-Api-Key',
            'Value' => 'secret',
        ])->write();

        $headers = $webhook->getHeaderMap();
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('secret', $headers['X-Api-Key']);
    }

    public function testValidateRejectsInvalidUrl(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'not-a-url',
            'Enabled' => true,
        ]);

        $result = $webhook->validate();
        $this->assertFalse($result->isValid());
    }
}
