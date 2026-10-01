<?php

/*
|--------------------------------------------------------------------------
| Input limits
|--------------------------------------------------------------------------
|
| Upper bounds for user-supplied values, in one place so they can be tuned per
| deployment without hunting through form requests.
|
| These are SANITY limits, not business rules. They exist to stop absurd or
| hostile input — a ₹999,999,999,999 payment, an order for 100,000 biryanis, a
| 5 MB "notes" field — from reaching the database. They are set deliberately
| generous so a legitimate large transaction is never blocked; anything that
| needs a tighter bound (a plan's real spend cap, a table's real capacity) is
| business logic and belongs in the service layer, not here.
|
| Raising a limit is safe. Lowering one can reject real data, so check the
| existing range in production first.
|
*/

return [

    'money' => [
        // Single transaction ceiling. A restaurant bill, refund or invoice line.
        'max' => (float) env('VALIDATION_MAX_AMOUNT', 10_000_000),
        // Currency amounts are stored to 3 dp in this platform (see Order totals).
        'decimals' => 3,
    ],

    'quantity' => [
        // Line-item quantity on an order, purchase or stock movement.
        'max' => (int) env('VALIDATION_MAX_QUANTITY', 10_000),
        // Fractional quantities are legitimate for weighed goods (0.25 kg).
        'decimals' => 3,
    ],

    'percent' => [
        'min' => 0,
        'max' => 100,
    ],

    'text' => [
        // Short identifiers: names, codes, labels.
        'name' => 100,
        'title' => 150,
        // Free text.
        'remark' => 500,
        'address' => 500,
        'description' => 1_000,
        'note' => 2_000,
        'message' => 3_000,
    ],

    'counts' => [
        // Bulk operation batch size — bounds both payload size and blast radius.
        'bulk_ids' => (int) env('VALIDATION_MAX_BULK_IDS', 100),
        // Line items in a single order or purchase.
        'line_items' => (int) env('VALIDATION_MAX_LINE_ITEMS', 200),
        'table_capacity' => 100,
        'printer_copies' => 10,
    ],
];
