<?php

/**
 * @see       https://github.com/mezzio/mezzio-migration for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-migration/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-migration/blob/master/LICENSE.md New BSD License
 */

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
