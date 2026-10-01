<?php
return [
    "printer_connection_types" => [
        "tcp" => "Network Thermal Printer (TCP/IP)",
        "spooler" => "USB / System Printer (Spooler)",
        "usb_raw" => "USB Raw ESC/POS Printer (Advanced)",
        "bluetooth" => "Bluetooth Thermal Printer"
    ],

    "printer_provider_types" => [
        "windows_agent" => "Windows Agent",
        "ubuntu_agent" => "Ubuntu Agent",
        "android_app" => "Android App",
    ],

    "printer_paper_sizes" => [
        "58mm" => "58mm",
        "80mm" => "80mm",
    ],

    "printer_spooler_orientations" => [
        "portrait" => "Portrait",
        "landscape" => "Landscape"
    ],

    "printer_usb_raw_endpoints" => [
        "0x01" => "Out"
    ],

    "print_job_statuses" => [
        "pending" => "Pending",
        "success" => "Success",
        "failed" => "Failed",
    ],

    "printer_spooler_color_modes" => [
        "color" => "Color",
        "mono" => "Mono",
    ],

    "printer_spooler_color_sides" => [
        "one-sided" => "One-sided",
        "two-sided-long-edge" => "Tow sided long edge",
        "two-sided-short-edge" => "Tow sided short edge",
    ],

    "print_content_types" => [
        "invoice" => "Invoice",
        "bill" => "Bill",
        "kitchen" => "Kitchen",
        "waiter" => "Waiter",
        "delivery" => "Delivery",
    ]
];
