<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Model\WebhookDefaultField;
use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookPayloadBuilder;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserForms\Model\EditableFormField\EditableEmailField;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;
use SilverStripe\UserForms\Model\UserDefinedForm;

class WebhookPayloadBuilderTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @dataProvider camelCaseProvider
     */
    public function testNameToLowerCamelCase(string $input, string $expected): void
    {
        $builder = new WebhookPayloadBuilder();
        $this->assertSame($expected, $builder->nameToLowerCamelCase($input));
    }

    public static function camelCaseProvider(): array
    {
        return [
            'already camel' => ['firstName', 'firstName'],
            'pascal case' => ['FirstName', 'firstName'],
            'snake case' => ['first_name', 'firstName'],
            'kebab case' => ['email-address', 'emailAddress'],
            'spaced' => ['Last Name', 'lastName'],
            'generated field' => ['EditableTextField_abc12', 'editableTextFieldAbc12'],
            'email example' => ['emailAddress', 'emailAddress'],
            'empty' => ['', ''],
        ];
    }

    public function testSetByPathCreatesNestedObjects(): void
    {
        $builder = new WebhookPayloadBuilder();
        $payload = [];

        $builder->setByPath($payload, 'customer.firstName', 'Sarah');
        $builder->setByPath($payload, 'customer.lastName', 'Grant');
        $builder->setByPath($payload, 'customer.emailAddress', 'sarah.grant@walkerscott.co');
        $builder->setByPath($payload, 'meta.source', 'website');

        $this->assertSame([
            'customer' => [
                'firstName' => 'Sarah',
                'lastName' => 'Grant',
                'emailAddress' => 'sarah.grant@walkerscott.co',
            ],
            'meta' => [
                'source' => 'website',
            ],
        ], $payload);
    }

    public function testSetByPathSupportsDeepNesting(): void
    {
        $builder = new WebhookPayloadBuilder();
        $payload = [];

        $builder->setByPath($payload, 'a.b.c', 'value');

        $this->assertSame([
            'a' => [
                'b' => [
                    'c' => 'value',
                ],
            ],
        ], $payload);
    }

    public function testSetByPathIgnoresEmptySegments(): void
    {
        $builder = new WebhookPayloadBuilder();
        $payload = [];

        $builder->setByPath($payload, 'customer..firstName', 'Sarah');
        $builder->setByPath($payload, '', 'ignored');
        $builder->setByPath($payload, '...', 'ignored');

        $this->assertSame([
            'customer' => [
                'firstName' => 'Sarah',
            ],
        ], $payload);
    }

    public function testBuildExpandsDotSyntaxWebhookKeys(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Contact']);
        $form->write();

        $firstName = EditableTextField::create([
            'Name' => 'FirstName',
            'Title' => 'First name',
            'WebhookKey' => 'customer.firstName',
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $firstName->write();

        $lastName = EditableTextField::create([
            'Name' => 'LastName',
            'Title' => 'Last name',
            'WebhookKey' => 'customer.lastName',
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $lastName->write();

        $email = EditableEmailField::create([
            'Name' => 'EmailAddress',
            'Title' => 'Email',
            'WebhookKey' => 'customer.emailAddress',
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $email->write();

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

        $builder = new WebhookPayloadBuilder();
        $payload = $builder->build($submittedForm, $form->Fields());

        $this->assertSame([
            'customer' => [
                'firstName' => 'Sarah',
                'lastName' => 'Grant',
                'emailAddress' => 'sarah.grant@walkerscott.co',
            ],
        ], $payload);
    }

    public function testApplyDefaultFieldsSupportsVariablesAndDotSyntax(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Contact']);
        $form->write();

        $webhook = EditableWebhook::create([
            'Title' => 'CRM',
            'EndpointURL' => 'https://example.com/hooks/crm',
            'Enabled' => true,
            'FormID' => $form->ID,
            'FormClass' => UserDefinedForm::class,
        ]);
        $webhook->write();

        WebhookDefaultField::create([
            'ParentID' => $webhook->ID,
            'Name' => 'created',
            'Value' => '{{Created}}',
        ])->write();

        WebhookDefaultField::create([
            'ParentID' => $webhook->ID,
            'Name' => 'submission.referenceId',
            'Value' => 'Contact-{{ID}}',
        ])->write();

        $submittedForm = SubmittedForm::create([
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $submittedForm->write();

        $builder = new WebhookPayloadBuilder();
        $payload = $builder->applyDefaultFields(
            ['firstName' => 'Sarah'],
            $webhook,
            $submittedForm
        );

        $this->assertSame('Sarah', $payload['firstName']);
        $this->assertSame((string) $submittedForm->Created, $payload['created']);
        $this->assertSame(
            'Contact-' . $submittedForm->ID,
            $payload['submission']['referenceId']
        );
    }
}
