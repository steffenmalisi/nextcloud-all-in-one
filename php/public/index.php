<?php
declare(strict_types=1);

// increase memory limit to 2GB
ini_set('memory_limit', '2048M');

// set max execution time to 2h just in case of a very slow internet connection
ini_set('max_execution_time', '7200');

use DI\Container;
use Slim\Csrf\Guard;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$container = \AIO\DependencyInjection::GetContainer();
$dataConst = $container->get(\AIO\Data\DataConst::class);
ini_set('session.save_path', $dataConst->GetSessionDirectory());

// Auto logout on browser close
ini_set('session.cookie_lifetime', '0');

// Make sure to delete all stale sessions after at least one day
ini_set('session.gc_maxlifetime', '86400');
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '1');

// Create app
AppFactory::setContainer($container);
$app = AppFactory::create();
$responseFactory = $app->getResponseFactory();

// Register Middleware On Container
$container->set(Guard::class, function () use ($responseFactory) {
    $guard = new Guard($responseFactory);
    $guard->setPersistentTokenMode(true);
    return $guard;
});

// Register Middleware To Be Executed On All Routes
session_start();
$app->add(Guard::class);

// Create Twig
$twig = Twig::create(__DIR__ . '/../templates/', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));
$twig->addExtension(new \AIO\Twig\CsrfExtension($container->get(Guard::class)));

// Auth Middleware
$app->add(new \AIO\Middleware\AuthMiddleware($container->get(\AIO\Auth\AuthManager::class)));

// API
$app->post('/api/docker/watchtower', AIO\Controller\DockerController::class . ':StartWatchtowerContainer');
$app->post('/api/docker/start', AIO\Controller\DockerController::class . ':StartContainer');
$app->post('/api/docker/backup', AIO\Controller\DockerController::class . ':StartBackupContainerBackup');
$app->post('/api/docker/backup-check', AIO\Controller\DockerController::class . ':StartBackupContainerCheck');
$app->post('/api/docker/restore', AIO\Controller\DockerController::class . ':StartBackupContainerRestore');
$app->post('/api/docker/stop', AIO\Controller\DockerController::class . ':StopContainer');
$app->get('/api/docker/logs', AIO\Controller\DockerController::class . ':GetLogs');
$app->post('/api/auth/login', AIO\Controller\LoginController::class . ':TryLogin');
$app->get('/api/auth/getlogin', AIO\Controller\LoginController::class . ':GetTryLogin');
$app->post('/api/auth/logout', AIO\Controller\LoginController::class . ':Logout');
$app->post('/api/configuration', \AIO\Controller\ConfigurationController::class . ':SetConfig');

// Views
$app->get('/containers', function ($request, $response, $args) use ($container) {
    $view = Twig::fromRequest($request);
    /** @var \AIO\Data\ConfigurationManager $configurationManager */
    $configurationManager = $container->get(\AIO\Data\ConfigurationManager::class);
    $dockerActionManger = $container->get(\AIO\Docker\DockerActionManager::class);
    $dockerActionManger->ConnectMasterContainerToNetwork();
    $dockerController = $container->get(\AIO\Controller\DockerController::class);
    $dockerController->StartDomaincheckContainer();
    $view->addExtension(new \AIO\Twig\ClassExtension());
    return $view->render($response, 'containers.twig', [
        'domain' => $configurationManager->GetDomain(),
        'borg_backup_host_location' => $configurationManager->GetBorgBackupHostLocation(),
        'borg_backup_mode' => $configurationManager->GetBorgBackupMode(),
        'nextcloud_password' => $configurationManager->GetSecret('NEXTCLOUD_PASSWORD'),
        'containers' => (new \AIO\ContainerDefinitionFetcher($container->get(\AIO\Data\ConfigurationManager::class), $container))->FetchDefinition(),
        'borgbackup_password' => $configurationManager->GetSecret('BORGBACKUP_PASSWORD'),
        'is_mastercontainer_update_available' => $dockerActionManger->IsMastercontainerUpdateAvailable(),
        'has_backup_run_once' => $configurationManager->hasBackupRunOnce(),
        'backup_exit_code' => $dockerActionManger->GetBackupcontainerExitCode(),
        'was_start_button_clicked' => $configurationManager->wasStartButtonClicked(),
        'has_update_available' => $dockerActionManger->isAnyUpdateAvailable(),
        'last_backup_time' => $configurationManager->GetLastBackupTime(),
    ]);
})->setName('profile');
$app->get('/login', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'login.twig');
});
$app->get('/setup', function ($request, $response, $args) use ($container) {
    $view = Twig::fromRequest($request);
    /** @var \AIO\Data\Setup $setup */
    $setup = $container->get(\AIO\Data\Setup::class);

    if(!$setup->CanBeInstalled()) {
        return $view->render(
            $response,
            'already-installed.twig'
        );
    }

    return $view->render(
        $response,
        'setup.twig',
        [
            'password' => $setup->Setup(),
        ]
    );
});

// Auth Redirector
$app->get('/', function (\Psr\Http\Message\RequestInterface $request, \Psr\Http\Message\ResponseInterface $response, $args) use ($container) {
    $authManager = $container->get(\AIO\Auth\AuthManager::class);

    /** @var \AIO\Data\Setup $setup */
    $setup = $container->get(\AIO\Data\Setup::class);
    if($setup->CanBeInstalled()) {
        return $response
            ->withHeader('Location', '/setup')
            ->withStatus(302);
    }

    if($authManager->IsAuthenticated()) {
        return $response
            ->withHeader('Location', '/containers')
            ->withStatus(302);
    } else {
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }
});

$app->run();