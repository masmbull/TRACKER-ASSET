<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TemplateController extends Controller
{
    public function index()
    {
        $templates = DocumentTemplate::orderBy('document_type')->get();

        return view('templates.index', compact('templates'));
    }

    public function create()
    {
        return view('templates.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'document_type' => 'required|string',
            'template_content' => 'required|array',
        ]);

        DocumentTemplate::create([
            ...$validated,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('templates.index')->with('success', 'Template created.');
    }

    public function toggle(DocumentTemplate $template)
    {
        $template->update(['is_active' => ! $template->is_active]);

        return redirect()->route('templates.index')->with('success', 'Template updated.');
    }

    public function destroy(DocumentTemplate $template)
    {
        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Template deleted.');
    }
}
