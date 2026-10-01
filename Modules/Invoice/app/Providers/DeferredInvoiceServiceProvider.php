<?php

namespace Modules\Invoice\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Invoice\Services\CreateCreditNote\CreateCreditNoteService;
use Modules\Invoice\Services\CreateCreditNote\CreateCreditNoteServiceInterface;
use Modules\Invoice\Services\CreateInvoice\CreateInvoiceService;
use Modules\Invoice\Services\CreateInvoice\CreateInvoiceServiceInterface;
use Modules\Invoice\Services\DiscountBuilder\DiscountBuilderService;
use Modules\Invoice\Services\DiscountBuilder\DiscountBuilderServiceInterface;
use Modules\Invoice\Services\Invoice\InvoiceService;
use Modules\Invoice\Services\Invoice\InvoiceServiceInterface;
use Modules\Invoice\Services\InvoiceLineBuilder\InvoiceLineBuilderService;
use Modules\Invoice\Services\InvoiceLineBuilder\InvoiceLineBuilderServiceInterface;
use Modules\Invoice\Services\InvoiceNumberGenerator\InvoiceNumberGeneratorService;
use Modules\Invoice\Services\InvoiceNumberGenerator\InvoiceNumberGeneratorServiceInterface;
use Modules\Invoice\Services\InvoicePartyBuilder\InvoicePartyBuilderService;
use Modules\Invoice\Services\InvoicePartyBuilder\InvoicePartyBuilderServiceInterface;
use Modules\Invoice\Services\InvoicePDF\InvoicePDFService;
use Modules\Invoice\Services\InvoicePDF\InvoicePDFServiceInterface;
use Modules\Invoice\Services\InvoiceTotalsCalculator\InvoiceTotalsCalculatorService;
use Modules\Invoice\Services\InvoiceTotalsCalculator\InvoiceTotalsCalculatorServiceInterface;
use Modules\Invoice\Services\PaymentAllocation\PaymentAllocationService;
use Modules\Invoice\Services\PaymentAllocation\PaymentAllocationServiceInterface;
use Modules\Invoice\Services\TaxBuilder\TaxBuilderService;
use Modules\Invoice\Services\TaxBuilder\TaxBuilderServiceInterface;

class DeferredInvoiceServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: CreateInvoiceServiceInterface::class,
            concrete: fn($app) => $app->make(CreateInvoiceService::class)
        );

        $this->app->singleton(
            abstract: InvoiceNumberGeneratorServiceInterface::class,
            concrete: fn($app) => $app->make(InvoiceNumberGeneratorService::class)
        );

        $this->app->singleton(
            abstract: InvoicePartyBuilderServiceInterface::class,
            concrete: fn($app) => $app->make(InvoicePartyBuilderService::class)
        );

        $this->app->singleton(
            abstract: InvoiceLineBuilderServiceInterface::class,
            concrete: fn($app) => $app->make(InvoiceLineBuilderService::class)
        );

        $this->app->singleton(
            abstract: InvoiceTotalsCalculatorServiceInterface::class,
            concrete: fn($app) => $app->make(InvoiceTotalsCalculatorService::class)
        );

        $this->app->singleton(
            abstract: TaxBuilderServiceInterface::class,
            concrete: fn($app) => $app->make(TaxBuilderService::class)
        );

        $this->app->singleton(
            abstract: DiscountBuilderServiceInterface::class,
            concrete: fn($app) => $app->make(DiscountBuilderService::class)
        );

        $this->app->singleton(
            abstract: CreateCreditNoteServiceInterface::class,
            concrete: fn($app) => $app->make(CreateCreditNoteService::class)
        );

        $this->app->singleton(
            abstract: PaymentAllocationServiceInterface::class,
            concrete: fn($app) => $app->make(PaymentAllocationService::class)
        );

        $this->app->singleton(
            abstract: InvoiceServiceInterface::class,
            concrete: fn($app) => $app->make(InvoiceService::class)
        );

        $this->app->singleton(
            abstract: InvoicePDFServiceInterface::class,
            concrete: fn($app) => $app->make(InvoicePDFService::class)
        );

    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            CreateInvoiceServiceInterface::class,
            InvoicePDFServiceInterface::class,
            CreateCreditNoteServiceInterface::class,
            InvoiceNumberGeneratorServiceInterface::class,
            InvoicePartyBuilderServiceInterface::class,
            InvoiceLineBuilderServiceInterface::class,
            InvoiceTotalsCalculatorServiceInterface::class,
            TaxBuilderServiceInterface::class,
            PaymentAllocationServiceInterface::class,
            InvoiceServiceInterface::class
        ];
    }
}
