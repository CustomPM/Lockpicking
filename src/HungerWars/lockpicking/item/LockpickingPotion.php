<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\item;

use HungerWars\lockpicking\LockpickingPlugin;
use pocketmine\entity\Living;
use pocketmine\item\Food;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\VanillaItems;
use pocketmine\item\Item;
use pocketmine\player\Player;

class LockpickingPotion extends Food{
	public function __construct(ItemIdentifier $identifier){
		parent::__construct($identifier, "Potion of Lockpicking");
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function requiresHunger() : bool{
		return false;
	}

	public function getFoodRestore() : int{
		return 0;
	}

	public function getSaturationRestore() : float{
		return 0.0;
	}

	public function getResidue() : Item{
		return VanillaItems::GLASS_BOTTLE();
	}

	public function onConsume(Living $consumer) : void{
		if(!$consumer instanceof Player){
			return;
		}
		$plugin = LockpickingPlugin::getInstance();
		if($plugin === null){
			return;
		}
		$plugin->getStore()->setEffectExpiry($consumer->getUniqueId()->toString(), time() + 180);
		$consumer->sendActionBarMessage("§tFortify Lockpicking §7(3:00)");
	}
}
