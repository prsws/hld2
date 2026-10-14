<?php

namespace PRSWS\CasaDelta\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * Settings API (contracts/settings-api.md). Thin wrappers around the base helpers on
 * hosts.host. There is no service to reconfigure: a saved change takes effect at the next
 * status request.
 */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'casadelta';
    protected static $internalModelClass = 'PRSWS\CasaDelta\CasaDelta';

    public function searchHostAction()
    {
        return $this->searchBase(
            'hosts.host',
            ['enabled', 'group', 'sort_order', 'name', 'address', 'description'],
            'group'
        );
    }

    public function getHostAction($uuid = null)
    {
        return $this->getBase('host', 'hosts.host', $uuid);
    }

    public function addHostAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $this->normalizeSortOrder();

        return $this->addBase('host', 'hosts.host');
    }

    public function setHostAction($uuid)
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $this->normalizeSortOrder();

        return $this->setBase('host', 'hosts.host', $uuid);
    }

    public function delHostAction($uuid)
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }

        return $this->delBase('hosts.host', $uuid);
    }

    public function toggleHostAction($uuid, $enabled = null)
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }

        return $this->toggleBase('hosts.host', $uuid, $enabled);
    }

    /**
     * An empty sort order is stored as 0 (data-model.md section 1).
     */
    private function normalizeSortOrder(): void
    {
        if (isset($_POST['host']) && is_array($_POST['host'])
                && array_key_exists('sort_order', $_POST['host']) && trim((string)$_POST['host']['sort_order']) === '') {
            $_POST['host']['sort_order'] = '0';
        }
    }
}
