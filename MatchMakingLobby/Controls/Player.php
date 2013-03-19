<?php
/**
 * @copyright   Copyright (c) 2009-2012 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision: 9091 $:
 * @author      $Author: philippe $:
 * @date        $Date: 2012-12-12 16:37:36 +0100 (mer., 12 déc. 2012) $:
 */

namespace ManiaLivePlugins\MatchMakingLobby\Controls;

use ManiaLib\Gui\Elements;

class Player extends \ManiaLive\Gui\Control
{
	const STATE_BLOCKED = -2;
	const STATE_NOT_READY = -1;
	const STATE_IN_MATCH = 1;
	const STATE_READY = 2;

	public $state;
	public $isAlly = false;

	/**
	 * @var Elements\Icons64x64_1
	 */
	protected $icon;
	/**
	 * @var Elements\Icons64x64_1
	 */
	protected $allyIcon;

	/**
	 * @var Elements\Label
	 */
	protected $label;

	function __construct($nickname)
	{
		$this->setSize(50, 5);

		$ui = new Elements\Bgs1InRace(50, 5);
		$ui->setSubStyle(Elements\Bgs1InRace::BgListLine);
		$this->addComponent($ui);

		$this->icon = new Elements\Icons64x64_1(2.5, 2.5);
		$this->icon->setSubStyle(Elements\Icons64x64_1::LvlRed);
		$this->icon->setValign('center');
		$this->icon->setPosition(1, -2.5);
		$this->addComponent($this->icon);

		$this->allyIcon = new Elements\Icons64x64_1(2.5, 2.5);
		$this->allyIcon->setSubStyle(Elements\Icons64x64_1::Buddy);
		$this->allyIcon->setValign('center');
		$this->allyIcon->setPosition(4, -2.5);
		$this->addComponent($this->allyIcon);

		$this->label = new Elements\Label(30);
		$this->label->setValign('center2');
		$this->label->setPosition(7.5, -2.5);
		$this->label->setText($nickname);
		$this->label->setTextColor('fff');
		$this->label->setScale(0.75);
		$this->addComponent($this->label);

		$this->state = static::STATE_NOT_READY;
	}

	function setState($state = 1, $isAlly = false)
	{
		switch($state)
		{
			case static::STATE_READY:
				$subStyle = Elements\Icons64x64_1::LvlGreen;
				break;
			case static::STATE_IN_MATCH:
				$subStyle = Elements\Icons64x64_1::LvlYellow;
				break;
			case static::STATE_BLOCKED:
				$subStyle = Elements\Icons64x64_1::StatePrivate;
				break;
			case static::STATE_NOT_READY:
				$subStyle = Elements\Icons64x64_1::LvlRed;
				break;
			default :
				$subStyle = Elements\Icons64x64_1::LvlRed;
		}
		$this->state = $state;
		$this->isAlly = $isAlly;
		
		$this->icon->setSubStyle($subStyle);
		$this->allyIcon->setSubStyle($isAlly ? Elements\Icons64x64_1::Buddy : Elements\Icons64x64_1::EmptyIcon);

	}

}

?>