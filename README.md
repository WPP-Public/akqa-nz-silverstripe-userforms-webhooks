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
4. On each form field, optionally set **Webhook JSON key**. When empty, the field `Name` is converted to lowerCamelCase (e.g. `First_Name` → `firstName`). Use **dot syntax** for nested objects (e.g. `customer.firstName`).
5. Open a submission under **Submissions** → **Webhooks** to inspect fired hooks, status codes, and bodies.

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

### Default fields and variables

Each webhook can define default fields that are always merged into the JSON payload. Field names support dot syntax. Values may include variables:

| Variable | Description |
| --- | --- |
| `{{ID}}` | Submitted form ID |
| `{{Created}}` | Submission created datetime |

Examples:

| Field name | Value | Result |
| --- | --- | --- |
| `created` | `{{Created}}` | `"created": "2026-09-14 10:00:00"` |
| `submission.referenceId` | `Contact-{{ID}}` | `"submission": { "referenceId": "Contact-123" }` |

Default fields are applied after form submission values, so they are always present on the payload for that webhook.

### Example payload

Flat keys:

```json
{
  "firstName": "Sarah",
  "lastName": "Johns",
  "emailAddress": "sarah.johns@akqa.com"
}
```

Nested keys (`customer.firstName`, `customer.lastName`, `customer.emailAddress`) plus defaults:

```json
{
  "customer": {
    "firstName": "Sarah",
    "lastName": "Johns",
    "emailAddress": "sarah.johns@akqa.com"
  },
  "created": "2026-09-14 10:00:00",
  "submission": {
    "referenceId": "Contact-123"
  }
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
