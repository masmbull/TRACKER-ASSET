<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Configurable, sequential, duplicate-proof numbering (PRD §33, §46).
 */
class DocumentNumberingTest extends TestCase
{
    use RefreshDatabase;

    private DocumentNumberService $numbers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->numbers = new DocumentNumberService;
        User::factory()->create();
    }

    private function makeDoc(string $type, string $number): Document
    {
        return Document::create([
            'document_type' => $type,
            'document_number' => $number,
            'title' => $type.' test',
            'file_path' => 'documents/test.pdf',
            'file_type' => 'pdf',
            'generated_by' => 1,
        ]);
    }

    public function test_first_number_starts_at_00001(): void
    {
        $this->assertSame('FAT/IT/'.now()->format('Y').'/00001', $this->numbers->next('FAT'));
    }

    public function test_numbers_increment_within_type(): void
    {
        $this->makeDoc('FAT', 'FAT/IT/'.now()->format('Y').'/00001');
        $this->makeDoc('FAT', 'FAT/IT/'.now()->format('Y').'/00002');

        $this->assertSame('FAT/IT/'.now()->format('Y').'/00003', $this->numbers->next('FAT'));
    }

    public function test_types_have_independent_sequences(): void
    {
        $this->makeDoc('FAT', 'FAT/IT/'.now()->format('Y').'/00001');

        $this->assertSame('BAST/IT/'.now()->format('Y').'/00001', $this->numbers->next('BAST'));
    }

    public function test_sequence_continues_from_preexisting_numbers(): void
    {
        $this->makeDoc('BAST', 'BAST/IT/'.now()->format('Y').'/00042');

        $this->assertSame('BAST/IT/'.now()->format('Y').'/00043', $this->numbers->next('BAST'));
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->numbers->next('INVOICE');
    }
}
