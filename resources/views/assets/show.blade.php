@extends('layouts.app')

@section('title', 'Asset ' . $asset->asset_id)

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Asset {{ $asset->asset_id }}</h1>
    <div>
        @can('update', $asset)
            <a href="{{ route('assets.edit', $asset) }}" class="bg-blue-600 text-white px-4 py-2 rounded">Edit</a>
        @endcan
        @can('archive', $asset)
            <form method="POST" action="{{ route('assets.destroy', $asset) }}" class="inline ml-2" onsubmit="return confirm('Archive this asset?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded">Archive</button>
            </form>
        @endcan
    </div>
</div>

<div id="overview" class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-lg font-semibold mb-4">Information</h2>
        <dl class="grid grid-cols-2 gap-2">
            <dt class="font-medium">Asset ID</dt><dd>{{ $asset->asset_id }}</dd>
            <dt class="font-medium">Company</dt><dd>{{ $asset->company?->name }}</dd>
            <dt class="font-medium">Category</dt><dd>{{ $asset->category?->name }}</dd>
            <dt class="font-medium">Brand/Model</dt><dd>{{ $asset->brand }} {{ $asset->model }}</dd>
            <dt class="font-medium">Serial Number</dt><dd>{{ $asset->serial_number }}</dd>
            <dt class="font-medium">Hostname</dt><dd>{{ $asset->hostname }}</dd>
            <dt class="font-medium">Processor</dt><dd>{{ $asset->processor }}</dd>
            <dt class="font-medium">RAM</dt><dd>{{ $asset->ram }}</dd>
            <dt class="font-medium">Storage</dt><dd>{{ $asset->storage }}</dd>
            <dt class="font-medium">OS</dt><dd>{{ $asset->os_version }}</dd>
            <dt class="font-medium">Location</dt><dd>{{ $asset->location?->name }}</dd>
            <dt class="font-medium">Purchase Year</dt><dd>{{ $asset->purchase_year }}</dd>
            <dt class="font-medium">Vendor</dt><dd>{{ $asset->vendor }}</dd>
            <dt class="font-medium">Status</dt><dd><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $asset->status?->name }}</span></dd>
            <dt class="font-medium">Current User</dt><dd>{{ $asset->currentUser?->name }}</dd>
            <dt class="font-medium">Admin IT PIC</dt><dd>{{ $asset->adminItPic?->name }}</dd>
            <dt class="font-medium">Warranty</dt><dd>{{ $asset->warranty_until }}</dd>
            <dt class="font-medium">Deploy Date</dt><dd>{{ $asset->deploy_date }}</dd>
            <dt class="font-medium">MAC Address</dt><dd>{{ $asset->mac_address }}</dd>
            <dt class="font-medium">Static IP</dt><dd>{{ $asset->static_ip }}</dd>
            <dt class="font-medium">License</dt><dd>{{ $asset->software_license }}</dd>
            <dt class="font-medium">Retire Date</dt><dd>{{ $asset->retire_date }}</dd>
            <dt class="font-medium">QR Code</dt><dd>{{ $asset->qr_code }}</dd>
            <dt class="font-medium">Barcode</dt><dd>{{ $asset->barcode }}</dd>
        </dl>
    </div>
</div>

<div class="mb-6">
    <nav class="flex gap-4 border-b pb-2">
        <a href="#overview" class="text-blue-600">Overview</a>
        <a href="#assignment-history" class="text-gray-500">Assignment History</a>
        <a href="#audit" class="text-gray-500">Audit</a>
        <a href="#documents" class="text-gray-500">Documents</a>
        <a href="#repair-history" class="text-gray-500">Repair History</a>
        <a href="#activity-log" class="text-gray-500">Activity Log</a>
    </nav>
</div>

<div id="assignment-history" class="mb-8">
    <h2 class="text-xl font-semibold mb-4">Assignment History</h2>
    @if($asset->assignments->count() > 0)
    <table class="min-w-full bg-white shadow rounded">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">User</th>
                <th class="text-left py-2 px-4">Assigned</th>
                <th class="text-left py-2 px-4">Returned</th>
                <th class="text-left py-2 px-4">Location</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->assignments as $assignment)
            <tr class="border-b">
                <td class="py-2 px-4">{{ $assignment->user?->name }}</td>
                <td class="py-2 px-4">{{ $assignment->assigned_date }}</td>
                <td class="py-2 px-4">{{ $assignment->returned_date }}</td>
                <td class="py-2 px-4">{{ $assignment->toLocation?->name }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No assignment history.</p>
    @endif
</div>

<div id="audit" class="mb-8">
    <h2 class="text-xl font-semibold mb-4">Audit History</h2>
    @if($asset->audits->count() > 0)
    <table class="min-w-full bg-white shadow rounded">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">Date</th>
                <th class="text-left py-2 px-4">Auditor</th>
                <th class="text-left py-2 px-4">Result</th>
                <th class="text-left py-2 px-4">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->audits as $audit)
            <tr class="border-b">
                <td class="py-2 px-4">{{ $audit->audit_date->format('Y-m-d') }}</td>
                <td class="py-2 px-4">{{ $audit->auditor?->name }}</td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded {{ $audit->result === 'MATCH' ? 'bg-green-200' : 'bg-red-200' }}">{{ $audit->result }}</span></td>
                <td class="py-2 px-4">{{ $audit->notes }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No audit records.</p>
    @endif
</div>

<div id="documents" class="mb-8">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">Documents</h2>
        @can('generateDocument', $asset)
            <div class="flex gap-2">
                <a href="{{ route('documents.generate', $asset) }}" class="bg-green-600 text-white px-3 py-1 rounded text-sm">+ Generate FAT / BAST</a>
                <a href="{{ route('documents.create', $asset) }}" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">+ Add Document</a>
            </div>
        @endcan
    </div>

    @if($asset->documents->count() > 0)
    <table class="min-w-full bg-white shadow rounded">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">Number</th>
                <th class="text-left py-2 px-4">Type</th>
                <th class="text-left py-2 px-4">Title</th>
                <th class="text-left py-2 px-4">Generated</th>
                <th class="text-left py-2 px-4">Status</th>
                <th class="text-left py-2 px-4">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->documents as $document)
            <tr class="border-b">
                <td class="py-2 px-4"><a href="{{ route('documents.preview', $document) }}" class="text-blue-600">{{ $document->document_number }}</a></td>
                <td class="py-2 px-4">{{ $document->document_type }}</td>
                <td class="py-2 px-4">{{ $document->title }}</td>
                <td class="py-2 px-4">{{ $document->generated_date?->format('Y-m-d') }}</td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded {{ $document->status === 'final' ? 'bg-green-200' : 'bg-gray-200' }}">{{ $document->status }}</span></td>
                <td class="py-2 px-4">
                    <form method="POST" action="/assets/{{ $asset->id }}/documents/{{ $document->id }}" class="inline" onsubmit="return confirm('Delete this document?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-600 text-sm">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No documents.</p>
    @endif
</div>

<div id="repair-history" class="mb-8">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">Repair History</h2>
        @can('manageRepair', $asset)
            <a href="{{ route('assets.repair.create', $asset) }}" class="bg-orange-600 text-white px-3 py-1 rounded text-sm">+ Add Repair</a>
        @endcan
    </div>
    @if($asset->repairs->count() > 0)
    <table class="min-w-full bg-white shadow rounded">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">Date</th>
                <th class="text-left py-2 px-4">Problem</th>
                <th class="text-left py-2 px-4">Action</th>
                <th class="text-left py-2 px-4">Vendor</th>
                <th class="text-left py-2 px-4">Cost</th>
                <th class="text-left py-2 px-4">Status</th>
                <th class="text-left py-2 px-4">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->repairs as $repair)
            <tr class="border-b">
                <td class="py-2 px-4">{{ $repair->date }}</td>
                <td class="py-2 px-4">{{ $repair->problem }}</td>
                <td class="py-2 px-4">{{ $repair->action }}</td>
                <td class="py-2 px-4">{{ $repair->vendor }}</td>
                <td class="py-2 px-4">{{ $repair->cost }}</td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $repair->status }}</span></td>
                <td class="py-2 px-4">
                    <form method="POST" action="/assets/{{ $asset->id }}/repair/{{ $repair->id }}" class="inline" onsubmit="return confirm('Delete this repair?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-600 text-sm">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No repair records.</p>
    @endif
</div>

<div id="activity-log" class="mb-8">
    <h2 class="text-xl font-semibold mb-4">Activity Log</h2>
    @if($asset->activityLogs->count() > 0)
    <table class="min-w-full bg-white shadow rounded">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">User</th>
                <th class="text-left py-2 px-4">Action</th>
                <th class="text-left py-2 px-4">Source</th>
                <th class="text-left py-2 px-4">Time</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->activityLogs as $log)
            <tr class="border-b">
                <td class="py-2 px-4">{{ $log->actor?->name }}</td>
                <td class="py-2 px-4">{{ $log->action }}</td>
                <td class="py-2 px-4">{{ $log->source }}</td>
                <td class="py-2 px-4">{{ $log->created_at->diffForHumans() }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No activity logs.</p>
    @endif
</div>

<div id="attachments" class="mb-8">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">Attachments</h2>
        @can('manageAttachments', $asset)
            <form method="POST" action="{{ route('assets.attachments.store', $asset) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                @csrf
                <input type="file" name="file" required accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv" class="border rounded px-3 py-1 text-sm">
                <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">Upload</button>
            </form>
        @endcan
    </div>
    @error('file')
        <p class="text-red-600 text-sm mb-2">{{ $message }}</p>
    @enderror
    @if($asset->attachments->count() > 0)
    <table class="min-w-full bg-white shadow rounded text-sm">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">File</th>
                <th class="text-left py-2 px-4">Type</th>
                <th class="text-left py-2 px-4">Size</th>
                <th class="text-left py-2 px-4">Uploaded By</th>
                <th class="text-left py-2 px-4">Date</th>
                <th class="text-left py-2 px-4">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->attachments as $attachment)
            <tr class="border-b">
                <td class="py-2 px-4"><a href="{{ route('assets.attachments.download', [$asset, $attachment]) }}" class="text-blue-600">{{ $attachment->file_name }}</a></td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $attachment->file_type }}</span></td>
                <td class="py-2 px-4">{{ number_format($attachment->file_size / 1024, 0) }} KB</td>
                <td class="py-2 px-4">{{ $attachment->uploader?->name }}</td>
                <td class="py-2 px-4">{{ $attachment->created_at?->format('Y-m-d') }}</td>
                <td class="py-2 px-4">
                    @can('manageAttachments', $asset)
                        <form method="POST" action="{{ route('assets.attachments.destroy', [$asset, $attachment]) }}" class="inline" onsubmit="return confirm('Delete this attachment?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 text-sm">Delete</button>
                        </form>
                    @endcan
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No attachments.</p>
    @endif
</div>

<div class="mb-8">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">QR Code</h2>
    </div>
    <div class="bg-white p-6 rounded shadow flex items-center gap-6">
        <img src="{{ route('assets.qr', $asset) }}" alt="QR {{ $asset->asset_id }}" width="150" height="150" class="border">
        <div class="text-sm text-gray-600">
            <p class="mb-1">Scan untuk membuka halaman aset ini (harus login).</p>
            <code>{{ url('/assets/' . $asset->id) }}</code>
        </div>
    </div>
</div>

<div id="inspections" class="mb-8">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">IKE_BAST Inspections</h2>
        @can('manageAttachments', $asset)
            <a href="{{ route('ike-bast.inspection.create', $asset) }}" class="bg-purple-600 text-white px-3 py-1 rounded text-sm">+ Start Inspection</a>
        @endcan
    </div>
    @if(($asset->inspectionSessions ?? collect())->count() > 0)
    <table class="min-w-full bg-white shadow rounded text-sm">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">Session No</th>
                <th class="text-left py-2 px-4">Kind</th>
                <th class="text-left py-2 px-4">Date</th>
                <th class="text-left py-2 px-4">Lines</th>
                <th class="text-left py-2 px-4">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->inspectionSessions as $inspection)
            <tr class="border-b">
                <td class="py-2 px-4"><a href="{{ route('ike-bast.inspection.show', $inspection) }}" class="text-blue-600">{{ $inspection->session_number }}</a></td>
                <td class="py-2 px-4">{{ $inspection->kind }}</td>
                <td class="py-2 px-4">{{ $inspection->inspection_date?->format('Y-m-d') }}</td>
                <td class="py-2 px-4">{{ $inspection->lines_count ?? $inspection->lines->count() }}</td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $inspection->status }}</span></td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No inspection sessions for this asset yet.</p>
    @endif
</div>

<div class="mt-6">
    @can('audit', $asset)
        <a href="{{ route('assets.audit', $asset) }}" class="bg-blue-600 text-white px-4 py-2 rounded">Start Audit</a>
        <a href="{{ route('assets.audit.device', $asset) }}" class="bg-indigo-600 text-white px-4 py-2 rounded ml-2">Device Scan</a>
    @endcan
    @can('manageAttachments', $asset)
        <a href="{{ route('ike-bast.inspection.create', $asset) }}" class="bg-purple-600 text-white px-4 py-2 rounded ml-2">IKE_BAST Inspection</a>
    @endcan
    @can('transfer', $asset)
        <a href="{{ route('assets.transfer', $asset) }}" class="bg-green-600 text-white px-4 py-2 rounded ml-2">Transfer Asset</a>
    @endcan
</div>
@endsection
