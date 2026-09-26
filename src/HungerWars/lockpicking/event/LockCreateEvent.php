<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\event;

use HungerWars\lockpicking\Variant;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\Event;
use pocketmine\player\Player;
use pocketmine\world\Position;

class LockCreateEvent extends Event implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private Player $player,
		private Position $position,
		private Variant $variant,
		private string $code
	){}

	public function getPlayer() : Player{ return $this->player; }

	public function getPosition() : Position{ return $this->position; }

	public function getVariant() : Variant{ return $this->variant; }

	public function getCode() : string{ return $this->code; }
}
