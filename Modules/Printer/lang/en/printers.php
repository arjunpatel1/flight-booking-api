<?php

return [
    "printers" => "Printers",
    "printer" => "Printer",
    "print_jobs" => "Print Jobs",
    "print_job" => "Print Job",
    "printer_assignments" => "Printer Assignments",

    "actions" => [
        "test_print" => "Test Print",
    ],

    "messages" => [
        "test_print_queued" => "Test print job queued.",
    ],

    "table" => [
        "name" => "Name",
        "connection_type" => "Connection Type",
        "provider_type" => "Print Provider",
    ],

    "form" => [
        "cards" => [
            "printer_information" => "Printer Information",
            "connection_options" => "Connection Options",
        ],
        "no_configuration_available" => 'No configuration available for the selected type'
    ],

    "filters" => [
        "connection_type" => "Connection Type",
        "provider_type" => "Print Provider",
    ],

    "assignments" => [
        "sections" => [
            "printer_master" => "Printer Master",
            "print_type_mapping" => "Print Type Mapping",
            "user_role_mapping" => "User / Role Printer Mapping",
            "default_fallback" => "Default Fallback Printer",
        ],
        "labels" => [
            "default_printer" => "Default Printer",
            "print_type" => "Print Type",
            "printer" => "Printer",
            "user" => "User",
            "role" => "Role",
            "user_mappings" => "User Mappings",
            "role_mappings" => "Role Mappings",
        ],
        "actions" => [
            "add_user_mapping" => "Add User Mapping",
            "add_role_mapping" => "Add Role Mapping",
        ],
        "messages" => [
            "select_branch" => "Select a branch to manage printer assignments.",
        ],
    ],

    "printer_connection_types_description" => [
        "tcp" => "Thermal printer connected via network socket (recommended for kitchens)",
        "spooler" => "Printer connected via OS driver (Windows/macOS/Linux)",
        "usb_raw" => "Direct USB connection without OS driver (advanced use only)",
        "bluetooth" => "Thermal printer connected through an RFCOMM Bluetooth serial device"
    ]
];
