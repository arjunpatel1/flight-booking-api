<?php

namespace Modules\Printer\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Models\Printer;

class PrinterDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Branch::query()
            ->select('id')
            ->get()
            ->each(fn(Branch $branch) => $this->seedBranchPrinters($branch));
    }

    private function seedBranchPrinters(Branch $branch): void
    {
        foreach ($this->presets() as $preset) {
            Printer::query()->withoutGlobalActive()->updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'name->en' => $preset['name']['en'],
                ],
                [
                    'name' => $preset['name'],
                    'connection_type' => $preset['connection_type'],
                    'options' => $preset['options'],
                    'is_active' => false,
                ]
            );
        }
    }

    private function presets(): array
    {
        return [
            [
                'name' => ['en' => 'POS-80 USB / Windows Queue'],
                'connection_type' => PrinterConnectionType::Spooler,
                'options' => $this->spoolerOptions('POS-80', 'Generic 80mm ESC/POS USB thermal printer'),
            ],
            [
                'name' => ['en' => 'TVS RP2330 USB / Windows Queue'],
                'connection_type' => PrinterConnectionType::Spooler,
                'options' => $this->spoolerOptions('TVS_RP2330', 'TVS RP2330 receipt printer for bill or waiter slips'),
            ],
            [
                'name' => ['en' => 'TVS RP3230 WiFi'],
                'connection_type' => PrinterConnectionType::Tcp,
                'options' => $this->tcpOptions('192.168.1.240', 'TVS RP3230 network thermal printer'),
            ],
            [
                'name' => ['en' => 'TVS WiFi 80mm'],
                'connection_type' => PrinterConnectionType::Tcp,
                'options' => $this->tcpOptions('192.168.1.241', 'TVS WiFi ESC/POS printer'),
            ],
            [
                'name' => ['en' => 'PeriPeri USB / Windows Queue'],
                'connection_type' => PrinterConnectionType::Spooler,
                'options' => $this->spoolerOptions('Periperi', 'PeriPeri 80mm USB thermal printer'),
            ],
            [
                'name' => ['en' => 'PeriPeri WiFi'],
                'connection_type' => PrinterConnectionType::Tcp,
                'options' => $this->tcpOptions('192.168.1.242', 'PeriPeri WiFi ESC/POS printer'),
            ],
            [
                'name' => ['en' => 'PeriPeri ZY803 USB / Windows Queue'],
                'connection_type' => PrinterConnectionType::Spooler,
                'options' => $this->spoolerOptions('ZY803', 'PeriPeri ZY803 thermal printer'),
            ],
            [
                'name' => ['en' => 'Posiflow KPC307-UEWB USB / Windows Queue'],
                'connection_type' => PrinterConnectionType::Spooler,
                'options' => $this->spoolerOptions('KPC307-UEWB', 'Posiflow KPC307-UEWB thermal printer'),
            ],
            [
                'name' => ['en' => 'Posiflow KPC307-UEWB WiFi'],
                'connection_type' => PrinterConnectionType::Tcp,
                'options' => $this->tcpOptions('192.168.1.243', 'Posiflow KPC307-UEWB network printer'),
            ],
            [
                'name' => ['en' => 'Epson TM Series WiFi'],
                'connection_type' => PrinterConnectionType::Tcp,
                'options' => $this->tcpOptions('192.168.1.244', 'Epson TM-T/TM-m network thermal printer'),
            ],
            [
                'name' => ['en' => 'XPrinter WiFi'],
                'connection_type' => PrinterConnectionType::Tcp,
                'options' => $this->tcpOptions('192.168.1.245', 'XPrinter 58mm/80mm network thermal printer'),
            ],
            [
                'name' => ['en' => 'Generic Bluetooth COM Printer'],
                'connection_type' => PrinterConnectionType::Bluetooth,
                'options' => $this->bluetoothOptions('COM5', 'Bluetooth ESC/POS printer paired as a Windows COM port'),
            ],
        ];
    }

    private function spoolerOptions(string $queueName, string $description): array
    {
        return [
            'agent_id' => null,
            'spooler_name' => $queueName,
            'paper_size' => '80mm',
            'copies' => 1,
            'timeout_ms' => 5000,
            'raw' => true,
            'fallback_text_mode' => false,
            'description' => $description,
            'setup_notes' => 'Install/pair this printer in Windows, then set spooler_name exactly equal to the Windows printer queue name.',
        ];
    }

    private function tcpOptions(string $host, string $description): array
    {
        return [
            'agent_id' => null,
            'host' => $host,
            'port' => 9100,
            'paper_size' => '80mm',
            'copies' => 1,
            'timeout_ms' => 3000,
            'retries' => 1,
            'cut_paper' => true,
            'beep' => false,
            'open_cash_drawer' => false,
            'description' => $description,
            'setup_notes' => 'Replace host with the printer IP from WiFi discovery. Port 9100 is the standard ESC/POS raw port.',
        ];
    }

    private function bluetoothOptions(string $devicePath, string $description): array
    {
        return [
            'agent_id' => null,
            'device_path' => $devicePath,
            'mac_address' => null,
            'channel' => 1,
            'paper_size' => '80mm',
            'copies' => 1,
            'timeout_ms' => 8000,
            'cut_paper' => true,
            'beep' => false,
            'description' => $description,
            'setup_notes' => 'Pair the Bluetooth printer in Windows first. Use its Windows queue name or COM port from Device Manager.',
        ];
    }
}
