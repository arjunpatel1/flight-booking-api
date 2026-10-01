<?php

namespace Modules\Printer\app\Factories;

use Modules\Printer\Contracts\PrintContentFactoryInterface;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Factories\PrintContents\PrintBillContentFactory;
use Modules\Printer\Factories\PrintContents\PrintDeliveryContentFactory;
use Modules\Printer\Factories\PrintContents\PrintInvoiceContentFactory;
use Modules\Printer\Factories\PrintContents\PrintKitchenContentFactory;
use Modules\Printer\Factories\PrintContents\PrintWaiterContentFactory;

class PrintContentFactory
{
    /**
     * Resolve factory
     *
     * @param PrintContentType $type
     * @return PrintContentFactoryInterface
     */
    public static function resolve(PrintContentType $type): PrintContentFactoryInterface
    {
        return match ($type) {
            PrintContentType::Bill => new PrintBillContentFactory(),
            PrintContentType::Invoice => new PrintInvoiceContentFactory(),
            PrintContentType::Kitchen => new PrintKitchenContentFactory(),
            PrintContentType::Delivery => new PrintDeliveryContentFactory(),
            PrintContentType::Waiter => new PrintWaiterContentFactory(),
        };
    }
}
