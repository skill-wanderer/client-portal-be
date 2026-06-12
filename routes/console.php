<?php

use App\Support\Runtime\DeploymentRuntimeInspector;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('runtime:validate {--fail-on-invalid : Exit non-zero when runtime config is incompatible or startup-failed} {--boot-log : Emit a startup validation log event}', function () {
    $runtimeInspector = app(DeploymentRuntimeInspector::class);
    $report = $runtimeInspector->healthReport();

    if ($this->option('boot-log')) {
        $context = $runtimeInspector->startupLogContext($report);

        if ($runtimeInspector->isBlocking($report)) {
            Log::error('be.runtime.startup_failed', $context);
        } else {
            Log::info('be.runtime.startup_validated', $context);
        }
    }

    if (! $this->output->isQuiet()) {
        $this->line(sprintf(
            'runtime_status=%s auth_runtime_status=%s config_validation_status=%s',
            $report['runtime_status'],
            $report['auth_runtime_status'],
            $report['config_validation_status'],
        ));
    }

    if ($this->option('fail-on-invalid') && $runtimeInspector->isBlocking($report)) {
        if (! $this->output->isQuiet()) {
            $this->error('Runtime configuration is incompatible with the frozen deployment contract.');
        }

        return self::FAILURE;
    }

    return self::SUCCESS;
})->purpose('Validate runtime deployment and auth configuration before serving traffic');
