<?php

namespace App\Exports;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AssetsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private Builder|Relation|null $query = null) {}

    public function query(): Builder|Relation
    {
        return $this->query
            ?? Asset::query()->with(['category', 'status', 'location', 'company', 'currentUser']);
    }

    public function headings(): array
    {
        return [
            'Asset ID', 'Company', 'Category', 'Brand', 'Model', 'Serial Number',
            'Hostname', 'Processor', 'Storage', 'RAM', 'OS Version', 'Location',
            'Purchase Year', 'Vendor', 'Purchase Price', 'Status', 'Current User',
            'MAC Address', 'Static IP', 'Software License', 'Warranty Until',
            'Deploy Date', 'Repair Notes', 'Retire Date', 'QR Code', 'Barcode',
            'Created At', 'Updated At',
        ];
    }

    public function map($asset): array
    {
        return [
            $asset->asset_id,
            $asset->company?->name ?? '',
            $asset->category?->name ?? '',
            $asset->brand,
            $asset->model,
            $asset->serial_number,
            $asset->hostname,
            $asset->processor,
            $asset->storage,
            $asset->ram,
            $asset->os_version,
            $asset->location?->name ?? '',
            $asset->purchase_year,
            $asset->vendor,
            $asset->purchase_price,
            $asset->status?->name ?? '',
            $asset->currentUser?->name ?? '',
            $asset->mac_address,
            $asset->static_ip,
            $asset->software_license,
            $asset->warranty_until?->format('Y-m-d') ?? '',
            $asset->deploy_date?->format('Y-m-d') ?? '',
            $asset->repair_notes,
            $asset->retire_date?->format('Y-m-d') ?? '',
            $asset->qr_code,
            $asset->barcode,
            $asset->created_at?->format('Y-m-d H:i:s') ?? '',
            $asset->updated_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }
}
