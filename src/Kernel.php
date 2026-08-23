<?php

namespace ApManBundle;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private const CONFIG_EXTS = '.{php,xml,yaml,yml}';

    /**
     * The clock the pages are written in.
     *
     * php's date.timezone is UTC here while the machine and every access point
     * are on CEST, so every timestamp the application had ever displayed was
     * two hours out — client pages, radio pages, the consistency report, the
     * block expiry, all of it. Nothing said so, which is what made it survive:
     * a time with no zone on it reads as local time to whoever is looking.
     *
     * It went unnoticed until the syslog page put a line from an access point
     * next to the time that access point had logged it at, which is the one
     * place where being two hours out is not cosmetic — lining a controller
     * line up against a device line is the whole reason that page exists.
     *
     * Taken from the system unless APP_TIMEZONE says otherwise, so that a
     * controller moved to another site does the right thing without anyone
     * having to remember this.
     */
    public function boot(): void
    {
        $zone = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? null;
        if (!$zone) {
            $link = @readlink('/etc/localtime');
            if (is_string($link) && false !== ($at = strpos($link, 'zoneinfo/'))) {
                $zone = substr($link, $at + 9);
            }
        }
        if ($zone) {
            try {
                new \DateTimeZone($zone);
                date_default_timezone_set($zone);
            } catch (\Exception $e) {
                // an unusable name is worse than the default, so keep the
                // default and say nothing the pages cannot act on
            }
        }

        parent::boot();
    }

    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir().'/config/bundles.php';
        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->addResource(new FileResource($this->getProjectDir().'/config/bundles.php'));
        $container->setParameter('container.dumper.inline_class_loader', \PHP_VERSION_ID < 70400 || $this->debug);
        $container->setParameter('container.dumper.inline_factories', true);
        $confDir = $this->getProjectDir().'/config';

        $loader->load($confDir.'/{packages}/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{packages}/'.$this->environment.'/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}_'.$this->environment.self::CONFIG_EXTS, 'glob');
    }

    /**
     * Symfony 6 hands a RoutingConfigurator instead of the old builder; the
     * import list is the same, only the type and the argument order changed.
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $confDir = $this->getProjectDir().'/config';

        $routes->import($confDir.'/{routes}/'.$this->environment.'/*'.self::CONFIG_EXTS, 'glob');
        $routes->import($confDir.'/{routes}/*'.self::CONFIG_EXTS, 'glob');
        $routes->import($confDir.'/{routes}'.self::CONFIG_EXTS, 'glob');
    }
}
