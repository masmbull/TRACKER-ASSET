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

    public function show(DocumentTemplate $template)
    {
        $fields = $template->template_content ?? [];

        // Every field already carries its own "section" label ("A. Informasi Yang
        // Menyerahkan"), authored to match the real paper form. Grouping on that
        // keeps the on-screen order identical to the document; the key prefix is
        // only a fallback for fields stored without one.
        $groups = [];
        $orphans = 0;

        foreach ($fields as $key => $definition) {
            $definition = is_array($definition) ? $definition : ['label' => $definition, 'type' => 'text'];

            $section = trim((string) ($definition['section'] ?? ''));

            if ($section === '') {
                $orphans++;
                $section = 'Lainnya';
            }

            $groups[$section][] = ['key' => $key] + $definition;
        }

        return view('templates.show', [
            'template' => $template,
            'groups' => $groups,
            'fieldCount' => count($fields),
            'orphans' => $orphans,
        ]);
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
