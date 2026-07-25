<?php

namespace Modules\User;

use App\Core\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'User';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/User');
    }
}
