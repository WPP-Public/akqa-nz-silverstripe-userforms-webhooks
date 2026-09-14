# Silverstripe UserForms Webhooks

Posts [Silverstripe UserForms](https://github.com/silverstripe/silverstripe-userforms) submissions to configurable HTTP webhooks.

Webhook endpoints, headers, and conditional rules are managed in the CMS. Each fired request is logged against the submission with status code, request body, and response body.

## Requirements

- PHP 8.1+
- Silverstripe CMS 5
- `silverstripe/userforms` ^6
- Guzzle 7

## Installation

```bash
composer require akqa-nz/silverstripe-userforms-webhooks
```

Run `dev/build` after installing.

## CMS usage

1. Open a **User Defined Form** in the CMS.
2. On the **Webhooks** tab, tick **Enable webhooks for this form**.
3. Add one or more webhooks with:
   - Endpoint URL
   - Optional HTTP headers
   - Optional custom rules (same style as email recipient conditions)
4. On each form field, optionally set **Webhook JSON key**. When empty, the field `Name` is converted to lowerCamelCase (e.g. `First_Name` → `firstName`).
5. Open a submission under **Submissions** → **Webhooks** to inspect fired hooks, status codes, and bodies.

### Example payload

```json
{
  "firstName": "Sarah",
  "lastName": "Grant",
  "emailAddress": "sarah.grant@walkerscott.co"
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
