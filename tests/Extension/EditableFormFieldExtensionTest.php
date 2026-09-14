<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Extension;

use Akqa\SilverStripe\UserFormsWebhooks\Extension\EditableFormFieldExtension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserForms\Model\EditableFormField\EditableTextField;
use SilverStripe\UserForms\Model\UserDefinedForm;

class EditableFormFieldExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testResolveWebhookKeyDefaultsToLowerCamelCase(): void
    {
        $this->assertTrue(EditableTextField::has_extension(EditableFormFieldExtension::class));

        $form = UserDefinedForm::create(['Title' => 'Form']);
        $form->write();

        $field = EditableTextField::create([
            'Name' => 'First_Name',
            'Title' => 'First name',
            'ParentID' => $form->ID,
        ]);
        $field->write();

        $this->assertSame('firstName', $field->resolveWebhookKey());
    }

    public function testResolveWebhookKeyUsesOverride(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Form']);
        $form->write();

        $field = EditableTextField::create([
            'Name' => 'First_Name',
            'Title' => 'First name',
            'WebhookKey' => 'givenName',
            'ParentID' => $form->ID,
        ]);
        $field->write();

        $this->assertSame('givenName', $field->resolveWebhookKey());
    }
}
