<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class UserImportTest extends TestCase
{
    use RefreshDatabase;

    private array $header = ['Full Name', 'Office Email', 'Asset ID'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);
    }

    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Users');
        $sheet->fromArray([$this->header, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'users').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            'users.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_template_has_the_expected_columns(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('users.import.template'));
        $response->assertOk()->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->getContent());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame($this->header, array_values($sheet->toArray(null, true, true, false)[0]));
    }

    public function test_import_creates_users_and_matches_by_email_case_insensitively(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['name' => 'Old Name', 'email' => 'budi@mito.co.id']);

        $this->actingAs($admin)->post(route('users.import.analyze'), [
            'file' => $this->workbook([
                ['Budi Santoso', 'BUDI@MITO.CO.ID', null],
                ['Siti Aminah', 'siti@mito.co.id', null],
                ['Budi Santoso', 'budi@mito.co.id', null],
            ]),
        ])->assertOk()->assertSee('duplicate', false);

        $this->actingAs($admin)->post(route('users.import.confirm'))
            ->assertRedirect(route('users.index'));

        $budi = User::where('email', 'budi@mito.co.id')->firstOrFail();
        $this->assertSame('Budi Santoso', $budi->name);
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['budi@mito.co.id'])->count());
        $this->assertTrue(User::whereRaw('LOWER(email) = ?', ['siti@mito.co.id'])->exists());
    }

    public function test_optional_asset_id_assigns_the_user_to_the_asset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = Asset::factory()->create(['asset_id' => 'MITO-LAP-2026-0099']);

        $this->actingAs($admin)->post(route('users.import.analyze'), [
            'file' => $this->workbook([['Andi Wijaya', 'andi@mito.co.id', 'mito-lap-2026-0099']]),
        ])->assertOk();

        $this->actingAs($admin)->post(route('users.import.confirm'))->assertRedirect();

        $user = User::where('email', 'andi@mito.co.id')->firstOrFail();
        $this->assertSame($user->id, $asset->fresh()->current_user_id);
        $this->assertSame(1, AssetAssignment::where('asset_id', $asset->id)->where('user_id', $user->id)->count());
    }

    public function test_support_cannot_import_users(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->get(route('users.import'))->assertForbidden();
        $this->actingAs($support)->get(route('users.import.template'))->assertForbidden();
        $this->actingAs($support)->post(route('users.import.analyze'), [
            'file' => $this->workbook([['Budi', 'budi@mito.co.id', null]]),
        ])->assertForbidden();
    }
}
