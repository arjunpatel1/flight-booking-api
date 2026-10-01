<?php

return [
    "loyalty_transactions" => "Loyalty Transactions",
    "loyalty_transaction" => "Loyalty Transaction",

    "table" => [
        "customer" => "Customer",
        "type" => "Type",
        "description" => "Description",
        "points" => "Points",
        "amount" => "Amount",
    ],

    "filters" => [
        "customer" => "Customer",
        "type" => "Type",
    ],

    "type_descriptions" => [
        "default" => [
            "earn" => "Earned :points points for Order #:order_id",
            "redeem" => "Redeemed :points points on Order #:order_id",
            "adjust" => "Manual adjustment of :points points",
            "expire" => "Expired :points points due to inactivity",
            "bonus" => "Bonus :points points from promotion"
        ]
    ]
];
