<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\UserFormsWebhooks\Model;

use LogicException;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\UserForms\Model\EditableFormField;

/**
 * Condition that determines whether a webhook should fire for a submission.
 *
 * @property int $ConditionFieldID
 * @property string $ConditionOption
 * @property string $ConditionValue
 * @property int $ParentID
 * @method EditableFormField ConditionField()
 * @method EditableWebhook Parent()
 */
class WebhookCondition extends DataObject
{
    /**
     * @config
     * @var array<string, string>
     */
    private static $condition_options = [
        'IsBlank' => 'Is blank',
        'IsNotBlank' => 'Is not blank',
        'Equals' => 'Equals',
        'NotEquals' => "Doesn't equal",
        'ValueLessThan' => 'Less than',
        'ValueLessThanEqual' => 'Less than or equal',
        'ValueGreaterThan' => 'Greater than',
        'ValueGreaterThanEqual' => 'Greater than or equal',
        'Includes' => 'Includes',
    ];

    private static $table_name = 'UserFormsWebhookCondition';

    private static $db = [
        'ConditionOption' => 'Enum("IsBlank,IsNotBlank,Equals,NotEquals,ValueLessThan,ValueLessThanEqual,ValueGreaterThan,ValueGreaterThanEqual,Includes")',
        'ConditionValue' => 'Varchar',
    ];

    private static $has_one = [
        'Parent' => EditableWebhook::class,
        'ConditionField' => EditableFormField::class,
    ];

    /**
     * @param array $data
     */
    public function matches(array $data): bool
    {
        $fieldName = $this->ConditionField()->Name;
        $fieldValue = $data[$fieldName] ?? null;
        $conditionValue = $this->ConditionValue;

        switch ($this->ConditionOption) {
            case 'IsBlank':
                return empty($fieldValue);
            case 'IsNotBlank':
                return !empty($fieldValue);
            case 'ValueLessThan':
                return $fieldValue < $conditionValue;
            case 'ValueLessThanEqual':
                return $fieldValue <= $conditionValue;
            case 'ValueGreaterThan':
                return $fieldValue > $conditionValue;
            case 'ValueGreaterThanEqual':
                return $fieldValue >= $conditionValue;
            case 'NotEquals':
            case 'Equals':
                $result = is_array($fieldValue)
                    ? in_array($conditionValue, $fieldValue, false)
                    : $fieldValue == $conditionValue;

                return $this->ConditionOption === 'NotEquals' ? !$result : $result;
            case 'Includes':
                return is_array($fieldValue)
                    ? in_array($conditionValue, $fieldValue, false)
                    : stripos((string) $fieldValue, (string) $conditionValue) !== false;
            default:
                throw new LogicException("Unhandled rule {$this->ConditionOption}");
        }
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
        if (isset($args[1]['Parent'])) {
            return $args[1]['Parent'];
        }

        if (Controller::has_curr() && Controller::curr() instanceof CMSMain) {
            return Controller::curr()->currentRecord();
        }

        return null;
    }

    public function canView($member = null): bool
    {
        return (bool) $this->Parent()->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return (bool) $this->Parent()->canEdit($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canEdit($member);
    }
}
