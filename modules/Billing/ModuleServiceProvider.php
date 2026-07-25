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
}
