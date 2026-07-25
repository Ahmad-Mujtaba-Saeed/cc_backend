<?php

return [
    App\Providers\AppServiceProvider::class,
    \Modules\Auth\ModuleServiceProvider::class, 
    \Modules\Billing\ModuleServiceProvider::class,
    \Modules\AccessControl\ModuleServiceProvider::class,
    \Modules\Project\ModuleServiceProvider::class,
    \Modules\User\ModuleServiceProvider::class,
    \Modules\Resume\ModuleServiceProvider::class,
    Barryvdh\DomPDF\ServiceProvider::class,
];
