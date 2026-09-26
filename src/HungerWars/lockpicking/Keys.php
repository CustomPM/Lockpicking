<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;

final class Keys{
	public static function holding(Player $player, string $identifier) : bool{
		return Items::isId($player->getInventory()->getItemInHand(), $identifier);
	}

	public static function hasCreativeKey(Player $player) : bool{
		return self::contains($player, function(Item $item) : bool{
			return Items::isId($item, Items::CREATIVE_KEY);
		});
	}

	public static function hasMatch(Player $player, LockRecord $lock) : bool{
		if($lock->variant === "creative"){
			return self::hasCreativeKey($player);
		}
		return self::contains($player, function(Item $item) use ($lock) : bool{
			return self::itemOpens($item, $lock);
		});
	}

	public static function handOpens(Player $player, LockRecord $lock) : bool{
		return self::itemOpens($player->getInventory()->getItemInHand(), $lock);
	}

	public static function itemOpens(Item $item, LockRecord $lock) : bool{
		$variant = Items::variantOfKey($item);
		if($variant === null || $variant->id !== $lock->variant){
			return false;
		}
		return Items::readCode($item) === $lock->code;
	}

	public static function takeOne(Player $player, string $identifier) : bool{
		$inventory = $player->getInventory();
		for($slot = 0; $slot < $inventory->getSize(); $slot++){
			$item = $inventory->getItem($slot);
			if(!Items::isId($item, $identifier)){
				continue;
			}
			$item->pop();
			$inventory->setItem($slot, $item->isNull() ? VanillaItems::AIR() : $item);
			return true;
		}
		return false;
	}

	/**
	 * @param \Closure(Item) : bool $matches
	 */
	private static function contains(Player $player, \Closure $matches) : bool{
		foreach([$player->getInventory(), $player->getOffHandInventory()] as $inventory){
			if(self::scan($inventory, $matches)){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param \Closure(Item) : bool $matches
	 */
	private static function scan(Inventory $inventory, \Closure $matches) : bool{
		for($slot = 0; $slot < $inventory->getSize(); $slot++){
			$item = $inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}
			if($matches($item)){
				return true;
			}
			if(Items::isId($item, Items::KEYCHAIN)){
				foreach(Items::readKeychain($item) as $stored){
					if($matches($stored)){
						return true;
					}
				}
			}
		}
		return false;
	}
}
