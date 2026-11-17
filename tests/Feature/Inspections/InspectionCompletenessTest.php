<?php

namespace Tests\Feature\Inspections;

use App\Models\Inspection;
use App\Support\Inspections\InspectionCompleteness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InspectionCompletenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('La prueba de completitud con tablas mínimas usa SQLite en memoria.');
        }
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('checklist_sections', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('organization_id');
            $table->string('checklist_version_id');
        });
        Schema::create('questions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('organization_id');
            $table->string('checklist_section_id');
            $table->string('answer_type');
            $table->text('options')->nullable();
            $table->boolean('required');
            $table->boolean('status');
        });
        Schema::create('inspection_answers', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('organization_id');
            $table->string('inspection_id');
            $table->string('question_id');
            $table->text('answer')->nullable();
            $table->text('observation')->nullable();
        });
        DB::table('checklist_sections')->insert([
            ['id' => 'historical', 'organization_id' => '00000000-0000-4000-8000-000000000001', 'checklist_version_id' => 'v1'],
            ['id' => 'current', 'organization_id' => '00000000-0000-4000-8000-000000000001', 'checklist_version_id' => 'v2'],
            ['id' => 'foreign', 'organization_id' => '00000000-0000-4000-8000-000000000002', 'checklist_version_id' => 'v1'],
        ]);
        app(TenantContext::class)->set('00000000-0000-4000-8000-000000000001');
    }

    private function inspection(): Inspection
    {
        return new Inspection(['id' => 'inspection', 'organization_id' => '00000000-0000-4000-8000-000000000001', 'checklist_version_id' => 'v1']);
    }

    private function question(string $id, string $type = 'text', bool $required = true, bool $active = true, string $section = 'historical', string $org = '00000000-0000-4000-8000-000000000001', ?array $options = null): void
    {
        DB::table('questions')->insert([
            'id' => $id, 'organization_id' => $org, 'checklist_section_id' => $section,
            'answer_type' => $type, 'required' => $required, 'status' => $active,
            'options' => $options === null ? null : json_encode($options),
        ]);
    }

    private function answer(string $question, ?string $value, string $org = '00000000-0000-4000-8000-000000000001', string $inspection = 'inspection'): void
    {
        DB::table('inspection_answers')->insert([
            'id' => $question.'-'.$org.'-'.$inspection, 'organization_id' => $org,
            'inspection_id' => $inspection, 'question_id' => $question,
            'answer' => $value, 'observation' => 'observación',
        ]);
    }

    private function assertIncomplete(): void
    {
        try {
            InspectionCompleteness::assertComplete($this->inspection());
            $this->fail('La inspección debería estar incompleta.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('answers', $e->errors());
        }
    }

    public function test_required_valid_values_optional_and_historical_scope_without_writes(): void
    {
        $this->question('boolean', 'boolean');
        $this->question('number', 'number');
        $this->question('optional', 'signature', false);
        $this->question('inactive', 'signature', true, false);
        $this->question('new-version', 'signature', true, true, 'current');
        $this->question('other-org', 'signature', true, true, 'foreign', '00000000-0000-4000-8000-000000000002');
        $this->question('mismatched-section', 'signature', true, true, 'foreign');
        $this->answer('boolean', 'false');
        $this->answer('number', '0');
        $this->answer('inactive', '__NA__');
        $before = DB::table('inspection_answers')->orderBy('id')->get()->toArray();

        DB::transaction(fn () => InspectionCompleteness::assertComplete($this->inspection()));

        $this->assertEquals($before, DB::table('inspection_answers')->orderBy('id')->get()->toArray());
    }

    public function test_no_active_questions_and_missing_or_null_required_answers_block(): void
    {
        $this->assertIncomplete();
        $this->question('q');
        $this->assertIncomplete();
        $this->answer('q', null);
        $this->assertIncomplete();
    }

    public function test_answers_of_another_inspection_or_organization_do_not_count(): void
    {
        $this->question('q');
        $this->answer('q', 'yes', '00000000-0000-4000-8000-000000000001', 'other-inspection');
        $this->answer('q', 'yes', '00000000-0000-4000-8000-000000000002');
        $this->assertIncomplete();
    }

    public function test_optional_present_invalid_and_reserved_values_block(): void
    {
        $this->question('q', 'text', false);
        $this->answer('q', null);
        $this->assertIncomplete();
        DB::table('inspection_answers')->update(['answer' => '   ']);
        $this->assertIncomplete();
        DB::table('inspection_answers')->update(['answer' => '__NA__']);
        $this->assertIncomplete();
        DB::table('inspection_answers')->update(['answer' => 'válida']);
        InspectionCompleteness::assertComplete($this->inspection());
        $this->assertTrue(true);
    }

    public function test_unsupported_required_or_answered_optional_blocks(): void
    {
        $this->question('q', 'signature');
        $this->assertIncomplete();
        $this->answer('q', 'legacy');
        $this->assertIncomplete();
        DB::table('questions')->where('id', 'q')->update(['required' => false, 'answer_type' => 'unknown']);
        $this->assertIncomplete();
        DB::table('inspection_answers')->delete();
        InspectionCompleteness::assertComplete($this->inspection());
        $this->assertTrue(true);
    }

    public function test_choice_must_match_options_and_dates_must_be_real(): void
    {
        $this->question('choice', 'multiple_choice', true, true, 'historical', '00000000-0000-4000-8000-000000000001', [['value' => 'yes']]);
        $this->question('date', 'date');
        $this->answer('choice', 'no');
        $this->answer('date', '2024-02-29');
        $this->assertIncomplete();
        DB::table('inspection_answers')->where('question_id', 'choice')->update(['answer' => 'yes']);
        DB::table('inspection_answers')->where('question_id', 'date')->update(['answer' => '2023-02-29']);
        $this->assertIncomplete();
        DB::table('inspection_answers')->where('question_id', 'date')->update(['answer' => '2024-02-29']);
        InspectionCompleteness::assertComplete($this->inspection());
        $this->assertTrue(true);
    }
}
