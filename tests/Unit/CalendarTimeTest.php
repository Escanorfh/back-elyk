<?php

namespace Tests\Unit;

use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * طبقة الوقت المشتركة: كل قرار تقويمي (متأخر؟ أي سلة؟ أي يوم؟) يُختبر هنا لأنه المصدر الوحيد
 * لتلك القواعد في الخادم. التوقيتات ثابتة ومختارة عمدًا: Asia/Dubai بلا توقيت صيفي، و
 * America/New_York لتغطية الانتقال بين الصيفي والشتوي.
 */
class CalendarTimeTest extends TestCase
{
    public function test_an_explicit_offset_wins_over_the_item_timezone()
    {
        $utc = CalendarTime::toUtc('2026-11-05T14:30:00+02:00', 'America/New_York');

        $this->assertSame('2026-11-05 12:30:00', $utc->setTimezone('UTC')->toDateTimeString());
    }

    public function test_a_naive_datetime_is_read_in_the_item_timezone()
    {
        $naive = CalendarTime::toUtc('2026-11-05 14:30:00', 'Asia/Dubai');
        $this->assertSame('2026-11-05 10:30:00', $naive->toDateTimeString());

        // صيفًا -4 وشتاءً -5: نفس الساعة المحلية تعطي لحظتين UTC مختلفتين.
        $this->assertSame('16:00:00', CalendarTime::toUtc('2026-07-01 12:00:00', 'America/New_York')->format('H:i:s'));
        $this->assertSame('17:00:00', CalendarTime::toUtc('2026-12-01 12:00:00', 'America/New_York')->format('H:i:s'));
    }

    public function test_only_iana_zones_are_accepted()
    {
        $this->assertTrue(CalendarTime::isValidTimezone('Africa/Cairo'));
        $this->assertFalse(CalendarTime::isValidTimezone('EST'), 'abbreviations carry no DST rules');
        $this->assertFalse(CalendarTime::isValidTimezone('+02:00'), 'offsets are not zones');
        $this->assertFalse(CalendarTime::isValidTimezone('Not/AZone'));
        $this->assertFalse(CalendarTime::isValidTimezone(null));
    }

    public function test_an_unparseable_or_unknown_input_throws_instead_of_guessing()
    {
        $this->expectException(\InvalidArgumentException::class);
        CalendarTime::toUtc('not a date', 'UTC');
    }

    public function test_overdue_is_computed_never_stored()
    {
        $now = CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC');

        $this->assertFalse(CalendarTime::isOverdue(null, 'pending', $now), 'no date is the No Date bucket');
        $this->assertFalse(CalendarTime::isOverdue($now->subDay(), 'completed', $now));
        $this->assertFalse(CalendarTime::isOverdue($now->subDay(), 'cancelled', $now));
        $this->assertFalse(CalendarTime::isOverdue($now->subDay(), 'submitted', $now), 'submitted is not overdue');
        $this->assertTrue(CalendarTime::isOverdue($now->subSecond(), 'pending', $now));
        $this->assertTrue(CalendarTime::isOverdue($now->subDay(), 'active', $now));
        $this->assertFalse(CalendarTime::isOverdue($now->addSecond(), 'pending', $now));
    }

    public function test_the_today_bucket_uses_the_users_calendar_not_the_utc_date()
    {
        // 23:30 UTC = 03:30 في دبي: يوم المستخدم محليًا هو 5 نوفمبر رغم أن UTC ما زال 4.
        $now = CarbonImmutable::parse('2026-11-04 23:30:00', 'UTC');
        $dueToday = CarbonImmutable::parse('2026-11-05 02:00:00', 'UTC');
        $dueTomorrow = CarbonImmutable::parse('2026-11-06 02:00:00', 'UTC');

        $this->assertSame('today', CalendarTime::bucket($dueToday, 'pending', $now, 'Asia/Dubai'));
        $this->assertSame('upcoming', CalendarTime::bucket($dueTomorrow, 'pending', $now, 'Asia/Dubai'));
        $this->assertSame('overdue', CalendarTime::bucket($now->subHour(), 'pending', $now, 'Asia/Dubai'));
        $this->assertSame('no_date', CalendarTime::bucket(null, 'pending', $now, 'Asia/Dubai'));
        $this->assertSame('completed', CalendarTime::bucket($now->subDay(), 'completed', $now, 'Asia/Dubai'));
        $this->assertSame('completed', CalendarTime::bucket($now->subDay(), 'submitted', $now, 'Asia/Dubai'));
    }

    public function test_start_of_day_is_local_midnight_returned_as_utc()
    {
        $now = CarbonImmutable::parse('2026-11-04 23:30:00', 'UTC');

        $this->assertSame('2026-11-04 20:00:00', CalendarTime::startOfDay($now, 'Asia/Dubai')->toDateTimeString());
        $this->assertSame('2026-11-04 00:00:00', CalendarTime::startOfDay($now, 'UTC')->toDateTimeString());
    }

    public function test_view_ranges_are_half_open_and_built_from_the_users_zone()
    {
        $day = CalendarTime::viewRange('day', '2026-11-04', 'Asia/Dubai');
        $this->assertSame('2026-11-03 20:00:00', $day['from']->toDateTimeString());
        $this->assertSame('2026-11-04 20:00:00', $day['to']->toDateTimeString());

        // 4 نوفمبر 2026 أحد، فأسبوع الاثنين يبدأ 2 نوفمبر محليًا = 1 نوفمبر 22:00 UTC.
        $week = CalendarTime::viewRange('week', '2026-11-04', 'UTC');
        $this->assertSame('2026-11-02 00:00:00', $week['from']->toDateTimeString());
        $this->assertSame('2026-11-09 00:00:00', $week['to']->toDateTimeString());

        $month = CalendarTime::viewRange('month', '2026-11-17', 'UTC');
        $this->assertSame('2026-11-01 00:00:00', $month['from']->toDateTimeString());
        $this->assertSame('2026-12-01 00:00:00', $month['to']->toDateTimeString());
    }

    public function test_a_null_timezone_declared_falls_back_to_the_app_timezone()
    {
        $this->assertSame((string) config('app.timezone'), CalendarTime::timezoneOf(null));
        $this->assertSame((string) config('app.timezone'), CalendarTime::timezoneOf('Mars/Olympus'));
        $this->assertSame('Asia/Dubai', CalendarTime::timezoneOf('Asia/Dubai'));
    }
}
