<?php

namespace Modules\AccessControl;

use App\Core\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'AccessControl';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/AccessControl');
    }
}
