<?php

declare(strict_types=1);

namespace EmailIntegrity\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleTest extends TestCase
{
    public function test_the_update_runs_daily_on_every_server(): void
    {
        $this->assertSame([['0 3 * * *', false, false]], $this->scheduled());
    }

    public function test_schedule_false_leaves_the_schedule_to_the_app(): void
    {
        config(['email-integrity.schedule' => false]);

        $this->assertSame([], $this->scheduled());
    }

    /** @return list<array{string, bool, bool}> [cron expression, withoutOverlapping, onOneServer] */
    private function scheduled(): array
    {
        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_ends_with((string)$event->command, 'email-integrity:update'))
            ->map(fn (Event $event): array => [$event->expression, $event->withoutOverlapping, $event->onOneServer])
            ->values()
            ->all();
    }
}
