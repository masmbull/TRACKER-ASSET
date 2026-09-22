<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Attachments (PRD §37, §46) + strict upload validation (PRD §50).
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->asset = Asset::factory()->create();
    }

    public function test_support_can_upload_and_download(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $response = $this->actingAs($support)->post(route('assets.attachments.store', $this->asset), [
            'file' => UploadedFile::fake()->image('invoice.png', 100, 100),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $attachment = Attachment::firstOrFail();
        $this->assertSame('invoice.png', $attachment->file_name);
        $this->assertSame('image', $attachment->file_type);
        $this->assertSame($support->id, $attachment->uploaded_by);

        Storage::assertExists($attachment->file_path);
        $this->assertDatabaseHas('activity_logs', ['action' => 'UPLOAD', 'entity_identifier' => 'invoice.png']);

        $download = $this->actingAs($support)->get(route('assets.attachments.download', [$this->asset, $attachment]));
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
    }

    public function test_pdf_upload_is_typed_as_pdf(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('assets.attachments.store', $this->asset), [
            'file' => UploadedFile::fake()->create('warranty.pdf', 50, 'application/pdf'),
        ]);

        $this->assertDatabaseHas('attachments', ['file_type' => 'pdf', 'mime_type' => 'application/pdf']);
    }

    public function test_invalid_mime_is_rejected(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('assets.attachments.store', $this->asset), [
            'file' => UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_delete_removes_file_and_record(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('assets.attachments.store', $this->asset), [
            'file' => UploadedFile::fake()->image('photo.png'),
        ]);

        $attachment = Attachment::firstOrFail();

        $this->actingAs($support)->delete(route('assets.attachments.destroy', [$this->asset, $attachment]))
            ->assertRedirect();

        Storage::assertMissing($attachment->file_path);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_viewer_cannot_upload(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->post(route('assets.attachments.store', $this->asset), [
            'file' => UploadedFile::fake()->image('photo.png'),
        ])->assertForbidden();

        $this->assertDatabaseCount('attachments', 0);
    }
}
