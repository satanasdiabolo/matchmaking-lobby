<?php
/**
 * @copyright   Copyright (c) 2009-2012 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */

namespace ManiaLivePlugins\MatchMakingLobby\Lobby;

use DedicatedApi\Structures;
use ManiaLive\DedicatedApi\Callback\Event as ServerEvent;
use ManiaLive\Gui\Windows\Shortkey;
use ManiaLivePlugins\MatchMakingLobby\Windows;
use ManiaLivePlugins\MatchMakingLobby\Services;
use ManiaLivePlugins\MatchMakingLobby\Config;
use ManiaLivePlugins\MatchMakingLobby\GUI;
use ManiaLivePlugins\MatchMakingLobby\Services\Match;

class Plugin extends \ManiaLive\PluginHandler\Plugin
{

	const PREFIX = 'LobbyInfo$000»$8f0 ';

	/** @var int */
	protected $tick;

	/** @var int */
	protected $mapTick;

	/** @var Config */
	protected $config;

	/** @var MatchMakers\MatchMakerInterface */
	protected $matchMaker;

	/** @var GUI\AbstractGUI */
	protected $gui;

	/** @var string */
	protected $backLink;

	/** @var int[string] */
	protected $countDown = array();

	/** @var int[string] */
	protected $blockedPlayers = array();

	/** @var Helpers\PenaltiesCalculator */
	protected $penaltiesCalculator;

	/** @var Services\MatchMakingService */
	protected $matchMakingService;

	/** @var string */
	protected $scriptName;

	/** @var string */
	protected $titleIdString;

	function onInit()
	{
		$this->setVersion('0.3');
		//Load MatchMaker and helpers for GUI
		$this->config = Config::getInstance();
		$script = $this->storage->gameInfos->scriptName;
		$this->scriptName = \ManiaLivePlugins\MatchMakingLobby\Config::getInstance()->script ? : preg_replace('~(?:.*?[\\\/])?(.*?)\.Script\.txt~ui', '$1', $script);

		$matchMakerClassName = $this->config->matchMakerClassName ? : __NAMESPACE__.'\MatchMakers\\'.$this->scriptName;
		$guiClassName = $this->config->guiClassName ? : '\ManiaLivePlugins\MatchMakingLobby\GUI\\'.$this->scriptName;
		$penaltiesCalculatorClassName = $this->config->penaltiesCalculatorClassName ? : __NAMESPACE__.'\Helpers\PenaltiesCalculator';

		$this->setGui(new $guiClassName());
		$this->gui->lobbyBoxPosY = 45;
		$this->setMatchMaker($matchMakerClassName::getInstance());
		$this->setPenaltiesCalculator(new $penaltiesCalculatorClassName);
	}

	function onLoad()
	{
		//Check if Lobby is not running with the match plugin
		if($this->isPluginLoaded('MatchMakingLobby/Match'))
		{
			throw new Exception('Lobby and match cannot be one the same server.');
		}
		$this->enableDedicatedEvents(
			ServerEvent::ON_PLAYER_CONNECT |
			ServerEvent::ON_PLAYER_DISCONNECT |
			ServerEvent::ON_PLAYER_ALLIES_CHANGED |
			ServerEvent::ON_BEGIN_MAP |
			ServerEvent::ON_PLAYER_INFO_CHANGED
		);
		$matchSettingsClass = $this->config->matchSettingsClassName ? : '\ManiaLivePlugins\MatchMakingLobby\MatchSettings\\'.$this->scriptName;
		/* @var $matchSettings \ManiaLivePlugins\MatchMakingLobby\MatchSettings\MatchSettings */
		$matchSettings = new $matchSettingsClass();
		$settings = $matchSettings->getLobbyScriptSettings();
		$this->connection->setModeScriptSettings($settings);

		$this->enableTickerEvent();

		$this->matchMakingService = new Services\MatchMakingService();
		$this->matchMakingService->createTables();

		$this->titleIdString = $this->connection->getSystemInfo()->titleId;

		$this->backLink = $this->storage->serverLogin.':'.$this->storage->server->password.'@'.$this->titleIdString;

		$this->setLobbyInfo();
		foreach(array_merge($this->storage->players, $this->storage->spectators) as $login => $obj)
		{
			$player = Services\PlayerInfo::Get($login);
			$player->ladderPoints = $this->storage->getPlayerObject($login)->ladderStats['PlayerRankings'][0]['Score'];
			$player->allies = $this->storage->getPlayerObject($login)->allies;
			$this->gui->createPlayerList($login);
			$this->onPlayerNotReady($login);
		}
		$this->gui->updatePlayerList($this->blockedPlayers);

		$this->registerLobby();

		$playersCount = $this->getReadyPlayersCount();
		$totalPlayerCount = $this->getTotalPlayerCount();

		$this->gui->updateLobbyWindow($this->storage->server->name, $playersCount, $totalPlayerCount, $this->getPlayingPlayersCount());

		$feedback = Windows\Feedback::Create();
		$feedback->setAlign('right', 'bottom');
		$feedback->setPosition(160.1, 75);
		$feedback->show();
	}

	function onUnload()
	{
		$this->setLobbyInfo(false);
		parent::onUnload();
	}

	function onPlayerConnect($login, $isSpectator)
	{
		\ManiaLive\Utilities\Logger::getLog('info')->write(sprintf('Player connected: %s', $login));
		if($this->matchMakingService->isInMatch($login))
		{
			$matchInfo = $this->matchMakingService->getPlayerCurrentMatch($login);
			$jumper = Windows\ForceManialink::Create($login);
			$jumper->set('maniaplanet://#qjoin='.$matchInfo->matchServerLogin.'@'.$matchInfo->titleIdString);
			$jumper->show();
			$this->gui->createLabel($login, $this->gui->getMatchInProgressText());
			return;
		}

		$message = '';
		$player = Services\PlayerInfo::Get($login);
		$message = ($player->ladderPoints ? $this->gui->getPlayerBackLabelPrefix() : '').$this->gui->getNotReadyText();
		$player->setAway(false);
		$player->ladderPoints = $this->storage->getPlayerObject($login)->ladderStats['PlayerRankings'][0]['Score'];
		$player->allies = $this->storage->getPlayerObject($login)->allies;

		$this->createMagnifyLabel($login, $message);

//		$this->gui->createLabel($login, $message);
		$this->setShortKey($login, array($this, 'onPlayerReady'));

		$this->gui->createPlayerList($login, $this->blockedPlayers);
		$this->gui->updatePlayerList($this->blockedPlayers);

		$this->updateLobbyWindow();
		$this->updateKarma($login);

		//TODO Rework text
//		$this->gui->showSplash($login, $this->storage->server->name,
//			array(
//			'Your are on a Lobby server',
//			'We will search an opponent of your level to play with',
//			'Queue until we find a match and a server for you',
//			'You will be automatically switch between the lobby and the match server',
//			'To abort a match, click on Ready',
//			'Click on Ready when you are',
//			'Use your "Alt" key to free your mouse'
//			), array($this, 'doNotShow')
//		);
	}

	function onPlayerDisconnect($login)
	{
		\ManiaLive\Utilities\Logger::getLog('info')->write(sprintf('Player disconnected: %s', $login));

		$matchInfo = $this->matchMakingService->getPlayerCurrentMatch($login);
		if($this->matchMakingService->isInMatch($login) && array_key_exists($matchInfo->matchServerLogin, $this->countDown) && $this->countDown[$matchInfo->matchServerLogin] > 0)
		{
			$this->onCancelMatchStart($login);
		}

		$player = Services\PlayerInfo::Get($login);
		$player->setAway();
		$this->gui->removePlayerFromPlayerList($login);

		$this->updateLobbyWindow();
	}

	function onPlayerInfoChanged($playerInfo)
	{
		$playerInfo = Structures\Player::fromArray($playerInfo);
		/*if($playerInfo->hasJoinedGame)
		{
			if($this->matchService->isInMatch($playerInfo->login))
			{
				//TODO Change The Label
				list($server, ) = Services\PlayerInfo::Get($playerInfo->login)->getMatch();
				$jumper = Windows\ForceManialink::Create($playerInfo->login);
				$jumper->set('maniaplanet://#qjoin='.$server.'@'.$this->connection->getSystemInfo()->titleId);
				$jumper->show();
				$this->gui->createLabel($playerInfo->login, $this->gui->getMatchInProgressText());
				return;
			}

			//TODO Something for new players to set them ready ?
			//TODO Splashscreen ??
		}*/
	}

	function onBeginMap($map, $warmUp, $matchContinuation)
	{
		$this->mapTick = 0;
	}

	//Core of the plugin
	function onTick()
	{
		foreach($this->blockedPlayers as $login => $countDown)
		{
			$this->blockedPlayers[$login] = --$countDown;
			if($this->blockedPlayers[$login] <= 0)
			{
				unset($this->blockedPlayers[$login]);
				$this->onPlayerNotReady($login);
			}
		}

		//If there is some match needing players
		//find backup in ready players and send them to the match server
		$matchesNeedingBackup = $this->matchMakingService->getMatchesNeedingBackup($this->storage->serverLogin, $this->scriptName, $this->titleIdString);
                $potentialBackups = $this->getMatchablePlayers();
		foreach($matchesNeedingBackup as $match)
		{
			/** @var Match $match */
			$quitters = $this->matchMakingService->getMatchQuitters($match->id);
			\ManiaLive\Utilities\Logger::getLog('info')->write(
					sprintf('match %d: searching backup for quitter %s', $match->id, implode(' & ', $quitters))
				);
			$backups = array();
			foreach ($quitters as $quitter)
			{
				$backup = $this->matchMaker->getBackup($quitter, $potentialBackups);
				if ($backup)
				{
					$backups[] = $backup;
                                        unset($potentialBackups[$backup]);
				}
			}
			if(count($backups) && count($backups) == count($quitters))
			{
				\ManiaLive\Utilities\Logger::getLog('info')->write(
					sprintf('match %d, %s will replace %s', $match->id, implode(' & ', $backups), implode(' & ', $quitters))
				);
				foreach($quitters as $quitter)
				{
					$this->matchMakingService->updatePlayerState($quitter, $match->id, Services\PlayerInfo::PLAYER_STATE_REPLACED);
				}
				foreach($backups as $backup)
				{
					$teamId = $match->getTeam(array_shift($quitters));
					$this->matchMakingService->addMatchPlayer($match->id, $backup, $teamId);
				}
				$this->gui->prepareJump($backups, $match->matchServerLogin, $match->titleIdString);
				$this->countDown[$match->matchServerLogin] = 2;
			}
		}

		foreach(array_merge($this->storage->players, $this->storage->spectators) as $player)
		{
			$this->updateKarma($player->login);
		}

		if($this->tick % 5 == 0)
		{

			$matches = $this->matchMaker->run($this->getMatchablePlayers());
			foreach($matches as $match)
			{
				/** @var Match $match */
				$server = $this->matchMakingService->getAvailableServer(
					$this->storage->serverLogin,
					$this->scriptName,
					$this->titleIdString
				);
				if(!$server)
				{
					foreach($match->players as $login)
					{
						$this->gui->createLabel($login, $this->gui->getNoServerAvailableText());
					}
				}
				else
				{
					//Match ready, let's prepare it !
					$this->prepareMatch($server, $match);
				}
			}
		}

		foreach($this->countDown as $server => $countDown)
		{
			switch(--$countDown)
			{
				case -1:
					$this->gui->eraseJump($server);
					unset($this->countDown[$server]);
					break;
				case 0:
					\ManiaLive\Utilities\Logger::getLog('info')->write(sprintf('prepare jump for server : %s', $server));
					$match = $this->matchMakingService->getServerCurrentMatch($server, $this->scriptName, $this->titleIdString);
					$players = array_map(array($this->storage, 'getPlayerObject'), $match->players);
					$this->gui->showJump($server);

					$nicknames = array();
					foreach($players as $player)
					{
						if($player) $nicknames[] = '$<'.$player->nickName.'$>';
					}

					$this->connection->chatSendServerMessage(self::PREFIX.implode(' & ', $nicknames).' join their match server.', null);
				default:
					$this->countDown[$server] = $countDown;
			}
		}

		if(++$this->mapTick % 1800 == 0)
		{
			$this->connection->nextMap();
		}

		$this->setLobbyInfo();
		$this->updateLobbyWindow();
		$this->registerLobby();
		Services\PlayerInfo::CleanUp();
	}

	function onPlayerReady($login)
	{
		$player = Services\PlayerInfo::Get($login);
		if (!$this->matchMakingService->isInMatch($login))
		{
			$player->setReady(true);
			$this->setShortKey($login, array($this, 'onPlayerNotReady'));

			$this->gui->createLabel($login, $this->gui->getReadyText());
			$this->gui->updatePlayerList($this->blockedPlayers);

			$this->setLobbyInfo();
			$this->updateLobbyWindow();
		}
		else
		{
			\ManiaLive\Utilities\Logger::getLog('info')->write(sprintf('Player try to be ready while in match: %s', $login));
		}
	}

	function onPlayerNotReady($login)
	{
		$player = Services\PlayerInfo::Get($login);
		$player->setReady(false);
		$this->setShortKey($login, array($this, 'onPlayerReady'));
		$this->createMagnifyLabel($login, $this->gui->getNotReadyText());

		$this->gui->updatePlayerList($this->blockedPlayers);

		$this->setLobbyInfo();
		$this->updateLobbyWindow();
	}

	function onPlayerAlliesChanged($login)
	{
		$player = $this->storage->getPlayerObject($login);
		if($player)
		{
			Services\PlayerInfo::Get($login)->allies = $player->allies;
		}
		$this->gui->updatePlayerList($this->blockedPlayers);
	}

	function doNotShow($login)
	{
		//TODO store data
		$this->gui->hideSplash($login);
	}

	function onCancelMatchStart($login)
	{
		\ManiaLive\Utilities\Logger::getLog('info')->write('Player cancel match start: '.$login);

		$match = $this->matchMakingService->getPlayerCurrentMatch($login);
		if ($match->state == Match::PREPARED)
		{
			$this->gui->eraseJump($match->matchServerLogin);
			unset($this->countDown[$match->matchServerLogin]);
			$this->matchMakingService->updateMatchState($match->id, Services\Match::PLAYER_CANCEL);

			$this->matchMakingService->updatePlayerState($login, $match->id, Services\PlayerInfo::PLAYER_STATE_CANCEL);

			foreach($match->players as $playerLogin)
			{
				if($playerLogin != $login)
					$this->onPlayerReady($playerLogin);
				else
					$this->onPlayerNotReady($playerLogin);
			}
		}
		else
		{
			\ManiaLive\Utilities\Logger::getLog('info')->write(sprintf('error: player %s cancel match start (%d) not in prepared mode',$login, $match->id));
		}
	}

	private function prepareMatch($server, $match)
	{

		$id = $this->matchMakingService->registerMatch($server, $match, $this->scriptName, $this->titleIdString);
		\ManiaLive\Utilities\Logger::getLog('info')->write(sprintf('Preparing match %d on server: %s',$id, $server));
		\ManiaLive\Utilities\Logger::getLog('info')->write(print_r($match,true));

		$this->gui->prepareJump($match->players, $server, $this->titleIdString);
		$this->countDown[$server] = 11;

		foreach($match->players as $player)
		{
			$this->gui->createLabel($player, $this->gui->getLaunchMatchText($match, $player), $this->countDown[$server] - 1);
			$this->setShortKey($player, array($this, 'onCancelMatchStart'));
		}
		$this->gui->updatePlayerList($this->blockedPlayers);
	}

	private function getReadyPlayersCount()
	{
		$count = 0;
		foreach(array_merge($this->storage->players, $this->storage->spectators) as $player)
			$count += Services\PlayerInfo::Get($player->login)->isReady() ? 1 : 0;

		return $count;
	}

	private function getTotalPlayerCount()
	{
		//Number of matchs in DB minus matchs prepared
		//Because player are still on the server
		$playingPlayers = $this->getPlayingPlayersCount();

		$playerCount = count($this->storage->players) + count($this->storage->spectators);

		return $playerCount + $playingPlayers;
	}

	private function getPlayingPlayersCount()
	{
		return $this->matchMakingService->getPlayersPlayingCount($this->storage->serverLogin, $this->scriptName, $this->titleIdString);
	}

	private function getTotalSlots()
	{
		$matchServerCount = $this->matchMakingService->getLiveMatchServersCount($this->storage->serverLogin, $this->scriptName, $this->titleIdString);
		return $matchServerCount * $this->matchMaker->playerPerMatch + $this->storage->server->currentMaxPlayers + $this->storage->server->currentMaxSpectators;
	}

	private function registerLobby()
	{
		$connectedPlayerCount = count($this->storage->players) + count($this->storage->spectators);
		$this->matchMakingService->registerLobby($this->storage->serverLogin, $this->getReadyPlayersCount(), $connectedPlayerCount, $this->storage->server->name, $this->backLink);
	}

	private function getLeavesCount($login)
	{
		return $this->matchMakingService->getLeaveCount($login, $this->storage->serverLogin);
	}

	/**
	 * @param $login
	 */
	private function updateKarma($login)
	{
		$player = $this->storage->getPlayerObject($login);
		$playerInfo = Services\PlayerInfo::Get($login);
		if ($player)
		{
			$leavesCount = $this->getLeavesCount($login);

			$karma = $this->penaltiesCalculator->calculateKarma($login, $leavesCount);
			if($playerInfo->karma < $karma || array_key_exists($login, $this->blockedPlayers))
			{
				if(!array_key_exists($login, $this->blockedPlayers))
				{
					$penalty = $this->penaltiesCalculator->getPenalty($login, $karma);
					$this->blockedPlayers[$login] = 60 * $penalty;
					$this->connection->chatSendServerMessage(
						sprintf(self::PREFIX.'$<%s$> is suspended for leaving matchs.', $player->nickName, $penalty)
					);
				}

				$this->onPlayerNotReady($login);

				$this->gui->createLabel($login, $this->gui->getBadKarmaText($this->blockedPlayers[$login]));
				$this->resetShortKey($login);
				$this->gui->updatePlayerList($this->blockedPlayers);
			}
			$playerInfo->karma = $karma;
		}
		else
		{
			\ManiaLive\Utilities\Logger::getLog('info')->write(sprintf('UpdateKarma for not connected player %s', $login));
		}
	}

	protected function setShortKey($login, $callback)
	{
		$shortKey = Shortkey::Create($login);
		$shortKey->removeCallback($this->gui->actionKey);
		$shortKey->addCallback($this->gui->actionKey, $callback);
	}

	protected function resetShortKey($login)
	{
		$shortKey = Shortkey::Create($login);
		$shortKey->removeCallback($this->gui->actionKey);
	}

	private function updateLobbyWindow()
	{
		$playersCount = $this->getReadyPlayersCount();
		$totalPlayerCount = $this->getTotalPlayerCount();
		$playingPlayersCount = $this->getPlayingPlayersCount();
		$this->gui->updateLobbyWindow($this->storage->server->name, $playersCount, $totalPlayerCount, $playingPlayersCount);
	}

	private function setLobbyInfo($enable = true)
	{
		if($enable)
		{
			$lobbyPlayers = $this->getTotalPlayerCount();
			$maxPlayers = $this->getTotalSlots();
		}
		else
		{
			$lobbyPlayers = count($this->storage->players);
			$maxPlayers = $this->storage->server->currentMaxPlayers;
		}
		$this->connection->setLobbyInfo($enable, $lobbyPlayers, $maxPlayers);
	}

	protected function setGui(GUI\AbstractGUI $GUI)
	{
		$this->gui = $GUI;
	}

	protected function setMatchMaker(MatchMakers\MatchMakerInterface $matchMaker)
	{
		$this->matchMaker = $matchMaker;
	}

	protected function setPenaltiesCalculator(Helpers\PenaltiesCalculator $penaltiesCalculator)
	{
		$this->penaltiesCalculator = $penaltiesCalculator;
	}

	protected function getMatchablePlayers()
	{
		$readyPlayers = Services\PlayerInfo::GetReady();
		$service = $this->matchMakingService;
		$notInMathcPlayers = array_filter($readyPlayers,
			function (Services\PlayerInfo $p) use ($service)
			{
				return !$service->isInMatch($p->login);
			});
		$blockedPlayers = array_keys($this->blockedPlayers);
		$notBlockedPlayers = array_filter($notInMathcPlayers,
			function (Services\PlayerInfo $p) use ($blockedPlayers)
			{
				return !in_array($p->login, $blockedPlayers);
			});

		return array_map(function (Services\PlayerInfo $p) { return $p->login; }, $notBlockedPlayers);
	}

	private function createMagnifyLabel($login, $message)
	{
		Windows\Label::Erase($login);
		$confirm = Windows\AnimatedLabel::Create($login);
		$confirm->setPosition(0, 40);
		$confirm->setMessage($message);
		$confirm->setId('animated-label');
		$confirm->show();
	}
}

?>