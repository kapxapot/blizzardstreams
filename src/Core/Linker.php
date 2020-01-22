<?php

namespace App\Core;

use Plasticode\Core\Linker as LinkerBase;

class Linker extends LinkerBase
{
	public function stream($stream)
	{
	    $alias = $stream['stream_alias'] ?? $stream['stream_id'];
		return $this->router->pathFor('main.stream', [ 'alias' => $alias ]);
	}
}
