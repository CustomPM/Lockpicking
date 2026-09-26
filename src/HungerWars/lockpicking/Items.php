<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\NBT;
use function str_replace;

/**
 * Registered item prototypes and key NBT helpers.
 */
final class Items{
	public const LOCKPICK = "paragonia_lockpick:lockpick";
	public const KEYCHAIN = "paragonia_lockpick:keychain";
	public const POTION = "paragonia_lockpick:potion_lockpicking";
	public const SKELETON_KEY = "paragonia_lockpick:skeleton_key";
	public const CREATIVE_KEY = "paragonia_lockpick:creative_key";
	public const TRIAL_KEY = "minecraft:trial_key";
	public const CODE_TAG = "LockCode";

	/** @var array<string, Item> */
	private static array $prototypes = [];

	public static function registerPrototype(string $identifier, Item $item) : void{
		self::$prototypes[$identifier] = $item;
	}

	public static function get(string $identifier) : Item{
		if(!isset(self::$prototypes[$identifier])){
			throw new \InvalidArgumentException("Unknown lockpicking item $identifier");
		}
		return clone self::$prototypes[$identifier];
	}

	public static function has(string $identifier) : bool{
		return isset(self::$prototypes[$identifier]);
	}

	public static function typeId(string $identifier) : int{
		return self::$prototypes[$identifier]->getTypeId();
	}

	public static function isId(Item $item, string $identifier) : bool{
		return isset(self::$prototypes[$identifier]) && $item->getTypeId() === self::$prototypes[$identifier]->getTypeId();
	}

	public static function variantOfLock(Item $item) : ?Variant{
		foreach(Variant::all() as $variant){
			if(self::isId($item, $variant->lockItemId)){
				return $variant;
			}
		}
		return null;
	}

	public static function variantOfBlank(Item $item) : ?Variant{
		foreach(Variant::all() as $variant){
			if($variant->blankItemId !== "" && self::isId($item, $variant->blankItemId)){
				return $variant;
			}
		}
		return null;
	}

	public static function variantOfKey(Item $item) : ?Variant{
		foreach(Variant::all() as $variant){
			if($variant->keyItemId !== "" && self::isId($item, $variant->keyItemId)){
				return $variant;
			}
		}
		return null;
	}

	public static function readCode(Item $item) : ?string{
		$tag = $item->getNamedTag();
		if(!$tag->getTag(self::CODE_TAG) instanceof \pocketmine\nbt\tag\StringTag){
			return null;
		}
		$code = $tag->getString(self::CODE_TAG);
		return self::validCode($code) ? $code : null;
	}

	public static function writeCode(Item $item, string $code, string $name) : Item{
		$tag = $item->getNamedTag();
		$tag->setString(self::CODE_TAG, $code);
		$item->setNamedTag($tag);
		if($name !== ""){
			$item->setCustomName("§r" . $name);
		}
		$item->setLore(["§r§7Code: §8" . str_replace(":", " ", $code)]);
		return $item;
	}

	public static function validCode(string $code) : bool{
		$parts = explode(":", $code);
		if(count($parts) !== 4){
			return false;
		}
		foreach($parts as $part){
			if(strlen($part) !== 1 || $part < "0" || $part > "9"){
				return false;
			}
		}
		return true;
	}

	public static function displayCode(string $code) : string{
		return str_replace(":", " ", $code);
	}

	/**
	 * @return list<Item>
	 */
	public static function readKeychain(Item $item) : array{
		$list = $item->getNamedTag()->getListTag("StoredKeys", CompoundTag::class);
		if($list === null){
			return [];
		}
		$keys = [];
		foreach($list as $entry){
			$payload = $entry->getCompoundTag("Item");
			if($payload === null){
				continue;
			}
			try{
				$stored = Item::nbtDeserialize($payload);
			}catch(\Throwable){
				continue;
			}
			if(self::variantOfKey($stored) !== null && self::readCode($stored) !== null){
				$stored->setCount(1);
				$keys[] = $stored;
			}
		}
		return $keys;
	}

	/**
	 * @param Item[] $keys
	 */
	public static function writeKeychain(Item $item, array $keys) : Item{
		$tags = [];
		foreach($keys as $key){
			if(self::variantOfKey($key) === null || self::readCode($key) === null){
				continue;
			}
			$key = clone $key;
			$key->setCount(1);
			$tags[] = CompoundTag::create()->setTag("Item", $key->nbtSerialize());
			if(count($tags) >= 27){
				break;
			}
		}
		$tag = $item->getNamedTag();
		$tag->setTag("StoredKeys", new ListTag($tags, NBT::TAG_Compound));
		$item->setNamedTag($tag);
		return $item;
	}

	public static function isKeptOnDeath(Item $item) : bool{
		if(self::isId($item, self::KEYCHAIN)){
			return true;
		}
		return self::variantOfKey($item) !== null && self::readCode($item) !== null;
	}
}
