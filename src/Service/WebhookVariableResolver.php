<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Resolves {{Variable}} placeholders for webhook default field values.
 */
class WebhookVariableResolver
{
    use Extensible;
    use Injectable;

    /**
     * Built-in variable names available in default field templates.
     *
     * @config
     * @var string[]
     */
    private static $default_variables = [
        'ID',
        'Created',
    ];

    /**
     * Build the variable map for a submission.
     *
     * @return array<string, string>
     */
    public function getVariables(SubmittedForm $submittedForm, ?EditableWebhook $webhook = null): array
    {
        $variables = [
            'ID' => (string) $submittedForm->ID,
            'Created' => (string) $submittedForm->Created,
        ];

        $this->extend('updateWebhookVariables', $variables, $submittedForm, $webhook);

        if ($webhook) {
            $webhook->extend('updateWebhookVariables', $variables, $submittedForm);
        }

        return $variables;
    }

    /**
     * Replace {{Variable}} tokens in a template string.
     *
     * Unknown variables are left unchanged. Matching is case-sensitive.
     *
     * @param array<string, string|int|float|null> $variables
     */
    public function resolve(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $name = $matches[1];
                if (!array_key_exists($name, $variables)) {
                    return $matches[0];
                }

                $value = $variables[$name];
                if ($value === null) {
                    return '';
                }

                return (string) $value;
            },
            $template
        );
    }

    /**
     * Convenience helper: resolve a template against a submission.
     */
    public function resolveForSubmission(
        string $template,
        SubmittedForm $submittedForm,
        ?EditableWebhook $webhook = null
    ): string {
        return $this->resolve($template, $this->getVariables($submittedForm, $webhook));
    }
}
