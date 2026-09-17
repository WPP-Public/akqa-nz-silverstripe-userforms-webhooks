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

    public function testSetByPathCreatesArrayItems(): void
    {
        $builder = new WebhookPayloadBuilder();
        $payload = [];

        $builder->setByPath($payload, 'responses[0].question', 'What cover do you need?');
        $builder->setByPath($payload, 'responses[0].answer', 'Family');
        $builder->setByPath($payload, 'responses[1].question', 'Preferred start date?');
        $builder->setByPath($payload, 'responses[1].answer', '2026-10-01');

        $this->assertSame([
            'responses' => [
                [
                    'question' => 'What cover do you need?',
                    'answer' => 'Family',
                ],
                [
                    'question' => 'Preferred start date?',
                    'answer' => '2026-10-01',
                ],
            ],
        ], $payload);
    }

    public function testSetByPathSupportsNestedArrayIndexes(): void
    {
        $builder = new WebhookPayloadBuilder();
        $payload = [];

        $builder->setByPath($payload, 'matrix[0][1]', 'value');

        $this->assertSame([
            'matrix' => [
                [
                    1 => 'value',
                ],
            ],
        ], $payload);
    }

    /**
     * @dataProvider parsePathProvider
     * @param list<string|int> $expected
     */
    public function testParsePath(string $input, array $expected): void
    {
        $builder = new WebhookPayloadBuilder();
        $this->assertSame($expected, $builder->parsePath($input));
    }

    public function parsePathProvider(): array
    {
        return [
            'flat' => ['firstName', ['firstName']],
            'dot nested' => ['customer.firstName', ['customer', 'firstName']],
            'array item' => ['responses[0].question', ['responses', 0, 'question']],
            'array only' => ['responses[0]', ['responses', 0]],
            'multi index' => ['matrix[0][1]', ['matrix', 0, 1]],
            'empty dots' => ['customer..firstName', ['customer', 'firstName']],
            'empty path' => ['', []],
        ];
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

    public function testBuildExpandsArraySyntaxWebhookKeys(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Quote']);
        $form->write();

        $question = EditableTextField::create([
            'Name' => 'QuestionOne',
            'Title' => 'Question one',
            'WebhookKey' => 'responses[0].question',
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $question->write();

        $answer = EditableTextField::create([
            'Name' => 'AnswerOne',
            'Title' => 'Answer one',
            'WebhookKey' => 'responses[0].answer',
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $answer->write();

        $questionTwo = EditableTextField::create([
            'Name' => 'QuestionTwo',
            'Title' => 'Question two',
            'WebhookKey' => 'responses[1].question',
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $questionTwo->write();

        $answerTwo = EditableTextField::create([
            'Name' => 'AnswerTwo',
            'Title' => 'Answer two',
            'WebhookKey' => 'responses[1].answer',
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $answerTwo->write();

        $submittedForm = SubmittedForm::create([
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $submittedForm->write();

        SubmittedFormField::create([
            'ParentID' => $submittedForm->ID,
            'Name' => 'QuestionOne',
            'Title' => 'Question one',
            'Value' => 'What cover do you need?',
        ])->write();
        SubmittedFormField::create([
            'ParentID' => $submittedForm->ID,
            'Name' => 'AnswerOne',
            'Title' => 'Answer one',
            'Value' => 'Family',
        ])->write();
        SubmittedFormField::create([
            'ParentID' => $submittedForm->ID,
            'Name' => 'QuestionTwo',
            'Title' => 'Question two',
            'Value' => 'Preferred start date?',
        ])->write();
        SubmittedFormField::create([
            'ParentID' => $submittedForm->ID,
            'Name' => 'AnswerTwo',
            'Title' => 'Answer two',
            'Value' => '2026-10-01',
        ])->write();

        $builder = new WebhookPayloadBuilder();
        $payload = $builder->build($submittedForm, $form->Fields());

        $this->assertSame([
            'responses' => [
                [
                    'question' => 'What cover do you need?',
                    'answer' => 'Family',
                ],
                [
                    'question' => 'Preferred start date?',
                    'answer' => '2026-10-01',
                ],
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
