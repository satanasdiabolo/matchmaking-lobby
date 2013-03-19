<?php
/**
 * @copyright   Copyright (c) 2009-2012 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: $:
 * @author      $Author: $:
 * @date        $Date: $:
 */
namespace ManiaLivePlugins\MatchMakingLobby\MatchSettings;

interface MatchSettings
{
	/**
	 * @return array
	 */
	function getLobbyScriptSettings();
	/**
	 * @return array
	 */
	function getMatchScriptSettings();
}

?>