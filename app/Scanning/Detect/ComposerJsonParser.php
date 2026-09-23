<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

final class ComposerJsonParser
{
    private const FRAMEWORKS = [
        'laravel/framework' => 'laravel',
        'laravel/lumen-framework' => 'lumen',
        'symfony/framework-bundle' => 'symfony',
        'symfony/symfony' => 'symfony',
        'livewire/livewire' => 'livewire',
        'inertiajs/inertia-laravel' => 'inertia',
        'filament/filament' => 'filament',
        'statamic/cms' => 'statamic',
        'slim/slim' => 'slim',
        'cakephp/cakephp' => 'cakephp',
        'codeigniter4/framework' => 'codeigniter',
        'yiisoft/yii2' => 'yii',
        'laminas/laminas-mvc' => 'laminas',
        'drupal/core' => 'drupal',
        'roots/bedrock' => 'wordpress',
        'johnpbloch/wordpress' => 'wordpress',
    ];

    private const TOOLING = [
        'phpstan/phpstan' => 'phpstan',
        'larastan/larastan' => 'larastan',
        'nunomaduro/larastan' => 'larastan',
        'laravel/pint' => 'pint',
        'friendsofphp/php-cs-fixer' => 'php-cs-fixer',
        'squizlabs/php_codesniffer' => 'phpcs',
        'vimeo/psalm' => 'psalm',
        'pestphp/pest' => 'pest',
        'phpunit/phpunit' => 'phpunit',
        'rector/rector' => 'rector',
        'infection/infection' => 'infection',
        'phpmd/phpmd' => 'phpmd',
    ];

    public static function parse(string $json): ManifestData
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return new ManifestData('composer');
        }

        $constraints = [];
        $php = '';
        foreach (['require', 'require-dev'] as $section) {
            foreach (is_array($data[$section] ?? null) ? $data[$section] : [] as $package => $constraint) {
                $package = (string) $package;
                if ($package === 'php') {
                    $php = is_string($constraint) ? $constraint : '';
                } elseif (str_contains($package, '/')) {
                    $constraints[$package] ??= is_string($constraint) ? $constraint : '';
                }
            }
        }

        return ManifestData::fromConstraints('composer', $constraints, self::FRAMEWORKS, self::TOOLING, ['php' => $php]);
    }
}
