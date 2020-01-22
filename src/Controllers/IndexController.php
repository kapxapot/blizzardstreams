<?php

namespace App\Controllers;

class IndexController extends BaseController
{
	public function index($request, $response, $args) {
	    $rows = $this->db->getStreams();
		$streams = $this->builder->buildSortedStreams($rows);
		$groups = $this->builder->buildStreamGroups($streams);

		$params = $this->buildParams([
			'params' => [
				'streams' => $streams,
				'groups' => $groups,
			],
		]);
	
		return $this->view->render($response, 'main/index.twig', $params);
	}

	public function item($request, $response, $args) {
		$alias = $args['alias'];

		$row = $this->db->getStreamByAlias($alias);
		
		if (!$row) {
			return $this->notFound($request, $response);
		}
		
		$stream = $this->builder->buildStream($row);
		$stats = $this->builder->buildStreamStats($stream);

		$params = $this->buildParams([
			'params' => [
				'stream' => $stream,
				'stats' => $stats,
				'title' => $stream['title'],
			],
		]);

		return $this->view->render($response, 'main/streams/item.twig', $params);
	}
	
	public function refresh($request, $response, $args) {
		$log = $request->getQueryParam('log', false);
		$notify = $request->getQueryParam('notify', true);

		$rows = $this->db->getStreams();
		
		$streamData = array_map(function($row) use ($notify) {
			return $this->builder->updateStreamData($row, $notify);
		}, $rows);

		$params = [ 
			'data' => $streamData,
			'log' => $log,
		];

		return $this->view->render($response, 'main/streams/refresh.twig', $params);
	}
}
