{{--
    The asset block, shared by every document type.

    Included rather than repeated in each template: the same eight rows were
    copied across FAT, BAST and generic, and copies drift - one template
    would gain a row the others never got. Anything genuinely type-specific
    stays in the type's own template.

    Pass $showAssignment as true for the types that name the current holder
    (FAT, generic); BAST leaves it out.
--}}
@php
    $asset = $document['asset'];
    $spec = collect([
        $asset['processor'] ?? null,
        $asset['ram'] ?? null,
        $asset['storage'] ?? null,
        $asset['os_version'] ?? null,
    ])->filter()->implode(' / ') ?: '-';
    $brandModel = trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? '')) ?: '-';
@endphp
<table>
    <tr><th colspan="2">Informasi Aset</th></tr>
    <tr><td class="label">Asset ID</td><td><strong>{{ $asset['asset_id'] }}</strong></td></tr>
    <tr><td class="label">Kategori</td><td>{{ $asset['category'] ?? '-' }}</td></tr>
    <tr><td class="label">Merk / Model</td><td>{{ $brandModel }}</td></tr>
    <tr><td class="label">Serial Number</td><td>{{ $asset['serial_number'] ?? '-' }}</td></tr>
    <tr><td class="label">Hostname</td><td>{{ $asset['hostname'] ?? '-' }}</td></tr>
    <tr><td class="label">Spesifikasi</td><td>{{ $spec }}</td></tr>
    @if(!empty($showAssignment))
        <tr><td class="label">Lokasi</td><td>{{ $document['location'] ?? '-' }}</td></tr>
        <tr><td class="label">Pengguna Saat Ini</td><td>{{ $document['current_user'] ?? '-' }}</td></tr>
    @endif
</table>
