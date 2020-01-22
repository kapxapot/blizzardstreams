<?php

namespace App\Core;

use Plasticode\Core\Builder as BuilderBase;
use Plasticode\Util\Arrays;
use Plasticode\Util\Date;
use Plasticode\Util\Sort;
use Plasticode\Util\Strings;

class Builder extends BuilderBase
{
	public function buildStream($row, $full = true)
	{
		$stream = $row;

		if ($stream['remote_online_at']) {
			$streamTimeToLive = $this->getSettings('streams.ttl');
			$age = Date::age($stream['remote_online_at']);
			
			$stream['alive'] = ($age->days < $streamTimeToLive);
		}

		$id = $stream['stream_id'];
		
		$stream['stream_alias'] = $stream['stream_alias'] ?? $id;
		$stream['page_url'] = $this->linker->stream($stream);

		$stream['img_url'] = $this->linker->twitchImg($id);
		$stream['large_img_url'] = $this->linker->twitchLargeImg($id);
		
		$stream['twitch'] = true;
		$stream['stream_url'] = $this->linker->twitch($id);

		$onlineAt = $stream['remote_online_at'];
		
		if ($onlineAt) {
		    $stream['remote_online_cmp'] = strtotime($onlineAt);
			$stream['remote_online_at'] = $this->formatDate(strtotime($onlineAt));
		}

		$stream['remote_online_ago'] = Date::toAgo($onlineAt, 'en');

		$stream['tags'] = $this->tags($stream['tags']);
		
		if ($full) {
		    $stream['description'] = $this->parser->justText($stream['description']);
		}
		
		$stream['channel'] = true;
		
		// placeholder
		$stream['language'] = $this->db->getLanguage($stream['language_id']);

		$stream['game'] = $this->buildRootGame($stream['game_id']);

		return $stream;
	}
	
	public function buildStreamStats($stream)
	{
		$stats = [];
		
		$games = $this->db->getStreamGameStats($stream['id']);
		
		if (!empty($games)) {
			$total = 0;
			foreach ($games as $game) {
				$total += $game['count'];
			}
			
			$games = array_map(function($game) use ($total) {
				$game['percent'] = ($total > 0)
					? round($game['count'] * 100 / $total, 1)
					: 0;

				return $game;
			}, $games);

			$games = Sort::desc($games, 'percent');

			$stats['games'] = $games;
		}

		$now = new \DateTime;
		
		$dayStart = Date::startOfHour($now)->modify('-23 hour');
		$lastDayStats = $this->db->getStreamStatsFrom($stream['id'], $dayStart);

		if (!empty($lastDayStats)) {
			$latest = array_map(function($s) {
				$cr = strtotime($s['created_at']);
				
				$s['stamp'] = strftime('%d-%H', $cr);
				$s['iso'] = Date::formatIso($cr);
				
				return $s;
			}, $lastDayStats);

			$stats['viewers'] = $this->buildGamelyStreamStats($latest, $dayStart, $now);
		}
		
		$utc = Date::utc();
		$monthStartUtc = Date::startOfDay($utc)->modify('-1 month')->modify('1 day');
		$monthStart = Date::fromUtc($monthStartUtc);
		$lastMonthStats = $this->db->getStreamStatsFrom($stream['id'], $monthStart);

		if (!empty($lastMonthStats)) {
			$latest = array_map(function($s) {
				$utcCreatedAt = Date::utc(Date::dt($s['created_at']));

				$s['stamp'] = $utcCreatedAt->format('m-d');

				return $s;
			}, $lastMonthStats);

			$stats['daily'] = $this->buildDailyStreamStats($latest, $monthStart, $now);
			
			$stats['logs'] = $this->buildStreamLogs($latest);
		}

		return $stats;
	}
	
	private function buildStreamLogs($stats)
	{
	    $logs = [];

        $add = function ($log) use (&$logs) {
            $log['start_iso'] = Date::formatIso(Date::dt($log['created_at']));
            $log['end_iso'] = Date::formatIso(Date::dt($log['finished_at']));

	        /*$chunks = [
	            '[' . $log['remote_game'] . ']',
	            $log['remote_status'],
	            $log['start_iso'],
	            $log['end_iso'],
	        ];
	        
	        $log['debug'] = implode(' ', $chunks);*/
	        
	        // game
	        $game = $this->db->getGameByTwitchName($log['remote_game']);
            $log['game'] = $this->buildRootGame($game);

	        $logs[] = $log;
        };

	    foreach ($stats as $stat) {
	        $stat['created_cmp'] = strtotime($stat['created_at']);

	        if (!$cur) {
	            $cur = $stat;
	        } else {
	            $exceeds = Date::exceedsInterval($cur['finished_at'], $stat['created_at'], '5 minutes');
	            
                if ($cur['remote_game'] == $stat['remote_game'] &&
                    $cur['remote_status'] == $stat['remote_status'] &&
                    !$exceeds)
                {
                    $cur['finished_at'] = $stat['finished_at'];
                } else {
                    $add($cur);
                    $cur = $stat;
                }
	        }
	    }

        if ($cur) {	        
	        $add($cur);
        }
	    
	    $logs = Sort::desc($logs, 'created_cmp');
	    
	    return $logs;
	}
	
	private function buildDailyStreamStats($latest, \DateTime $start, \DateTime $end)
	{
		$daily = [];
		
		$cur = clone $start;

		while ($cur < $end) {
		    $utcCur = Date::utc($cur);
			$stamp = $utcCur->format('m-d');

			$slice = array_filter($latest, function($s) use ($stamp) {
				return $s['stamp'] == $stamp;
			});

			$peak = 0;
			$peakStatus = null;
			
			if (!empty($slice)) {
				foreach ($slice as $stat) {
					$peak = max($stat['remote_viewers'], $peak);
					$peakStatus = $stat['remote_status'];
				}
			}
			
			$daily[] = [
				'day' => $utcCur->format('M j'),
				'week_day' => $utcCur->format('D, M j'),
				'peak_viewers' => $peak,
				'peak_status' => $peakStatus,
			];

			$cur->modify('+1 day');
		}
		
		$endOfDay = Date::utc($end);
		$endOfDay = Date::stripTime($endOfDay);
		$endOfDay->modify('1 day');
		
		return $daily;
	}

	private function buildGamelyStreamStats($latest, \DateTime $start, \DateTime $end)
	{
		$gamely = [];
		
		$prev = null;
		$prevGame = null;
		
		$set = [];
		
		$closeSet = function($game) use (&$gamely, &$set) {
			if (!empty($set)) {
				if (!array_key_exists($game, $gamely)) {
					$gamely[$game] = [];
				}

				$gamely[$game][] = $set;
				$set = [];
			}
		};
		
		foreach ($latest as $s) {
			$game = $s['remote_game'];
			
			if ($prev) {
				$exceeds = Date::exceedsInterval($prev['created_at'], $s['created_at'], 'PT30M'); // 30 minutes

				if ($exceeds) {
					$closeSet($prevGame);
				}
				elseif ($prevGame != $game) {
					$closeSet($prevGame);

					$prev['remote_game'] = $game;
					$set[] = $prev;
				}
			}

			$set[] = $s;
			
			$prev = $s;
			$prevGame = $game;
		}
		
		$closeSet($prevGame);

		return [
			'data' => $gamely,
			'min_date' => Date::formatIso($start),
			'max_date' => Date::formatIso($end),
		];
	}
	
	public function updateStreamData($row, $notify = false)
	{
		$stream = $row;
		
		$id = $stream['stream_id'];
		
		$data = $this->twitch->getStreamData($id);
		
		$s = $data['data'][0] ?? null;

		if ($s) {
			$streamStarted = ($stream['remote_online'] == 0);
			
			$gameId = $s['game_id'];
			$game = $this->getGameData($gameId);

			$userId = $s['user_id'];
			$user = $this->getUserData($userId);

			$stream['remote_online'] = 1;
			$stream['remote_game'] = $game['name'] ?? $gameId;
			$stream['remote_viewers'] = $s['viewer_count'];
			
			$stream['remote_title'] = $user['display_name'] ?? null;
			$stream['remote_status'] = $s['title'];
			$stream['remote_logo'] = $user['profile_image_url'] ?? null;

			$description = $user['description'] ?? null;
			
			if (!is_null($description)) {
			    $stream['description'] = $description;
			}
			
			if ($notify && $streamStarted) {
				$message = $this->sendStreamNotifications($stream);
			}
		}
		else {
			$stream['remote_online'] = 0;
			$stream['remote_viewers'] = 0;
		}

		$this->db->saveStream($stream);
		$this->updateStreamStats($stream);

		if ($s) {
			$stream['json'] = $data;
			$stream['message'] = $message;
		}

		return $stream;
	}
	
	private function getGameData(string $id)
	{
	    return $this->cache->getCached(
	        'twitch_game_' . $id,
	        function () use ($id) {
	            $data = $this->twitch->getGameData($id);
	            return $data['data'][0] ?? null;
	        }
        );
	}
	
	private function getUserData(string $id)
	{
	    return $this->cache->getCached(
	        'twitch_user_' . $id,
	        function () use ($id) {
	            $data = $this->twitch->getUserData($id);
	            return $data['data'][0] ?? null;
	        }
        );
	}
	
	private function updateStreamStats($stream)
	{
		$online = ($stream['remote_online'] == 1);
		$refresh = $online;
		
		$stats = $this->db->getLastStreamStats($stream['id']);
		
		if ($stats) {
			if ($online) {
				$statsTTL = $this->getSettings('streams.stats_ttl');

				$exceeds = Date::exceedsInterval($stats['created_at'], null, "PT{$statsTTL}M");
	
				if (!$exceeds && ($stream['remote_game'] == $stats['remote_game'])) {
					$refresh = false;
				}
			}

			if (!$stats['finished_at'] && (!$online || $refresh)) {
				$this->db->finishStreamStats($stats['id']);
			}
		}
		
		if ($refresh) {
			$this->db->saveStreamStats($stream);
		}
	}
	
	public function buildStreamNotificationMessage($stream)
	{
		$verb = $stream['remote_status']
				? "is streaming <b>{$stream['remote_status']}</b>"
				: 'started streaming';
		
		$url = $this->linker->stream($stream);
		$url = $this->linker->abs($url);
		
		$message = "<a href=\"{$url}\">{$stream['title']}</a> {$verb}";
		
		$tags = $this->tags($stream['tags']);
		$tags = Arrays::extract($tags, 'text');
		
		$twitchUrl = $this->linker->twitch($stream['stream_id']);

		return [
		    'text' => $message,
		    'url' => $url,
			'tags' => $tags,
			'embed_url' => $twitchUrl,
		];
	}
	
	private function buildTwitterMessage($text, $url, $tags, $embedUrl)
    {
        $chunks = [];
        
        $chunks[] = strip_tags($text);
        
        if (strlen($url) > 0) {
            $chunks[] = $url;
        }
        
        if (!empty($tags)) {
            $chunks[] = Strings::hashTags($tags);
        }

        if (strlen($embedUrl) > 0) {
            $chunks[] = $embedUrl;
        }
        
        return implode(' ', $chunks);
	}
	
	private function sendStreamNotifications($stream)
	{
	    $message = $this->buildStreamNotificationMessage($stream);
	    
		//$this->telegram->sendMessage('blizzard_streams', $message['text']);

        $msg = $this->buildTwitterMessage($message['text'], $message['url'], $message['tags'], $message['embed_url']);
		$this->twitter->tweet($msg);

		return $message;
	}

	public function buildSortedStreams($streams)
	{
		$streams = array_map(function ($s) {
			return $this->buildStream($s, false);
		}, $streams ?? []);

		$sorts = [
			'remote_online' => [ 'dir' => 'desc' ],
			'remote_viewers' => [ 'dir' => 'desc' ],
			'remote_online_cmp' => [ 'dir' => 'desc' ],
			'title' => [ 'type' => 'string' ],
		];
		
		$streams = Sort::multi($streams, $sorts);
		
		return $streams;
	}
	
	private function arrangeStreams($streams)
	{
	    return [
		    array_filter($streams, function($s) {
			    return $s['remote_online'];
			}),
		    array_filter($streams, function($s) {
			    return !$s['remote_online'] && $s['remote_logo'];
			}),
		    array_filter($streams, function($s) {
			    return !$s['remote_online'] && !$s['remote_logo'];
			}),
        ];
	}
	
	public function buildStreamGroups($streams)
	{
	    $latestStreams = array_filter($streams, function($s) {
	        $oat = $s['remote_online_at'];
	        return ($oat != null) && !Date::exceedsInterval($oat, null, '7 days');
		});

		$groups = [
			[
				'id' => 'all',
				'label' => 'All',
				//'telegram' => 'warcry_streams',
    			'streams' => $this->arrangeStreams($latestStreams),
			],
		];

		$groupsByLang = [];
		
		$byLang = Arrays::groupBy($streams, 'language_id');
		
		foreach ($byLang as $langId => $langStreams) {
		    $lang = $this->db->getLanguage($langId);

            $groupsByLang[] = [
				'id' => $lang['alias'],
				'label' => $lang['name'],
				'title' => $lang['name_en'],
				//'telegram' => 'warcry_streams',
    			'streams' => $this->arrangeStreams($langStreams),
				'position' => $lang['position'],
			];
		}

		$groupsByLang = Sort::asc($groupsByLang, 'position');

		return array_merge($groups, $groupsByLang);
	}
	
	public function buildStreamsByTag($tag)
	{
		$rows = $this->db->getStreamsByTag($tag);
		$streams = $this->buildSortedStreams($rows);

		return $this->arrangeStreams($streams);
	}
	
	// games
	
	public function buildGame($row)
	{
		$game = $row;
		
		$game['default'] = ($game['id'] == $this->db->getDefaultGameId());
		//$game['url'] = $this->linker->game($game);

		return $game;
	}

	/**
	 * Build root game.
	 * 
	 * @param mixed $game Game array or game id.
	 */
	protected function buildRootGame($game)
	{
	    $rootGame = $this->db->getRootGame($game);
	    return $this->buildGame($rootGame);
	}
}
