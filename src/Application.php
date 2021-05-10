<?php

declare(strict_types=1);

namespace Mezzio\Migration;

class Application extends \Symfony\Component\Console\Application
{
    public function __construct(string $name = 'mezzio-migration', string $version = 'UNKNOWN')
    {
        parent::__construct($name, $version);

        $this->addCommands([
            new MigrateCommand('migrate'),
        ]);
    }
}
