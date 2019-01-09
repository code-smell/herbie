<?php
/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <https://www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Herbie;

use Ausi\SlugGenerator\SlugGenerator;
use Ausi\SlugGenerator\SlugGeneratorInterface;
use Ausi\SlugGenerator\SlugOptions;
use Herbie\Exception\SystemException;
use Herbie\Menu\MenuBuilder;
use Herbie\Menu\MenuList;
use Herbie\Menu\MenuTree;
use Herbie\Menu\MenuTrail;
use Herbie\Middleware\ErrorHandlerMiddleware;
use Herbie\Middleware\MiddlewareDispatcher;
use Herbie\Middleware\PageRendererMiddleware;
use Herbie\Middleware\PageResolverMiddleware;
use Herbie\Persistence\FlatfilePagePersistence;
use Herbie\Persistence\FlatfilePersistenceInterface;
use Herbie\Repository\DataRepositoryInterface;
use Herbie\Repository\FlatfilePageRepository;
use Herbie\Repository\PageRepositoryInterface;
use Herbie\Repository\YamlDataRepository;
use Herbie\Url\UrlGenerator;
use Herbie\Url\UrlMatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use Tebe\HttpFactory\HttpFactory;

defined('HERBIE_DEBUG') or define('HERBIE_DEBUG', false);

class Application
{
    /**
     * Herbie version
     * @see http://php.net/version-compare
     * @var string
     */
    const VERSION = '2.0.0';

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var string
     */
    private $sitePath;

    /**
     * @var string
     */
    private $vendorDir;

    /**
     * @var array
     */
    private $middlewares;

    /**
     * @param string $sitePath
     * @param string $vendorDir
     * @throws \Exception
     */
    public function __construct($sitePath, $vendorDir = '../vendor')
    {
        $this->sitePath = $this->normalizePath($sitePath);
        $this->vendorDir = $this->normalizePath($vendorDir);
        $this->middlewares = [];
        $this->init();
    }

    /**
     * @param string $path
     * @return string
     * @throws \Exception
     */
    private function normalizePath(string $path)
    {
        $realpath = realpath($path);
        if ($realpath === false) {
            $message = sprintf('Could not normalize path "%s"', $path);
            throw SystemException::serverError($message);
        }
        return rtrim($realpath, '/');
    }

    /**
     * Initialize the application.
     */
    private function init()
    {
        $errorHandler = new ErrorHandler();
        $errorHandler->register($this->sitePath . '/runtime/log');

        $this->container = $c = new Container();

        $c[Environment::class] = $environment = new Environment();

        $c[Config::class] = $config = new Config(
            $this->sitePath,
            dirname($_SERVER['SCRIPT_FILENAME']),
            $environment->getBaseUrl()
        );

        setlocale(LC_ALL, $c[Config::class]->get('locale'));

        // Add custom PSR-4 plugin path to Composer autoloader
        $pluginsPath = $c[Config::class]->get('plugins.path');
        $autoload = require($this->vendorDir . '/autoload.php');
        $autoload->addPsr4('herbie\\plugin\\', $pluginsPath);

        $c[HttpFactory::class] = new HttpFactory();

        $c[ServerRequestInterface::class] = $c[HttpFactory::class]->createServerRequestFromGlobals();

        $c[Alias::class] = new Alias([
            '@app' => $config->get('app.path'),
            '@asset' => $this->sitePath . '/assets',
            '@media' => $config->get('media.path'),
            '@page' => $config->get('pages.path'),
            '@plugin' => $config->get('plugins.path'),
            '@site' => $this->sitePath,
            '@vendor' => $this->vendorDir,
            '@web' => $config->get('web.path')
        ]);

        $c[TwigRenderer::class] = function ($c) {
            $twig = new TwigRenderer(
                $c[Alias::class],
                $c[Config::class],
                $c[UrlGenerator::class],
                $c[SlugGeneratorInterface::class],
                $c[Assets::class],
                $c[MenuList::class],
                $c[MenuTree::class],
                $c[MenuTrail::class],
                $c[Environment::class],
                $c[DataRepositoryInterface::class],
                $c[Translator::class],
                $c[EventManager::class]
            );
            return $twig;
        };

        $c[SlugGeneratorInterface::class] = function ($c) {
            $locale = $c[Config::class]->get('language');
            $options = new SlugOptions([
                'locale' => $locale,
                'delimiter' => '-'
            ]);
            return new SlugGenerator($options);
        };

        $c[Assets::class] = function ($c) {
            return new Assets($c[Alias::class], $c[Config::class]->get('web.url'));
        };

        $c[Cache::class] = function () {
            return new Cache();
        };

        $c[DataRepositoryInterface::class] = function ($c) {
            $dataRepository = new YamlDataRepository(
                $c[Config::class]->get('data.path'),
                $c[Config::class]->get('data.extensions')
            );
            return $dataRepository;
        };

        $c[FlatfilePersistenceInterface::class] = function ($c) {
            return new FlatfilePagePersistence($c[Alias::class]);
        };

        $c[PageRepositoryInterface::class] = function ($c) {
            $pageRepository = new FlatfilePageRepository(
                $c[FlatfilePersistenceInterface::class],
                new PageFactory()
            );
            return $pageRepository;
        };

        $c[MenuBuilder::class] = function ($c) {

            $paths = [];
            $paths['@page'] = $this->normalizePath($c[Config::class]->get('pages.path'));
            foreach ($c[Config::class]->get('pages.extra_paths', []) as $alias) {
                $paths[$alias] = $c[Alias::class]->get($alias);
            }
            $extensions = $c[Config::class]->get('pages.extensions', []);

            $builder = new MenuBuilder(
                $c[FlatfilePersistenceInterface::class],
                $paths,
                $extensions
            );
            return $builder;
        };

        $c[EventManager::class] = function () {
            $zendEventManager = new \Zend\EventManager\EventManager();
            $zendEventManager->setEventPrototype(new Event());
            return new EventManager($zendEventManager);
        };

        $c[PluginManager::class] = function ($c) {
            $enabled = $c[Config::class]->get('plugins.enable', []);
            $path = $c[Config::class]->get('plugins.path');
            $enabledSysPlugins = $c[Config::class]->get('sysplugins.enable');
            return new PluginManager($c[EventManager::class], $enabled, $path, $enabledSysPlugins, $c);
        };

        $c[UrlGenerator::class] = function ($c) {
            return new UrlGenerator(
                $c[ServerRequestInterface::class],
                $c[Environment::class],
                $c[Config::class]->get('nice_urls', false)
            );
        };

        $c[MenuList::class] = function ($c) {
            $cache = $c[Cache::class];
            $c[MenuBuilder::class]->setCache($cache);
            return $c[MenuBuilder::class]->buildMenuList();
        };

        $c[MenuTree::class] = function ($c) {
            return MenuTree::buildTree($c[MenuList::class]);
        };

        $c[MenuTrail::class] = function ($c) {
            $menuTrail = new MenuTrail(
                $c[MenuList::class],
                $c[Environment::class]->getRoute()
            );
            return $menuTrail;
        };

        $c[Translator::class] = function ($c) {
            $translator = new Translator($c[Config::class]->get('language'), [
                'app' => $c[Alias::class]->get('@app/../messages')
            ]);
            foreach ($c[PluginManager::class]->getPluginPaths() as $key => $dir) {
                $translator->addPath($key, $dir . '/messages');
            }
            $translator->init();
            return $translator;
        };

        $c[UrlMatcher::class] = function ($c) {
            return new UrlMatcher($c[MenuList::class]);
        };
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        // Init PluginManager
        $this->getPluginManager()->init();

        $middlewares = $this->getMiddlewares();
        $dispatcher = new MiddlewareDispatcher($middlewares);
        $request = $this->container->get(ServerRequestInterface::class);
        $response = $dispatcher->dispatch($request);

        $this->getEventManager()->trigger('onResponseGenerated', $response);

        $this->emitResponse($response);

        $this->getEventManager()->trigger('onResponseRendered');
    }

    /**
     * @return array
     */
    private function getMiddlewares(): array
    {
        $middlewares = array_merge(
            [
                new ErrorHandlerMiddleware(
                    $this->getTwigRenderer()
                )
            ],
            $this->middlewares,
            [
                new PageResolverMiddleware(
                    $this,
                    $this->getEnvironment(),
                    $this->getPageRepository(),
                    $this->getUrlMatcher()
                )
            ],
            $this->getPluginManager()->getMiddlewares(),
            [
                new PageRendererMiddleware(
                    $this->getPageCache(),
                    $this->getEnvironment(),
                    $this->getHttpFactory(),
                    $this->getEventManager(),
                    $this->getTwigRenderer(),
                    $this->getConfig(),
                    $this->getDataRepository(),
                    $this->getMenuList(),
                    $this->getMenuTree(),
                    $this->getMenuTrail()
                )
            ]
        );
        return $middlewares;
    }

    /**
     * @param array $middlewares
     * @return Application
     */
    public function setMiddlewares(array $middlewares)
    {
        $this->middlewares = $middlewares;
        return $this;
    }

    /**
     * @return Environment
     */
    public function getEnvironment()
    {
        return $this->container->get(Environment::class);
    }

    /**
     * @return UrlMatcher
     */
    public function getUrlMatcher()
    {
        return $this->container->get(UrlMatcher::class);
    }

    /**
     * @return PageRepositoryInterface
     */
    public function getPageRepository()
    {
        return $this->container->get(PageRepositoryInterface::class);
    }

    /**
     * @return PluginManager
     */
    public function getPluginManager()
    {
        return $this->container->get(PluginManager::class);
    }

    /**
     * @return EventManager
     */
    public function getEventManager()
    {
        return $this->container->get(EventManager::class);
    }

    /**
     * @param ResponseInterface $response
     */
    private function emitResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        http_response_code($statusCode);
        foreach ($response->getHeaders() as $k => $values) {
            foreach ($values as $v) {
                header(sprintf('%s: %s', $k, $v), false);
            }
        }
        echo $response->getBody();
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->container->get(Config::class);
    }

    /**
     * @return Alias
     */
    public function getAlias()
    {
        return $this->container->get(Alias::class);
    }

    /**
     * @return UrlGenerator
     */
    public function getUrlGenerator()
    {
        return $this->container->get(UrlGenerator::class);
    }

    /**
     * @return CacheInterface
     */
    public function getPageCache()
    {
        return $this->container->get(Cache::class);
    }

    /**
     * @param CacheInterface $cache
     * @return Application
     */
    public function setPageCache(CacheInterface $cache)
    {
        $this->container->set(Cache::class, $cache);
        return $this;
    }

    /**
     * @return Assets
     */
    public function getAssets()
    {
        return $this->container->get(Assets::class);
    }

    /**
     * @return MenuTrail
     */
    public function getMenuTrail()
    {
        return $this->container->get(MenuTrail::class);
    }

    /**
     * @return MenuTree
     */
    public function getMenuTree()
    {
        return $this->container->get(MenuTree::class);
    }

    /**
     * @return HttpFactory
     */
    public function getHttpFactory()
    {
        return $this->container->get(HttpFactory::class);
    }

    /**
     * @return Translator
     */
    public function getTranslator()
    {
        return $this->container->get(Translator::class);
    }

    /**
     * @return MenuList
     */
    public function getMenuList()
    {
        return $this->container->get(MenuList::class);
    }

    /**
     * @return DataRepositoryInterface
     */
    public function getDataRepository()
    {
        return $this->container->get(DataRepositoryInterface::class);
    }

    /**
     * @return SlugGeneratorInterface
     */
    public function getSlugGenerator()
    {
        return $this->container->get(SlugGeneratorInterface::class);
    }

    /**
     * @return TwigRenderer
     */
    public function getTwigRenderer()
    {
        return $this->container->get(TwigRenderer::class);
    }
}
