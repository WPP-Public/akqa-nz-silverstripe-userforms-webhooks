<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Model;

use Akqa\SilverStripe\UserFormsWebhooks\Model\WebhookCondition;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\UserDefinedForm;

class WebhookConditionTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testMatchesEqualsAndNotEquals(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Form']);
        $form->write();

        $field = EditableTextField::create([
            'Name' => 'status',
            'Title' => 'Status',
            'ParentID' => $form->ID,
        ]);
        $field->write();

        $equals = WebhookCondition::create([
            'ConditionFieldID' => $field->ID,
            'ConditionOption' => 'Equals',
            'ConditionValue' => 'open',
        ]);

        $this->assertTrue($equals->matches(['status' => 'open']));
        $this->assertFalse($equals->matches(['status' => 'closed']));

        $notEquals = WebhookCondition::create([
            'ConditionFieldID' => $field->ID,
            'ConditionOption' => 'NotEquals',
            'ConditionValue' => 'open',
        ]);

        $this->assertFalse($notEquals->matches(['status' => 'open']));
        $this->assertTrue($notEquals->matches(['status' => 'closed']));
    }

    public function testMatchesBlankRules(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Form']);
        $form->write();

        $field = EditableTextField::create([
            'Name' => 'notes',
            'Title' => 'Notes',
            'ParentID' => $form->ID,
        ]);
        $field->write();

        $isBlank = WebhookCondition::create([
            'ConditionFieldID' => $field->ID,
            'ConditionOption' => 'IsBlank',
        ]);
        $this->assertTrue($isBlank->matches([]));
        $this->assertFalse($isBlank->matches(['notes' => 'hello']));

        $isNotBlank = WebhookCondition::create([
            'ConditionFieldID' => $field->ID,
            'ConditionOption' => 'IsNotBlank',
        ]);
        $this->assertFalse($isNotBlank->matches([]));
        $this->assertTrue($isNotBlank->matches(['notes' => 'hello']));
    }

    public function testMatchesIncludes(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Form']);
        $form->write();

        $field = EditableTextField::create([
            'Name' => 'message',
            'Title' => 'Message',
            'ParentID' => $form->ID,
        ]);
        $field->write();

        $includes = WebhookCondition::create([
            'ConditionFieldID' => $field->ID,
            'ConditionOption' => 'Includes',
            'ConditionValue' => 'urgent',
        ]);

        $this->assertTrue($includes->matches(['message' => 'This is urgent news']));
        $this->assertFalse($includes->matches(['message' => 'all good']));
    }
}
