<?php
/**
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */
namespace ManiaLivePlugins\MatchMakingLobby\Lobby\MatchMakers;

class Combo extends AbstractAllies
{
	function getNumberOfTeam()
	{
		return 2;
	}

	function getPlayersPerMatch()
	{
		return 4;
	}

	protected function getFallbackMatchMaker()
	{
		return DistanceCombo::getInstance();
	}

}
?>
