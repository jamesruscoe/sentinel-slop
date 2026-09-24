<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

/**
 * Per-language regex families for the repository profile. Everything the
 * profile counts (logging, error handling, input reads, validation, config
 * and env access, outbound calls) is a pattern here, keyed by language
 * family, so adding a language is a data change.
 *
 * Counts are observations, not judgements: the absence checks decide what
 * is reliable enough to become a finding, and only for families listed in
 * ASSESSED. Every other family is profiled but never produces an absence
 * finding.
 */
final class LanguagePatterns
{
    public const FAMILIES = [
        'php' => ['php'],
        'js' => ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte', 'astro'],
        'python' => ['py'],
        'ruby' => ['rb'],
        'go' => ['go'],
        'java' => ['java', 'kt', 'kts'],
        'csharp' => ['cs'],
        'rust' => ['rs'],
        'swift' => ['swift'],
    ];

    /** Families whose patterns are complete enough for absence checks to fire. */
    public const ASSESSED = ['php', 'js', 'python', 'ruby', 'go', 'java', 'csharp'];

    /**
     * Fully qualified PHP types that are loggers. A file referencing any of
     * them (through use, alias, constructor injection or a fully qualified
     * name) logs, whatever the call is named.
     */
    public const PHP_LOGGER_TYPES = [
        'Illuminate\Support\Facades\Log',
        'Illuminate\Log\LogManager',
        'Illuminate\Log\Logger',
        'Illuminate\Contracts\Log\Log',
        'Psr\Log\LoggerInterface',
        'Psr\Log\LoggerAwareInterface',
        'Psr\Log\LoggerAwareTrait',
        'Psr\Log\LoggerTrait',
        'Monolog\Logger',
        'Symfony\Component\HttpKernel\Log\Logger',
        'Log',
    ];

    /** npm packages that are loggers or error reporters. */
    public const JS_LOGGER_PACKAGES = ['winston', 'pino', 'bunyan', 'loglevel', 'consola', 'debug', 'log4js', 'roarr', 'signale', 'electron-log', 'tslog', '@sentry/browser', '@sentry/node', '@sentry/vue', '@sentry/react', '@sentry/nextjs', 'bugsnag', '@bugsnag/js', 'rollbar', 'morgan'];

    /** Python modules that are loggers. */
    public const PYTHON_LOGGER_MODULES = ['logging', 'structlog', 'loguru', 'sentry_sdk'];

    /** Package names (any ecosystem) that provide an input validation mechanism. */
    public const VALIDATION_PACKAGES = [
        'js' => ['zod', 'yup', 'joi', '@hapi/joi', 'valibot', 'class-validator', 'express-validator', 'vee-validate', '@vee-validate/zod', '@vuelidate/core', 'ajv', 'superstruct', '@sinclair/typebox', 'io-ts', 'arktype', 'validator', 'celebrate', '@nestjs/class-validator', 'fastest-validator', 'vine', '@vinejs/vine', 'typanion', 'runtypes'],
        'python' => ['pydantic', 'marshmallow', 'cerberus', 'voluptuous', 'wtforms', 'attrs', 'schema', 'jsonschema', 'django.forms', 'rest_framework.serializers', 'fastapi', 'ninja', 'django_ninja'],
        'ruby' => ['dry-validation', 'dry/validation', 'active_model', 'reform'],
        'go' => ['github.com/go-playground/validator', 'github.com/go-ozzo/ozzo-validation', 'github.com/asaskevich/govalidator'],
    ];

    /**
     * Regex families per language. Each key maps to one pattern (alternation)
     * matched with preg_match_all against the file contents, except
     * `comment_line`, which is matched per line.
     *
     * @var array<string, array<string, string>>
     */
    public const PATTERNS = [
        'php' => [
            'logging_call' => '/\b(?:Log|Logger)::[a-zA-Z]+\(|\blogger\(|\breport\((?!\))|->(?:logger|log)->(?:emergency|alert|critical|error|warning|notice|info|debug|log)\(|\$logger->[a-zA-Z]+\(|\berror_log\(|\bsyslog\(|Sentry\\\\|\\\\Sentry\b/',
            'try' => '/\btry\s*\{/',
            'catch' => '/\bcatch\s*\(/',
            'input_read' => '/\$request->(?:input|all|get|post|query|json|only|except|string|integer|boolean|float|date|enum|file|collect|has|filled)\(|\$_(?:GET|POST|REQUEST)\[|\bRequest::(?:input|all|get|query|post|only)\(|\brequest\(\)->(?:input|all|get|query|post|only|except)\(|\brequest\([\'"]/',
            'validation_call' => '/->validate(?:WithBag)?\(|\bValidator::make\(|->validated\(\)|->safe\(\)|#\[Validate\b|\bvalidator\(\)|->rules\(\)|\bValidator::validate\(/',
            'validation_mechanism' => '/\bextends\s+(?:\\\\?Illuminate\\\\Foundation\\\\Http\\\\)?FormRequest\b|Illuminate\\\\Foundation\\\\Http\\\\FormRequest|Illuminate\\\\Validation\\\\|Illuminate\\\\Support\\\\Facades\\\\Validator|#\[Validate\b|Spatie\\\\LaravelData\\\\Data\b|Symfony\\\\Component\\\\Validator\\\\|Respect\\\\Validation\\\\|Rakit\\\\Validation\\\\/',
            'env_read' => '/\benv\(\s*[\'"]|\bgetenv\(|\$_ENV\[|\$_SERVER\[\s*[\'"][A-Z][A-Z0-9_]*[\'"]\s*\]/',
            'config_read' => '/\bconfig\(\s*[\'"]|\bConfig::get\(/',
            'external_call' => '/\bHttp::(?:get|post|put|patch|delete|head|send|withHeaders|withToken|withBasicAuth|acceptJson|asJson|asForm|baseUrl|timeout|retry|withOptions|pool)\(|GuzzleHttp\\\\|\bcurl_exec\(|\bcurl_init\(|\bfile_get_contents\(\s*[\'"]https?:|\bStripe\\\\[A-Z]|\bAws\\\\[A-Z]|\bSocialite::|\bOpenAI\\\\|\bAnthropic\\\\|\bTwilio\\\\|\bSendGrid\\\\|->request\(\s*[\'"](?:GET|POST|PUT|PATCH|DELETE)[\'"]/',
            'guard' => '/->retry\(|->throw\(\)|->throwIf\(|\bretry\(\s*\d|->onError\(|\bwithExceptions\(|\$this->renderable\(|\$this->reportable\(/',
            'global_handler' => '/\bwithExceptions\(|\bextends\s+(?:\\\\?Illuminate\\\\Foundation\\\\Exceptions\\\\)?ExceptionHandler\b|Illuminate\\\\Foundation\\\\Exceptions\\\\Handler\b|\bset_exception_handler\(|Symfony\\\\Component\\\\HttpKernel\\\\Event\\\\ExceptionEvent\b/',
            'comment_line' => '~^\s*(?://|#|/\*|\*|\*/)~',
        ],
        'js' => [
            'logging_call' => '/\bconsole\.(?:error|warn|info|log|debug|trace)\(|\blogger\.[a-zA-Z]+\(|\blog\.(?:error|warn|info|debug|trace|fatal)\(|\bSentry\.capture[A-Za-z]*\(|\bBugsnag\.notify\(|\brollbar\.[a-zA-Z]+\(/',
            'try' => '/\btry\s*\{/',
            'catch' => '/\bcatch\s*[\({]|\.catch\(/',
            'input_read' => '/\breq\.(?:body|query|params)\b|\brequest\.(?:body|json|formData|query|params)\b|\bctx\.request\.body\b|\bevent\.body\b|\bformData\.get\(|\bsearchParams\.get\(/',
            'validation_call' => '/\.(?:parse|safeParse|parseAsync|safeParseAsync)\(|\.validate(?:Sync|Async)?\(|\bvalidationResult\(|\buseVuelidate\(|\buseForm\(\s*\{[^}]*validationSchema/',
            'validation_mechanism' => '/\bfrom\s+[\'"](?:zod|yup|joi|@hapi\/joi|valibot|class-validator|express-validator|vee-validate|@vee-validate\/zod|@vuelidate\/core|ajv|superstruct|@sinclair\/typebox|io-ts|arktype|validator|celebrate|fastest-validator|@vinejs\/vine|typanion|runtypes)[\'"]|\brequire\([\'"](?:zod|yup|joi|ajv|express-validator|class-validator|validator)[\'"]\)/',
            'env_read' => '/\bprocess\.env\.(?!NODE_ENV\b)[A-Za-z_]|\bprocess\.env\[|\bDeno\.env\.get\(|\bBun\.env\./',
            'config_read' => '/\bimport\.meta\.env\.|\bconfig\.[a-zA-Z]+\b|\buseRuntimeConfig\(/',
            'external_call' => '/\bfetch\(|\baxios(?:\.[a-z]+)?\(|\bgot(?:\.[a-z]+)?\(|\bky(?:\.[a-z]+)?\(|\$fetch\(|\buseFetch\(|\bsuperagent\b|\bnew XMLHttpRequest\(|\bhttps?\.request\(|\bofetch\(/',
            'guard' => '/interceptors\.response\.use\(|\baxios-retry\b|\bp-retry\b|\bretry\(|\bwithRetry\(|\.catch\(/',
            'global_handler' => '/\bapp\.config\.errorHandler\b|\bunhandledRejection\b|\bwindow\.onerror\b|\bonErrorCaptured\(|\bErrorBoundary\b|\(\s*err(?:or)?\s*,\s*req\s*,\s*res\s*,\s*next\s*\)|@Catch\(|\bsetErrorHandler\(|\bapp\.onError\(|\bprocess\.on\(\s*[\'"]uncaughtException/',
            'comment_line' => '~^\s*(?://|/\*|\*|\*/|<!--)~',
        ],
        'python' => [
            'logging_call' => '/\blogging\.(?:getLogger|basicConfig|info|error|warning|debug|exception|critical)\(|\bgetLogger\(|\blogger\.(?:info|error|warning|debug|exception|critical|log)\(|\blog\.(?:info|error|warning|debug|exception)\(|\bstructlog\.|\bloguru\b|\bsentry_sdk\.|\bcapture_exception\(/',
            'try' => '/^\s*try\s*:/m',
            'catch' => '/^\s*except\b/m',
            'input_read' => '/\brequest\.(?:POST|GET|json|data|form|args|files|get_json|body)\b|\bawait\s+request\.json\(\)|\bself\.request\.(?:body|arguments)\b/',
            'validation_call' => '/\.is_valid\(|\.validate\(|\bmodel_validate\(|\bparse_obj\(|\bTypeAdapter\(|\.load\(|\bvalidate_call\b/',
            'validation_mechanism' => '/^\s*(?:from|import)\s+(?:pydantic|marshmallow|cerberus|voluptuous|wtforms|jsonschema|django\.forms|django\.core\.validators|rest_framework(?:\.serializers)?|fastapi|ninja|attrs|schema|drf_spectacular)\b|\bclass\s+\w+\((?:BaseModel|Schema|Serializer|ModelSerializer|Form|ModelForm)\)/m',
            'env_read' => '/\bos\.environ\b|\bos\.getenv\(|\benviron\.get\(|\benviron\[/',
            'config_read' => '/\bsettings\.[A-Z_]+|\bconfig\.[a-zA-Z_]+|\bget_settings\(\)/',
            'external_call' => '/\brequests\.(?:get|post|put|patch|delete|head|request|Session)\(|\bhttpx\.|\burllib\.request\b|\burlopen\(|\baiohttp\.|\bboto3\.|\bstripe\.|\bopenai\.|\banthropic\./',
            'guard' => '/@retry\b|\btenacity\b|\bbackoff\.|\bHTTPAdapter\(|\bmax_retries\b|\bRetry\(/',
            'global_handler' => '/@app\.errorhandler\(|\.errorhandler\(|\bexception_handler\(|\badd_exception_handler\(|\bhandler500\b|\bprocess_exception\(|\bsys\.excepthook\b/',
            'comment_line' => '~^\s*#~',
        ],
        'ruby' => [
            'logging_call' => '/\bRails\.logger\b|\blogger\.(?:info|error|warn|debug|fatal)\b|\bLogger\.new\(|\bSentry\.capture|\bBugsnag\.notify/',
            'try' => '/\bbegin\b/',
            'catch' => '/\brescue\b/',
            'input_read' => '/\bparams\[|\bparams\.(?:require|permit|fetch|dig)\(|\brequest\.(?:body|raw_post|params)\b/',
            'validation_call' => '/\bvalidates?\b|\bparams\.require\(|\.permit\(|\.valid\?/',
            'validation_mechanism' => '/\bvalidates?\b|\bActiveModel::Validations\b|\bDry::Validation\b|\bparams\.require\(/',
            'env_read' => '/\bENV\[|\bENV\.fetch\(/',
            'config_read' => '/\bRails\.application\.config\b|\bRails\.configuration\b|\bcredentials\./',
            'external_call' => '/\bNet::HTTP\b|\bFaraday\b|\bHTTParty\b|\bRestClient\b|\bURI\.open\(|\bStripe::|\bAws::/',
            'guard' => '/\bretry\b|\bFaraday::Retry\b|\bretries:/',
            'global_handler' => '/\brescue_from\b|\bconfig\.exceptions_app\b/',
            'comment_line' => '~^\s*#~',
        ],
        'go' => [
            'logging_call' => '/\blog\.(?:Print|Printf|Println|Fatal|Fatalf|Panic|Error|Info|Warn)\(|\bslog\.|\bzap\.|\blogrus\.|\bzerolog\.|\blogger\.[A-Z][a-z]+\(/',
            'try' => '/\bdefer\s+func\(\)/',
            'catch' => '/\bif\s+err\s*!=\s*nil\b|\brecover\(\)/',
            'input_read' => '/\br\.(?:Body|FormValue|PostFormValue|URL\.Query)\b|\bc\.(?:Bind|ShouldBind|BindJSON|ShouldBindJSON|Param|Query|PostForm)\(|\bjson\.NewDecoder\(r\.Body\)/',
            'validation_call' => '/\bvalidate\.Struct\(|\.Validate\(|\bvalidator\.New\(/',
            'validation_mechanism' => '/\bgithub\.com\/go-playground\/validator\b|\bozzo-validation\b|\bgovalidator\b|\bbinding:"required|\bvalidate:"/',
            'env_read' => '/\bos\.Getenv\(|\bos\.LookupEnv\(|\bos\.Environ\(\)/',
            'config_read' => '/\bviper\.|\bcfg\.[A-Z]|\bconfig\.[A-Z]/',
            'external_call' => '/\bhttp\.(?:Get|Post|PostForm|Head|NewRequest|NewRequestWithContext)\(|\bclient\.Do\(|\bhttp\.DefaultClient\b/',
            'guard' => '/\bbackoff\.|\bretry\.|\bRetryMax\b|\bhashicorp\/go-retryablehttp\b/',
            'global_handler' => '/\brecover\(\)|\bRecovery\(\)|\bmiddleware\.Recoverer\b/',
            'comment_line' => '~^\s*(?://|/\*|\*)~',
        ],
        'java' => [
            'logging_call' => '/\b(?:log|logger|LOG|LOGGER)\.(?:info|error|warn|debug|trace)\(|\bLoggerFactory\.getLogger\(|\b@Slf4j\b|\bLogger\.getLogger\(|\bTimber\.[a-z]\(|\bLog\.[deiwv]\(/',
            'try' => '/\btry\s*\{|\brunCatching\s*\{/',
            'catch' => '/\bcatch\s*\(|\bonFailure\s*\{/',
            'input_read' => '/@RequestBody\b|@RequestParam\b|@PathVariable\b|\bgetParameter\(|\bgetInputStream\(\)|\bcall\.receive\b/',
            'validation_call' => '/\bvalidator\.validate\(|\.validate\(/',
            'validation_mechanism' => '/@Valid\b|@Validated\b|@NotNull\b|@NotBlank\b|@Size\(|\bjavax\.validation\b|\bjakarta\.validation\b|\bkonform\b/',
            'env_read' => '/\bSystem\.getenv\(|\bSystem\.getProperty\(/',
            'config_read' => '/@Value\(|@ConfigurationProperties\b|\bEnvironment\b\.getProperty\(/',
            'external_call' => '/\bRestTemplate\b|\bWebClient\b|\bHttpClient\b|\bOkHttpClient\b|\bFeignClient\b|\bHttpURLConnection\b|\bRetrofit\b|\bKtor\b/',
            'guard' => '/@Retryable\b|\bResilience4j\b|\bCircuitBreaker\b|\bRetryTemplate\b/',
            'global_handler' => '/@ControllerAdvice\b|@RestControllerAdvice\b|@ExceptionHandler\b|\bStatusPages\b|\bUncaughtExceptionHandler\b/',
            'comment_line' => '~^\s*(?://|/\*|\*)~',
        ],
        'csharp' => [
            'logging_call' => '/\b_?logger\.Log(?:Information|Error|Warning|Debug|Trace|Critical)\(|\bILogger<|\bLog\.(?:Information|Error|Warning|Debug|Fatal|Verbose)\(|\bSerilog\b|\bNLog\b/',
            'try' => '/\btry\s*\{/',
            'catch' => '/\bcatch\b/',
            'input_read' => '/\[FromBody\]|\[FromQuery\]|\[FromForm\]|\bRequest\.(?:Form|Query|Body)\b|\bReadFromJsonAsync</',
            'validation_call' => '/\bModelState\.IsValid\b|\bvalidator\.Validate(?:Async)?\(|\bTryValidateModel\(/',
            'validation_mechanism' => '/\[Required\]|\[StringLength\(|\[Range\(|\bFluentValidation\b|\bIValidator<|\bAbstractValidator<|\bModelState\.IsValid\b/',
            'env_read' => '/\bEnvironment\.GetEnvironmentVariable\(/',
            'config_read' => '/\bIConfiguration\b|\bIOptions<|\b_?configuration\[/',
            'external_call' => '/\bHttpClient\b|\bRestSharp\b|\bRestClient\b|\bWebRequest\.Create\(|\bFlurl\b/',
            'guard' => '/\bPolly\b|\bAddPolicyHandler\(|\bAddTransientHttpErrorPolicy\(/',
            'global_handler' => '/\bUseExceptionHandler\(|\bIExceptionFilter\b|\bIExceptionHandler\b|\bUseDeveloperExceptionPage\(/',
            'comment_line' => '~^\s*(?://|/\*|\*)~',
        ],
        'rust' => [
            'logging_call' => '/\b(?:log|tracing)::|\b(?:info|error|warn|debug|trace)!\(/',
            'try' => '/\bmatch\b/',
            'catch' => '/\bErr\(/',
            'input_read' => '/\bJson<|\bQuery<|\bPath<|\bForm<|\bweb::(?:Json|Query|Path|Form)\b/',
            'validation_call' => '/\.validate\(\)/',
            'validation_mechanism' => '/\bvalidator::|#\[validate\b|\bgarde::/',
            'env_read' => '/\bstd::env::var\(|\benv::var\(/',
            'config_read' => '/\bconfig::|\bfigment::|\bdotenvy::/',
            'external_call' => '/\breqwest::|\bureq::|\bhyper::|\bsurf::/',
            'guard' => '/\btokio_retry\b|\bbackoff::/',
            'global_handler' => '/\bpanic::set_hook\(|\bcatch_unwind\(/',
            'comment_line' => '~^\s*(?://|/\*|\*)~',
        ],
        'swift' => [
            'logging_call' => '/\bos_log\(|\bLogger\(|\bprint\(|\bNSLog\(/',
            'try' => '/\bdo\s*\{/',
            'catch' => '/\bcatch\b/',
            'input_read' => '/\breq\.(?:content|query|parameters)\b/',
            'validation_call' => '/\.validate\(/',
            'validation_mechanism' => '/\bValidatable\b|\bValidations\b/',
            'env_read' => '/\bProcessInfo\.processInfo\.environment\b|\bEnvironment\.get\(/',
            'config_read' => '/\bBundle\.main\.object\(/',
            'external_call' => '/\bURLSession\b|\bAlamofire\b|\bAF\.request\(/',
            'guard' => '/\bretry\b/',
            'global_handler' => '/\bErrorMiddleware\b/',
            'comment_line' => '~^\s*(?://|/\*|\*)~',
        ],
    ];

    public static function familyOf(string $relativePath): ?string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if ($extension === 'php' && str_ends_with(strtolower($relativePath), '.blade.php')) {
            return null;
        }

        foreach (self::FAMILIES as $family => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $family;
            }
        }

        return null;
    }

    public static function isAssessed(string $family): bool
    {
        return in_array($family, self::ASSESSED, true);
    }

    /**
     * Count matches of one pattern family in a file's contents.
     */
    public static function count(string $family, string $key, string $contents): int
    {
        $pattern = self::PATTERNS[$family][$key] ?? null;
        if ($pattern === null || $contents === '') {
            return 0;
        }

        return (int) preg_match_all($pattern, $contents);
    }

    public static function isCommentLine(string $family, string $line): bool
    {
        $pattern = self::PATTERNS[$family]['comment_line'] ?? '~^\s*(?://|#|/\*|\*)~';

        return preg_match($pattern, $line) === 1;
    }
}
