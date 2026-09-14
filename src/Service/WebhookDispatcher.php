<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Service;

use Akqa\SilverStripe\UserFormsWebhooks\Model\EditableWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Model\SubmittedWebhook;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Evaluates configured webhooks and posts matching submissions.
 */
class WebhookDispatcher
{
    use Extensible;
    use Injectable;

    private ClientInterface $client;

    private WebhookPayloadBuilder $payloadBuilder;

    private ?LoggerInterface $logger;

    public function __construct(
        ?ClientInterface $client = null,
        ?WebhookPayloadBuilder $payloadBuilder = null,
        ?LoggerInterface $logger = null
    ) {
        $this->client = $client ?: Injector::inst()->get(ClientInterface::class);
        $this->payloadBuilder = $payloadBuilder ?: Injector::inst()->get(WebhookPayloadBuilder::class);
        $this->logger = $logger;
    }

    /**
     * @param array $data Original form submission data used for condition matching
     */
    public function dispatch(SubmittedForm $submittedForm, array $data = []): void
    {
        $form = $submittedForm->Parent();
        if (!$form || !$form->hasMethod('Webhooks') || !$form->hasField('EnableWebhooks')) {
            return;
        }

        if (!$form->EnableWebhooks) {
            return;
        }

        $webhooks = $form->Webhooks()->filter('Enabled', true);
        if (!$webhooks->count()) {
            return;
        }

        $formFields = $form->hasMethod('Fields') ? $form->Fields() : null;
        $payload = $this->payloadBuilder->build($submittedForm, $formFields);
        $this->extend('updateWebhookPayload', $payload, $submittedForm, $data, $form);

        foreach ($webhooks as $webhook) {
            /** @var EditableWebhook $webhook */
            if (!$webhook->canSend($data)) {
                continue;
            }

            $webhookPayload = $this->payloadBuilder->applyDefaultFields(
                $payload,
                $webhook,
                $submittedForm
            );
            $this->extend(
                'updateWebhookPayloadForWebhook',
                $webhookPayload,
                $webhook,
                $submittedForm,
                $data,
                $form
            );

            $this->sendWebhook($webhook, $submittedForm, $webhookPayload, $data);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array $data
     */
    protected function sendWebhook(
        EditableWebhook $webhook,
        SubmittedForm $submittedForm,
        array $payload,
        array $data
    ): void {
        $headers = $webhook->getHeaderMap();
        $endpointURL = $webhook->getResolvedEndpointURL();
        $requestBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($requestBody === false) {
            $requestBody = '{}';
        }

        $options = [
            'headers' => $headers,
            'body' => $requestBody,
            'http_errors' => false,
            'timeout' => 30,
        ];

        $this->extend('updateWebhookRequestOptions', $options, $webhook, $submittedForm, $payload, $data);
        $webhook->extend('updateWebhookRequestOptions', $options, $submittedForm, $payload, $data);

        $result = SubmittedWebhook::create();
        $result->WebhookID = $webhook->ID;
        $result->SubmittedFormID = $submittedForm->ID;
        $result->WebhookTitle = $webhook->getTitle();
        $result->EndpointURL = $endpointURL;
        $result->RequestBody = $requestBody;

        try {
            $response = $this->client->request('POST', $endpointURL, $options);
            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();

            $result->StatusCode = $statusCode;
            $result->ResponseBody = $responseBody;
            $result->Success = $statusCode >= 200 && $statusCode < 300;
        } catch (RequestException $exception) {
            $result->Success = false;
            $result->ErrorMessage = $exception->getMessage();
            if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                $result->StatusCode = $response->getStatusCode();
                $result->ResponseBody = (string) $response->getBody();
            }
            $this->logException($exception, $webhook, $submittedForm);
        } catch (GuzzleException | Exception $exception) {
            $result->Success = false;
            $result->ErrorMessage = $exception->getMessage();
            $this->logException($exception, $webhook, $submittedForm);
        }

        $this->extend('updateSubmittedWebhook', $result, $webhook, $submittedForm, $payload);
        $result->write();
    }

    protected function logException(
        Exception $exception,
        EditableWebhook $webhook,
        SubmittedForm $submittedForm
    ): void {
        $logger = $this->logger ?: Injector::inst()->get(LoggerInterface::class);
        $logger->error(sprintf(
            'Webhook "%s" failed for submission %d: %s',
            $webhook->getTitle(),
            $submittedForm->ID,
            $exception->getMessage()
        ), ['exception' => $exception]);
    }
}
