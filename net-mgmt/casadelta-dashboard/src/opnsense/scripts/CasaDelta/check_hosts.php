#!/usr/local/bin/php
<?php

/*
 * configd action "casadelta check_hosts [force]" (contracts/configd-check-hosts.md).
 *
 * Probes every enabled monitored host with two ICMP echo requests, writes the shared snapshot
 * /tmp/casadelta_dashboard_status.json and prints one JSON document. Read-only toward the
 * firewall configuration. No daemon: the script exits when the run ends.
 */

declare(strict_types=1);

const CASADELTA_SNAPSHOT_FILE = '/tmp/casadelta_dashboard_status.json';
const CASADELTA_LOCK_FILE = '/tmp/casadelta_dashboard_check.lock';
const CASADELTA_PING = '/sbin/ping';
const CASADELTA_LIB_DIR = '/usr/local/opnsense/mvc/app/library/PRSWS/CasaDelta/Lib';
const CASADELTA_MAX_PARALLEL = 16;
const CASADELTA_PROBE_DEADLINE = 4.0;
const CASADELTA_RUN_CAP = 20.0;
const CASADELTA_LOCK_WAIT = 25.0;

spl_autoload_register(static function (string $class): void {
    $prefix = 'PRSWS\\CasaDelta\\Lib\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = CASADELTA_LIB_DIR . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use PRSWS\CasaDelta\Lib\HostAddress;
use PRSWS\CasaDelta\Lib\PingResultParser;
use PRSWS\CasaDelta\Lib\SnapshotFreshness;

function casadelta_iso(int $time): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $time);
}

/**
 * @return array<string, mixed>|null
 */
function casadelta_read_snapshot(): ?array
{
    $raw = @file_get_contents(CASADELTA_SNAPSHOT_FILE);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * Enabled hosts from //PRSWS/CasaDelta/hosts, read-only.
 *
 * @return list<array{uuid: string, address: string}>
 */
function casadelta_load_enabled_hosts(): array
{
    require_once('script/load_phalcon.php');

    $hosts = [];
    try {
        $model = new \PRSWS\CasaDelta\CasaDelta();
        foreach ($model->hosts->host->iterateItems() as $uuid => $node) {
            if ((string)$node->enabled === '1') {
                $hosts[] = ['uuid' => (string)$uuid, 'address' => (string)$node->address];
            }
        }
    } catch (\Throwable $e) {
        // Fallback (research R-06): read the config section directly.
        $hosts = [];
        $config = \OPNsense\Core\Config::getInstance()->object();
        if (isset($config->PRSWS->CasaDelta->hosts->host)) {
            foreach ($config->PRSWS->CasaDelta->hosts->host as $node) {
                if ((string)$node->enabled === '1') {
                    $hosts[] = ['uuid' => (string)$node->attributes()['uuid'], 'address' => (string)$node->address];
                }
            }
        }
    }

    return $hosts;
}

/**
 * @param array<string, mixed>|null $previous
 * @return array<string, mixed>
 */
function casadelta_failure(string $message, ?array $previous): array
{
    $results = $previous !== null && is_array($previous['results'] ?? null) ? $previous['results'] : [];
    $failure = [
        'schema' => SnapshotFreshness::SCHEMA,
        'ok' => false,
        'error' => $message,
        'performed' => false,
        'retry_after_s' => 0,
    ];
    if ($previous !== null && is_string($previous['finished_at'] ?? null)) {
        $failure['finished_at'] = $previous['finished_at'];
    }
    $failure['results'] = $results;

    return $failure;
}

/**
 * @param array<string, mixed> $snapshot
 */
function casadelta_write_snapshot(array $snapshot): void
{
    $tmp = CASADELTA_SNAPSHOT_FILE . '.tmp';
    $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json) === false) {
        throw new \RuntimeException('cannot write snapshot');
    }
    chmod($tmp, 0644);
    if (!rename($tmp, CASADELTA_SNAPSHOT_FILE)) {
        throw new \RuntimeException('cannot replace snapshot');
    }
}

/**
 * @param resource $process
 * @param array<int, resource> $pipes
 * @return array{state: string, latency_ms: ?float, error_message: ?string}
 */
function casadelta_finish_probe(
    $process,
    array $pipes,
    string $stdout,
    string $stderr,
    int $exitCode,
    bool $timedOut,
    string $host
): array {
    $stdout .= (string)@stream_get_contents($pipes[1]);
    $stderr .= (string)@stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return PingResultParser::parse($exitCode, $stdout, $stderr, $host, $timedOut);
}

/**
 * Runs the bounded ping pool: at most CASADELTA_MAX_PARALLEL processes, a per-process deadline
 * (SIGKILL) and a whole-run cap. No shell is involved.
 *
 * @param array<string, HostAddress> $targets uuid => address
 * @return array<string, array<string, mixed>> uuid => result
 */
function casadelta_run_probes(array $targets): array
{
    $results = [];
    $queue = $targets;
    $running = [];
    $runStart = microtime(true);

    $record = static function (string $uuid, array $probe) use (&$results): void {
        $results[$uuid] = [
            'state' => $probe['state'],
            'latency_ms' => $probe['latency_ms'],
            'last_checked' => casadelta_iso(time()),
            'error_message' => $probe['error_message'],
        ];
    };

    while (count($queue) > 0 || count($running) > 0) {
        $now = microtime(true);

        if ($now - $runStart >= CASADELTA_RUN_CAP) {
            foreach ($running as $uuid => $job) {
                proc_terminate($job['process'], 9);
                $record($uuid, casadelta_finish_probe(
                    $job['process'], $job['pipes'], $job['stdout'], $job['stderr'], -1, true, $job['host']
                ));
            }
            $running = [];
            foreach ($queue as $uuid => $address) {
                $record($uuid, PingResultParser::parse(-1, '', '', $address->host, true));
            }
            $queue = [];
            break;
        }

        while (count($queue) > 0 && count($running) < CASADELTA_MAX_PARALLEL) {
            $uuid = array_key_first($queue);
            $address = $queue[$uuid];
            unset($queue[$uuid]);

            $argv = [CASADELTA_PING, '-c', '2', '-i', '0.5', '-W', '1000', '-t', '3'];
            if ($address->kind === HostAddress::KIND_IPV4) {
                $argv[] = '-4';
            } elseif ($address->kind === HostAddress::KIND_IPV6) {
                $argv[] = '-6';
            }
            $argv[] = $address->host;

            $pipes = [];
            $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                $record($uuid, PingResultParser::parse(-1, '', '', $address->host));
                continue;
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$uuid] = [
                'process' => $process,
                'pipes' => $pipes,
                'stdout' => '',
                'stderr' => '',
                'started' => microtime(true),
                'host' => $address->host,
            ];
        }

        if (count($running) === 0) {
            continue;
        }

        $read = [];
        foreach ($running as $job) {
            $read[] = $job['pipes'][1];
            $read[] = $job['pipes'][2];
        }
        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 0, 100000);

        foreach ($running as $uuid => $job) {
            $running[$uuid]['stdout'] .= (string)fread($job['pipes'][1], 8192);
            $running[$uuid]['stderr'] .= (string)fread($job['pipes'][2], 8192);
            $job = $running[$uuid];

            $status = proc_get_status($job['process']);
            if (!$status['running']) {
                $record($uuid, casadelta_finish_probe(
                    $job['process'], $job['pipes'], $job['stdout'], $job['stderr'],
                    (int)$status['exitcode'], false, $job['host']
                ));
                unset($running[$uuid]);
            } elseif (microtime(true) - $job['started'] >= CASADELTA_PROBE_DEADLINE) {
                proc_terminate($job['process'], 9);
                $record($uuid, casadelta_finish_probe(
                    $job['process'], $job['pipes'], $job['stdout'], $job['stderr'], -1, true, $job['host']
                ));
                unset($running[$uuid]);
            }
        }
    }

    return $results;
}

function casadelta_main(array $argv): void
{
    $force = ($argv[1] ?? '') === 'force';
    $previous = casadelta_read_snapshot();
    $lock = null;

    try {
        try {
            $hosts = casadelta_load_enabled_hosts();
        } catch (\Throwable $e) {
            syslog(LOG_ERR, 'host configuration could not be loaded: ' . get_class($e));
            echo json_encode(casadelta_failure('Host configuration could not be loaded.', $previous), JSON_UNESCAPED_SLASHES);
            return;
        }
        $fingerprint = SnapshotFreshness::fingerprint($hosts);

        $lock = fopen(CASADELTA_LOCK_FILE, 'c');
        if ($lock === false) {
            throw new \RuntimeException('cannot open lock file');
        }
        $waitStart = microtime(true);
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) - $waitStart >= CASADELTA_LOCK_WAIT) {
                throw new \RuntimeException('lock wait timed out');
            }
            usleep(100000);
        }

        // Double-checked locking: another run may have finished while we waited for the lock.
        $existing = casadelta_read_snapshot();
        $now = time();
        if (SnapshotFreshness::isInCooldown($existing, $now)) {
            echo json_encode(
                array_merge($existing, ['performed' => false, 'retry_after_s' => SnapshotFreshness::retryAfter($existing, $now)]),
                JSON_UNESCAPED_SLASHES
            );
            return;
        }
        if (!$force && SnapshotFreshness::isFresh($existing, $now, $fingerprint)) {
            echo json_encode(array_merge($existing, ['performed' => false, 'retry_after_s' => 0]), JSON_UNESCAPED_SLASHES);
            return;
        }

        $started = microtime(true);
        $startedAt = time();
        $targets = [];
        $results = [];
        foreach ($hosts as $host) {
            $address = HostAddress::tryParse($host['address']);
            if ($address === null) {
                $results[$host['uuid']] = [
                    'state' => 'down',
                    'latency_ms' => null,
                    'last_checked' => casadelta_iso(time()),
                    'error_message' => 'invalid address',
                ];
                continue;
            }
            $targets[$host['uuid']] = $address;
        }
        $results = array_merge($results, casadelta_run_probes($targets));
        $durationMs = (int)round((microtime(true) - $started) * 1000);

        $snapshot = [
            'schema' => SnapshotFreshness::SCHEMA,
            'ok' => true,
            'error' => null,
            'started_at' => casadelta_iso($startedAt),
            'finished_at' => casadelta_iso(time()),
            'duration_ms' => $durationMs,
            'fingerprint' => $fingerprint,
            'results' => $results,
        ];
        casadelta_write_snapshot($snapshot);
        syslog(LOG_NOTICE, sprintf('checked %d hosts in %d ms', count($hosts), $durationMs));

        echo json_encode(array_merge($snapshot, ['performed' => true, 'retry_after_s' => 0]), JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        syslog(LOG_ERR, 'host check failed: ' . get_class($e) . ': ' . $e->getMessage());
        $failure = casadelta_failure('Host check could not be completed.', $previous);
        if ($lock !== null && $lock !== false) {
            try {
                $persisted = $failure;
                unset($persisted['performed'], $persisted['retry_after_s']);
                $persisted['finished_at'] = casadelta_iso(time());
                casadelta_write_snapshot($persisted);
            } catch (\Throwable) {
                // Best effort only: the failure is still reported on stdout.
            }
        }
        echo json_encode($failure, JSON_UNESCAPED_SLASHES);
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

openlog('casadelta-dashboard', LOG_ODELAY, LOG_LOCAL4);
casadelta_main($argv);
echo "\n";
