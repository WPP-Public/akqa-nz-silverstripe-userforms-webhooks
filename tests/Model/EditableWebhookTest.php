<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Model;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Model\WebhookCondition;
use Akqa\SilverStripe\UserFormsWebhooks\Model\WebhookHeader;
use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookVariableResolver;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Kernel;
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

    public function testGetHeaderMapResolvesAllowlistedEnvVariables(): void
    {
        Config::modify()->set(WebhookVariableResolver::class, 'allowed_env_variables', [
            'WEBHOOK_TEST_API_KEY',
        ]);
        Environment::setEnv('WEBHOOK_TEST_API_KEY', 'env-secret');
        Environment::setEnv('SS_DATABASE_USERNAME', 'db-user');

        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hook',
        ]);
        $webhook->write();

        WebhookHeader::create([
            'ParentID' => $webhook->ID,
            'Name' => 'Authorization',
            'Value' => 'Bearer {{env.WEBHOOK_TEST_API_KEY}}',
        ])->write();
        WebhookHeader::create([
            'ParentID' => $webhook->ID,
            'Name' => 'X-Db-User',
            'Value' => '{{env.SS_DATABASE_USERNAME}}',
        ])->write();

        $headers = $webhook->getHeaderMap();
        $this->assertSame('Bearer env-secret', $headers['Authorization']);
        $this->assertSame('{{env.SS_DATABASE_USERNAME}}', $headers['X-Db-User']);
    }

    public function testCanCreateDoesNotCrashWithoutCurrentController(): void
    {
        $webhook = EditableWebhook::create();
        $this->assertIsBool($webhook->canCreate());
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

    public function testGetResolvedEndpointURLUsesEnvironmentSpecificUrls(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://live.example.com/hook',
            'EndpointURLTest' => 'https://test.example.com/hook',
            'EndpointURLDev' => 'https://dev.example.com/hook',
            'Enabled' => true,
        ]);

        $this->assertSame(
            'https://live.example.com/hook',
            $webhook->getResolvedEndpointURL(Kernel::LIVE)
        );
        $this->assertSame(
            'https://test.example.com/hook',
            $webhook->getResolvedEndpointURL(Kernel::TEST)
        );
        $this->assertSame(
            'https://dev.example.com/hook',
            $webhook->getResolvedEndpointURL(Kernel::DEV)
        );
    }

    public function testGetResolvedEndpointURLFallsBackToLive(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://live.example.com/hook',
            'EndpointURLTest' => '',
            'EndpointURLDev' => '',
            'Enabled' => true,
        ]);

        $this->assertSame(
            'https://live.example.com/hook',
            $webhook->getResolvedEndpointURL(Kernel::TEST)
        );
        $this->assertSame(
            'https://live.example.com/hook',
            $webhook->getResolvedEndpointURL(Kernel::DEV)
        );
    }

    public function testCanSendRequiresResolvedEndpointForCurrentEnvironment(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => '',
            'EndpointURLDev' => 'https://dev.example.com/hook',
            'Enabled' => true,
        ]);

        /** @var Kernel $kernel */
        $kernel = \SilverStripe\Core\Injector\Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();

        try {
            $kernel->setEnvironment(Kernel::LIVE);
            $this->assertFalse($webhook->canSend([]));

            $kernel->setEnvironment(Kernel::DEV);
            $this->assertTrue($webhook->canSend([]));
        } finally {
            $kernel->setEnvironment($previous);
        }
    }

    public function testValidateRejectsInvalidEnvironmentUrls(): void
    {
        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://live.example.com/hook',
            'EndpointURLDev' => 'not-a-url',
            'Enabled' => true,
        ]);

        $this->assertFalse($webhook->validate()->isValid());
    }
}
