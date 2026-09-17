<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Extension\EditableFormFieldExtension;
use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\SS_List;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;

/**
 * Builds the JSON payload submitted to a webhook endpoint.
 */
class WebhookPayloadBuilder
{
    use Extensible;

    /**
     * Build a payload keyed by each field's webhook key.
     *
     * @param SubmittedForm $submittedForm
     * @param SS_List|null $formFields EditableFormField list from the parent form
     * @return array<string, mixed>
     */
    public function build(SubmittedForm $submittedForm, ?SS_List $formFields = null): array
    {
        $payload = [];
        $fieldsByName = $this->indexFormFields($formFields);

        /** @var SubmittedFormField $submittedField */
        foreach ($submittedForm->Values() as $submittedField) {
            $editableField = $fieldsByName[$submittedField->Name] ?? null;
            if ($editableField && !$editableField->showInReports()) {
                continue;
            }

            $key = $this->resolveWebhookKey($submittedField, $editableField);
            if ($key === '') {
                continue;
            }

            $this->setByPath($payload, $key, $this->normaliseValue($submittedField->Value));
        }

        $this->extend('updateWebhookPayload', $payload, $submittedForm);

        return $payload;
    }

    /**
     * Merge webhook default fields into a payload copy.
     *
     * Default field names support dot and bracket syntax. Values support {{Variable}} tokens.
     * Defaults are applied after submission fields so they always appear in the payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applyDefaultFields(
        array $payload,
        EditableWebhook $webhook,
        SubmittedForm $submittedForm
    ): array {
        if (!$webhook->DefaultFields()->count()) {
            return $payload;
        }

        /** @var WebhookVariableResolver $resolver */
        $resolver = Injector::inst()->get(WebhookVariableResolver::class);
        $variables = $resolver->getVariables($submittedForm, $webhook);

        foreach ($webhook->DefaultFields() as $defaultField) {
            $name = trim((string) $defaultField->Name);
            if ($name === '') {
                continue;
            }

            $value = $resolver->resolve((string) $defaultField->Value, $variables);
            $this->setByPath($payload, $name, $this->normaliseValue($value));
        }

        $this->extend('updateWebhookPayloadWithDefaults', $payload, $webhook, $submittedForm);

        return $payload;
    }

    /**
     * Assign a value into a nested array using dot and bracket-path syntax.
     *
     * Examples:
     * - `customer.firstName` → `['customer' => ['firstName' => ...]]`
     * - `responses[0].question` → `['responses' => [['question' => ...]]]`
     *
     * @param array<string, mixed> $payload
     * @param mixed $value
     */
    public function setByPath(array &$payload, string $path, $value): void
    {
        $tokens = $this->parsePath($path);
        if (!$tokens) {
            return;
        }

        $current = &$payload;
        $lastIndex = count($tokens) - 1;

        foreach ($tokens as $index => $token) {
            if ($index === $lastIndex) {
                $current[$token] = $value;
                return;
            }

            if (!isset($current[$token]) || !is_array($current[$token])) {
                $current[$token] = [];
            }

            $current = &$current[$token];
        }
    }

    /**
     * Tokenise a webhook path into string keys and integer array indexes.
     *
     * @return list<string|int>
     */
    public function parsePath(string $path): array
    {
        $tokens = [];
        $segments = explode('.', $path);

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (!preg_match('/^([^\[\]]*)((?:\[\d+\])*)$/', $segment, $matches)) {
                $tokens[] = $segment;
                continue;
            }

            $key = $matches[1];
            $brackets = $matches[2];

            if ($key !== '') {
                $tokens[] = $key;
            }

            if ($brackets === '') {
                continue;
            }

            preg_match_all('/\[(\d+)\]/', $brackets, $indexes);
            foreach ($indexes[1] as $arrayIndex) {
                $tokens[] = (int) $arrayIndex;
            }
        }

        return $tokens;
    }

    /**
     * Convert a form field Name into lowerCamelCase.
     */
    public function nameToLowerCamelCase(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split(
            '/[^a-zA-Z0-9]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
            $name,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (!$parts) {
            return lcfirst($name);
        }

        $parts = array_map(static fn (string $part): string => strtolower($part), $parts);
        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result .= ucfirst($part);
        }

        return $result ?? '';
    }

    /**
     * @param SS_List|null $formFields
     * @return array<string, EditableFormField>
     */
    protected function indexFormFields(?SS_List $formFields): array
    {
        $indexed = [];
        if (!$formFields) {
            return $indexed;
        }

        foreach ($formFields as $field) {
            if ($field instanceof EditableFormField && $field->Name) {
                $indexed[$field->Name] = $field;
            }
        }

        return $indexed;
    }

    protected function resolveWebhookKey(
        SubmittedFormField $submittedField,
        ?EditableFormField $editableField
    ): string {
        if ($editableField && $editableField->hasMethod('resolveWebhookKey')) {
            return (string) $editableField->resolveWebhookKey();
        }

        if ($editableField && $editableField->hasExtension(EditableFormFieldExtension::class)) {
            /** @var EditableFormField&EditableFormFieldExtension $editableField */
            return $editableField->resolveWebhookKey();
        }

        return $this->nameToLowerCamelCase((string) $submittedField->Name);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function normaliseValue($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                return $decoded;
            }
        }

        return $value;
    }
}
