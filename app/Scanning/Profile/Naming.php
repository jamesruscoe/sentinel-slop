<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

/**
 * Path conventions the profile relies on: which directory an "area" is,
 * what kind of file a name suggests, and the feature stem a filename
 * shares with its siblings (DogController, StoreDogRequest, Dogs/Index.vue
 * and dog.ts all have the stem "dog").
 *
 * These are conventions across ecosystems, not a Laravel analyser: a file
 * under handlers/ is a handler in Go, Python or PHP alike.
 */
final class Naming
{
    /** Top-level directories that are containers, so areas are one level deeper. */
    private const CONTAINERS = ['app', 'src', 'lib', 'resources', 'packages', 'internal', 'pkg', 'cmd', 'modules', 'apps', 'database', 'source', 'server', 'client', 'backend', 'frontend', 'web'];

    /** Second-level directories that are themselves containers (resources/js/pages, app/Http/Controllers). */
    private const NESTED_CONTAINERS = ['js', 'ts', 'http', 'src', 'app', 'lib', 'main', 'python', 'java', 'kotlin', 'domain', 'infrastructure', 'application'];

    /** Filename suffix (PascalCase) or directory name => kind. Suffix wins over directory. */
    private const SUFFIX_KINDS = [
        'Controller' => 'controller', 'Request' => 'request', 'Resource' => 'resource', 'Collection' => 'resource',
        'Service' => 'service', 'Job' => 'job', 'Listener' => 'listener', 'Event' => 'event', 'Policy' => 'policy',
        'Middleware' => 'middleware', 'Command' => 'command', 'Notification' => 'notification', 'Mail' => 'mail', 'Mailable' => 'mail',
        'Provider' => 'provider', 'ServiceProvider' => 'provider', 'Exception' => 'exception', 'Action' => 'action', 'Repository' => 'repository',
        'Factory' => 'factory', 'Seeder' => 'seeder', 'Test' => 'test', 'Handler' => 'handler', 'Store' => 'store', 'Layout' => 'layout',
        'Observer' => 'observer', 'Rule' => 'rule', 'Cast' => 'cast', 'Scope' => 'scope', 'Enum' => 'enum', 'Trait' => 'trait',
        'Interface' => 'contract', 'Contract' => 'contract', 'Helper' => 'helper', 'Util' => 'helper', 'Utils' => 'helper', 'Support' => 'helper',
        'Manager' => 'service', 'Client' => 'service', 'Gateway' => 'service', 'Presenter' => 'presenter', 'Transformer' => 'presenter',
        'Serializer' => 'presenter', 'Schema' => 'schema', 'Model' => 'model', 'Data' => 'dto', 'Dto' => 'dto', 'DTO' => 'dto',
        'Form' => 'form', 'Modal' => 'component', 'Card' => 'component', 'Table' => 'component', 'Button' => 'component',
        'Page' => 'page', 'View' => 'view', 'Migration' => 'migration',
    ];

    private const DIRECTORY_KINDS = [
        'controllers' => 'controller', 'requests' => 'request', 'resources' => 'resource', 'services' => 'service', 'jobs' => 'job',
        'listeners' => 'listener', 'events' => 'event', 'policies' => 'policy', 'middleware' => 'middleware', 'commands' => 'command',
        'console' => 'command', 'notifications' => 'notification', 'mail' => 'mail', 'providers' => 'provider', 'exceptions' => 'exception',
        'actions' => 'action', 'repositories' => 'repository', 'factories' => 'factory', 'seeders' => 'seeder', 'seeds' => 'seeder',
        'migrations' => 'migration', 'tests' => 'test', 'test' => 'test', '__tests__' => 'test', 'spec' => 'test', 'specs' => 'test',
        'handlers' => 'handler', 'stores' => 'store', 'store' => 'store', 'layouts' => 'layout', 'pages' => 'page', 'views' => 'view',
        'components' => 'component', 'composables' => 'composable', 'hooks' => 'composable', 'types' => 'type', 'interfaces' => 'contract',
        'contracts' => 'contract', 'enums' => 'enum', 'models' => 'model', 'entities' => 'model', 'domain' => 'model', 'dto' => 'dto', 'dtos' => 'dto',
        'data' => 'dto', 'config' => 'config', 'routes' => 'route', 'router' => 'route', 'helpers' => 'helper', 'utils' => 'helper', 'util' => 'helper',
        'support' => 'helper', 'lib' => 'helper', 'observers' => 'observer', 'rules' => 'rule', 'casts' => 'cast', 'scopes' => 'scope',
        'traits' => 'trait', 'concerns' => 'trait', 'schemas' => 'schema', 'serializers' => 'presenter', 'presenters' => 'presenter',
        'transformers' => 'presenter', 'forms' => 'form', 'api' => 'controller', 'endpoints' => 'controller', 'graphql' => 'controller',
        'assets' => 'asset', 'public' => 'asset', 'static' => 'asset', 'scripts' => 'script', 'bin' => 'script', 'tools' => 'script',
        'docs' => 'doc', 'doc' => 'doc', 'infra' => 'infra', 'terraform' => 'infra', 'deploy' => 'infra', 'docker' => 'infra', 'k8s' => 'infra',
    ];

    /** Kinds that make up a service layer: business logic, not transport or UI. */
    public const SERVICE_KINDS = ['service', 'job', 'listener', 'action', 'command', 'repository', 'handler', 'observer'];

    /** Kinds that receive input from the outside world. */
    public const INPUT_KINDS = ['controller', 'handler', 'route', 'middleware', 'request', 'form'];

    /** Kinds that hold business or transport logic: the only kinds for which "no tests" is reported as a finding. */
    public const LOGIC_KINDS = ['service', 'job', 'listener', 'action', 'command', 'repository', 'handler', 'observer', 'controller', 'model', 'policy', 'middleware', 'helper', 'rule', 'cast', 'presenter', 'request'];

    /** Kinds that are UI: importing one of these is not using it as a logging wrapper. */
    public const UI_KINDS = ['page', 'component', 'layout', 'view', 'form', 'asset'];

    /** Kinds that are UI, config, data or scaffolding: tests for them are not expected in the same way. */
    public const NON_LOGIC_KINDS = ['page', 'component', 'layout', 'type', 'config', 'route', 'migration', 'factory', 'seeder', 'asset', 'script', 'doc', 'infra', 'view', 'test', 'enum', 'contract', 'dto', 'schema', 'provider', 'exception', 'event', 'mail', 'notification', 'form', 'store', 'composable'];

    /** Words removed from a filename before the stem is taken. */
    private const ROLE_WORDS = [
        'controller', 'request', 'resource', 'collection', 'service', 'job', 'listener', 'event', 'policy', 'middleware', 'command',
        'notification', 'mail', 'mailable', 'provider', 'exception', 'action', 'repository', 'factory', 'seeder', 'test', 'tests', 'spec',
        'handler', 'store', 'layout', 'observer', 'rule', 'cast', 'scope', 'enum', 'trait', 'interface', 'contract', 'helper', 'util', 'utils',
        'support', 'manager', 'client', 'gateway', 'presenter', 'transformer', 'serializer', 'schema', 'model', 'data', 'dto', 'form',
        'modal', 'card', 'table', 'button', 'page', 'view', 'migration', 'index', 'show', 'edit', 'create', 'update', 'store', 'destroy',
        'delete', 'list', 'detail', 'details', 'item', 'items', 'use', 'api', 'type', 'types', 'component', 'partial', 'section',
        'base', 'abstract', 'default', 'main', 'app', 'application', 'impl', 'service', 'ui', 'kernel', 'settings',
    ];

    /** Directories where env reads are the mechanism, not a smell. */
    private const CONFIG_DIRECTORIES = ['config', 'configs', 'bootstrap', 'settings', 'env', 'environments', '.github', 'deploy', 'infra', 'docker', 'scripts', 'bin'];

    public static function areaOf(string $relativePath): string
    {
        $segments = explode('/', $relativePath);
        if (count($segments) === 1) {
            return '(root)';
        }

        $first = $segments[0];
        if (! in_array(strtolower($first), self::CONTAINERS, true) || count($segments) === 2) {
            return $first;
        }

        $second = $segments[1];
        if (in_array(strtolower($second), self::NESTED_CONTAINERS, true) && count($segments) > 3) {
            return $first.'/'.$second.'/'.$segments[2];
        }

        return $first.'/'.$second;
    }

    /**
     * The kind a file's own name suggests, ignoring its directory.
     */
    public static function kindFromName(string $relativePath): ?string
    {
        $base = self::baseName($relativePath);

        if (self::isTestName($relativePath)) {
            return 'test';
        }

        foreach (self::SUFFIX_KINDS as $suffix => $kind) {
            if (strlen($base) > strlen($suffix) && str_ends_with($base, $suffix)) {
                return $kind;
            }
        }

        if (str_starts_with($base, 'use') && strlen($base) > 3 && ctype_upper($base[3])) {
            return 'composable';
        }

        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $base) === 1 || preg_match('/^\d{14}_/', $base) === 1) {
            return 'migration';
        }

        return null;
    }

    /**
     * The kind a file's directory suggests, walking from the innermost directory outwards.
     */
    public static function kindFromDirectory(string $relativePath): ?string
    {
        $segments = array_map('strtolower', explode('/', $relativePath));
        array_pop($segments);

        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $segment = $segments[$i];
            // "resources" is an HTTP resource directory only under Http/; Laravel's top-level resources/ is assets and views.
            if ($segment === 'resources' && ($segments[$i - 1] ?? '') !== 'http') {
                continue;
            }
            $kind = self::DIRECTORY_KINDS[$segment] ?? null;
            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    public static function kindOf(string $relativePath): string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (str_ends_with(strtolower($relativePath), '.blade.php') || in_array($extension, ['html', 'twig', 'erb', 'hbs', 'ejs', 'pug', 'jinja', 'jinja2'], true)) {
            return 'view';
        }
        if (in_array($extension, ['css', 'scss', 'sass', 'less', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'mp4', 'webp'], true)) {
            return 'asset';
        }
        if (in_array($extension, ['md', 'rst', 'txt'], true)) {
            return 'doc';
        }
        if (in_array($extension, ['json', 'yml', 'yaml', 'toml', 'ini', 'xml', 'env', 'neon', 'lock'], true)) {
            return 'config';
        }
        if (in_array($extension, ['tf', 'tfvars', 'hcl'], true) || str_starts_with(strtolower(basename($relativePath)), 'dockerfile')) {
            return 'infra';
        }
        if (in_array($extension, ['sh', 'bash', 'ps1', 'bat', 'cmd'], true)) {
            return 'script';
        }
        if (str_ends_with(strtolower($relativePath), '.d.ts')) {
            return 'type';
        }
        // vite.config.ts, tailwind.config.js, artisan, manage.py and friends at the root are configuration, not application code.
        if (! str_contains($relativePath, '/') && self::isConfigPath($relativePath)) {
            return 'config';
        }

        return self::kindFromName($relativePath) ?? self::kindFromDirectory($relativePath) ?? ($extension === 'vue' || $extension === 'svelte' ? 'component' : 'code');
    }

    /**
     * The feature stem: the first meaningful word of the filename after role
     * words are stripped and plurals singularised, or the nearest meaningful
     * directory name when nothing remains (pages/Staff/Dogs/Index.vue => dog).
     * Null when nothing meaningful can be found.
     */
    public static function stemOf(string $relativePath): ?string
    {
        $words = self::meaningfulWords(self::baseName($relativePath));
        if ($words === []) {
            $segments = explode('/', $relativePath);
            array_pop($segments);
            foreach (array_reverse($segments) as $segment) {
                if (self::DIRECTORY_KINDS[strtolower($segment)] ?? null) {
                    continue;
                }
                $words = self::meaningfulWords($segment);
                if ($words !== []) {
                    break;
                }
            }
        }

        if ($words === []) {
            return null;
        }

        $stem = self::singular($words[0]);

        return strlen($stem) >= 3 ? $stem : null;
    }

    public static function isTestName(string $relativePath): bool
    {
        $path = strtolower($relativePath);

        return preg_match('~(^|/)(tests?|__tests__|__mocks__|spec|specs|testing|e2e|cypress|playwright)(/|$)~', $path) === 1
            || preg_match('~[._-](test|spec|e2e|stories)[.][a-z]+$~', $path) === 1
            || preg_match('~(^|/)test_[^/]+[.]py$~', $path) === 1
            || preg_match('~_test[.](go|rb|py|exs)$~', $path) === 1
            || str_ends_with($path, 'test.php')
            || str_ends_with($path, 'tests.php')
            || str_ends_with($path, 'test.java')
            || str_ends_with($path, 'test.kt')
            || str_ends_with($path, 'tests.cs');
    }

    public static function isConfigPath(string $relativePath): bool
    {
        $path = strtolower($relativePath);
        $segments = explode('/', $path);
        $base = array_pop($segments);

        // Only a top-level config directory counts: app/Http/Controllers/Settings/ProfileController.php is a
        // controller in a "Settings" namespace, not configuration.
        if ($segments !== [] && in_array($segments[0], self::CONFIG_DIRECTORIES, true)) {
            return true;
        }

        return preg_match('/(^|[._-])(config|settings|env|environment|constants)([._-]|$)|\.config\.[a-z]+$|^(vite|webpack|rollup|jest|vitest|tailwind|postcss|eslint|prettier|babel|next|nuxt|astro|svelte|playwright|cypress|phpunit|pest|artisan|gulpfile|gruntfile|karma|tsup|esbuild)\b|^conf\.py$|^wsgi\.py$|^asgi\.py$|^manage\.py$|^server\.php$|^index\.php$/', $base) === 1;
    }

    public static function baseName(string $relativePath): string
    {
        $base = basename($relativePath);
        $base = preg_replace('/\.(blade|test|spec|stories|d|min|config)\.[a-z]+$/', '', $base) ?? $base;

        return pathinfo($base, PATHINFO_FILENAME);
    }

    /**
     * @return list<string> lowercase words with role words removed
     */
    private static function meaningfulWords(string $name): array
    {
        $split = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name) ?? $name;
        $split = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $split) ?? $split;
        $words = preg_split('/[\s_\-.]+/', strtolower($split)) ?: [];

        return array_values(array_filter($words, fn (string $w) => $w !== '' && ! in_array($w, self::ROLE_WORDS, true) && preg_match('/^\d+$/', $w) !== 1));
    }

    public static function singular(string $word): string
    {
        if (str_ends_with($word, 'ies') && strlen($word) > 4) {
            return substr($word, 0, -3).'y';
        }
        if (str_ends_with($word, 'sses') || str_ends_with($word, 'xes') || str_ends_with($word, 'ches') || str_ends_with($word, 'shes')) {
            return substr($word, 0, -2);
        }
        if (str_ends_with($word, 's') && ! str_ends_with($word, 'ss') && ! str_ends_with($word, 'us') && ! str_ends_with($word, 'is') && strlen($word) > 3) {
            return substr($word, 0, -1);
        }

        return $word;
    }
}
