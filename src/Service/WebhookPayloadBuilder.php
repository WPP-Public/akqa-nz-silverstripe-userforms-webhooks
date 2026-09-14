<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Extension\EditableFormFieldExtension;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\SS_List;
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

            $payload[$key] = $this->normaliseValue($submittedField->Value);
        }

        $this->extend('updateWebhookPayload', $payload, $submittedForm);

        return $payload;
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
