<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit77f042c7e09a035fda3688beb817fd4f
{
    public static $prefixLengthsPsr4 = array (
        'G' => 
        array (
            'Gajus\\Dindent\\' => 14,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Gajus\\Dindent\\' => 
        array (
            0 => __DIR__ . '/..' . '/gajus/dindent/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit77f042c7e09a035fda3688beb817fd4f::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit77f042c7e09a035fda3688beb817fd4f::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit77f042c7e09a035fda3688beb817fd4f::$classMap;

        }, null, ClassLoader::class);
    }
}
