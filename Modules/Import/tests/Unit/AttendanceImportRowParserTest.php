<?php

namespace Modules\Import\Tests\Unit;

use Modules\Import\Support\AttendanceImportRowParser;
use Tests\TestCase;

class AttendanceImportRowParserTest extends TestCase
{
    private AttendanceImportRowParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceImportRowParser;
    }

    public function test_parses_a_fully_valid_row(): void
    {
        $result = $this->parser->parse(1, [
            'employee_email' => 'rahul@test.com',
            'date' => '2026-08-01',
            'check_in' => '09:55',
            'check_out' => '18:30',
            'performance' => '8',
        ]);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasAttendance());
        $this->assertTrue($result->hasPerformance());
        $this->assertSame('2026-08-01', $result->date);
        $this->assertSame(8, $result->score);
    }

    public function test_missing_email_is_invalid(): void
    {
        $result = $this->parser->parse(2, ['employee_email' => '', 'date' => '2026-08-01', 'check_in' => '09:00', 'check_out' => '18:00', 'performance' => '']);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('email', $result->error);
    }

    public function test_malformed_date_is_invalid(): void
    {
        $result = $this->parser->parse(3, ['employee_email' => 'a@b.com', 'date' => '01-08-2026', 'check_in' => '', 'check_out' => '', 'performance' => '5']);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid date', $result->error);
    }

    public function test_checkout_before_checkin_is_invalid(): void
    {
        $result = $this->parser->parse(4, ['employee_email' => 'a@b.com', 'date' => '2026-08-01', 'check_in' => '18:00', 'check_out' => '09:00', 'performance' => '']);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid check-in/check-out sequence', $result->error);
    }

    public function test_checkin_without_checkout_is_invalid(): void
    {
        $result = $this->parser->parse(5, ['employee_email' => 'a@b.com', 'date' => '2026-08-01', 'check_in' => '09:00', 'check_out' => '', 'performance' => '']);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid check-in/check-out sequence', $result->error);
    }

    public function test_malformed_time_is_invalid(): void
    {
        $result = $this->parser->parse(5, ['employee_email' => 'a@b.com', 'date' => '2026-08-01', 'check_in' => '10:70', 'check_out' => '18:00', 'performance' => '']);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid time', $result->error);
    }

    public function test_score_out_of_range_is_invalid(): void
    {
        $result = $this->parser->parse(6, ['employee_email' => 'a@b.com', 'date' => '2026-08-01', 'check_in' => '', 'check_out' => '', 'performance' => '11']);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid score', $result->error);
    }

    public function test_performance_without_a_date_is_invalid(): void
    {
        // This CSV has no standalone "month" column - a score needs the row's date.
        $result = $this->parser->parse(7, ['employee_email' => 'a@b.com', 'date' => '', 'check_in' => '', 'check_out' => '', 'performance' => '7']);

        $this->assertFalse($result->isValid());
        $this->assertSame('performance requires a date', $result->error);
    }

    public function test_date_with_performance_but_no_attendance_is_valid(): void
    {
        $result = $this->parser->parse(9, ['employee_email' => 'a@b.com', 'date' => '2026-08-01', 'check_in' => '', 'check_out' => '', 'performance' => '7']);

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasAttendance());
        $this->assertTrue($result->hasPerformance());
    }

    public function test_completely_empty_row_is_invalid(): void
    {
        $result = $this->parser->parse(8, ['employee_email' => 'a@b.com', 'date' => '', 'check_in' => '', 'check_out' => '', 'performance' => '']);

        $this->assertFalse($result->isValid());
    }
}
