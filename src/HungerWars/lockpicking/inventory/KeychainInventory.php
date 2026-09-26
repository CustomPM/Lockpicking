<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\inventory;

use pocketmine\inventory\SimpleInventory;

/**
 * A virtual 27-slot inventory. It is opened on the player, not on a block,
 * so the client does not immediately close it.
 */
final class KeychainInventory extends SimpleInventory{
	public function __construct(private int $keychainId){
		parent::__construct(27);
	}

	public function getKeychainId() : int{
		return $this->keychainId;
	}
}
