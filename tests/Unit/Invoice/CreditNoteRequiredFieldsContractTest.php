<?php

namespace Tests\Unit\Invoice;

use PHPUnit\Framework\TestCase;

class CreditNoteRequiredFieldsContractTest extends TestCase
{
    public function test_credit_note_sets_the_required_issue_timestamp(): void
    {
        $service = file_get_contents(__DIR__.'/../../../Modules/Invoice/app/Services/CreateCreditNote/CreateCreditNoteService.php');

        $this->assertStringContainsString("'issued_at' => now()", $service);
    }
}
