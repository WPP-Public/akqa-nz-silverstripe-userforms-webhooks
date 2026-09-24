<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Resolves {{Variable}} placeholders for webhook default field values.
 *
 * Supports built-in variables such as {{ID}} and {{Created}}, plus allowlisted
 * environment variables via {{env.NAME}}.
 */
class WebhookVariableResolver
{
    use Configurable;
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
     * Environment variable names that may be referenced as {{env.NAME}} in
     * default field values. Only names listed here are resolved; others are
     * left unchanged so secrets such as SS_DATABASE_USERNAME cannot be exposed.
     *
     * Configure in YAML, for example:
     *
     * ```yaml
     * Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookVariableResolver:
     *   allowed_env_variables:
     *     - MY_WEBHOOK_API_KEY
     * ```
     *
     * @config
     * @var string[]
     */
    private static $allowed_env_variables = [];

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

        $variables = array_merge($variables, $this->getAllowedEnvVariables());

        $this->extend('updateWebhookVariables', $variables, $submittedForm, $webhook);

        if ($webhook) {
            $webhook->extend('updateWebhookVariables', $variables, $submittedForm);
        }

        return $variables;
    }

    /**
     * Resolve allowlisted environment variables into env.NAME map entries.
     *
     * @return array<string, string>
     */
    public function getAllowedEnvVariables(): array
    {
        $variables = [];

        foreach ((array) $this->config()->get('allowed_env_variables') as $envName) {
            if (!is_string($envName) || $envName === '') {
                continue;
            }

            // Reject names that could not be a normal env key.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $envName)) {
                continue;
            }

            $value = Environment::getEnv($envName);
            $variables['env.' . $envName] = ($value === false || $value === null)
                ? ''
                : (string) $value;
        }

        return $variables;
    }

    /**
     * Replace {{Variable}} tokens in a template string.
     *
     * Unknown variables are left unchanged. Matching is case-sensitive.
     * Environment variables use the {{env.NAME}} form and must be allowlisted.
     *
     * @param array<string, string|int|float|null> $variables
     */
    public function resolve(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)\s*\}\}/',
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
