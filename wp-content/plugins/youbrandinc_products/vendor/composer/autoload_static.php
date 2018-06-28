<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit2c5e0f670ff0d53089d0addb6dcea367
{
    public static $files = array (
        'ad155f8f1cf0d418fe49e248db8c661b' => __DIR__ . '/..' . '/react/promise/src/functions_include.php',
        '5255c38a0faeba867671b61dfda6d864' => __DIR__ . '/..' . '/paragonie/random_compat/lib/random.php',
        '5c2defbf7f7cf93c47ed4965a7eb595e' => __DIR__ . '/..' . '/seregazhuk/pinterest-bot/src/Helpers/functions.php',
    );

    public static $prefixLengthsPsr4 = array (
        's' => 
        array (
            'seregazhuk\\PinterestBot\\' => 24,
        ),
        'R' => 
        array (
            'React\\Promise\\' => 14,
            'Ramsey\\Uuid\\' => 12,
        ),
        'I' => 
        array (
            'Instagram\\' => 10,
            'Imgur\\' => 6,
        ),
        'G' => 
        array (
            'GuzzleHttp\\Stream\\' => 18,
            'GuzzleHttp\\Ring\\' => 16,
            'GuzzleHttp\\' => 11,
        ),
        'C' => 
        array (
            'Curl\\' => 5,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'seregazhuk\\PinterestBot\\' => 
        array (
            0 => __DIR__ . '/..' . '/seregazhuk/pinterest-bot/src',
        ),
        'React\\Promise\\' => 
        array (
            0 => __DIR__ . '/..' . '/react/promise/src',
        ),
        'Ramsey\\Uuid\\' => 
        array (
            0 => __DIR__ . '/..' . '/ramsey/uuid/src',
        ),
        'Instagram\\' => 
        array (
            0 => __DIR__ . '/..' . '/liamcottle/instagram-sdk-php/src',
        ),
        'Imgur\\' => 
        array (
            0 => __DIR__ . '/..' . '/j0k3r/php-imgur-api-client/lib/Imgur',
        ),
        'GuzzleHttp\\Stream\\' => 
        array (
            0 => __DIR__ . '/..' . '/guzzlehttp/streams/src',
        ),
        'GuzzleHttp\\Ring\\' => 
        array (
            0 => __DIR__ . '/..' . '/guzzlehttp/ringphp/src',
        ),
        'GuzzleHttp\\' => 
        array (
            0 => __DIR__ . '/..' . '/guzzlehttp/guzzle/src',
        ),
        'Curl\\' => 
        array (
            0 => __DIR__ . '/..' . '/php-curl-class/php-curl-class/src/Curl',
        ),
    );

    public static $prefixesPsr0 = array (
        'O' => 
        array (
            'OAuth\\Unit' => 
            array (
                0 => __DIR__ . '/..' . '/lusitanian/oauth/tests',
            ),
            'OAuth' => 
            array (
                0 => __DIR__ . '/..' . '/lusitanian/oauth/src',
            ),
        ),
        'J' => 
        array (
            'JsonMapper' => 
            array (
                0 => __DIR__ . '/..' . '/netresearch/jsonmapper/src',
            ),
        ),
    );

    public static $classMap = array (
        'TwitterAPIExchange' => __DIR__ . '/..' . '/j7mbo/twitter-api-php/TwitterAPIExchange.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit2c5e0f670ff0d53089d0addb6dcea367::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit2c5e0f670ff0d53089d0addb6dcea367::$prefixDirsPsr4;
            $loader->prefixesPsr0 = ComposerStaticInit2c5e0f670ff0d53089d0addb6dcea367::$prefixesPsr0;
            $loader->classMap = ComposerStaticInit2c5e0f670ff0d53089d0addb6dcea367::$classMap;

        }, null, ClassLoader::class);
    }
}