<?php

namespace PRSWS\CasaDelta;

use OPNsense\Base\IndexController;

class SettingsController extends IndexController
{
    public function indexAction()
    {
        $this->view->formDialogHost = $this->getForm('dialogHost');
        $this->view->pick('PRSWS/CasaDelta/settings');
    }
}
