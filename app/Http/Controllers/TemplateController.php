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
            'template_content' => 'required',
        ]);

        // The field is a <textarea>, so a browser can only ever send a string -
        // yet the rule was `required|array`, which a textarea can never satisfy.
        // Validating that way meant the form was incapable of saving anything,
        // and with no error rendered anywhere the submission simply bounced back
        // looking untouched.
        $content = $validated['template_content'];

        // An array is still accepted, so anything calling the endpoint directly
        // is unaffected.
        $decoded = is_array($content) ? $content : json_decode((string) $content, true);

        if (! is_array($decoded)) {
            // Hand-written JSON deserves a specific complaint. "The template
            // content must be an array" is true and useless: the author did
            // write a JSON object, and the actual mistake is always a specific
            // character somewhere in it.
            return back()
                ->withInput()
                ->withErrors(['template_content' => $this->jsonComplaint((string) $content)]);
        }

        DocumentTemplate::create([
            'name' => $validated['name'],
            'document_type' => $validated['document_type'],
            'template_content' => $decoded,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('templates.index')->with('success', 'Template created.');
    }

    /**
     * Turn a JSON parse failure into something the author can act on.
     */
    private function jsonComplaint(string $json): string
    {
        json_decode($json);

        return match (json_last_error()) {
            JSON_ERROR_NONE => 'The template fields must be a JSON object, for example {"asset_id": {"label": "Asset ID", "type": "text"}}.',
            JSON_ERROR_DEPTH => 'The template fields are nested too deeply.',
            JSON_ERROR_UTF8 => 'The template fields contain a character that is not valid text.',
            default => 'The template fields are not valid JSON ('.json_last_error_msg().'). Check for a trailing comma, a missing colon or an unclosed brace.',
        };
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
