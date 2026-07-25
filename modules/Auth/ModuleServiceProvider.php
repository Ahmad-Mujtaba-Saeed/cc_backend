<?php

namespace Modules\Auth;

use App\Core\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'Auth';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/Auth');
    }
}
