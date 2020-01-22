<?php

namespace App\Data;

use Plasticode\Data\Db as DbBase;
use Plasticode\Util\Arrays;
use Plasticode\Util\Date;

class Db extends DbBase {
    private $games;

	public function init()
	{
	    $this->initGames();
	}
	
	private function initGames()
	{
	    $games = Arrays::toAssocBy($this->getGames());

	    // roots
	    foreach ($games as &$game) {
	        $cur = $game;
	        
	        do {
	            $game['root_id'] = $cur['id'];
	            $cur = $games[$cur['parent_id']] ?? null;
	        } while ($cur);
	    }
	    
	    // subgames
	    $byRoot = Arrays::groupBy($games, 'root_id');
	    foreach ($byRoot as $rootId => $group) {
	        $games[$rootId]['subgames'] = array_column($group, 'id');
	    }
	    
	    $this->games = $games;
	}
	
	// STREAMS

	private function encodeStreamData($data)
	{
		if ($data) {
			$data['remote_status'] = urlencode($data['remote_status']);
		}
		
		return $data;
	}
	
	private function decodeStreamData($data)
	{
		if ($data) {
			$data['remote_status'] = urldecode($data['remote_status']);
		}
		
		return $data;
	}
	
	private function decodeManyStreamData($array)
	{
		return ($array !== null)
		    ? array_map(array($this, 'decodeStreamData'), $array)
		    : null;
	}
	
    public function getStreams()
    {
    	$streams = $this->getMany(Tables::STREAMS, function($q) {
    		return $q
    			->where('published', 1)
    			->orderByDesc('remote_viewers');
    	});

    	return $this->decodeManyStreamData($streams);
    }
    
    public function getStreamByAlias($alias)
    {
    	$stream = $this->getBy(Tables::STREAMS, function($q) use ($alias) {
    		return $q
    			->whereRaw('(stream_alias = ? or (stream_alias is null and stream_id = ?))', [ $alias, $alias ])
    			->where('published', 1);
    	});
    	
    	return $this->decodeStreamData($stream);
    }
	
	public function saveStream($data)
	{
		$data = $this->encodeStreamData($data);
		
		$stream = $this->getObj(Tables::STREAMS, $data['id']);

        $stream->remote_viewers = $data['remote_viewers'];
        $stream->remote_title = $data['remote_title'];
        $stream->remote_game = $data['remote_game'];
        $stream->remote_status = $data['remote_status'];
        $stream->remote_logo = $data['remote_logo'];
        $stream->remote_online = $data['remote_online'];
		$stream->setExpr('remote_updated_at', 'now()');

		if ($data['remote_online'] == 1) {
			$stream->setExpr('remote_online_at', 'now()');
		}

		$stream->save();
	}
	
	public function getLastStreamStats($streamId)
	{
		$stats = $this->getBy(Tables::STREAM_STATS, function($q) use ($streamId) {
			return $q
				->where('stream_id', $streamId)
				->orderByDesc('created_at');
		});
		
    	return $this->decodeStreamData($stats);
	}
	
	public function saveStreamStats($data)
	{
		$data = $this->encodeStreamData($data);

		$stats = $this->forTable(Tables::STREAM_STATS)->create();

		$stats->stream_id = $data['id'];
        $stats->remote_viewers = $data['remote_viewers'];
        $stats->remote_game = $data['remote_game'];
        $stats->remote_status = $data['remote_status'];

		$stats->save();
	}
	
	public function finishStreamStats($id)
	{
		$this->setField(Tables::STREAM_STATS, $id, 'finished_at', Date::dbNow());
	}
	
	public function getStreamGameStats($streamId, $days = 30)
	{
		$stats = $this->getMany(Tables::STREAM_STATS, function($q) use ($streamId, $days) {
			$table = $this->getTableName(Tables::STREAM_STATS);
			
			return $q->rawQuery(
				"select remote_game, count(*) count
				from {$table}
				where created_at >= date_sub(now(), interval {$days} day) and length(remote_game) > 0 and stream_id = :stream_id
				group by remote_game",
				[ 'stream_id' => intval($streamId) ]);
		});
		
    	return $this->decodeManyStreamData($stats);
	}
	
	/*public function getLatestStreamStats($streamId, $days = 1)
	{
		$stats = $this->getMany(Tables::STREAM_STATS, function($q) use ($streamId, $days) {
			$table = $this->getTableName(Tables::STREAM_STATS);
			
			return $q
				->rawQuery(
					"select *
					from {$table}
					where created_at >= date_sub(now(), interval {$days} day) and length(remote_game) > 0 and stream_id = :stream_id",
					[ 'stream_id' => intval($streamId) ])
				->orderByAsc('created_at');
		});
	
	   	return $this->decodeManyStreamData($stats);
	}*/
	
	public function getStreamStatsFrom($streamId, \DateTime $from)
	{
	    // utc:
	    // CONVERT_TZ( created_at, @ @session.time_zone ,  '+00:00' ) AS utc_created_at
	    
		$stats = $this->getMany(Tables::STREAM_STATS, function($q) use ($streamId, $from) {
			return $q
				->where('stream_id', $streamId)
				->whereGte('created_at', Date::formatDb($from))
				->orderByAsc('created_at');
		});

    	return $this->decodeManyStreamData($stats);
	}
	
	public function getStreamsByTag($tag)
	{
		$streams = $this->getByTag(Tables::STREAMS, Taggable::STREAMS, $tag);
		
    	return $this->decodeManyStreamData($streams);
	}
	
	// GAMES
	
	public function getGames()
	{
		return $this->getMany(Tables::GAMES);
	}

	public function getGame($id)
	{
		return $this->games[$id] ?? null;
	}
	
	public function getGameByAlias($alias)
	{
		return Arrays::firstBy($this->games, 'alias', $alias);
	}
	
	public function getGameByTwitchName($name)
	{
    	$game = $this->getBy(Tables::GAMES, function($q) use ($name) {
    		return $q->whereRaw('(coalesce(twitch_name, name) = ?)', [ $name ]);
    	});
    	
    	return $game ? $this->getGame($game['id']) : null;
	}

	public function getDefaultGameId()
	{
		return $this->getSettings('default_game_id');
	}

	public function getDefaultGame()
	{
		$id = $this->getDefaultGameId();
		return $this->getGame($id);
	}

	public function getRootGame($game)
	{
	    if (!is_array($game)) {
	        $game = $this->getGame($game) ?? $this->getDefaultGame();
	    }
	    
	    $rootId = $game['root_id'];
	    return $this->getGame($rootId);
	}
	
	public function getSubGamesIds($game)
	{
	    return $game['subgames'] ?? null;
	}
	
	public function getSubGames($game)
	{
	    $ids = $this->getSubGamesIds($game);
	    
	    if ($ids === null) {
	        return null;
	    }
	    
	    return Arrays::filter($this->games, function ($item) use ($ids) {
	        return in_array($item['id'], $ids);
	    });
	}
	
	// MISC
	
	public function getLanguage($id)
	{
	    return $this->get(Tables::LANGUAGES, $id);
	}
}
