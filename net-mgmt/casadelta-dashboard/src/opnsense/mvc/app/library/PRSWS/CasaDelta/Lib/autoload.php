<?php

declare(strict_types=1);

// Minimal PSR-4 loader for PRSWS\CasaDelta\Lib, so the plugin does not depend on how the
// OPNsense class loader maps vendor library directories. Safe to require more than once.
spl_autoload_register(static function (string $class): void {
    $prefix = 'PRSWS\\CasaDelta\\Lib\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
