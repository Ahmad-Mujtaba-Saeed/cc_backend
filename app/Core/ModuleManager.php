<?php

namespace App\Core;

class ModuleManager
{
    public static function load()
    {
        $modulesPath = base_path('modules');

        foreach (glob($modulesPath . '/*/module.json') as $manifest) {
            $config = json_decode(file_get_contents($manifest), true);

            if (!($config['enabled'] ?? false)) {
                continue;
            }

            foreach ($config['providers'] as $provider) {
                app()->register($provider);
            }
        }
    }
}
