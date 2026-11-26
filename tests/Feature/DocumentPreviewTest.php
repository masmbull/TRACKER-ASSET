<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Document preview.
 *
 * The page used to embed the PDF in an iframe, so the browser's own viewer drew
 * a toolbar over it. It now renders into a canvas from metadata, which means the
 * two endpoints have to agree: JSON for the viewer, the bytes for the canvas.
 */
class DocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function asset(): Asset
    {
        $company = Company::firstOrCreate(['name' => 'PT. Mahakarya Sukses Indonesia'], ['code' => 'MITO']);
        $category = AssetCategory::firstOrCreate(['name' => 'Laptop'], ['code' => 'LAP']);

        return Asset::create([
            'asset_id' => 'MITO-LAP-2026-0001',
            'company_id' => $company->id,
            'category_id' => $category->id,
            'brand' => 'Dell',
            'model' => 'Latitude 3420',
            'serial_number' => 'SN-1',
            'hostname' => 'PC-1',
            'location_id' => Location::firstOrCreate(['name' => 'HQ'], ['code' => 'HQ'])->id,
            'asset_status_id' => AssetStatus::firstOrCreate(['name' => 'Active'], ['code' => 'ACT'])->id,
            'is_active' => true,
        ]);
    }

    private function document(Asset $asset, bool $withFile = true)
    {
        $path = 'documents/test-'.uniqid().'.pdf';

        if ($withFile) {
            Storage::put($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        }

        return $asset->documents()->create([
            'document_type' => 'BAST',
            'document_number' => 'BAST/IT/2026/0000'.rand(1, 9),
            'title' => 'Berita Acara Serah Terima',
            'file_path' => $path,
            'file_type' => 'application/pdf',
            'file_size' => 64,
            'generated_date' => now(),
            'generated_by' => User::factory()->create()->id,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** The modal fetches this before it can render anything. */
    public function test_preview_data_returns_what_the_viewer_needs(): void
    {
        $document = $this->document($this->asset());

        $this->actingAs($this->admin())
            ->getJson(route('documents.preview.data', $document))
            ->assertOk()
            ->assertJsonStructure(['title', 'document_number', 'file_url', 'download_url'])
            ->assertJsonPath('document_number', $document->document_number);
    }

    public function test_preview_data_is_404_when_the_file_is_gone(): void
    {
        $document = $this->document($this->asset(), withFile: false);

        $this->actingAs($this->admin())
            ->getJson(route('documents.preview.data', $document))
            ->assertNotFound();
    }

    public function test_guest_cannot_read_preview_data(): void
    {
        $document = $this->document($this->asset());

        $this->getJson(route('documents.preview.data', $document))->assertUnauthorized();
    }

    /** Inline allows the shell to open it in a viewer rather than download it. */
    public function test_download_serves_the_pdf_inline_for_the_viewer(): void
    {
        $document = $this->document($this->asset());

        $response = $this->actingAs($this->admin())
            ->get(route('documents.download', $document).'?inline=1');

        $response->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_download_without_inline_is_an_attachment(): void
    {
        $document = $this->document($this->asset());

        $response = $this->actingAs($this->admin())->get(route('documents.download', $document));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    /**
     * The number is a clickable preview trigger. The wrapper cannot itself be a
     * <button>: callers pass a button as the slot, and nested buttons break the
     * HTML parser into two siblings - the visible number then loses its click
     * handler entirely.
     */
    public function test_document_number_trigger_is_not_a_nested_button(): void
    {
        $document = $this->document($this->asset());

        $html = $this->actingAs($this->admin())
            ->get(route('documents.index'))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $nodes = (new DOMXPath($dom))->query('//*[@data-pdf-open="document-preview-'.$document->id.'"]');

        $this->assertSame(1, $nodes->length);
        $opener = $nodes->item(0);
        $this->assertSame('span', strtolower($opener->nodeName));
        $this->assertStringContainsString($document->document_number, trim($opener->textContent));
    }
}
