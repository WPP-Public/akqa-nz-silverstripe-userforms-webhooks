<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookPayloadBuilder;
use SilverStripe\Dev\SapphireTest;

class WebhookPayloadBuilderTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @dataProvider camelCaseProvider
     */
    public function testNameToLowerCamelCase(string $input, string $expected): void
    {
        $builder = new WebhookPayloadBuilder();
        $this->assertSame($expected, $builder->nameToLowerCamelCase($input));
    }

    public function camelCaseProvider(): array
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
}
