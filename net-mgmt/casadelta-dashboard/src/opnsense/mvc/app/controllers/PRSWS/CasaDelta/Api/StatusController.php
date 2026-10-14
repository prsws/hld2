<?php

namespace PRSWS\CasaDelta\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use PRSWS\CasaDelta\CasaDelta;
use PRSWS\CasaDelta\Lib\SnapshotFreshness;
use PRSWS\CasaDelta\Lib\StatusAssembler;

require_once __DIR__ . '/../../../../library/PRSWS/CasaDelta/Lib/autoload.php';

/**
 * Status API (contracts/status-api.md). Never changes the firewall configuration.
 */
class StatusController extends ApiControllerBase
{
    private const SNAPSHOT_FILE = '/tmp/casadelta_dashboard_status.json';
    private const CONFIGD_TIMEOUT = 30;
    private const BACKEND_ERROR = 'Host check could not be executed (backend timeout).';

    /**
     * GET /api/casadelta/status/get
     */
    public function getAction()
    {
        if (!$this->request->isGet()) {
            return ['status' => 'failed'];
        }

        return $this->buildStatus(false)['body'];
    }

    /**
     * POST /api/casadelta/status/refresh
     */
    public function refreshAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed'];
        }
        $result = $this->buildStatus(true);
        $body = $result['body'];
        $performed = $result['performed'];
        $body['refresh'] = [
            'performed' => $performed,
            'retry_after_s' => $performed ? 5 : max(1, min(5, $result['retry_after_s'])),
        ];

        return $body;
    }

    /**
     * @return array{body: array<string, mixed>, performed: bool, retry_after_s: int}
     */
    private function buildStatus(bool $force): array
    {
        $hosts = $this->loadHosts();
        $enabled = array_values(array_filter($hosts, static fn(array $h): bool => $h['enabled'] === '1'));

        if (count($enabled) === 0) {
            // Nothing to check: configd is never called.
            return [
                'body' => StatusAssembler::assemble($hosts, null),
                'performed' => false,
                'retry_after_s' => 5,
            ];
        }

        $fingerprint = SnapshotFreshness::fingerprint($enabled);
        $snapshot = $this->readSnapshot();
        $performed = false;
        $retryAfter = 0;
        $error = null;

        if ($force || !SnapshotFreshness::isFresh($snapshot, time(), $fingerprint)) {
            $output = $this->runCheck($force);
            if ($output === null) {
                $error = self::BACKEND_ERROR;
            } else {
                $performed = ($output['performed'] ?? false) === true;
                $retryAfter = (int)($output['retry_after_s'] ?? 0);
                unset($output['performed'], $output['retry_after_s']);
                $snapshot = $output;
            }
        }

        return [
            'body' => StatusAssembler::assemble($hosts, $snapshot, $error),
            'performed' => $performed,
            'retry_after_s' => $retryAfter,
        ];
    }

    /**
     * All configured hosts from the current configuration.
     *
     * @return list<array<string, string>>
     */
    private function loadHosts(): array
    {
        $hosts = [];
        $model = new CasaDelta();
        foreach ($model->hosts->host->iterateItems() as $uuid => $node) {
            $hosts[] = [
                'uuid' => (string)$uuid,
                'enabled' => (string)$node->enabled,
                'name' => (string)$node->name,
                'address' => (string)$node->address,
                'group' => (string)$node->group,
                'sort_order' => (string)$node->sort_order,
            ];
        }

        return $hosts;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSnapshot(): ?array
    {
        $raw = @file_get_contents(self::SNAPSHOT_FILE);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Runs the configd action. Returns the decoded script output, or null when configd failed,
     * timed out or returned something that is not a JSON document.
     *
     * @return array<string, mixed>|null
     */
    private function runCheck(bool $force): ?array
    {
        try {
            $action = 'casadelta check_hosts' . ($force ? ' force' : '');
            $response = trim((new Backend())->configdRun($action, false, self::CONFIGD_TIMEOUT));
        } catch (\Throwable $e) {
            return null;
        }
        if ($response === '' || stripos($response, 'Execute error') !== false) {
            return null;
        }
        $data = json_decode($response, true);

        return is_array($data) ? $data : null;
    }
}
