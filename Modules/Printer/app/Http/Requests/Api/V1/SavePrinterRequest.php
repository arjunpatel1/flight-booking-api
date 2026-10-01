<?php

namespace Modules\Printer\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Illuminate\Database\Query\Builder;
use Modules\Core\Http\Requests\Request;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Enum\PrinterProviderType;
use Modules\Printer\Enum\PrinterSpoolerColorMode;
use Modules\Printer\Enum\PrinterSpoolerOrientation;
use Modules\Printer\Enum\PrinterSpoolerSide;
use Modules\Printer\Enum\PrinterUsbRawEndpoint;

class SavePrinterRequest extends Request
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'provider_type' => $this->input('provider_type', PrinterProviderType::WindowsAgent->value),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => "required|string|max:255",
            ]),
            ...$this->getBranchRule(),
            "connection_type" => ["required", "string", Rule::enum(PrinterConnectionType::class)],
            "provider_type" => ["required", "string", Rule::enum(PrinterProviderType::class)],
            "is_active" => "required|boolean",
            'options' => "required|array",
            'options.agent_id' => [
                'nullable',
                'string',
                'max:50',
                Rule::exists('print_agents', 'agent_id')->where(function (Builder $query) {
                    $branchId = $this->user()?->assignedToBranch()
                        ? $this->user()->branch_id
                        : $this->input('branch_id');

                    return $query
                        ->where('branch_id', $branchId)
                        ->where('is_active', true)
                        ->where('platform', $this->providerPlatform());
                }),
            ],
            'options.paper_size' => ['nullable', Rule::enum(PrinterPaperSize::class)],
            ...$this->printerTypeRules(),
        ];
    }

    /**
     * Printer type rules
     *
     * @return array
     */
    protected function printerTypeRules(): array
    {
        return match ($this->input('connection_type')) {
            PrinterConnectionType::Tcp->value => [
                'options.host' => "required|max:255",
                'options.port' => "required|integer|min:1|max:65535",
                'options.copies' => "nullable|integer|min:1|max:5",
                'options.timeout_ms' => 'nullable|integer|min:3000|max:30000',
                'options.retries' => 'nullable|min:0|max:5',
                'options.cut_paper' => "required|boolean",
                'options.beep' => "required|boolean",
                'options.open_cash_drawer' => "required|boolean",
            ],
            PrinterConnectionType::Spooler->value => [
                'options.spooler_name' => "required|string|max:255",
                'options.host' => "nullable|max:255",
                'options.copies' => "nullable|integer|min:1|max:5",
                'options.timeout_ms' => 'nullable|integer|min:3000|max:30000',
                'options.sides' => ['nullable', Rule::enum(PrinterSpoolerSide::class)],
                'options.color_mode' => ['nullable', Rule::enum(PrinterSpoolerColorMode::class)],
                'options.raw' => "required|boolean",
                'options.orientation' => ['nullable', Rule::enum(PrinterSpoolerOrientation::class)],
                'options.margins' => "required|array",
                'options.margins.top' => "nullable|integer|min:0|max:30",
                'options.margins.right' => "nullable|integer|min:0|max:30",
                'options.margins.bottom' => "nullable|integer|min:0|max:30",
                'options.margins.left' => "nullable|integer|min:0|max:30",
                'options.fallback_text_mode' => "required|boolean",
            ],
            PrinterConnectionType::UsbRaw->value => [
                'options.device_path' => "nullable|string|max:255|regex:/^\\/dev\\/(usb\\/lp[0-9]+|lp[0-9]+)$/",
                'options.vendor_id' => "required_without:options.device_path|nullable|regex:/^0x[0-9a-fA-F]+$/",
                'options.product_id' => "required_without:options.device_path|nullable|regex:/^0x[0-9a-fA-F]+$/",
                'options.endpoint' => ['nullable', Rule::enum(PrinterUsbRawEndpoint::class)],
                'options.interface_index' => "nullable|integer|min:0",
                'options.detach_kernel_driver' => "required|boolean",
                'options.copies' => "nullable|integer|min:1|max:5",
                'options.timeout_ms' => "nullable|integer|min:3000|max:30000",
            ],
            PrinterConnectionType::Bluetooth->value => [
                'options.device_path' => [
                    Rule::requiredIf($this->input('provider_type') !== PrinterProviderType::AndroidApp->value),
                    'nullable',
                    'string',
                    'max:255',
                    'regex:/^\\/dev\\/rfcomm[0-9]+$/',
                ],
                'options.mac_address' => [
                    Rule::requiredIf($this->input('provider_type') === PrinterProviderType::AndroidApp->value),
                    'nullable',
                    'string',
                    'max:17',
                    'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/',
                ],
                'options.channel' => "nullable|integer|min:1|max:30",
                'options.copies' => "nullable|integer|min:1|max:5",
                'options.timeout_ms' => "nullable|integer|min:3000|max:30000",
                'options.cut_paper' => "nullable|boolean",
                'options.beep' => "nullable|boolean",
            ],
            default => [],
        };
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "printer::attributes.printers";
    }

    private function providerPlatform(): string
    {
        return match ($this->input('provider_type')) {
            PrinterProviderType::AndroidApp->value => 'android',
            PrinterProviderType::UbuntuAgent->value => 'linux',
            default => 'windows',
        };
    }
}
