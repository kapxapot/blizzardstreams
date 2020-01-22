<?php

namespace App\Controllers;

use Plasticode\Util\Sort;

class TagController extends BaseController
{
	public function item($request, $response, $args)
	{
		$tag = $args['tag'];

		if (strlen($tag) == 0) {
			return $this->notFound($request, $response);
		}
		
		$streams = $this->builder->buildStreamsByTag($tag);//buildTagParts($tag);

		$params = $this->buildParams([
			'params' => [
				'tag' => $tag,
				'title' => "Tag \"{$tag}\"",
				'streams' => $streams,
			],
		]);
	
		return $this->view->render($response, 'main/tags/item.twig', $params);
	}
}
