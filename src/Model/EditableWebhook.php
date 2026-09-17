<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Model;

use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Kernel;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\UserForms\Model\UserDefinedForm;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;

/**
 * CMS-managed webhook configuration for a UserDefinedForm.
 *
 * @property string $Title
 * @property string $EndpointURL
 * @property string $EndpointURLDev
 * @property string $EndpointURLTest
 * @property bool $Enabled
 * @property string $CustomRulesCondition
 * @property int $FormID
 * @property string $FormClass
 * @method DataObject Form()
 * @method HasManyList<WebhookHeader> Headers()
 * @method HasManyList<WebhookDefaultField> DefaultFields()
 * @method HasManyList<WebhookCondition> CustomRules()
 */
class EditableWebhook extends DataObject
{
    private static $table_name = 'EditableWebhook';

    private static $singular_name = 'Webhook';

    private static $plural_name = 'Webhooks';

    private static $db = [
        'Title' => 'Varchar(255)',
        'EndpointURL' => 'Varchar(2048)',
        'EndpointURLDev' => 'Varchar(2048)',
        'EndpointURLTest' => 'Varchar(2048)',
        'Enabled' => 'Boolean',
        'CustomRulesCondition' => 'Enum("And,Or","And")',
    ];

    private static $has_one = [
        'Form' => DataObject::class,
    ];

    private static $has_many = [
        'Headers' => WebhookHeader::class,
        'DefaultFields' => WebhookDefaultField::class,
        'CustomRules' => WebhookCondition::class,
    ];

    private static $owns = [
        'Headers',
        'DefaultFields',
        'CustomRules',
    ];

    private static $cascade_deletes = [
        'Headers',
        'DefaultFields',
        'CustomRules',
    ];

    private static $cascade_duplicates = [
        'Headers',
        'DefaultFields',
        'CustomRules',
    ];

    private static $defaults = [
        'Enabled' => true,
        'CustomRulesCondition' => 'And',
    ];

    private static $summary_fields = [
        'Title' => 'Title',
        'EndpointURL' => 'Live endpoint',
        'Enabled.Nice' => 'Enabled',
    ];

    private static $versioned_gridfield_extensions = false;

    public function getTitle(): string
    {
        if ($this->getField('Title')) {
            return (string) $this->getField('Title');
        }

        if ($this->EndpointURL) {
            return (string) $this->EndpointURL;
        }

        return parent::getTitle() ?: 'Webhook';
    }

    /**
     * Resolve the endpoint URL for the current (or given) Silverstripe environment.
     *
     * Uses Director::get_environment_type() values: dev, test, live.
     * Dev/Test fall back to the Live URL when left blank.
     */
    public function getResolvedEndpointURL(?string $environment = null): string
    {
        $environment = $environment ?: Director::get_environment_type();
        $live = trim((string) $this->EndpointURL);

        switch ($environment) {
            case Kernel::DEV:
                $url = trim((string) $this->EndpointURLDev) ?: $live;
                break;
            case Kernel::TEST:
                $url = trim((string) $this->EndpointURLTest) ?: $live;
                break;
            case Kernel::LIVE:
            default:
                $url = $live;
                break;
        }

        $this->extend('updateResolvedEndpointURL', $url, $environment);

        return $url;
    }

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(TabSet::create('Root'));

        if (!$this->getFormParent()) {
            $fields->addFieldToTab(
                'Root.Main',
                LiteralField::create(
                    'UnsavedFormMessage',
                    sprintf(
                        '<p class="alert alert-warning">%s</p>',
                        _t(
                            __CLASS__ . '.UNSAVED_FORM',
                            'Save this form before configuring webhook fields and conditions.'
                        )
                    )
                )
            );
        }

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Title', _t(__CLASS__ . '.TITLE', 'Title')),
            CheckboxField::create(
                'Enabled',
                _t(__CLASS__ . '.ENABLED', 'Enable this webhook')
            ),
            TextField::create(
                'EndpointURL',
                _t(__CLASS__ . '.ENDPOINT_URL_LIVE', 'Live endpoint URL')
            )->setDescription(_t(
                __CLASS__ . '.ENDPOINT_URL_LIVE_DESCRIPTION',
                'Used when SS_ENVIRONMENT_TYPE is live. Also used as the fallback when Dev/Test URLs are blank.'
            )),
            TextField::create(
                'EndpointURLTest',
                _t(__CLASS__ . '.ENDPOINT_URL_TEST', 'Test endpoint URL')
            )->setDescription(_t(
                __CLASS__ . '.ENDPOINT_URL_TEST_DESCRIPTION',
                'Optional. Used when SS_ENVIRONMENT_TYPE is test. Falls back to the Live URL when blank.'
            )),
            TextField::create(
                'EndpointURLDev',
                _t(__CLASS__ . '.ENDPOINT_URL_DEV', 'Dev endpoint URL')
            )->setDescription(_t(
                __CLASS__ . '.ENDPOINT_URL_DEV_DESCRIPTION',
                'Optional. Used when SS_ENVIRONMENT_TYPE is dev. Falls back to the Live URL when blank.'
            )),
        ]);

        $fields->addFieldToTab('Root.Headers', $this->getHeadersGridField());
        $fields->fieldByName('Root.Headers')?->setTitle(_t(__CLASS__ . '.HEADERS_TAB', 'Headers'));

        $fields->addFieldToTab('Root.DefaultFields', $this->getDefaultFieldsGridField());
        $fields->fieldByName('Root.DefaultFields')?->setTitle(
            _t(__CLASS__ . '.DEFAULT_FIELDS_TAB', 'Default Fields')
        );

        $fields->addFieldsToTab('Root.CustomRules', [
            DropdownField::create(
                'CustomRulesCondition',
                _t(__CLASS__ . '.SEND_IF', 'Send condition'),
                [
                    'Or' => _t(
                        'SilverStripe\\UserForms\\Model\\UserDefinedForm.SENDIFOR',
                        'Any conditions are true'
                    ),
                    'And' => _t(
                        'SilverStripe\\UserForms\\Model\\UserDefinedForm.SENDIFAND',
                        'All conditions are true'
                    ),
                ]
            ),
            $this->getRulesGridField(),
        ]);
        $fields->fieldByName('Root.CustomRules')?->setTitle(_t(__CLASS__ . '.CUSTOM_RULES_TAB', 'Custom Rules'));

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    protected function getHeadersGridField(): GridField
    {
        $config = GridFieldConfig::create()
            ->addComponents(
                new GridFieldButtonRow('before'),
                new GridFieldToolbarHeader(),
                new GridFieldAddNewInlineButton(),
                new GridFieldDeleteAction(),
                $columns = new GridFieldEditableColumns()
            );

        $columns->setDisplayFields([
            'Name' => function ($record, $column, $grid) {
                return TextField::create($column, _t(WebhookHeader::class . '.NAME', 'Header name'));
            },
            'Value' => function ($record, $column, $grid) {
                return TextField::create($column, _t(WebhookHeader::class . '.VALUE', 'Header value'));
            },
        ]);

        return GridField::create(
            'Headers',
            _t(__CLASS__ . '.HEADERS', 'Request headers'),
            $this->Headers(),
            $config
        )->setDescription(_t(
            __CLASS__ . '.HEADERS_DESCRIPTION',
            'Optional HTTP headers sent with each webhook request. Content-Type is set to application/json automatically.'
        ));
    }

    protected function getDefaultFieldsGridField(): GridField
    {
        $config = GridFieldConfig::create()
            ->addComponents(
                new GridFieldButtonRow('before'),
                new GridFieldToolbarHeader(),
                new GridFieldAddNewInlineButton(),
                new GridFieldDeleteAction(),
                $columns = new GridFieldEditableColumns()
            );

        $columns->setDisplayFields([
            'Name' => function ($record, $column, $grid) {
                return TextField::create(
                    $column,
                    _t(WebhookDefaultField::class . '.NAME', 'Field name')
                )->setAttribute('placeholder', 'submission.referenceId');
            },
            'Value' => function ($record, $column, $grid) {
                return TextField::create(
                    $column,
                    _t(WebhookDefaultField::class . '.VALUE', 'Value')
                )->setAttribute('placeholder', 'Contact-{{ID}}');
            },
        ]);

        return GridField::create(
            'DefaultFields',
            _t(__CLASS__ . '.DEFAULT_FIELDS', 'Default fields'),
            $this->DefaultFields(),
            $config
        )->setDescription(_t(
            __CLASS__ . '.DEFAULT_FIELDS_DESCRIPTION',
            'Extra JSON fields always included in this webhook payload. Field names support dot and bracket '
            . 'syntax (e.g. submission.referenceId, responses[0].question). Values may include {{ID}} and '
            . '{{Created}} variables.'
        ));
    }

    protected function getRulesGridField(): GridField
    {
        $formFields = $this->getFormParent() ? $this->getFormParent()->Fields() : null;

        $config = GridFieldConfig::create()
            ->addComponents(
                new GridFieldButtonRow('before'),
                new GridFieldToolbarHeader(),
                new GridFieldAddNewInlineButton(),
                new GridFieldDeleteAction(),
                $columns = new GridFieldEditableColumns()
            );

        $columns->setDisplayFields([
            'ConditionFieldID' => function ($record, $column, $grid) use ($formFields) {
                $source = $formFields ? $formFields->map('ID', 'Title') : [];
                return DropdownField::create($column, false, $source);
            },
            'ConditionOption' => function ($record, $column, $grid) {
                $options = WebhookCondition::config()->get('condition_options');
                return DropdownField::create($column, false, $options);
            },
            'ConditionValue' => function ($record, $column, $grid) {
                return TextField::create($column);
            },
        ]);

        return GridField::create(
            'CustomRules',
            _t(__CLASS__ . '.CUSTOM_RULES', 'Custom Rules'),
            $this->CustomRules(),
            $config
        )->setDescription(_t(
            __CLASS__ . '.RULES_DESCRIPTION',
            'This webhook only fires when the custom rules are met. If no rules are defined, it fires for every submission.'
        ));
    }

    /**
     * @return UserDefinedForm|null
     */
    protected function getFormParent()
    {
        if ($this->FormID && $this->FormClass) {
            $formClass = $this->FormClass;
            return $formClass::get()->byID($this->FormID);
        }

        $sessionNamespace = $this->config()->get('session_namespace') ?: CMSMain::class;
        if (!Controller::has_curr()) {
            return null;
        }

        $formID = Controller::curr()->getRequest()->getSession()->get($sessionNamespace . '.currentPage');
        if ($formID) {
            return UserDefinedForm::get()->byID($formID);
        }

        return null;
    }

    /**
     * Determine whether this webhook should fire for the given submission data.
     *
     * @param array $data
     */
    public function canSend(array $data): bool
    {
        if (!$this->Enabled) {
            return false;
        }

        if (!$this->getResolvedEndpointURL()) {
            return false;
        }

        $customRules = $this->CustomRules();
        if (!$customRules->count()) {
            return true;
        }

        $isAnd = $this->CustomRulesCondition === 'And';
        foreach ($customRules as $customRule) {
            $matches = $customRule->matches($data);
            if ($isAnd && !$matches) {
                return false;
            }
            if (!$isAnd && $matches) {
                return true;
            }
        }

        return $isAnd;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaderMap(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        foreach ($this->Headers() as $header) {
            if (!$header->Name) {
                continue;
            }
            $headers[$header->Name] = (string) $header->Value;
        }

        $this->extend('updateWebhookHeaders', $headers);

        return $headers;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        $urlFields = [
            'EndpointURL' => _t(__CLASS__ . '.ENDPOINT_URL_LIVE', 'Live endpoint URL'),
            'EndpointURLTest' => _t(__CLASS__ . '.ENDPOINT_URL_TEST', 'Test endpoint URL'),
            'EndpointURLDev' => _t(__CLASS__ . '.ENDPOINT_URL_DEV', 'Dev endpoint URL'),
        ];

        foreach ($urlFields as $field => $label) {
            $value = trim((string) $this->getField($field));
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                $result->addError(_t(
                    __CLASS__ . '.INVALID_URL_NAMED',
                    'Please enter a valid URL for {label}.',
                    ['label' => $label]
                ));
            }
        }

        return $result;
    }

    public function canCreate($member = null, $context = []): bool
    {
        $parent = $this->getCanCreateContext(func_get_args());
        if ($parent) {
            return (bool) $parent->canEdit($member);
        }

        return parent::canCreate($member);
    }

    /**
     * @param array $args
     * @return SiteTree|null
     */
    protected function getCanCreateContext($args)
    {
        if (isset($args[1]['Form'])) {
            return $args[1]['Form'];
        }

        if (Controller::has_curr() && Controller::curr() instanceof CMSMain) {
            return Controller::curr()->currentRecord();
        }

        return null;
    }

    public function canView($member = null): bool
    {
        if ($form = $this->getFormParent()) {
            return (bool) $form->canView($member);
        }

        return parent::canView($member);
    }

    public function canEdit($member = null): bool
    {
        if ($form = $this->getFormParent()) {
            return (bool) $form->canEdit($member);
        }

        return parent::canEdit($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canEdit($member);
    }
}
