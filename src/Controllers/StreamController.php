<?php

namespace App\Controllers;

class StreamController extends BaseController
{
	public function item($request, $response, $args)
	{
		$alias = $args['alias'];

		$row = $this->db->getStreamByAlias($alias);
		
		if (!$row) {
			return $this->notFound($request, $response);
		}
		
		$stream = $this->builder->buildStream($row);
		$stats = $this->builder->buildStreamStats($stream);

		$params = $this->buildParams([
		    'image' => $stream['remote_logo'],
		    'description' => $stream['remote_status'],
			'params' => [
				'stream' => $stream,
				'game' => $stream['game'],
				'stats' => $stats,
				'title' => $stream['title'],
				'streams_title' => $this->getSettings('streams.title'),
			],
		]);

		return $this->view->render($response, 'main/streams/item.twig', $params);
	}
	
	public function refresh($request, $response, $args)
	{
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
	
	/**
	 * Twitter test
	 */
	public function twitter($request, $response, $args)
	{
		$alias = $args['alias'];

		$row = $this->db->getStreamByAlias($alias);

        $message = $this->builder->buildStreamNotificationMessage($row);

        // dev environment fix
        $url = str_replace('/bs/', '/', $message['url']);

        $msg = $this->twitter->buildMessage($message['text'], $url, $message['tags']);
		//$this->twitter->tweet($msg);

		return $msg;
	}
}
