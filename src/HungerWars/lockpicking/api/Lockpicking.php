<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\api;

use HungerWars\lockpicking\Items;
use HungerWars\lockpicking\Keys;
use HungerWars\lockpicking\LockpickingPlugin;
use HungerWars\lockpicking\LockRecord;
use HungerWars\lockpicking\Variant;
use HungerWars\lockpicking\Visuals;
use HungerWars\lockpicking\event\LockCreateEvent;
use HungerWars\lockpicking\event\LockRemoveEvent;
use HungerWars\lockpicking\event\LockToggleEvent;
use pocketmine\player\Player;
use pocketmine\world\Position;

/**
 * Public API for other plugins.
 *
 * Locks are stored per block. Double chests share one lock on the left half.
 * A matching encoded key of the same material opens a lock. Creative locks only
 * answer to a Creative Key.
 */
final class Lockpicking{
	public static function plugin() : ?LockpickingPlugin{
		return LockpickingPlugin::getInstance();
	}

	public static function lock(Position $position, string $variantId, string $code, string $owner, ?Player $actor = null) : bool{
		$plugin = self::plugin();
		$variant = Variant::byId($variantId);
		if($plugin === null || $variant === null){
			return false;
		}
		if($variant->id !== "creative" && !Items::validCode($code)){
			return false;
		}
		if($plugin->getStore()->get($position) !== null){
			return false;
		}
		if($actor !== null){
			$event = new LockCreateEvent($actor, $position, $variant, $code);
			$event->call();
			if($event->isCancelled()){
				return false;
			}
		}
		$plugin->getStore()->put(new LockRecord(
			$variant->id,
			$variant->id === "creative" ? "" : $code,
			$owner,
			true,
			$position->getWorld()->getFolderName(),
			$position->getFloorX(),
			$position->getFloorY(),
			$position->getFloorZ()
		));
		return true;
	}

	public static function remove(Position $position, ?Player $actor = null) : bool{
		$plugin = self::plugin();
		if($plugin === null){
			return false;
		}
		$lock = $plugin->getStore()->get($position);
		if($lock === null){
			return false;
		}
		if($actor !== null){
			$event = new LockRemoveEvent($actor, $lock);
			$event->call();
			if($event->isCancelled()){
				return false;
			}
		}
		$canonical = $lock->position();
		if($canonical !== null){
			$plugin->getStore()->remove($canonical);
		}
		return true;
	}

	/**
	 * @return bool|null the new locked state, or null when no lock exists
	 */
	public static function toggle(Position $position, ?Player $actor = null) : ?bool{
		$plugin = self::plugin();
		if($plugin === null){
			return null;
		}
		$lock = $plugin->getStore()->get($position);
		if($lock === null){
			return null;
		}
		$next = !$lock->locked;
		if($actor !== null){
			$event = new LockToggleEvent($actor, $lock, $next);
			$event->call();
			if($event->isCancelled()){
				return $lock->locked;
			}
		}
		$lock->locked = $next;
		$plugin->getStore()->update($lock);
		Visuals::applyLockState($plugin->getStore(), $lock);
		return $next;
	}

	public static function getLock(Position $position) : ?LockRecord{
		return self::plugin()?->getStore()->get($position);
	}

	public static function hasLock(Position $position) : bool{
		return self::getLock($position) !== null;
	}

	public static function isLocked(Position $position) : bool{
		$lock = self::getLock($position);
		return $lock !== null && $lock->locked;
	}

	public static function playerHasKey(Player $player, Position $position) : bool{
		$lock = self::getLock($position);
		return $lock !== null && (Keys::holding($player, Items::CREATIVE_KEY) || Keys::handOpens($player, $lock));
	}

	public static function canAccess(Player $player, Position $position) : bool{
		$lock = self::getLock($position);
		if($lock === null){
			return true;
		}
		if(Keys::holding($player, Items::CREATIVE_KEY)){
			return true;
		}
		return Keys::handOpens($player, $lock);
	}
}
