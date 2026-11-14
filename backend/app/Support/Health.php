<?php

namespace App\Support;

use App\Models\OutgoingEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the parts that keep the installation alive are working: the
 * database, the queue worker, the scheduler, file storage and backups.
 * "failing" means people are affected now; "warning" means look at it soon.
 */
class Health
{
    public const SCHEDULER_KEY = 'health:scheduler';

    /** @return array{status: string, checked_at: string, checks: array<string, array{status: string, detail: string}>} */
    public static function report(): array
    {
        $checks = [];

        $probes = [
            'database' => self::database(...),
            'queue' => self::queue(...),
            'failed_jobs' => self::failedJobs(...),
            'scheduler' => self::scheduler(...),
            'storage' => self::storage(...),
            'mail' => self::mail(...),
            'backup' => self::backup(...),
        ];

        foreach ($probes as $name => $probe) {
            try {
                $checks[$name] = $probe();
            } catch (Throwable $e) {
                // A check that cannot run is a finding in itself, and must not hide the others.
                $checks[$name] = self::result('failing', 'The check itself failed: '.class_basename($e).'.');
            }
        }

        $statuses = array_column($checks, 'status');

        return [
            'status' => in_array('failing', $statuses, true) ? 'failing' : (in_array('warning', $statuses, true) ? 'warning' : 'ok'),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /** Called by the scheduler every minute; its absence is how a stopped scheduler is noticed. */
    public static function beat(): void
    {
        Cache::put(self::SCHEDULER_KEY, now()->getTimestamp(), now()->addDay());
    }

    /** @return array{status: string, detail: string} */
    private static function database(): array
    {
        DB::select('select 1');

        return self::result('ok', 'Answers.');
    }

    /** @return array{status: string, detail: string} */
    private static function queue(): array
    {
        $waiting = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $limit = (int) config('health.queue_max_wait_minutes');

        if ($oldest !== null && (int) $oldest < now()->subMinutes($limit)->getTimestamp()) {
            $minutes = intdiv(now()->getTimestamp() - (int) $oldest, 60);

            return self::result('failing', "A job has waited {$minutes} minutes: the queue worker is not running. Notifications and webhooks are stuck.");
        }

        return self::result('ok', $waiting === 0 ? 'Nothing waits.' : "{$waiting} waiting, none for long.");
    }

    /** @return array{status: string, detail: string} */
    private static function failedJobs(): array
    {
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return $failed === 0
            ? self::result('ok', 'None in the last day.')
            : self::result('warning', "{$failed} in the last day. See `php artisan queue:failed`.");
    }

    /** @return array{status: string, detail: string} */
    private static function scheduler(): array
    {
        $last = Cache::get(self::SCHEDULER_KEY);

        if (! is_numeric($last) || (int) $last < now()->subMinutes(5)->getTimestamp()) {
            return self::result('failing', 'No sign of the scheduler for over 5 minutes: cycles, announcements and clean-up do not run. Start `php artisan schedule:work` or the cron entry.');
        }

        return self::result('ok', 'Ran within the last 5 minutes.');
    }

    /** @return array{status: string, detail: string} */
    private static function storage(): array
    {
        $path = storage_path('app');

        return is_dir($path) && is_writable($path)
            ? self::result('ok', 'Writable.')
            : self::result('failing', 'The uploads folder cannot be written to.');
    }

    /** @return array{status: string, detail: string} */
    private static function mail(): array
    {
        $failed = OutgoingEmail::query()->where('status', OutgoingEmail::FAILED)->count();

        return $failed === 0
            ? self::result('ok', 'No failed messages.')
            : self::result('warning', "{$failed} announcement messages failed. See Operator area → Email.");
    }

    /** @return array{status: string, detail: string} */
    private static function backup(): array
    {
        $file = config('health.backup_heartbeat');

        if (! is_string($file) || $file === '') {
            return self::result('ok', 'Not watched here (BACKUP_HEARTBEAT is not set).');
        }

        $hours = (int) config('health.backup_max_age_hours');

        if (! is_file($file) || filemtime($file) < now()->subHours($hours)->getTimestamp()) {
            return self::result('failing', "No successful backup in the last {$hours} hours.");
        }

        return self::result('ok', 'Last successful backup: '.date('c', (int) filemtime($file)).'.');
    }

    /** @return array{status: string, detail: string} */
    private static function result(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }
}
