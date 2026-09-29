<?php

namespace App\Http\Controllers;

use App\Services\DirectorySearchException;
use App\Services\MicrosoftDirectory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typeahead over the Microsoft 365 employee directory (PRD §39).
 *
 * Admin-only, on the same gate as the rest of user administration. The response
 * carries names, addresses, departments and job titles for the whole tenant, and
 * that is exactly the set of facts a support or technician account has no reason
 * to read.
 */
class MicrosoftDirectoryController extends Controller
{
    public function __construct(private readonly MicrosoftDirectory $directory) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Disabled is a normal configuration, not an error: Entra is optional
        // here, and a 500 would send an admin looking for a fault that does not
        // exist. The frontend hides the box; this answers honestly if it is hit.
        if (! $this->directory->enabled()) {
            return response()->json(['enabled' => false, 'results' => []]);
        }

        $request->validate([
            'q' => 'required|string|max:255',
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        try {
            $results = $this->directory->search(
                (string) $request->input('q'),
                (int) ($request->input('limit') ?: 10),
            );
        } catch (ConnectionException) {
            // No response at all. Distinct from the next case because the fix is
            // different: this one is worth retrying, and the message says so.
            return response()->json([
                'enabled' => true,
                'results' => [],
                'error' => 'Could not reach Microsoft 365. Check the network connection and try again.',
            ], 503);
        } catch (DirectorySearchException $e) {
            // Answered and refused. Retrying will not help; the message names the
            // likely cause.
            return response()->json([
                'enabled' => true,
                'results' => [],
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json(['enabled' => true, 'results' => $results]);
    }
}
