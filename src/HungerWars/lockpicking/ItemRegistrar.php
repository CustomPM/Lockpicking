<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use HungerWars\lockpicking\item\CustomStackItem;
use HungerWars\lockpicking\item\LockpickingPotion;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeCategory;
use pocketmine\inventory\CreativeGroup;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function max;

final class ItemRegistrar{
	public static function register() : void{
		$entries = [];
		$groupIcon = null;

		$add = function(string $identifier, Item $item, string $texture, int $category, bool $hand, bool $drink = false, bool $component = true) use (&$entries, &$groupIcon) : void{
			Items::registerPrototype($identifier, $item);
			GlobalItemDataHandlers::getDeserializer()->map($identifier, fn() => clone $item);
			GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData($identifier));
			StringToItemParser::getInstance()->register($identifier, fn() => clone $item);

			if($component && !self::dictionaryHas($identifier)){
				$entries[] = new ItemTypeEntry($identifier, 0, true, 1, new CacheableNbt(self::components($item->getName(), $texture, $item->getMaxStackSize(), $category, $hand, $drink)));
			}
			if($groupIcon === null){
				$groupIcon = clone $item;
			}
		};

		foreach(Variant::all() as $variant){
			$add($variant->lockItemId, new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), $variant->display . " Lock", 64), (string) $variant->lockTexture, 4, false);
			if($variant->blankItemId !== ""){
				$add($variant->blankItemId, new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), "Blank " . $variant->display . " Key", 64), (string) $variant->blankTexture, 4, true);
				$add($variant->keyItemId, new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), $variant->display . " Key", 64), (string) $variant->keyTexture, 4, true);
			}
		}

		$add(Items::LOCKPICK, new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), "Lockpick", 64), "paragonia_lockpick_lockpick", 3, true);
		$add(Items::SKELETON_KEY, new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), "Skeleton Key", 64), "paragonia_lockpick_skeleton_key", 4, true);
		$add(Items::CREATIVE_KEY, new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), "Creative Key", 1), "paragonia_lockpick_creative_key", 4, true);
		$add(Items::KEYCHAIN, new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), "Keychain", 1), "paragonia_lockpick_keychain", 4, true);
		$add(Items::POTION, new LockpickingPotion(new ItemIdentifier(ItemTypeIds::newId())), "paragonia_lockpick_potion_bottle_lockpicking", 4, false, true);

		self::registerTrialKey();
		self::pushEntries($entries);

		$group = new CreativeGroup("Lockin' & Pickin'", $groupIcon ?? Items::get(Items::LOCKPICK));
		$creative = CreativeInventory::getInstance();
		foreach(self::creativeOrder() as $identifier){
			$category = $identifier === Items::LOCKPICK ? CreativeCategory::EQUIPMENT : CreativeCategory::ITEMS;
			$creative->add(Items::get($identifier), $category, $group);
		}
	}

	/**
	 * @return list<string>
	 */
	private static function creativeOrder() : array{
		$order = [];
		foreach(Variant::all() as $variant){
			$order[] = $variant->lockItemId;
		}
		foreach(Variant::all() as $variant){
			if($variant->blankItemId !== ""){
				$order[] = $variant->blankItemId;
				$order[] = $variant->keyItemId;
			}
		}
		$order[] = Items::CREATIVE_KEY;
		$order[] = Items::SKELETON_KEY;
		$order[] = Items::LOCKPICK;
		$order[] = Items::POTION;
		$order[] = Items::KEYCHAIN;
		return $order;
	}

	private static function registerTrialKey() : void{
		if(StringToItemParser::getInstance()->parse("trial_key") !== null || StringToItemParser::getInstance()->parse(Items::TRIAL_KEY) !== null){
			return;
		}
		if(!self::dictionaryHas(Items::TRIAL_KEY) && !self::dictionaryHas(ItemTypeNames::TRIAL_KEY)){
			return;
		}
		$item = new CustomStackItem(new ItemIdentifier(ItemTypeIds::newId()), "Trial Key", 64);
		Items::registerPrototype(Items::TRIAL_KEY, $item);
		GlobalItemDataHandlers::getDeserializer()->map(Items::TRIAL_KEY, fn() => clone $item);
		GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData(Items::TRIAL_KEY));
		StringToItemParser::getInstance()->register("trial_key", fn() => clone $item);
	}

	private static function dictionaryHas(string $identifier) : bool{
		try{
			TypeConverter::getInstance()->getItemTypeDictionary()->fromStringId($identifier);
			return true;
		}catch(\InvalidArgumentException){
			return false;
		}
	}

	/**
	 * @param ItemTypeEntry[] $fresh
	 */
	private static function pushEntries(array $fresh) : void{
		if($fresh === []){
			return;
		}
		$converter = TypeConverter::getInstance();
		$dictionary = $converter->getItemTypeDictionary();
		$existing = $dictionary->getEntries();
		$next = 1;
		foreach($existing as $entry){
			$next = max($next, $entry->getNumericId() + 1);
		}
		foreach($fresh as $entry){
			$existing[] = new ItemTypeEntry($entry->getStringId(), $next++, true, 1, $entry->getComponentNbt());
		}
		$replacement = new ItemTypeDictionary($existing);
		$converterReflection = new \ReflectionClass($converter);
		$converterReflection->getProperty("itemTypeDictionary")->setValue($converter, $replacement);
		$translatorReflection = new \ReflectionClass($converter->getItemTranslator());
		$translatorReflection->getProperty("itemTypeDictionary")->setValue($converter->getItemTranslator(), $replacement);
	}

	private static function components(string $name, string $texture, int $stack, int $category, bool $hand, bool $drink) : CompoundTag{
		$properties = CompoundTag::create()
			->setByte("allow_off_hand", 1)
			->setByte("can_destroy_in_creative", 1)
			->setInt("creative_category", $category)
			->setString("creative_group", "")
			->setByte("foil", 0)
			->setByte("hand_equipped", $hand ? 1 : 0)
			->setInt("max_stack_size", $stack)
			->setTag("minecraft:icon", CompoundTag::create()->setTag("textures", CompoundTag::create()->setString("default", $texture)))
			->setByte("should_despawn", 1);
		$components = CompoundTag::create()
			->setTag("item_properties", $properties)
			->setTag("minecraft:display_name", CompoundTag::create()->setString("value", $name))
			->setTag("minecraft:icon", CompoundTag::create()->setString("texture", $texture));
		if($drink){
			$components
				->setTag("minecraft:food", CompoundTag::create()
					->setByte("can_always_eat", 1)
					->setInt("nutrition", 0)
					->setFloat("saturation_modifier", 0))
				->setTag("minecraft:use_animation", CompoundTag::create()->setString("value", "drink"))
				->setTag("minecraft:use_modifiers", CompoundTag::create()
					->setFloat("movement_modifier", 0.35)
					->setFloat("use_duration", 1.6)
					->setString("start_using", "always"));
			$properties->setInt("use_animation", 2)->setInt("use_duration", 32);
		}
		return CompoundTag::create()->setTag("components", $components);
	}
}
