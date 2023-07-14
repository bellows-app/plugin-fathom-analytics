<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\HttpClient;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\AddApiCredentialsPrompt;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Deployment;
use Bellows\PluginSdk\Facades\Entity;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Illuminate\Http\Client\PendingRequest;

class FathomAnalytics extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    protected $siteId;

    public function __construct(
        protected HttpClient $http,
    ) {
    }

    public function defaultForDeployConfirmation(): bool
    {
        return Project::env()->has('FATHOM_SITE_ID');
    }

    public function deploy(): ?DeploymentResult
    {
        $this->setSiteId();

        return DeploymentResult::create()->environmentVariables($this->environmentVariables());
    }

    public function install(): ?InstallationResult
    {
        $result = InstallationResult::create();

        if (Console::confirm('Setup Fathom Analytics now?', false)) {
            $this->setSiteId();

            $result->environmentVariables($this->environmentVariables());
        }

        return $result->updateConfig(
            'services.fathom_analytics.site_id',
            "env('FATHOM_SITE_ID')",
        )->wrapUp(function () {
            collect(glob(Project::path('resources/views/*.blade.php')))
                ->merge(glob(Project::path('resources/views/**/*.blade.php')))
                ->each(
                    fn ($file) => Project::file($file)->replace(
                        '</head>',
                        str_repeat(' ', 4) . "@include('partials.fathom_analytics')\n</head>",
                    ),
                );
        });
    }

    public function shouldDeploy(): bool
    {
        return !Deployment::site()->env()->has('FATHOM_SITE_ID');
    }

    protected function setSiteId(): void
    {
        $this->http->createJsonClient(
            'https://api.usefathom.com/v1/',
            fn (PendingRequest $request, array $credentials) => $request->withToken($credentials['token']),
            new AddApiCredentialsPrompt(
                url: 'https://app.usefathom.com/api',
                credentials: ['token'],
                displayName: 'Fathom Analytics',
            ),
            fn (PendingRequest $request) => $request->get('sites', ['limit' => 1]),
        );

        $sites = collect(
            $this->http->client()->get('sites', ['limit' => 100])->json()['data']
        );

        $this->siteId = Entity::from($sites)
            ->selectFromExisting(
                'Choose a site',
                'name',
                Project::appName(),
                'Create new site',
            )
            ->createNew(
                'Create new Fathom Analytics site?',
                $this->createNewSite(...),
            )
            ->prompt()['id'];
    }

    protected function createNewSite(): array
    {
        $siteName = Console::ask('Enter your site name', Project::appName());

        return $this->http->client()->post('sites', [
            'name' => $siteName,
        ])->json();
    }

    protected function environmentVariables(): array
    {
        return [
            'FATHOM_SITE_ID' => $this->siteId,
        ];
    }
}
