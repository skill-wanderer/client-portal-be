<?php

namespace App\Support\Runtime;

use App\Http\Middleware\RequestContextMiddleware;
use Illuminate\Http\Request;
use Monolog\Formatter\JsonFormatter;

final class DeploymentRuntimeInspector
{
    private const STATUS_HEALTHY = 'healthy';

    private const STATUS_DEGRADED = 'degraded';

    private const STATUS_INCOMPATIBLE = 'incompatible';

    private const STATUS_STARTUP_FAILED = 'startup-failed';

    /**
     * @var array<string, int>
     */
    private const STATUS_PRIORITY = [
        self::STATUS_HEALTHY => 0,
        self::STATUS_DEGRADED => 1,
        self::STATUS_INCOMPATIBLE => 2,
        self::STATUS_STARTUP_FAILED => 3,
    ];

    /**
     * @return array<string, mixed>
     */
    public function healthReport(): array
    {
        return $this->buildReport(null, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function deploymentReport(?Request $request = null): array
    {
        return $this->buildReport($request, true);
    }

    /**
     * @param array<string, mixed> $report
     */
    public function isBlocking(array $report): bool
    {
        return $this->isBlockingStatus($report['runtime_status'] ?? null);
    }

    /**
     * @param array<string, mixed> $report
     */
    public function healthHttpStatus(array $report): int
    {
        return $this->isBlocking($report) ? 503 : 200;
    }

    /**
     * @param array<string, mixed> $report
     */
    public function deploymentHttpStatus(array $report): int
    {
        if ($this->isBlocking($report)) {
            return 503;
        }

        return ($report['compatibility_status'] ?? null) === self::STATUS_INCOMPATIBLE
            ? 412
            : 200;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function startupLogContext(array $report): array
    {
        return [
            'deployment_id' => $report['deployment_id'] ?? 'unknown',
            'contract_version' => $report['contract_version'] ?? 'unknown',
            'runtime_status' => $report['runtime_status'] ?? self::STATUS_STARTUP_FAILED,
            'auth_runtime_status' => $report['auth_runtime_status'] ?? self::STATUS_STARTUP_FAILED,
            'config_validation_status' => $report['config_validation_status'] ?? self::STATUS_STARTUP_FAILED,
            'issues' => $report['issues'] ?? [],
            'runtime_visibility' => $report['runtime_visibility'] ?? [],
            'auth_runtime' => $report['auth_runtime'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(?Request $request, bool $includeFrontendRuntime): array
    {
        $environment = $this->normalizeString(config('app.env')) ?? 'unknown';
        $deploymentId = $this->normalizeString(config('app.deployment_id'));
        $contractVersion = $this->normalizeString(config('app.contract_version'));
        $appUrl = $this->normalizeUrl(config('app.url'));
        $frontendAppUrl = $this->normalizeUrl(config('app.frontend_app_url'));
        $trustedProxies = $this->normalizeString(config('app.trusted_proxies'));
        $sessionDriver = $this->normalizeString(config('session.driver'));
        $sessionConnection = $this->normalizeString(config('session.connection'));
        $sessionStore = $this->normalizeString(config('session.store'));
        $cacheStore = $this->normalizeString(config('cache.default'));
        $queueConnection = $this->normalizeString(config('queue.default'));
        $loggingDefault = $this->normalizeString(config('logging.default'));
        $stackChannels = $this->normalizeStringList(config('logging.channels.stack.channels'));
        $stderrFormatter = $this->normalizeString(config('logging.channels.stderr.formatter'));
        $allowedOrigins = $this->normalizeOrigins(config('cors.allowed_origins', []));
        $keycloakBaseUrl = $this->normalizeUrl(config('keycloak.base_url'));
        $keycloakIssuer = $this->normalizeUrl(config('keycloak.issuer'));
        $keycloakClientId = $this->normalizeString(config('keycloak.client_id'));
        $keycloakClientSecret = $this->normalizeString(config('keycloak.client_secret'));
        $keycloakRedirectUri = $this->normalizeUrl(config('keycloak.redirect_uri'));
        $keycloakAuthorizationEndpoint = $this->normalizeUrl(config('keycloak.authorization_endpoint'));
        $keycloakTokenEndpoint = $this->normalizeUrl(config('keycloak.token_endpoint'));
        $frontendDashboardUrl = $this->normalizeUrl(config('keycloak.frontend_dashboard_url'));

        $configStatuses = [];
        $authStatuses = [];
        $issues = [];
        $checks = [];

        $checks['deployment_metadata'] = $this->deploymentMetadataCheck(
            $environment,
            $deploymentId,
            $contractVersion,
            $configStatuses,
            $issues,
        );

        $checks['runtime_urls'] = $this->runtimeUrlsCheck(
            $environment,
            $appUrl,
            $frontendAppUrl,
            $configStatuses,
            $issues,
        );

        $checks['session_runtime'] = $this->sessionRuntimeCheck(
            $environment,
            $sessionDriver,
            $sessionConnection,
            $sessionStore,
            $configStatuses,
            $issues,
        );

        $checks['cache_runtime'] = $this->cacheRuntimeCheck(
            $cacheStore,
            $configStatuses,
            $issues,
        );

        $checks['proxy_runtime'] = $this->proxyRuntimeCheck(
            $environment,
            $trustedProxies,
            $configStatuses,
            $issues,
        );

        $checks['logging_runtime'] = $this->loggingRuntimeCheck(
            $loggingDefault,
            $stackChannels,
            $stderrFormatter,
            $configStatuses,
            $issues,
        );

        $checks['keycloak_runtime'] = $this->keycloakRuntimeCheck(
            $environment,
            $keycloakBaseUrl,
            $keycloakIssuer,
            $keycloakClientId,
            $keycloakClientSecret,
            $keycloakAuthorizationEndpoint,
            $keycloakTokenEndpoint,
            $authStatuses,
            $issues,
        );

        $checks['auth_alignment'] = $this->authAlignmentCheck(
            $appUrl,
            $frontendAppUrl,
            $allowedOrigins,
            $keycloakRedirectUri,
            $frontendDashboardUrl,
            $authStatuses,
            $issues,
        );

        $configValidationStatus = $this->aggregateStatus($configStatuses);
        $authRuntimeStatus = $this->aggregateStatus($authStatuses);
        $runtimeStatus = $this->aggregateStatus([$configValidationStatus, $authRuntimeStatus]);
        $compatibilityStatus = $runtimeStatus;
        $frontendRuntime = null;

        if ($includeFrontendRuntime) {
            $frontendRuntime = $this->frontendRuntimeCheck($request, $deploymentId, $contractVersion, $issues);
            $checks['frontend_runtime'] = [
                'status' => $frontendRuntime['status'],
                'message' => $frontendRuntime['message'],
                'details' => [
                    'metadata_present' => $frontendRuntime['metadata_present'],
                    'frontend_deployment_id' => $frontendRuntime['deployment_id'],
                    'frontend_contract_version' => $frontendRuntime['contract_version'],
                    'deployment_match' => $frontendRuntime['deployment_match'],
                    'contract_match' => $frontendRuntime['contract_match'],
                ],
            ];
            $compatibilityStatus = $this->aggregateStatus([$runtimeStatus, $frontendRuntime['status']]);
        }

        return [
            'deployment_id' => $deploymentId ?? 'unknown',
            'contract_version' => $contractVersion ?? 'unknown',
            'environment' => $environment,
            'runtime_status' => $runtimeStatus,
            'auth_runtime_status' => $authRuntimeStatus,
            'config_validation_status' => $configValidationStatus,
            'compatibility_status' => $compatibilityStatus,
            'expected_frontend_headers' => [
                'X-Deployment-ID' => $deploymentId ?? 'unknown',
                'X-Contract-Version' => $contractVersion ?? 'unknown',
            ],
            'runtime_visibility' => [
                'app_url' => $appUrl ?? 'unknown',
                'frontend_app_url' => $frontendAppUrl ?? 'unknown',
                'trusted_proxies' => $trustedProxies ?? 'unknown',
                'session_driver' => $sessionDriver ?? 'unknown',
                'session_connection' => $sessionConnection ?? 'default',
                'session_store' => $sessionStore ?? 'default',
                'cache_store' => $cacheStore ?? 'unknown',
                'queue_connection' => $queueConnection ?? 'unknown',
                'logging_default' => $loggingDefault ?? 'unknown',
                'stderr_formatter' => $stderrFormatter ?? 'unknown',
            ],
            'auth_runtime' => [
                'issuer' => $keycloakIssuer ?? 'unknown',
                'redirect_uri' => $keycloakRedirectUri ?? 'unknown',
                'authorization_endpoint' => $keycloakAuthorizationEndpoint ?? 'unknown',
                'token_endpoint' => $keycloakTokenEndpoint ?? 'unknown',
                'frontend_dashboard_url' => $frontendDashboardUrl ?? 'unknown',
                'allowed_origins' => $allowedOrigins,
            ],
            'frontend_runtime' => $frontendRuntime,
            'checks' => $checks,
            'issues' => $issues,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function deploymentMetadataCheck(
        string $environment,
        ?string $deploymentId,
        ?string $contractVersion,
        array &$statuses,
        array &$issues,
    ): array {
        $details = [
            'deployment_id' => $deploymentId ?? 'unknown',
            'contract_version' => $contractVersion ?? 'unknown',
        ];

        if ($deploymentId === null || $contractVersion === null) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'RUNTIME_METADATA_MISSING',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_runtime',
                message: 'Deployment metadata must be present for rollout verification.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'Deployment metadata is missing from the backend runtime.',
                'details' => $details,
            ];
        }

        if ($environment === 'production' && in_array($deploymentId, ['local-dev', 'unknown'], true)) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'DEPLOYMENT_ID_PLACEHOLDER',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_runtime',
                message: 'Production rollout metadata still uses a placeholder deployment identifier.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'The backend deployment identifier is still a placeholder.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Deployment metadata is present and suitable for runtime verification.',
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function runtimeUrlsCheck(
        string $environment,
        ?string $appUrl,
        ?string $frontendAppUrl,
        array &$statuses,
        array &$issues,
    ): array {
        $details = [
            'app_url' => $appUrl ?? 'unknown',
            'frontend_app_url' => $frontendAppUrl ?? 'unknown',
        ];

        if (! $this->isAbsoluteHttpUrl($appUrl) || ! $this->isAbsoluteHttpUrl($frontendAppUrl)) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'PUBLIC_RUNTIME_URL_INVALID',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_runtime',
                message: 'The backend or frontend public runtime URL is missing or invalid.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'The public backend or frontend URL is not a valid absolute HTTP URL.',
                'details' => $details,
            ];
        }

        if ($environment === 'production' && (! $this->isSecureUrl($appUrl) || ! $this->isSecureUrl($frontendAppUrl) || $this->isLoopbackUrl($appUrl) || $this->isLoopbackUrl($frontendAppUrl))) {
            $statuses[] = self::STATUS_INCOMPATIBLE;
            $issues[] = $this->issue(
                code: 'PUBLIC_RUNTIME_URL_INCOMPATIBLE',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'backend_runtime',
                message: 'Production rollout URLs must be HTTPS and non-loopback.',
                details: $details,
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'Production rollout URLs still point to an insecure or loopback runtime.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Public backend and frontend URLs are aligned for deployment verification.',
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function sessionRuntimeCheck(
        string $environment,
        ?string $sessionDriver,
        ?string $sessionConnection,
        ?string $sessionStore,
        array &$statuses,
        array &$issues,
    ): array {
        $details = [
            'session_driver' => $sessionDriver ?? 'unknown',
            'session_connection' => $sessionConnection ?? 'default',
            'session_store' => $sessionStore ?? 'default',
        ];

        if ($sessionDriver === null) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'SESSION_DRIVER_MISSING',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_session',
                message: 'The session runtime driver is missing.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'The session runtime driver is missing.',
                'details' => $details,
            ];
        }

        if ($environment === 'production' && $sessionDriver === 'array') {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'SESSION_DRIVER_NON_DURABLE',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_session',
                message: 'Production rollout cannot use the in-memory session driver.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'The session runtime is not durable enough for production rollout.',
                'details' => $details,
            ];
        }

        if (in_array($sessionDriver, ['cookie', 'database'], true)) {
            $statuses[] = self::STATUS_INCOMPATIBLE;
            $issues[] = $this->issue(
                code: 'SESSION_DRIVER_UNSUPPORTED',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'backend_session',
                message: 'The session runtime driver depends on cookie or database persistence.',
                details: $details,
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'The session runtime driver is not valid for token/session validation.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Session runtime storage is valid for deployment continuity.',
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function cacheRuntimeCheck(
        ?string $cacheStore,
        array &$statuses,
        array &$issues,
    ): array {
        $details = [
            'cache_store' => $cacheStore ?? 'unknown',
        ];

        if ($cacheStore === null) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'CACHE_STORE_MISSING',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_runtime',
                message: 'The cache store is missing from runtime configuration.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'The cache runtime store is missing.',
                'details' => $details,
            ];
        }

        if ($cacheStore === 'database') {
            $statuses[] = self::STATUS_INCOMPATIBLE;
            $issues[] = $this->issue(
                code: 'CACHE_STORE_DATABASE_UNSUPPORTED',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'backend_runtime',
                message: 'The cache runtime must not require database persistence.',
                details: $details,
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'The cache runtime depends on database persistence.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Cache runtime settings are valid for token/session validation.',
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function proxyRuntimeCheck(
        string $environment,
        ?string $trustedProxies,
        array &$statuses,
        array &$issues,
    ): array {
        $details = [
            'trusted_proxies' => $trustedProxies ?? 'unknown',
        ];

        if ($trustedProxies === null) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'TRUSTED_PROXIES_MISSING',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_runtime',
                message: 'Trusted proxy configuration is missing.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'Trusted proxy configuration is missing.',
                'details' => $details,
            ];
        }

        if ($environment === 'production' && $trustedProxies === 'REMOTE_ADDR') {
            $statuses[] = self::STATUS_DEGRADED;
            $issues[] = $this->issue(
                code: 'TRUSTED_PROXIES_LOCAL_ONLY',
                status: self::STATUS_DEGRADED,
                boundary: 'backend_runtime',
                message: 'Production rollout still trusts only the immediate remote address.',
                details: $details,
            );

            return [
                'status' => self::STATUS_DEGRADED,
                'message' => 'Trusted proxy configuration is still in a local-only posture.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Trusted proxy configuration is explicit enough for rollout diagnostics.',
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function loggingRuntimeCheck(
        ?string $loggingDefault,
        array $stackChannels,
        ?string $stderrFormatter,
        array &$statuses,
        array &$issues,
    ): array {
        $usesStderr = $loggingDefault === 'stderr'
            || ($loggingDefault === 'stack' && in_array('stderr', $stackChannels, true));
        $usesJsonFormatter = $stderrFormatter === JsonFormatter::class;
        $details = [
            'logging_default' => $loggingDefault ?? 'unknown',
            'stack_channels' => $stackChannels,
            'stderr_formatter' => $stderrFormatter ?? 'unknown',
        ];

        if ($loggingDefault === null) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'LOG_CHANNEL_MISSING',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_runtime',
                message: 'The default log channel is missing.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'The default log channel is missing.',
                'details' => $details,
            ];
        }

        if (! $usesStderr || ! $usesJsonFormatter) {
            $statuses[] = self::STATUS_DEGRADED;
            $issues[] = $this->issue(
                code: 'STDERR_LOGGING_NOT_STRUCTURED',
                status: self::STATUS_DEGRADED,
                boundary: 'backend_runtime',
                message: 'Runtime logs are not consistently emitted as structured stderr events.',
                details: $details,
            );

            return [
                'status' => self::STATUS_DEGRADED,
                'message' => 'Runtime logs are not consistently using structured stderr output.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Runtime logs use the expected structured stderr contract.',
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function keycloakRuntimeCheck(
        string $environment,
        ?string $keycloakBaseUrl,
        ?string $keycloakIssuer,
        ?string $keycloakClientId,
        ?string $keycloakClientSecret,
        ?string $keycloakAuthorizationEndpoint,
        ?string $keycloakTokenEndpoint,
        array &$statuses,
        array &$issues,
    ): array {
        $details = [
            'base_url' => $keycloakBaseUrl ?? 'unknown',
            'issuer' => $keycloakIssuer ?? 'unknown',
            'client_id' => $keycloakClientId ?? 'unknown',
            'authorization_endpoint' => $keycloakAuthorizationEndpoint ?? 'unknown',
            'token_endpoint' => $keycloakTokenEndpoint ?? 'unknown',
        ];

        if (
            ! $this->isAbsoluteHttpUrl($keycloakBaseUrl)
            || ! $this->isAbsoluteHttpUrl($keycloakIssuer)
            || ! $this->isAbsoluteHttpUrl($keycloakAuthorizationEndpoint)
            || ! $this->isAbsoluteHttpUrl($keycloakTokenEndpoint)
            || $keycloakClientId === null
        ) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'KEYCLOAK_RUNTIME_INVALID',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_auth',
                message: 'Keycloak runtime configuration is missing required absolute URLs or client metadata.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'Keycloak runtime configuration is missing required URLs or client metadata.',
                'details' => $details,
            ];
        }

        if ($environment === 'production' && $keycloakClientSecret === null) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'KEYCLOAK_CLIENT_SECRET_MISSING',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_auth',
                message: 'Production auth rollout is missing the Keycloak client secret.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'Production auth rollout is missing the Keycloak client secret.',
                'details' => $details,
            ];
        }

        $issuerOrigin = $this->urlOrigin($keycloakIssuer);
        $authorizationOrigin = $this->urlOrigin($keycloakAuthorizationEndpoint);
        $tokenOrigin = $this->urlOrigin($keycloakTokenEndpoint);

        if ($issuerOrigin === null || $authorizationOrigin === null || $tokenOrigin === null || $authorizationOrigin !== $issuerOrigin || $tokenOrigin !== $issuerOrigin) {
            $statuses[] = self::STATUS_INCOMPATIBLE;
            $issues[] = $this->issue(
                code: 'KEYCLOAK_ENDPOINT_ORIGIN_MISMATCH',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'backend_auth',
                message: 'Keycloak issuer and endpoint origins do not align.',
                details: $details,
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'Keycloak issuer and endpoint origins are out of sync.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Keycloak runtime endpoints and client metadata are aligned.',
            'details' => $details,
        ];
    }

    /**
     * @param list<string> $statuses
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function authAlignmentCheck(
        ?string $appUrl,
        ?string $frontendAppUrl,
        array $allowedOrigins,
        ?string $keycloakRedirectUri,
        ?string $frontendDashboardUrl,
        array &$statuses,
        array &$issues,
    ): array {
        $expectedRedirectUri = $appUrl !== null ? $appUrl.'/v1/auth/callback' : null;
        $details = [
            'expected_redirect_uri' => $expectedRedirectUri ?? 'unknown',
            'redirect_uri' => $keycloakRedirectUri ?? 'unknown',
            'frontend_app_url' => $frontendAppUrl ?? 'unknown',
            'frontend_dashboard_url' => $frontendDashboardUrl ?? 'unknown',
            'allowed_origins' => $allowedOrigins,
        ];

        if (! $this->isAbsoluteHttpUrl($keycloakRedirectUri) || ! $this->isAbsoluteHttpUrl($frontendDashboardUrl) || $expectedRedirectUri === null) {
            $statuses[] = self::STATUS_STARTUP_FAILED;
            $issues[] = $this->issue(
                code: 'AUTH_RUNTIME_URLS_MISSING',
                status: self::STATUS_STARTUP_FAILED,
                boundary: 'backend_auth',
                message: 'Auth callback or frontend redirect URLs are missing.',
                details: $details,
            );

            return [
                'status' => self::STATUS_STARTUP_FAILED,
                'message' => 'Auth callback or frontend redirect URLs are missing.',
                'details' => $details,
            ];
        }

        if ($keycloakRedirectUri !== $expectedRedirectUri) {
            $statuses[] = self::STATUS_INCOMPATIBLE;
            $issues[] = $this->issue(
                code: 'KC_REDIRECT_URI_MISMATCH',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'backend_auth',
                message: 'Keycloak redirect URI does not match the backend callback contract.',
                details: $details,
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'The Keycloak redirect URI does not match the backend callback contract.',
                'details' => $details,
            ];
        }

        if ($frontendAppUrl === null || ! str_starts_with($frontendDashboardUrl, $frontendAppUrl)) {
            $statuses[] = self::STATUS_INCOMPATIBLE;
            $issues[] = $this->issue(
                code: 'FE_DASHBOARD_URL_MISMATCH',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'backend_auth',
                message: 'The Keycloak dashboard redirect does not align with the configured frontend origin.',
                details: $details,
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'The Keycloak dashboard redirect does not align with the configured frontend origin.',
                'details' => $details,
            ];
        }

        if (! in_array($frontendAppUrl, $allowedOrigins, true)) {
            $statuses[] = self::STATUS_INCOMPATIBLE;
            $issues[] = $this->issue(
                code: 'AUTH_ORIGIN_NOT_ALLOWED',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'backend_auth',
                message: 'The frontend auth origin is not present in the backend CORS allow-list.',
                details: $details,
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'The frontend auth origin is not present in the backend CORS allow-list.',
                'details' => $details,
            ];
        }

        $statuses[] = self::STATUS_HEALTHY;

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Auth callback, trusted origins, and frontend redirect targets are aligned.',
            'details' => $details,
        ];
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function frontendRuntimeCheck(
        ?Request $request,
        ?string $deploymentId,
        ?string $contractVersion,
        array &$issues,
    ): array {
        $frontendDeploymentId = $this->normalizeString(
            $request?->attributes->get(RequestContextMiddleware::FRONTEND_DEPLOYMENT_ID_ATTRIBUTE)
                ?? $request?->header('X-Deployment-ID')
        );
        $frontendContractVersion = $this->normalizeString(
            $request?->attributes->get(RequestContextMiddleware::FRONTEND_CONTRACT_VERSION_ATTRIBUTE)
                ?? $request?->header('X-Contract-Version')
        );
        $metadataPresent = $frontendDeploymentId !== null || $frontendContractVersion !== null;
        $deploymentMatch = $frontendDeploymentId !== null && $deploymentId !== null
            ? $frontendDeploymentId === $deploymentId
            : null;
        $contractMatch = $frontendContractVersion !== null && $contractVersion !== null
            ? $frontendContractVersion === $contractVersion
            : null;

        if (! $metadataPresent) {
            return [
                'status' => self::STATUS_DEGRADED,
                'message' => 'Frontend deployment metadata was not supplied with the verification request.',
                'metadata_present' => false,
                'deployment_id' => null,
                'contract_version' => null,
                'deployment_match' => null,
                'contract_match' => null,
            ];
        }

        if ($deploymentMatch === false || $contractMatch === false) {
            $issues[] = $this->issue(
                code: 'FRONTEND_RUNTIME_MISMATCH',
                status: self::STATUS_INCOMPATIBLE,
                boundary: 'frontend_runtime',
                message: 'Frontend deployment metadata does not match the backend runtime metadata.',
                details: [
                    'frontend_deployment_id' => $frontendDeploymentId,
                    'frontend_contract_version' => $frontendContractVersion,
                    'backend_deployment_id' => $deploymentId,
                    'backend_contract_version' => $contractVersion,
                ],
            );

            return [
                'status' => self::STATUS_INCOMPATIBLE,
                'message' => 'Frontend deployment metadata does not match the backend runtime metadata.',
                'metadata_present' => true,
                'deployment_id' => $frontendDeploymentId,
                'contract_version' => $frontendContractVersion,
                'deployment_match' => $deploymentMatch,
                'contract_match' => $contractMatch,
            ];
        }

        return [
            'status' => self::STATUS_HEALTHY,
            'message' => 'Frontend deployment metadata matches the backend runtime metadata.',
            'metadata_present' => true,
            'deployment_id' => $frontendDeploymentId,
            'contract_version' => $frontendContractVersion,
            'deployment_match' => $deploymentMatch,
            'contract_match' => $contractMatch,
        ];
    }

    /**
     * @param list<string> $statuses
     */
    private function aggregateStatus(array $statuses): string
    {
        if ($statuses === []) {
            return self::STATUS_HEALTHY;
        }

        $resolvedStatus = self::STATUS_HEALTHY;
        $resolvedPriority = self::STATUS_PRIORITY[$resolvedStatus];

        foreach ($statuses as $status) {
            $priority = self::STATUS_PRIORITY[$status] ?? self::STATUS_PRIORITY[self::STATUS_STARTUP_FAILED];

            if ($priority > $resolvedPriority) {
                $resolvedStatus = $status;
                $resolvedPriority = $priority;
            }
        }

        return $resolvedStatus;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function issue(
        string $code,
        string $status,
        string $boundary,
        string $message,
        array $details,
    ): array {
        return [
            'code' => $code,
            'status' => $status,
            'boundary' => $boundary,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || strtolower($normalized) === 'null') {
            return null;
        }

        return $normalized;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        if ($normalized === null) {
            return null;
        }

        return rtrim($normalized, '/');
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalizedValues = [];

        foreach ($value as $entry) {
            $normalizedEntry = $this->normalizeString($entry);

            if ($normalizedEntry !== null) {
                $normalizedValues[] = $normalizedEntry;
            }
        }

        return array_values(array_unique($normalizedValues));
    }

    /**
     * @return list<string>
     */
    private function normalizeOrigins(mixed $value): array
    {
        $normalizedOrigins = [];

        if (! is_array($value)) {
            return $normalizedOrigins;
        }

        foreach ($value as $origin) {
            $normalizedOrigin = $this->normalizeUrl($origin);

            if ($normalizedOrigin !== null) {
                $normalizedOrigins[] = $normalizedOrigin;
            }
        }

        return array_values(array_unique($normalizedOrigins));
    }

    private function isAbsoluteHttpUrl(?string $value): bool
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && is_string($host)
            && $host !== '';
    }

    private function isSecureUrl(?string $value): bool
    {
        if (! $this->isAbsoluteHttpUrl($value)) {
            return false;
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }

    private function isLoopbackUrl(?string $value): bool
    {
        if (! $this->isAbsoluteHttpUrl($value)) {
            return false;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function urlOrigin(?string $value): ?string
    {
        if (! $this->isAbsoluteHttpUrl($value)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $port = parse_url($value, PHP_URL_PORT);

        if ($host === '') {
            return null;
        }

        return $port === null
            ? $scheme.'://'.$host
            : $scheme.'://'.$host.':'.$port;
    }

    private function isValidPort(?string $value): bool
    {
        if ($value === null || ! ctype_digit($value)) {
            return false;
        }

        $port = (int) $value;

        return $port >= 1 && $port <= 65535;
    }

    private function isBlockingStatus(mixed $status): bool
    {
        return is_string($status) && in_array($status, [self::STATUS_INCOMPATIBLE, self::STATUS_STARTUP_FAILED], true);
    }
}
