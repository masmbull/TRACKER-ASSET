<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetRepair;
use App\Models\AssetStatus;
use App\Models\AssetTransfer;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\IkeBastInspectionSession;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * No page may answer 500 (PRD §52 "every screen works").
 *
 * This walks every GET route as an admin and fails on any 5xx or unhandled
 * exception. It exists because a sweep found two things no other test could:
 * the QR code route threw for every role because the PNG back end needs the
 * Imagick extension, and the Excel export looked broken only because this very
 * sweep was calling status() on a BinaryFileResponse, which has no such method.
 *
 * Authorization is left enabled, so a 403 is a real 403 rather than a thrown
 * exception - the point here is that pages render, not that everyone may see
 * them. The per-role matrix is deliberately not asserted; a viewer reaching a
 * page is a policy question, and AssetPolicyTest already answers it.
 */
class PageSweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_page_returns_a_server_error(): void
    {
        Storage::fake('local');

        $company = Company::create(['name' => 'PT. Test', 'code' => 'TT', 'address' => '']);
        $category = AssetCategory::create(['name' => 'Laptop']);
        $loc = Location::create(['name' => 'HO', 'status' => 'active']);
        $st = AssetStatus::create(['name' => 'Active']);

        $admin = User::factory()->create(['role' => 'admin']);
        $support = User::factory()->create(['role' => 'support']);

        $asset = Asset::factory()->create([
            'asset_id' => 'SWEEP-1',
            'company_id' => $company->id,
            'category_id' => $category->id,
            'location_id' => $loc->id,
            'asset_status_id' => $st->id,
        ]);

        $tpl = DocumentTemplate::create([
            'name' => 'FAT Pakem',
            'document_type' => 'FAT',
            'template_content' => ['asset_id' => ['label' => 'Asset ID', 'type' => 'text']],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        AssetTransfer::create([
            'company_id' => $company->id,
            'asset_id' => $asset->id,
            'from_user_id' => $admin->id,
            'from_location_id' => $loc->id,
            'to_user_id' => $support->id,
            'to_location_id' => $loc->id,
            'transfer_number' => 'SWEEP/TRF/1',
            'transfer_date' => now()->toDateString(),
            'status' => 'completed',
            'submitted_by' => $admin->id,
        ]);

        $doc = Document::create([
            'asset_id' => $asset->id,
            'document_type' => 'FAT',
            'document_number' => 'SWEEP/FAT/1',
            'title' => 'FAT record',
            'file_path' => 'documents/sweep.pdf',
            'file_type' => 'pdf',
            'generated_date' => now()->toDateString(),
            'generated_by' => $admin->id,
        ]);

        AssetRepair::create([
            'asset_id' => $asset->id,
            'date' => now()->toDateString(),
            'problem' => 'Screen flicker',
            'status' => 'open',
        ]);

        $sess = IkeBastInspectionSession::factory()->create(['asset_id' => $asset->id]);
        $tok = AgentApiToken::create([
            'name' => 'sweep',
            'token_hash' => hash('sha256', 'sweep'),
            'token_plain' => 'sweep',
        ]);
        $att = Attachment::create([
            'asset_id' => $asset->id,
            'document_id' => $doc->id,
            'file_name' => 'invoice.pdf',
            'file_path' => 'attachments/invoice.pdf',
            'mime_type' => 'application/pdf',
            'file_type' => 'pdf',
            'file_size' => 10,
            'uploaded_by' => $admin->id,
        ]);

        $this->walk($asset, $doc, $tpl, $sess, $tok, $att, $admin);
    }

    private function walk($asset, $doc, $tpl, $sess, $tok, $att, User $admin): void
    {
        $a = $asset->id;
        $c = $asset->company_id;

        $list = [
            '/' => 'dashboard',
            '/assets' => 'assets.index',
            '/assets/create' => 'assets.create',
            "/assets/$a" => 'assets.show',
            "/assets/$a/edit" => 'assets.edit',
            "/assets/$a/audit" => 'assets.audit',
            "/assets/$a/audit-device" => 'assets.audit.device',
            "/assets/$a/transfer" => 'assets.transfer',
            "/assets/$a/repair/create" => 'assets.repair.create',
            "/assets/$a/documents/create" => 'documents.create',
            "/assets/$a/documents/generate" => 'documents.generate',
            "/assets/$a/qr" => 'assets.qr',
            '/assets/retired' => 'assets.retired',
            '/assets/import' => 'assets.import',
            '/assets/export' => 'assets.export',
            '/transfers' => 'transfers.index',
            '/documents' => 'documents.index',
            "/documents/{$doc->id}" => 'documents.preview',
            "/documents/{$doc->id}/download" => 'documents.download',
            "/assets/$a/attachments/{$att->id}" => 'attachments.download',
            '/templates' => 'templates.index',
            '/templates/create' => 'templates.create',
            "/templates/{$tpl->id}" => 'templates.show',
            '/master/companies' => 'master.companies',
            '/master/companies/create' => 'master.create.companies',
            "/master/companies/$c/edit" => 'master.edit.companies',
            '/master/locations' => 'master.locations',
            '/master/categories' => 'master.categories',
            '/master/statuses' => 'master.statuses',
            '/users' => 'users.index',
            '/users/create' => 'users.create',
            "/users/{$admin->id}/edit" => 'users.edit',
            '/activity' => 'activity.index',
            '/reports' => 'reports.index',
            '/reports/export' => 'reports.export',
            '/barcode' => 'barcode.form',
            '/ike-bast/inspection' => 'ike-bast.index',
            "/ike-bast/inspection/create/$a" => 'ike-bast.create',
            "/ike-bast/inspection/{$sess->id}" => 'ike-bast.show',
            "/ike-bast/inspection/{$sess->id}/print" => 'ike-bast.print',
            '/agent' => 'agent.index',
            '/agent/fleet' => 'agent.fleet',
            '/agent/commands' => 'agent.commands',
            "/agent/{$tok->id}/fingerprint" => 'agent.fingerprint',
            '/services' => 'services.index',
            '/services/health.json' => 'services.health',
            '/login' => 'login',
        ];

        $broken = [];

        foreach ($list as $uri => $name) {
            try {
                // getStatusCode(), not status(): the Excel export answers with a
                // BinaryFileResponse, which has no status() method, so calling
                // one would report a working export as broken.
                $status = $this->actingAs($admin)->get($uri)->getStatusCode();
            } catch (\Throwable $e) {
                $status = 'EX: '.class_basename($e).': '.str($e->getMessage())->limit(200);
            }

            // Two routes answer with a non-2xx on purpose, and neither is a page.
            //
            // /services/health.json answers 503 when a backing service is down -
            // that is the contract, so a load balancer can read the status code
            // instead of trusting a cheerful body. The download routes 404 because
            // Storage is faked and the file genuinely is not there.
            $reportsItsOwnState = $uri === '/services/health.json' || str_contains($uri, '/download');

            if (($status === 404 || $status === 503) && $reportsItsOwnState) {
                continue;
            }

            if (is_string($status) || $status >= 500) {
                $broken[$name] = $status;
            }
        }

        $this->assertSame([], $broken, "Pages that did not render:\n".implode("\n", array_map(
            fn ($name, $status) => "  {$name} -> {$status}",
            array_keys($broken),
            $broken
        )));
    }
}
