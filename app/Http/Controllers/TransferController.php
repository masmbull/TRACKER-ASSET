<?php

namespace App\Http\Controllers;

use App\Models\AssetTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('export-assets');

        $query = AssetTransfer::query()
            ->with(['asset', 'fromUser', 'toUser', 'fromLocation', 'toLocation', 'submitter']);

        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($q) use ($search) {
                $q->where('transfer_number', 'like', $search)
                    ->orWhereHas('asset', fn ($sub) => $sub
                        ->where('asset_id', 'like', $search)
                        ->orWhere('hostname', 'like', $search)
                        ->orWhere('serial_number', 'like', $search))
                    ->orWhereHas('fromUser', fn ($sub) => $sub->where('name', 'like', $search))
                    ->orWhereHas('toUser', fn ($sub) => $sub->where('name', 'like', $search));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('transfer_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('transfer_date', '<=', $request->input('to'));
        }

        $transfers = $query->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('transfers.index', compact('transfers'));
    }
}
