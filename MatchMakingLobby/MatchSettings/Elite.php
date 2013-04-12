<?php
/**
 * @copyright   Copyright (c) 2009-2013 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */
namespace ManiaLivePlugins\MatchMakingLobby\MatchSettings;

class Elite implements MatchSettings
{
	public function getLobbyScriptSettings()
	{
		$rules = array(
			'S_UseLobby' => 1,
			'S_LobbyTimePerMap' => 1800
		);
		return $rules;
	}

	public function getMatchScriptSettings()
	{
		$rules = array(
			'S_UseLobby' => 0,
			'S_Mode' => 1,
			'S_MatchmakingSleep' => 15,
			'S_WarmUpDuration' => 15
		);
		return $rules;
	}
}

?>