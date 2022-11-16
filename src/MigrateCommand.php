<?php

declare(strict_types=1);

namespace Mezzio\Migration;

use InvalidArgumentException;
use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

use function array_merge;
use function arsort;
use function assert;
use function chdir;
use function exec;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_writable;
use function json_decode;
use function json_encode;
use function key;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function realpath;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function strtr;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

class MigrateCommand extends Command
{
    private ?InputInterface $input = null;

    private ?OutputInterface $output = null;

    /** @var string[] */
    private array $packages = [
        'laminas/laminas-diactoros',
        'laminas/laminas-component-installer',
        'mezzio/mezzio-problem-details',
        'laminas/laminas-stratigility',
    ];

    private string $packagesPattern = '#^mezzio/mezzio(?!-migration)#';

    /** @var string */
    private $skeletonVersion;

    protected function configure()
    {
        $this->setDescription('Migrate an Mezzio application from version 2 to version 3.');
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Path to the mezzio application',
            realpath(getcwd())
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $path = $input->getArgument('path') ?: getcwd();
        if (! is_dir($path)) {
            throw new InvalidArgumentException('Given path is not a directory.');
        }

        if (! is_writable(sprintf('%s/composer.json', $path))) {
            throw new InvalidArgumentException(sprintf(
                'File %s/composer.json does not exist or is not writable.',
                $path
            ));
        }
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;

        $path = $input->getArgument('path');
        chdir($path);

        $packages = $this->findPackagesToUpdate();
        if (! isset($packages['mezzio/mezzio'])) {
            $output->writeln('<error>Package mezzio/mezzio has not been detected.</error>');
            return 1;
        }

        if (file_exists('composer.lock')) {
            $lock = json_decode(file_get_contents('composer.lock'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($lock['packages'] as $package) {
                if (
                    strtolower($package['name']) === 'mezzio/mezzio'
                    && preg_match('/\d+\.\d+(\.\d+)?/', $package['version'], $matches)
                ) {
                    $version = $matches[0];
                    break;
                }
            }
        }

        if (! isset($version)) {
            $output->writeln('<error>Cannot detect mezzio version.</error>');
            return 1;
        }

        $output->writeln(sprintf('<info>Detected mezzio in version %s</info>', $version));

        if (! str_starts_with($version, '2.')) {
            $output->writeln(sprintf('<error>This tool can migrate only Mezzio v2 applications</error>'));
            return 1;
        }

        $removePackages = [];
        if (isset($packages['aura/di'])) {
            $removePackages[] = 'aura/di';

            $packages['laminas/laminas-auradi-config'] = [
                'name' => 'laminas/laminas-auradi-config',
                'dev'  => false,
            ];
        }

        if (
            isset($packages['pimple/pimple'])
            || isset($packages['xtreamwayz/pimple-container-interop'])
        ) {
            $removePackages[] = 'pimple/pimple';
            $removePackages[] = 'xtreamwayz/pimple-container-interop';

            $packages['laminas/laminas-pimple-config'] = [
                'name' => 'laminas/laminas-pimple-config',
                'dev'  => false,
            ];
        }

        if (isset($packages['http-interop/http-middleware'])) {
            $removePackages[] = 'http-interop/http-middleware';
        }

        if ($removePackages) {
            exec(sprintf(
                'composer remove %s',
                implode(' ', $removePackages)
            ));
        }

        $this->updatePackages($packages);
        $this->updatePipeline();
        $this->updateRoutes();
        $this->replaceIndex();

        if (isset($packages['laminas/laminas-pimple-config'])) {
            $container = $this->getFileContent('src/MezzioInstaller/Resources/config/container-pimple.php');
            file_put_contents('config/container.php', $container);
        }

        if (isset($packages['laminas/laminas-auradi-config'])) {
            $container = $this->getFileContent('src/MezzioInstaller/Resources/config/container-aura-di.php');
            file_put_contents('config/container.php', $container);
        }

        $src = $this->getDirectory('Please provide the path to the application sources', 'src');
        $this->migrateInteropMiddlewares($src);

        $actionDir = $this->getDirectory(
            'Please provide the path to the application actions to be converted to request handlers',
            'src'
        );

        $this->migrateMiddlewaresToRequestHandlers($actionDir);

        $this->csAutoFix();

        return 0;
    }

    private function csAutoFix(): void
    {
        assert($this->output instanceof OutputInterface);

        $this->output->writeln('<question>Running CS auto-fixer</question>');
        if (file_exists('vendor/bin/phpcbf')) {
            exec('composer cs-fix', $output);
            $this->output->writeln($output);
        }
    }

    private function getDirectory(string $questionString, ?string $default = null): string
    {
        $helper   = $this->getHelper('question');
        $question = new Question(
            ($default ? sprintf('%s [<info>%s</info>]', $questionString, $default) : $questionString) . ': ',
            $default
        );
        $question->setValidator(static function ($dir) {
            if (! $dir || ! is_dir($dir)) {
                throw new RuntimeException(sprintf('Directory %s does not exist. Please try again', $dir));
            }

            return $dir;
        });

        assert($this->output instanceof OutputInterface);

        $src = $helper->ask($this->input, $this->output, $question);

        $this->output->writeln('<question>Provided directory is: ' . $src . '</question>');

        return $src;
    }

    private function migrateInteropMiddlewares(string $src): void
    {
        exec(sprintf(
            'composer mezzio -- migrate:interop-middleware --src %s',
            $src
        ), $output);

        assert($this->output instanceof OutputInterface);

        $this->output->writeln($output);
    }

    private function migrateMiddlewaresToRequestHandlers(string $dir): void
    {
        exec(sprintf(
            'composer mezzio -- migrate:middleware-to-request-handler --src %s',
            $dir
        ), $output);

        assert($this->output instanceof OutputInterface);

        $this->output->writeln($output);
    }

    private function updatePackages(array $packages): void
    {
        exec('rm -Rf vendor');
        exec('composer install --no-interaction');

        $composer                            = $this->getComposerContent();
        $composer['config']['sort-packages'] = true;
        if (
            isset($composer['config']['platform']['php'])
            && ! str_contains($composer['config']['platform']['php'], '7.1')
            && ! str_contains($composer['config']['platform']['php'], '7.2')
            && ! str_contains($composer['config']['platform']['php'], '7.3')
        ) {
            $composer['config']['platform']['php'] = '7.1.3';
        }

        // Add composer scripts
        if (file_exists('vendor/bin/phpcs')) {
            $composer['scripts']['cs-check'] = 'phpcs';
        }
        if (file_exists('vendor/bin/phpcbf')) {
            $composer['scripts']['cs-fix'] = 'phpcbf';
        }
        $composer['scripts']['mezzio'] = 'mezzio';

        $this->updateComposer($composer);

        if (isset($packages['laminas/laminas-component-installer'])) {
            $packages['laminas/laminas-component-installer']['dev'] = true;
        } else {
            $packages['laminas/laminas-component-installer'] = [
                'name' => 'laminas/laminas-component-installer',
                'dev'  => true,
            ];
        }

        if (isset($packages['mezzio/mezzio-tooling'])) {
            $packages['mezzio/mezzio-tooling']['dev'] = true;
        } else {
            $packages['mezzio/mezzio-tooling'] = [
                'name' => 'mezzio/mezzio-tooling',
                'dev'  => true,
            ];
        }

        $deps = [];
        $lock = json_decode(file_get_contents('composer.lock'), true, 512, JSON_THROW_ON_ERROR);

        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            $name = $package['name'];
            if (! $this->isPackageToUpdate($name)) {
                continue;
            }

            exec(sprintf('composer why %s', $name), $output, $returnCode);

            if ($returnCode !== 0) {
                continue;
            }

            foreach ($output as $line) {
                $exp           = explode(' ', $line, 2);
                $deps[$exp[0]] = $exp[0];
            }
        }
        unset($deps[$composer['name']]);

        $extraRequire    = [];
        $extraRequireDev = [];
        foreach ($deps as $dep) {
            if (isset($composer['require'][$dep])) {
                $extraRequire[] = $dep;
            }

            if (isset($composer['require-dev'][$dep])) {
                $extraRequireDev[] = $dep;
            }
        }

        $require    = ['laminas/laminas-diactoros'];
        $requireDev = [];
        foreach ($packages as $name => $package) {
            if ($package['dev']) {
                $requireDev[] = $name;
            } else {
                $require[] = $name;
            }
        }

        $commands = [
            // Remove this package itself if it was previously installed
            sprintf('composer remove -q mezzio/mezzio-migration'),
            sprintf(
                'composer remove --dev %s --no-interaction',
                implode(' ', [...$require, ...$requireDev, ...$extraRequire, ...$extraRequireDev])
            ),
            sprintf(
                'composer remove %s --no-interaction',
                implode(' ', [...$require, ...$requireDev, ...$extraRequire, ...$extraRequireDev])
            ),
            sprintf('composer update --no-interaction'),
            sprintf('composer require %s --no-interaction', implode(' ', $require)),
            sprintf('composer require --dev %s --no-interaction', implode(' ', $requireDev)),
            sprintf('composer require %s --no-interaction', implode(' ', $extraRequire)),
            sprintf('composer require --dev %s --no-interaction', implode(' ', $extraRequireDev)),
        ];

        assert($this->output instanceof OutputInterface);

        foreach ($commands as $command) {
            $this->output->writeln('<question>' . $command . '</question>');
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->output->writeln(
                    '<error>Error occurred on executing above command. Please see logs above</error>'
                );
                return;
            }
        }
    }

    private function updatePipeline(): void
    {
        assert($this->output instanceof OutputInterface);

        $this->output->write('<info>Updating pipeline...</info>');

        if (! $this->addFunctionWrapper('config/pipeline.php')) {
            $this->output->writeln(' <comment>SKIPPED</comment>');
            return;
        }

        $pipeline = file_get_contents('config/pipeline.php');

        // @codingStandardsIgnoreStart
        $replacement = [
            '->pipeRoutingMiddleware();'                  => '->pipe(\Mezzio\Router\Middleware\RouteMiddleware::class);',
            '->pipeDispatchMiddleware();'                 => '->pipe(\Mezzio\Router\Middleware\DispatchMiddleware::class);',
            'Mezzio\Middleware\NotFoundHandler'           => 'Mezzio\Handler\NotFoundHandler',
            'Mezzio\Middleware\ImplicitHeadMiddleware'    => 'Mezzio\Router\Middleware\ImplicitHeadMiddleware',
            'Mezzio\Middleware\ImplicitOptionsMiddleware' => 'Mezzio\Router\Middleware\ImplicitOptionsMiddleware',
        ];
        // @codingStandardsIgnoreEnd

        $pipeline = strtr($pipeline, $replacement);

        // Find the latest
        $search = [
            'RouteMiddleware::class);'           => false,
            'ImplicitHeadMiddleware::class);'    => false,
            'ImplicitHeadMiddleware\');'         => false,
            'ImplicitHeadMiddleware");'          => false,
            'ImplicitOptionsMiddleware::class);' => false,
            'ImplicitOptionsMiddleware");'       => false,
        ];

        foreach ($search as $string => &$pos) {
            $pos = strrpos($pipeline, $string);
        }
        arsort($search);

        $string   = key($search);
        $pipeline = preg_replace(
            '/' . preg_quote($string, '/') . '/',
            $string . PHP_EOL . '$app->pipe(\Mezzio\Router\Middleware\MethodNotAllowedMiddleware::class);',
            $pipeline
        );

        file_put_contents('config/pipeline.php', $pipeline);

        $this->output->writeln(' <comment>DONE</comment>');
    }

    private function updateRoutes(): void
    {
        assert($this->output instanceof OutputInterface);

        $this->output->write('<info>Updating routes...</info>');

        if (! $this->addFunctionWrapper('config/routes.php')) {
            $this->output->writeln(' <comment>SKIPPED</comment>');
        }

        $this->output->writeln(' <comment>DONE</comment>');
    }

    private function replaceIndex(): void
    {
        assert($this->output instanceof OutputInterface);

        $this->output->write('<info>Replacing index.php...</info>');
        $index = $this->getFileContent('public/index.php');

        file_put_contents('public/index.php', $index);
        $this->output->writeln(' <comment>DONE</comment>');
    }

    private function detectLastSkeletonVersion(string $match): string
    {
        if (! $this->skeletonVersion) {
            $this->skeletonVersion = 'master';

            $package = json_decode(
                file_get_contents('https://packagist.org/packages/mezzio/mezzio-skeleton.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $version = null;
            foreach ($package['package']['versions'] as $version => $details) {
                if (preg_match($match, $version)) {
                    $this->skeletonVersion = $version;
                    break;
                }
            }

            assert($this->output instanceof OutputInterface);

            $this->output->write(sprintf(' <info>from skeleton version: %s</info>', $version));
        }

        return $this->skeletonVersion;
    }

    private function getFileContent(string $path): string
    {
        $version = $this->detectLastSkeletonVersion('/^3\.\d+\.\d+$/');
        $uri     = sprintf(
            'https://raw.githubusercontent.com/mezzio/mezzio-skeleton/%s/',
            $version
        );

        return file_get_contents($uri . $path);
    }

    private function addFunctionWrapper(string $file): bool
    {
        if (! file_exists($file)) {
            return false;
        }

        $contents = file_get_contents($file);

        if (str_contains($contents, 'return function')) {
            return false;
        }

        if (! str_contains($contents, 'strict_types')) {
            $contents = str_replace('<?php', '<?php' . PHP_EOL . PHP_EOL . 'declare(strict_types=1);', $contents);
        }

        $contents = preg_replace(
            '/^\s*\$app->/m',
            sprintf(
                'return function (' . PHP_EOL
                    . '    \%s $app,' . PHP_EOL
                    . '    \%s $factory,' . PHP_EOL
                    . '    \%s $container' . PHP_EOL
                    . ') : void {',
                Application::class,
                MiddlewareFactory::class,
                ContainerInterface::class
            ) . PHP_EOL . '\\0',
            $contents,
            1
        );

        $contents = trim($contents) . PHP_EOL . '};' . PHP_EOL;

        file_put_contents($file, $contents);
        return true;
    }

    private function getComposerContent(): array
    {
        return json_decode(file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function updateComposer(array $data): void
    {
        foreach ($data as $sectionName => $sectionData) {
            if (is_array($sectionData) && ! $sectionData) {
                unset($data[$sectionName]);
            }
        }

        file_put_contents('composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array {
     *     @var string $name
     *     @var string $constraint
     *     @var bool $dev
     * }
     */
    private function findPackagesToUpdate(): array
    {
        $packages = [];
        $composer = $this->getComposerContent();

        foreach ($composer['require'] as $package => $constraint) {
            $package = strtolower($package);
            if ($this->isPackageToUpdate($package)) {
                $packages[$package] = [
                    'name'       => $package,
                    'constraint' => $constraint,
                    'dev'        => false,
                ];
            }
        }

        foreach ($composer['require-dev'] as $package => $constraint) {
            $package = strtolower($package);
            if ($this->isPackageToUpdate($package)) {
                $packages[$package] = [
                    'name'       => $package,
                    'constraint' => $constraint,
                    'dev'        => true,
                ];
            }
        }

        return $packages;
    }

    private function isPackageToUpdate(string $name): bool
    {
        return in_array($name, $this->packages, true) || preg_match($this->packagesPattern, $name);
    }
}
