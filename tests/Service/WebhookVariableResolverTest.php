<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Tests\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookVariableResolver;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\UserDefinedForm;

class WebhookVariableResolverTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testGetVariablesIncludesIdAndCreated(): void
    {
        $form = UserDefinedForm::create(['Title' => 'Contact']);
        $form->write();

        $submittedForm = SubmittedForm::create([
            'ParentID' => $form->ID,
            'ParentClass' => UserDefinedForm::class,
        ]);
        $submittedForm->write();

        $resolver = new WebhookVariableResolver();
        $variables = $resolver->getVariables($submittedForm);

        $this->assertSame((string) $submittedForm->ID, $variables['ID']);
        $this->assertSame((string) $submittedForm->Created, $variables['Created']);
    }

    public function testResolveReplacesKnownVariables(): void
    {
        $resolver = new WebhookVariableResolver();

        $resolved = $resolver->resolve('Contact-{{ID}} created {{Created}}', [
            'ID' => '42',
            'Created' => '2026-09-14 10:00:00',
        ]);

        $this->assertSame('Contact-42 created 2026-09-14 10:00:00', $resolved);
    }

    public function testResolveLeavesUnknownVariablesIntact(): void
    {
        $resolver = new WebhookVariableResolver();

        $resolved = $resolver->resolve('Hello {{Name}} #{{ID}}', [
            'ID' => '7',
        ]);

        $this->assertSame('Hello {{Name}} #7', $resolved);
    }

    public function testResolveAllowsWhitespaceInsideBraces(): void
    {
        $resolver = new WebhookVariableResolver();

        $this->assertSame(
            'ref-9',
            $resolver->resolve('ref-{{ ID }}', ['ID' => '9'])
        );
    }

    public function testUpdateWebhookVariablesExtensionHook(): void
    {
        WebhookVariableResolver::add_extension(WebhookVariableResolverTestExtension::class);

        try {
            $form = UserDefinedForm::create(['Title' => 'Contact']);
            $form->write();

            $submittedForm = SubmittedForm::create([
                'ParentID' => $form->ID,
                'ParentClass' => UserDefinedForm::class,
            ]);
            $submittedForm->write();

            $resolver = new WebhookVariableResolver();
            $variables = $resolver->getVariables($submittedForm);

            $this->assertSame('website', $variables['Source']);
            $this->assertSame(
                'website-ref-' . $submittedForm->ID,
                $resolver->resolve('{{Source}}-ref-{{ID}}', $variables)
            );
        } finally {
            WebhookVariableResolver::remove_extension(WebhookVariableResolverTestExtension::class);
        }
    }
}

/**
 * @extends Extension<WebhookVariableResolver>
 */
class WebhookVariableResolverTestExtension extends Extension
{
    /**
     * @param array<string, string> $variables
     */
    public function updateWebhookVariables(array &$variables, SubmittedForm $submittedForm, $webhook = null): void
    {
        $variables['Source'] = 'website';
    }
}
