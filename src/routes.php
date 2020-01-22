<?php

use Plasticode\Core\Core;
use Plasticode\Middleware\AuthMiddleware;
use Plasticode\Middleware\GuestMiddleware;
use Plasticode\Middleware\AccessMiddleware;
use Plasticode\Middleware\TokenAuthMiddleware;

$access = function($entity, $action, $redirect = null) use ($container) {
	return new AccessMiddleware($container, $entity, $action, $redirect);
};

$root = $settings['root'];
$trueRoot = (strlen($root) == 0);

$app->group($root, function() use ($trueRoot, $settings, $access, $container) {
	// api
	
	$this->group('/api/v1', function() use ($settings) {
		$this->get('/captcha', function($request, $response, $args) use ($settings) {
			$captcha = $this->captcha->generate($settings['captcha_digits'], true);
			return Core::json($response, [ 'captcha' => $captcha['captcha'] ]);
		});
	});
	
	$this->group('/api/v1', function() use ($settings, $access, $container) {
		foreach ($settings['tables'] as $alias => $table) {
			if (isset($table['api'])) {
				$gen = $container->generatorResolver->resolveEntity($alias);
				$gen->generateAPIRoutes($this, $access);
			}
		}
	
		$this->post('/parser/parse', \Plasticode\Controllers\ParserController::class . ':parse')
			->setName('api.parser.parse');
	})->add(new TokenAuthMiddleware($container));
	
	// admin
	
	$this->get('/admin', function($request, $response, $args) {
		return $this->view->render($response, 'admin/index.twig');
	})->setName('admin.index');
	
	$this->group('/admin', function() use ($settings, $access, $container) {
		foreach (array_keys($settings['entities']) as $entity) {
			$gen = $container->generatorResolver->resolveEntity($entity);
			$gen->generateAdminPageRoute($this, $access);
		}
	})->add(new AuthMiddleware($container, 'admin.index'));

	// site

	$this->get('/tags/{tag}', \App\Controllers\TagController::class . ':item')->setName('main.tag');
	$this->get('/{alias}', \App\Controllers\StreamController::class . ':item')->setName('main.stream');
	$this->get('/{alias}/twitter', \App\Controllers\StreamController::class . ':twitter')->setName('main.stream.twitter');

	$this->get($trueRoot ? '/' : '', \App\Controllers\IndexController::class . ':index')->setName('main.index');

	// cron
	
	$this->group('/cron', function () {
		$this->get('/streams/refresh', \App\Controllers\StreamController::class . ':refresh')->setName('main.cron.streams.refresh');
	});

	// auth
	
	$this->group('/auth', function() {
		$this->post('/signup', \Plasticode\Controllers\Auth\AuthController::class . ':postSignUp')->setName('auth.signup');
		$this->post('/signin', \Plasticode\Controllers\Auth\AuthController::class . ':postSignIn')->setName('auth.signin');
	})->add(new GuestMiddleware($container, 'main.index'));
		
	$this->group('/auth', function() {
		$this->post('/signout', \Plasticode\Controllers\Auth\AuthController::class . ':postSignOut')->setName('auth.signout');
		$this->post('/password/change', \Plasticode\Controllers\Auth\PasswordController::class . ':postChangePassword')->setName('auth.password.change');
	})->add(new AuthMiddleware($container, 'main.index'));
});
