<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\item;

use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;

class CustomStackItem extends Item{
	public function __construct(ItemIdentifier $identifier, string $name, private int $maxStack){
		parent::__construct($identifier, $name);
	}

	public function getMaxStackSize() : int{
		return $this->maxStack;
	}
}
