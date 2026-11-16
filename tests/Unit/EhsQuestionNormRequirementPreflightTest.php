<?php

namespace Tests\Unit;

use App\Support\Ehs\QuestionNormRequirementPreflight;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EhsQuestionNormRequirementPreflightTest extends TestCase
{
    public function test_preflight_accepts_matching_rows_and_rejects_cross_tenant_and_orphan_rows_without_repair(): void
    {
        $db = new SQLiteConnection(new PDO('sqlite::memory:'));
        $db->statement('CREATE TABLE questions (id TEXT PRIMARY KEY, organization_id TEXT NOT NULL)');
        $db->statement('CREATE TABLE norm_requirements (id TEXT PRIMARY KEY, organization_id TEXT NOT NULL)');
        $db->statement('CREATE TABLE question_norm_requirement (id TEXT PRIMARY KEY, question_id TEXT, norm_requirement_id TEXT, organization_id TEXT)');
        $db->statement("INSERT INTO questions VALUES ('q1', 'a'), ('q2', 'b')");
        $db->statement("INSERT INTO norm_requirements VALUES ('r1', 'a'), ('r2', 'b')");
        $db->statement("INSERT INTO question_norm_requirement VALUES ('p1', 'q1', 'r1', 'a')");
        QuestionNormRequirementPreflight::assertValid($db);

        foreach ([['q2', 'r1', 'a'], ['q1', 'r2', 'a'], ['missing', 'r1', 'a'], ['q1', 'missing', 'a'], ['q1', 'r1', null]] as $invalid) {
            $db->table('question_norm_requirement')->insert([
                'id' => 'invalid', 'question_id' => $invalid[0],
                'norm_requirement_id' => $invalid[1], 'organization_id' => $invalid[2],
            ]);
            try {
                QuestionNormRequirementPreflight::assertValid($db);
                $this->fail('El preflight debe rechazar datos históricos inválidos.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('pivote', $exception->getMessage());
                $this->assertSame(2, $db->table('question_norm_requirement')->count());
            }
            $db->table('question_norm_requirement')->where('id', 'invalid')->delete();
        }
    }
}
