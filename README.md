# Silverstripe UserForms Webhooks

Posts [Silverstripe UserForms](https://github.com/silverstripe/silverstripe-userforms) submissions to configurable HTTP webhooks.

Webhook endpoints, headers, default fields, and conditional rules are managed in the CMS. Each fired request is logged against the submission with status code, request body, and response body.

## Requirements

- PHP 8.3+
- Silverstripe CMS 6
- `silverstripe/userforms` ^7
- Guzzle 7

For Silverstripe CMS 5, use the `1.x` release line (the `1` branch) instead.

## Installation

```bash
composer require akqa-nz/silverstripe-userforms-webhooks
```

Run `dev/build` after installing.

## CMS usage

1. Open a **User Defined Form** (or Elemental user form) in the CMS.
2. On the **Webhooks** tab, tick **Enable webhooks for this form**.
3. Add one or more webhooks with:
   - **Live / Test / Dev endpoint URLs** (mapped to `SS_ENVIRONMENT_TYPE`)
   - Optional HTTP headers
   - Optional **default fields** (static/templated JSON values)
   - Optional custom rules (same style as email recipient conditions)
4. On each form field, optionally set **Webhook JSON key**. When empty, the field `Name` is converted to lowerCamelCase (e.g. `First_Name` → `firstName`). Use **dot syntax** for nested objects (e.g. `customer.firstName`) and **bracket indexes** for arrays (e.g. `responses[0].question`, `responses[1].answer`).
5. Open a submission under **Submissions** → **Webhooks** to inspect fired hooks, status codes, and bodies.
6. Use **Trigger** on a webhook result row to manually re-fire that webhook. This skips the form-level enable flag and custom rules, posts using the current webhook configuration, and writes a new result row.

### Environment-specific endpoints

Each webhook can define different URLs for Silverstripe's three environments:

| CMS field | Environment (`SS_ENVIRONMENT_TYPE`) |
| --- | --- |
| Live endpoint URL | `live` |
| Test endpoint URL | `test` |
| Dev endpoint URL | `dev` |

If Dev or Test is left blank, the Live URL is used as a fallback. The webhook will not fire when the resolved URL for the current environment is empty.

Customise resolution with `updateResolvedEndpointURL` on `EditableWebhook`:

```php
public function updateResolvedEndpointURL(string &$url, string $environment): void
{
    if ($environment === 'dev') {
        $url = 'https://webhook.site/debug';
    }
}
```

### Default fields, headers, and variables

Each webhook can define default fields that are always merged into the JSON payload, and optional HTTP headers. Field names support dot and bracket syntax. Default field and header values may include variables:

| Variable | Description |
| --- | --- |
| `{{ID}}` | Submitted form ID |
| `{{Created}}` | Submission created datetime |
| `{{env.NAME}}` | Value of an allowlisted environment variable named `NAME` |

Environment variables are **not** available by default. Projects must explicitly allow each name via YAML so secrets such as `SS_DATABASE_USERNAME` cannot be leaked into webhook payloads or headers:

```yaml
Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookVariableResolver:
  allowed_env_variables:
    - MY_WEBHOOK_API_KEY
```

Only names listed in `allowed_env_variables` resolve. References to any other env name (for example `{{env.SS_DATABASE_PASSWORD}}`) are left unchanged.

Examples:

| Field name / header | Value | Result |
| --- | --- | --- |
| `created` | `{{Created}}` | `"created": "2026-09-14 10:00:00"` |
| `submission.referenceId` | `Contact-{{ID}}` | `"submission": { "referenceId": "Contact-123" }` |
| `apiKey` | `{{env.MY_WEBHOOK_API_KEY}}` | `"apiKey": "…"` (when allowlisted) |
| Header `Authorization` | `Bearer {{env.MY_WEBHOOK_API_KEY}}` | `Authorization: Bearer …` (when allowlisted) |

Default fields are applied after form submission values, so they are always present on the payload for that webhook.

### Example payload

Flat keys:

```json
{
  "firstName": "Sarah",
  "lastName": "Johns",
  "emailAddress": "sarah.johns@example.com"
}
```

Nested keys (`customer.firstName`, `customer.lastName`, `customer.emailAddress`) plus defaults:

```json
{
  "customer": {
    "firstName": "Sarah",
    "lastName": "Johns",
    "emailAddress": "sarah.johns@example.com"
  },
  "created": "2026-09-14 10:00:00",
  "submission": {
    "referenceId": "Contact-123"
  }
}
```

Array keys (`responses[0].question`, `responses[0].answer`, `responses[1].question`, `responses[1].answer`):

```json
{
  "firstName": "Sarah",
  "lastName": "Johns",
  "emailAddress": "sarah.johns@example.com",
  "responses": [
    {
      "question": "How old are you?",
      "answer": "25 - 35"
    },
    {
      "question": "Preferred start date?",
      "answer": "2026-10-01"
    }
  ]
}
```

## Extension hooks

Customise request construction from PHP:

```php
// On WebhookPayloadBuilder / WebhookDispatcher
public function updateWebhookPayload(array &$payload, SubmittedForm $submittedForm): void
{
    $payload['source'] = 'website';
}

// Add or override template variables used in default fields
public function updateWebhookVariables(
    array &$variables,
    SubmittedForm $submittedForm,
    ?EditableWebhook $webhook = null
): void {
    $variables['Source'] = 'website';
    $variables['Created'] = date('c', strtotime($variables['Created']));
}

// On EditableWebhook / WebhookDispatcher
public function updateWebhookHeaders(array &$headers): void
{
    $headers['X-Custom'] = 'value';
}

public function updateWebhookRequestOptions(
    array &$options,
    EditableWebhook $webhook,
    SubmittedForm $submittedForm,
    array $payload,
    array $data
): void {
    $options['timeout'] = 10;
}

public function updateSubmittedWebhook(
    SubmittedWebhook $result,
    EditableWebhook $webhook,
    SubmittedForm $submittedForm,
    array $payload
): void {
    // inspect or mutate the stored result before write
}
```

Apply extensions via YAML, for example:

```yaml
Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookVariableResolver:
  extensions:
    - My\App\WebhookVariableExtension

Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookPayloadBuilder:
  extensions:
    - My\App\WebhookPayloadExtension
```

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpcs src/ tests/
```
