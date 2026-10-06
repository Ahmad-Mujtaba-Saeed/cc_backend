<?php

namespace Modules\Billing;

use App\Core\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'Billing';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/Billing');
    }

    public function register()
    {
        // One client per request, built lazily: most requests never touch
        // Stripe. An empty key is allowed here and fails on the first call.
        $this->app->singleton(\Stripe\StripeClient::class, fn () => new \Stripe\StripeClient([
            'api_key' => (string) config('stripe.secret') ?: null,
            'max_network_retries' => 2,
        ]));
    }
}
