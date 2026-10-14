<?php

namespace PRSWS\CasaDelta;

use OPNsense\Base\IndexController;

class StatusController extends IndexController
{
    public function indexAction()
    {
        $this->view->pick('PRSWS/CasaDelta/status');
    }
}
