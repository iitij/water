<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class BackupControl
{
    public const ZONE = 'Asia/Aden';

    public const INTERVALS = [6, 12, 24, 48, 72, 168, 336, 720];

    public const TARGETS = ['server', 'msi'];

    private function path(bool $state = false): string
    {
        return config('water-backup.'.($state ? 'state_dir' : 'policy_dir'));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::ZONE));
    }

    private function read(string $name, bool $state = false): ?array
    {
        $file = $this->path($state).'/'.$name.'.json';
        if (is_link($file)) {
            throw new RuntimeException('Unexpected backup control link.');
        }
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid backup control data.');
        }

        return $data;
    }

    private function directory(bool $state): string
    {
        $dir = $this->path($state);
        if (is_link($dir) || (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir))) {
            throw new RuntimeException('Cannot prepare private backup control directory.');
        }

        return $dir;
    }

    private function write(string $name, array $data, bool $state = false): void
    {
        $dir = $this->directory($state);
        $tmp = tempnam($dir, '.control-');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create backup control file.');
        }
        try {
            if (! chmod($tmp, 0600) || file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR)."\n") === false
                || ! rename($tmp, $dir.'/'.$name.'.json')) {
                throw new RuntimeException('Cannot save backup control file.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function policy(?DateTimeImmutable $now = null): array
    {
        $saved = $this->read('policy');
        if ($saved !== null) {
            $this->validate($saved);
            if (! isset($saved['anchor']) || ! is_int($saved['anchor']) || $saved['anchor'] < 1) {
                throw new RuntimeException('Invalid schedule anchor.');
            }
            return $saved;
        }
        $now ??= $this->now();

        return ['enabled' => true, 'destination' => 'msi', 'interval_hours' => 24, 'time' => '10:30',
            'anchor' => $this->anchor('10:30', $now)];
    }

    public function initialize(): void
    {
        if ($this->read('policy') === null) {
            $this->write('policy', $this->policy());
        }
    }

    private function validate(array $p): void
    {
        if (! is_bool($p['enabled'] ?? null)
            || ! in_array($p['destination'] ?? null, self::TARGETS, true)
            || ! in_array($p['interval_hours'] ?? null, self::INTERVALS, true)
            || ! is_string($p['time'] ?? null)
            || ! preg_match('/^(?:[01][0-9]|2[0-3]):(?:00|05|10|15|20|25|30|35|40|45|50|55)$/D', $p['time'])) {
            throw new RuntimeException('Invalid backup policy.');
        }
    }

    private function anchor(string $time, DateTimeImmutable $now): int
    {
        $now = $now->setTimezone(new DateTimeZone(self::ZONE));
        [$h, $m] = array_map('intval', explode(':', $time));
        $start = $now->setTime($h, $m);
        if ($start <= $now) {
            $start = $start->modify('+1 day');
        }
        return $start->getTimestamp();
    }

    public function save(array $data, ?DateTimeImmutable $now = null): void
    {
        $now ??= $this->now();
        $next = [
            'enabled' => $data['enabled'] ?? null,
            'destination' => $data['destination'] ?? null,
            'interval_hours' => isset($data['interval_hours']) && ctype_digit((string) $data['interval_hours']) ? (int) $data['interval_hours'] : null,
            'time' => $data['time'] ?? null,
        ];
        $this->validate($next);
        $old = $this->policy($now);
        $next['anchor'] = ($old['enabled'] === $next['enabled'] && $old['interval_hours'] === $next['interval_hours']
            && $old['time'] === $next['time']) ? $old['anchor'] : $this->anchor($next['time'], $now);
        $this->write('policy', $next);
    }

    public function status(): array
    {
        return $this->read('scheduler', true) ?? [];
    }

    public function deviceStatus(): array
    {
        return $this->read('device-msi', true) ?? [];
    }

    public function deviceAllowed(): bool
    {
        return $this->policy()['destination'] === 'msi';
    }

    public function acknowledge(string $name, string $digest): void
    {
        if (! $this->deviceAllowed() || ! preg_match('/^water-\d{8}T\d{6}Z-[a-f0-9]{6}$/D', $name)
            || ! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new RuntimeException('Invalid backup verification receipt.');
        }
        $file = config('water-backup.archive_dir').'/'.$name.'/'.$name.'.tar.gz.gpg.sha256';
        if (! is_file($file) || is_link($file) || trim(file_get_contents($file)) !== $digest.'  '.$name.'.tar.gz.gpg') {
            throw new RuntimeException('Backup verification receipt does not match.');
        }
        $this->write('device-msi', ['archive' => $name, 'verified_at' => $this->now()->getTimestamp()], true);
    }

    public function nextDue(?DateTimeImmutable $now = null): ?int
    {
        $p = $this->policy($now);
        if (! $p['enabled']) {
            return null;
        }
        $t = ($now ?? $this->now())->getTimestamp();
        $anchor = $p['anchor'];
        $period = $p['interval_hours'] * 3600;
        if ($t < $anchor) {
            return $anchor;
        }
        $slot = $anchor + intdiv($t - $anchor, $period) * $period;
        $s = $this->status();
        return ($s['success_slot'] ?? null) === $slot ? $slot + $period : $slot;
    }

    /** A fixed CLI runner is injected; no web-supplied command, path or arguments. */
    public function tick(callable $runner, ?DateTimeImmutable $now = null): string
    {
        $now ??= $this->now();
        $lock = fopen($this->directory(true).'/scheduler.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open scheduler lock.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return 'busy';
        }
        try {
            $p = $this->policy($now);
            $t = $now->getTimestamp();
            if (! $p['enabled'] || $t < $p['anchor']) {
                return 'not-due';
            }
            $period = $p['interval_hours'] * 3600;
            $slot = $p['anchor'] + intdiv($t - $p['anchor'], $period) * $period;
            $s = $this->status();
            if (($s['success_slot'] ?? null) === $slot) {
                return 'already-complete';
            }
            // Never launch overlapping backups, and retry a failed slot at most every 30 minutes.
            if (($s['attempt_slot'] ?? null) === $slot && $t - ($s['attempt_at'] ?? 0) < 1800) {
                return 'retry-wait';
            }
            $s['attempt_slot'] = $slot;
            $s['attempt_at'] = $t;
            $s['result'] = 'running';
            $this->write('scheduler', $s, true);
            $ok = false;
            try {
                $ok = $runner() === true;
            } finally {
                $s['result'] = $ok ? 'success' : 'failed';
                if ($ok) {
                    $s['success_slot'] = $slot;
                    $s['success_at'] = $this->now()->getTimestamp();
                }
                $this->write('scheduler', $s, true);
            }
            return $s['result'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
