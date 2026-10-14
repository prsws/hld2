<?php

namespace PRSWS\CasaDelta\FieldTypes;

use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\Validators\CallbackValidator;
use PRSWS\CasaDelta\Lib\HostAddress;
use PRSWS\CasaDelta\Lib\HostAddressException;

require_once __DIR__ . '/../../../../library/PRSWS/CasaDelta/Lib/autoload.php';

/**
 * Host address: IPv4, IPv6 or hostname, optionally followed by a port (research R-04).
 * The parser's error message is returned as the validation message.
 */
class HostAddressField extends BaseField
{
    protected $internalIsContainer = false;

    public function getValidators()
    {
        $validators = parent::getValidators();
        if ($this->internalValue !== null && $this->internalValue !== '') {
            $validators[] = new CallbackValidator([
                'callback' => function ($data) {
                    try {
                        HostAddress::parse((string)$data);
                    } catch (HostAddressException $e) {
                        return [$e->getMessage()];
                    }
                    return [];
                }
            ]);
        }
        return $validators;
    }
}
