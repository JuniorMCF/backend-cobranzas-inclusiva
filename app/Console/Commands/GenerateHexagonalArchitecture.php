<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;


class GenerateHexagonalArchitecture extends Command
{
    protected $signature = 'make:hexagonal {context}';

    protected $description = 'Generate hexagonal architecture folder structure';

    public function handle()
    {
        $context = $this->argument('context');


        if (!File::exists('core')) {
            File::makeDirectory('core', 0755, true);
        }

        File::makeDirectory("core/$context/Application", 0755, true);
        File::makeDirectory("core/$context/Domain", 0755, true);
        File::makeDirectory("core/$context/Infrastructure", 0755, true);

        // Crear archivos dentro de la capa Application
        File::put("core/$context/Application/Query.php", '');


        // Crear archivos dentro de la capa Domain
        File::makeDirectory("core/$context/Domain/Contracts", 0755, true);
        File::put("core/$context/Domain/Contracts/Repository.php", '');
        File::put("core/$context/Domain/Entity.php", '');

        // Crear archivos dentro de la capa Infrastructure
        File::makeDirectory("core/$context/Infrastructure/Repositories", 0755, true);
        File::put("core/$context/Infrastructure/Repositories/DatabaseRepository.php", '');
        File::put("core/$context/Infrastructure/Controller.php", '');


    }

}
