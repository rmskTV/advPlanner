<?php

namespace Modules\EnterpriseData\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Modules\EnterpriseData\app\Console\Commands\AnalyzeObjectStructureCommand;
use Modules\EnterpriseData\app\Console\Commands\CleanupExchangeLogsCommand;
use Modules\EnterpriseData\app\Console\Commands\ExchangeStatusCommand;
use Modules\EnterpriseData\app\Console\Commands\InspectFileCommand;
use Modules\EnterpriseData\app\Console\Commands\ProcessExchangeCommand;
use Modules\EnterpriseData\app\Console\Commands\ShowMappingsCommand;
use Modules\EnterpriseData\app\Console\Commands\ShowUnmappedObjectsCommand;
use Modules\EnterpriseData\app\Console\Commands\TestFtpConnectionCommand;
use Modules\EnterpriseData\app\Mappings\ContractMapping;
use Modules\EnterpriseData\app\Mappings\CounterpartyGroupMapping;
use Modules\EnterpriseData\app\Mappings\CounterpartyMapping;
use Modules\EnterpriseData\app\Mappings\CurrencyMapping;
use Modules\EnterpriseData\app\Mappings\OrganizationMapping;
use Modules\EnterpriseData\app\Mappings\SystemUserMapping;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;
use Modules\EnterpriseData\app\Services\ExchangeConfigValidator;
use Modules\EnterpriseData\app\Services\ExchangeDataMapper;
use Modules\EnterpriseData\app\Services\ExchangeDataSanitizer;
use Modules\EnterpriseData\app\Services\ExchangeFileManager;
use Modules\EnterpriseData\app\Services\ExchangeFtpConnectorService;
use Modules\EnterpriseData\app\Services\ExchangeLogger;
use Modules\EnterpriseData\app\Services\ExchangeMessageProcessor;
use Modules\EnterpriseData\app\Services\ExchangeOrchestrator;
use Modules\EnterpriseData\app\Services\ExchangeTransactionManager;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class EnterpriseDataServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'EnterpriseData';

    protected string $nameLower = 'enterprisedata';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerObjectMappings();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Регистрация основных сервисов
        $this->registerServices();
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessExchangeCommand::class,
                ExchangeStatusCommand::class,
                CleanupExchangeLogsCommand::class,
                TestFtpConnectionCommand::class,
                InspectFileCommand::class,
                ShowMappingsCommand::class,
                AnalyzeObjectStructureCommand::class,
                ShowUnmappedObjectsCommand::class,
            ]);
        }
    }

    protected function registerServices(): void
    {
        // Регистрация реестра маппингов как синглтона
        $this->app->singleton(ObjectMappingRegistry::class, function ($app) {
            return new ObjectMappingRegistry;
        });

        // Регистрация других сервисов
        $this->app->bind(ExchangeDataSanitizer::class);
        $this->app->bind(ExchangeMessageProcessor::class);
        $this->app->bind(ExchangeFileManager::class);
        $this->app->bind(ExchangeDataMapper::class);
        $this->app->bind(ExchangeTransactionManager::class);
        $this->app->bind(ExchangeConfigValidator::class);
        $this->app->bind(ExchangeLogger::class);
        $this->app->bind(ExchangeOrchestrator::class);
        $this->app->bind(ExchangeFtpConnectorService::class);
    }

    protected function registerObjectMappings(): void
    {
        try {
            $registry = $this->app->make(ObjectMappingRegistry::class);

            // Регистрация маппинга для организаций
            $registry->registerMapping('Справочник.Организации', new OrganizationMapping);
            $registry->registerMapping('Справочник.Договоры', new ContractMapping);
            $registry->registerMapping('Справочник.КонтрагентыГруппа', new CounterpartyGroupMapping());
            $registry->registerMapping('Справочник.Контрагенты', new CounterpartyMapping());
            $registry->registerMapping('Справочник.Валюты', new CurrencyMapping());
            $registry->registerMapping('Справочник.Пользователи', new SystemUserMapping());
            Log::info('Registered object mappings', [
                'mappings_count' => count($registry->getAllMappings()),
                'registered_types' => $registry->getSupportedObjectTypes(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to register object mappings', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        // $this->app->booted(function () {
        //     $schedule = $this->app->make(Schedule::class);
        //     $schedule->command('inspire')->hourly();
        // });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $relativeConfigPath = config('modules.paths.generator.config.path');
        $configPath = module_path($this->name, $relativeConfigPath);

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativePath = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $configKey = $this->nameLower.'.'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $relativePath);
                    $key = ($relativePath === 'config.php') ? $this->nameLower : $configKey;

                    $this->publishes([$file->getPathname() => config_path($relativePath)], 'config');
                    $this->mergeConfigFrom($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        $componentNamespace = $this->module_namespace($this->name, $this->app_path(config('modules.paths.generator.component-class.path')));
        Blade::componentNamespace($componentNamespace, $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
