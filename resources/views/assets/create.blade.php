@extends('layouts.app')

@section('title', 'Add Asset')

@section('content')
<h1 class="text-2xl font-bold mb-6">Add Asset</h1>

<form method="POST" action="/assets" class="bg-white p-6 rounded shadow space-y-4">
    @csrf

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Asset ID *</label>
            <input type="text" name="asset_id" required class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Company *</label>
            <select name="company_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Category *</label>
            <select name="category_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">Status *</label>
            <select name="asset_status_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($statuses as $status)
                <option value="{{ $status->id }}">{{ $status->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Brand *</label>
            <input type="text" name="brand" required class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Model *</label>
            <input type="text" name="model" required class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Serial Number *</label>
            <input type="text" name="serial_number" required class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Hostname *</label>
            <input type="text" name="hostname" required class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Location *</label>
            <select name="location_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($locations as $location)
                <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">Purchase Year</label>
            <input type="number" name="purchase_year" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Vendor</label>
            <input type="text" name="vendor" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Purchase Price</label>
            <input type="number" name="purchase_price" step="0.01" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">OS Version</label>
            <input type="text" name="os_version" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">MAC Address</label>
            <input type="text" name="mac_address" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Static IP</label>
            <input type="text" name="static_ip" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Current User</label>
            <select name="current_user_id" class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Warranty Until</label>
        <input type="date" name="warranty_until" class="w-full border rounded px-3 py-2">
    </div>

    <div>
        <label class="block text-sm font-medium">Software License</label>
        <input type="text" name="software_license" class="w-full border rounded px-3 py-2">
    </div>

    <div>
        <label class="block text-sm font-medium">Admin IT PIC</label>
        <select name="admin_it_pic" class="w-full border rounded px-3 py-2">
            <option value="">Select</option>
            @foreach($users as $user)
            <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">Save</button>
        <a href="/assets" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
