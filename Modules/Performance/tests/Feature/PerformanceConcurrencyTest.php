<?php

namespace Modules\Performance\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Services\PerformanceService;
use Modules\User\Models\Department;
use Tests\TestCase;

/**
 * Same setup as AttendanceConcurrencyTest: two forked processes race the same
 * employee/month score, the unique(employee_id, month) index picks the winner
 * and the loser must get the conflict exception, not a raw QueryException.
 * No RefreshDatabase - see the attendance twin for why.
 */
class PerformanceConcurrencyTest extends TestCase
{
    private ?User $manager = null;

    private ?User $employee = null;

    private ?Department $department = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PARATEST') !== false) {
            $this->markTestSkipped('pcntl_fork() concurrency test does not run under --parallel.');
        }

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $this->department = Department::factory()->create();
        $this->manager = User::factory()->manager()->create(['department_id' => $this->department->id]);
        $this->employee = User::factory()->employee()->create(['department_id' => $this->department->id]);
    }

    protected function tearDown(): void
    {
        if ($this->employee) {
            DB::table('performance_scores')->where('employee_id', $this->employee->id)->delete();
            DB::table('notifications')->where('notifiable_id', $this->employee->id)->delete();
            $this->employee->forceDelete();
        }

        $this->manager?->forceDelete();
        $this->department?->delete();

        parent::tearDown();
    }

    public function test_only_one_of_two_simultaneous_scores_succeeds(): void
    {
        $managerId = $this->manager->id;
        $employeeId = $this->employee->id;
        $results = [];

        $pipes = [];
        for ($i = 0; $i < 2; $i++) {
            socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
            $pipes[] = $pair;
        }

        $pids = [];
        foreach ($pipes as $i => [$parentEnd, $childEnd]) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork process.');
            }

            if ($pid === 0) {
                // child must not share the parent's DB connection
                DB::purge();

                $outcome = 'ok';

                try {
                    app(PerformanceService::class)->record(
                        User::find($managerId), $employeeId, '2026-08-01', 7, null
                    );
                } catch (\Throwable $e) {
                    $outcome = get_class($e);
                }

                socket_write($childEnd, $outcome);
                socket_close($childEnd);
                socket_close($parentEnd);
                exit(0);
            }

            $pids[] = $pid;
            socket_close($childEnd);
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        foreach ($pipes as [$parentEnd, $childEnd]) {
            $results[] = socket_read($parentEnd, 256);
            socket_close($parentEnd);
        }

        $successes = count(array_filter($results, fn ($r) => $r === 'ok'));
        $conflicts = count(array_filter($results, fn ($r) => str_contains($r, 'PerformanceScoreConflictException')));

        $this->assertSame(1, $successes, 'Exactly one concurrent score should succeed.');
        $this->assertSame(1, $conflicts, 'The other should be rejected as a conflict, not a QueryException.');
        $this->assertSame(1, DB::table('performance_scores')->where('employee_id', $employeeId)->count());
    }
}
