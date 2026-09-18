<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Forms\GridField;

use Akqa\SilverStripe\UserFormsWebhooks\Model\SubmittedWebhook;
use Akqa\SilverStripe\UserFormsWebhooks\Service\WebhookDispatcher;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionMenuItem;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;

/**
 * GridField action that manually re-fires a webhook for a submission result.
 */
class GridFieldTriggerWebhookAction implements
    GridField_ColumnProvider,
    GridField_ActionProvider,
    GridField_ActionMenuItem
{
    public function getTitle($gridField, $record, $columnName): string
    {
        return _t(__CLASS__ . '.TRIGGER', 'Trigger');
    }

    public function getGroup($gridField, $record, $columnName): ?string
    {
        $field = $this->getTriggerAction($gridField, $record, $columnName);

        return $field ? GridField_ActionMenuItem::DEFAULT_GROUP : null;
    }

    public function getExtraData($gridField, $record, $columnName): array
    {
        $field = $this->getTriggerAction($gridField, $record, $columnName);

        return $field ? $field->getAttributes() : [];
    }

    public function augmentColumns($gridField, &$columns): void
    {
        if (!in_array('Actions', $columns ?? [], true)) {
            $columns[] = 'Actions';
        }
    }

    public function getColumnAttributes($gridField, $record, $columnName): array
    {
        return ['class' => 'grid-field__col-compact'];
    }

    public function getColumnMetadata($gridField, $columnName): array
    {
        if ($columnName === 'Actions') {
            return ['title' => ''];
        }

        return [];
    }

    public function getColumnsHandled($gridField): array
    {
        return ['Actions'];
    }

    public function getActions($gridField): array
    {
        return ['triggerwebhook'];
    }

    /**
     * @param DataObject $record
     */
    public function getColumnContent($gridField, $record, $columnName): ?string
    {
        $field = $this->getTriggerAction($gridField, $record, $columnName);
        if (!$field) {
            return null;
        }

        return (string) $field->Field();
    }

    /**
     * @param DataObject $record
     */
    protected function getTriggerAction(
        GridField $gridField,
        $record,
        string $columnName
    ): ?GridField_FormAction {
        if (!($record instanceof SubmittedWebhook) || !$record->canTrigger()) {
            return null;
        }

        $title = $this->getTitle($gridField, $record, $columnName);

        return GridField_FormAction::create(
            $gridField,
            'TriggerWebhook' . $record->ID,
            false,
            'triggerwebhook',
            ['RecordID' => $record->ID]
        )
            ->addExtraClass(
                'action--trigger-webhook btn btn--no-text btn--icon-md '
                . 'font-icon-sync grid-field__icon-action action-menu--handled'
            )
            ->setAttribute('classNames', 'action--trigger-webhook font-icon-sync')
            ->setDescription($title)
            ->setAttribute('aria-label', $title);
    }

    /**
     * @param array $arguments
     * @param array $data
     * @throws ValidationException
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data): void
    {
        if ($actionName !== 'triggerwebhook') {
            return;
        }

        /** @var SubmittedWebhook|null $item */
        $item = $gridField->getList()->byID($arguments['RecordID'] ?? 0);
        if (!$item instanceof SubmittedWebhook) {
            return;
        }

        if (!$item->canTrigger()) {
            throw new ValidationException(_t(
                __CLASS__ . '.PERMISSION_FAILURE',
                'You do not have permission to trigger this webhook.'
            ));
        }

        try {
            /** @var WebhookDispatcher $dispatcher */
            $dispatcher = Injector::inst()->get(WebhookDispatcher::class);
            $result = $dispatcher->redispatch($item);
        } catch (Exception $exception) {
            throw new ValidationException($exception->getMessage());
        }

        $message = $result->Success
            ? _t(
                __CLASS__ . '.TRIGGER_SUCCESS',
                'Webhook "{title}" triggered successfully (HTTP {status}).',
                [
                    'title' => $result->WebhookTitle,
                    'status' => $result->StatusCode,
                ]
            )
            : _t(
                __CLASS__ . '.TRIGGER_FAILED',
                'Webhook "{title}" was triggered but failed (HTTP {status}).',
                [
                    'title' => $result->WebhookTitle,
                    'status' => $result->StatusCode ?: '—',
                ]
            );

        if (Controller::has_curr()) {
            Controller::curr()->getResponse()->addHeader(
                'X-Status',
                rawurlencode($message)
            );
        }
    }
}
