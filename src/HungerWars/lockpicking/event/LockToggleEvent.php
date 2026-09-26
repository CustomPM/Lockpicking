<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\event;

use HungerWars\lockpicking\LockRecord;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;
use pocketmine\player\Player;

class LockToggleEvent extends Event implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private Player $player,
		private LockRecord $lock,
		private bool $locked
	){}

	public function getPlayer() : Player{ return $this->player; }

	public function getLock() : LockRecord{ return $this->lock; }

	public function isLocked() : bool{ return $this->locked; }
}
