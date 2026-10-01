<?php

namespace Modules\Printer\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SavePrintAgentRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(["name" => "required|max:255"]),
            ...$this->getBranchRule(),
            "is_active" => "required|boolean",
            "agent_id" => "required|string|min:5|max:50",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "printer::attributes.print_agents";
    }
}
