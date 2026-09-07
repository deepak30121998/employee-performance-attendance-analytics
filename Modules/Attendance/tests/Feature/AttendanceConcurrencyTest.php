<?php

namespace Modules\Attendance\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Attendance\Services\AttendanceService;
use Tests\TestCase;

/**
 * Two check-ins raced via pcntl_fork() - separate processes, separate DB
 * connections, so the unique(employee_id, date) index is what decides it.
 * No RefreshDatabase here: a forked child would corrupt the parent's open
 * transaction, so we commit for real and clean up in tearDown().
 */
class AttendanceConcurrencyTest extends TestCase
{
    private ?User $employee = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PARATEST') !== false) {
            $this->markTestSkipped('pcntl_fork() concurrency test does not run under --parallel.');
        }

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $this->employee = User::factory()->employee()->create();
    }

    protected function tearDown(): void
    {
        if ($this->employee) {
            DB::table('attendances')->where('employee_id', $this->employee->id)->delete();
            $this->employee->forceDelete();
        }

        parent::tearDown();
    }

    public function test_only_one_of_two_simultaneous_check_ins_succeeds(): void
    {
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
                // Child: must not share the parent's DB connection.
                DB::purge();

                $outcome = 'ok';

                try {
                    app(AttendanceService::class)->checkIn(User::find($employeeId));
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
        $conflicts = count(array_filter($results, fn ($r) => str_contains($r, 'AttendanceConflictException')));

        $this->assertSame(1, $successes, 'Exactly one concurrent check-in should succeed.');
        $this->assertSame(1, $conflicts, 'The other concurrent check-in should be rejected as a conflict.');
        $this->assertSame(1, DB::table('attendances')->where('employee_id', $employeeId)->count());
    }
}
