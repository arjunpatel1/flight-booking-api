<?php

namespace Modules\Option\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum OptionType: string
{
    use EnumArrayable, EnumTranslatable;

    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case MultipleSelect = 'multiple_select';
    case Checkbox = 'checkbox';
    case Radio = 'radio';
    case Date = 'date';
    case Time = 'time';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "option::enums.option_types";
    }

    /**
     * Determine if option type is field
     *
     * @return bool
     */
    public function isFieldType(): bool
    {
        return in_array($this, [
            OptionType::Text,
            OptionType::Textarea,
            OptionType::Date,
            OptionType::Time,
        ]);
    }
}
