<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit127f479f980ee953172ea768c643d12d
{
    public static $files = array (
        '5ff2501974ebd86c0be698ddfca6451e' => __DIR__ . '/..' . '/yahnis-elsts/plugin-update-checker/load-v5p0.php',
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->classMap = ComposerStaticInit127f479f980ee953172ea768c643d12d::$classMap;

        }, null, ClassLoader::class);
    }
}