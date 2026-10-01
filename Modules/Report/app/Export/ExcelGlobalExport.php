<?php

namespace Modules\Report\Export;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ExcelGlobalExport implements FromCollection, WithHeadings
{
    use Exportable;
    
    /**
     * Create a new instance of the per school sheet.
     *
     * @return void
     */
    public function __construct(protected array $headings, protected Collection $collection)
    {
    }


    /** @inheritDoc */
    public function headings(): array
    {
        return $this->headings;
    }

    /** @inheritDoc */
    public function collection(): Collection
    {
        return $this->collection;
    }
}
