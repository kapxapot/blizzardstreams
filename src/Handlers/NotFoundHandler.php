<?php

namespace App\Handlers;

use App\Controllers\BaseController;

class NotFoundHandler extends BaseController {
	public function __invoke($request, $response) {
		$params = $this->buildParams([
			'params' => [
				'text' => 'Page not found or moved somewhere we don\'t know where.',
				'title' => 'Error 404',
				'no_breadcrumbs' => true,
				'no_disqus' => 1,
				'no_social' => 1,
			],
		]);

		return $this->view->render($response, 'main/generic.twig', $params)
			->withStatus(404);
	}
}
