<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use Illuminate\Support\Facades\Response;

class AgentTokenController extends Controller
{
    public function index()
    {
        $tokens = AgentApiToken::orderByDesc('id')->get();

        return view('agent.index', compact('tokens'));
    }

    public function download(AgentApiToken $token)
    {
        $token->update(['last_used_at' => now()]);

        $exePath = storage_path('app/agent/TrackerAgent.exe');
        if (! file_exists($exePath)) {
            return back()->with('error', 'Agent executable not found. Build the agent first.');
        }

        return Response::download($exePath, 'TrackerAgent.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
        ]);
    }

    public function downloadLatest()
    {
        $token = AgentApiToken::orderByDesc('id')->first();

        if (! $token) {
            $token = AgentApiToken::create([
                'name' => 'device-'.now()->format('Ymd-His'),
                'token_hash' => hash('sha256', ''),
                'token_plain' => '',
            ]);
        }

        return redirect()->route('agent.download', $token);
    }

    public function revoke(AgentApiToken $token)
    {
        $token->update(['is_active' => false]);

        return back()->with('success', "Device '{$token->name}' revoked.");
    }
}
