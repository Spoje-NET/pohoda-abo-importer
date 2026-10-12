<?php

declare(strict_types=1);
defined('APP_NAME') || define('APP_NAME', 'spojenet/pohoda-abo-importer');
defined('APP_VERSION') || define('APP_VERSION', '1.0.1');

require_once '/usr/share/php/Composer/InstalledVersions.php';
require_once '/usr/share/php/Ease/autoload.php';
require_once '/usr/share/php/mServer/autoload.php';
require_once '/usr/share/php/SpojeNet/AboParser/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'SpojeNet\\Pohoda\\AboImporter\\';
    if (strncmp($prefix, $class, \strlen($prefix)) !== 0) {
        return;
    }
    $file = '/usr/lib/pohoda-abo-importer/AboImporter/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

(function (): void {
    $versions = [];
    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }
    $name    = 'spojenet/pohoda-abo-importer';
    $version = '1.0.1';
    $versions[$name] = ['pretty_version' => $version, 'version' => $version,
        'reference' => null, 'type' => 'project', 'install_path' => __DIR__,
        'aliases' => [], 'dev_requirement' => false];
    \Composer\InstalledVersions::reload([
        'root' => ['name' => $name, 'pretty_version' => $version, 'version' => $version,
            'reference' => null, 'type' => 'project', 'install_path' => __DIR__,
            'aliases' => [], 'dev' => false],
        'versions' => $versions,
    ]);
})();
