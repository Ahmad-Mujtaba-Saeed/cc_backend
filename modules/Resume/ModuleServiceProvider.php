<?php

namespace Modules\Resume;

use App\Core\BaseModuleServiceProvider;
use Illuminate\Support\Facades\File;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'Resume';
    protected string $moduleNameLower = 'resume';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/Resume');
    }

    public function boot()
    {
        parent::boot();
        
        $this->registerViews();
    }

    protected function registerViews()
    {
        $viewsPath = $this->modulePath . '/resources/views';


        if (!File::isDirectory($viewsPath)) {
            File::makeDirectory($viewsPath, 0755, true);
        }

        $this->loadViewsFrom($viewsPath, $this->moduleName);
        $this->loadViewsFrom($viewsPath, $this->moduleNameLower);
    }
}