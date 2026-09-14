<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Model\SubmittedWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookDispatcher;
use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookPayloadBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserForms\Model\EditableFormField\EditableEmailField;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;
use SilverStripe\UserForms\Model\UserDefinedForm;

class WebhookDispatcherTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testDispatchPostsJsonPayloadAndLogsResult(): void
    {
        $form = UserDefinedForm::create([
            'Title' => 'Contact',
            'EnableWebhooks' => true,
        ]);
        $form->write();

        $firstName = EditableTextField::create([
            'Name' => 'FirstName',
            'Title' => 'First name',
            'ParentID' => $form->ID,
        ]);
        $firstName->write();

        $lastName = EditableTextField::create([
            'Name' => 'LastName',
            'Title' => 'Last name',
            'ParentID' => $form->ID,
        ]);
        $lastName->write();

        $email = EditableEmailField::create([
            'Name' => 'EmailAddress',
            'Title' => 'Email',
            'ParentID' => $form->ID,
        ]);
        $email->write();

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

        SubmittedFormField::create([
            'ParentID' => $submittedForm->ID,
            'Name' => 'FirstName',
            'Title' => 'First name',
            'Value' => 'Sarah',
        ])->write();
        SubmittedFormField::create([
            'ParentID' => $submittedForm->ID,
            'Name' => 'LastName',
            'Title' => 'Last name',
            'Value' => 'Grant',
        ])->write();
        SubmittedFormField::create([
            'ParentID' => $submittedForm->ID,
            'Name' => 'EmailAddress',
            'Title' => 'Email',
            'Value' => 'sarah.grant@walkerscott.co',
        ])->write();

        $history = [];
        $mock = new MockHandler([
            new Response(202, [], '{"ok":true}'),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);

        $dispatcher = new WebhookDispatcher($client, new WebhookPayloadBuilder(), new NullLogger());
        $dispatcher->dispatch($submittedForm, [
            'FirstName' => 'Sarah',
            'LastName' => 'Grant',
            'EmailAddress' => 'sarah.grant@walkerscott.co',
        ]);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://example.com/hooks/crm', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame([
            'firstName' => 'Sarah',
            'lastName' => 'Grant',
            'emailAddress' => 'sarah.grant@walkerscott.co',
        ], $body);

        $result = SubmittedWebhook::get()->filter('SubmittedFormID', $submittedForm->ID)->first();
        $this->assertNotNull($result);
        $this->assertSame(202, $result->StatusCode);
        $this->assertTrue((bool) $result->Success);
        $this->assertSame('{"ok":true}', $result->ResponseBody);
        $this->assertStringContainsString('firstName', $result->RequestBody);
    }

    public function testDispatchSkippedWhenWebhooksDisabledOnForm(): void
    {
        $form = UserDefinedForm::create([
            'Title' => 'Contact',
            'EnableWebhooks' => false,
        ]);
        $form->write();

        EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hooks/crm',
            'Enabled' => true,
            'FormID' => $form->ID,
            'FormClass' => UserDefinedForm::class,
        ])->write();

        $submittedForm = SubmittedForm::create([
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $submittedForm->write();

        $history = [];
        $mock = new MockHandler([
            new Response(200, [], 'ok'),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);

        $dispatcher = new WebhookDispatcher($client, new WebhookPayloadBuilder(), new NullLogger());
        $dispatcher->dispatch($submittedForm, []);

        $this->assertCount(0, $history);
        $this->assertSame(0, SubmittedWebhook::get()->count());
    }
}
