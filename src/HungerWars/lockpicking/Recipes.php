<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\PotionTypeRecipe;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\Server;

final class Recipes{
	public static function register() : void{
		$manager = Server::getInstance()->getCraftingManager();
		$ingot = [
			"copper" => VanillaItems::COPPER_INGOT(),
			"iron" => VanillaItems::IRON_INGOT(),
			"gold" => VanillaItems::GOLD_INGOT(),
			"netherite" => VanillaItems::NETHERITE_INGOT(),
		];
		$nugget = [
			"copper" => VanillaItems::COPPER_NUGGET(),
			"iron" => VanillaItems::IRON_NUGGET(),
			"gold" => VanillaItems::GOLD_NUGGET(),
			"netherite" => VanillaItems::NETHERITE_SCRAP(),
		];

		foreach(Variant::all() as $variant){
			if(!$variant->craftable){
				continue;
			}
			$nuggetIngredient = new ExactRecipeIngredient($nugget[$variant->id]);
			$ingotIngredient = new ExactRecipeIngredient($ingot[$variant->id]);
			$lock = Items::get($variant->lockItemId);
			$lock->setCount(1);
			// Shown in the crafting table: two nuggets on top, two ingots below.
			self::locksmith($manager, new ShapedRecipe(
				["NN", "II"],
				["N" => $nuggetIngredient, "I" => $ingotIngredient],
				[$lock]
			));
			// Same craft in any slots of the crafting table.
			$manager->registerShapelessRecipe(new ShapelessRecipe(
				[$nuggetIngredient, $nuggetIngredient, $ingotIngredient, $ingotIngredient],
				[clone $lock],
				ShapelessRecipeType::CRAFTING
			));
			self::locksmith($manager, new ShapedRecipe(
				["I ", "IN"],
				["I" => new ExactRecipeIngredient($ingot[$variant->id]), "N" => new ExactRecipeIngredient($nugget[$variant->id])],
				[Items::get($variant->blankItemId)]
			));
		}

		self::locksmith($manager, new ShapedRecipe(
			["I", "L", "S"],
			[
				"I" => new ExactRecipeIngredient(VanillaItems::IRON_INGOT()),
				"L" => new ExactRecipeIngredient(VanillaItems::LEATHER()),
				"S" => new ExactRecipeIngredient(VanillaItems::STICK()),
			],
			[Items::get(Items::LOCKPICK)]
		));

		$trial = StringToItemParserSafe::trialKey();
		if($trial !== null){
			self::locksmith($manager, new ShapedRecipe(
				["S", "K", "B"],
				[
					"S" => new ExactRecipeIngredient(VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::SKELETON)->asItem()),
					"K" => new ExactRecipeIngredient($trial),
					"B" => new ExactRecipeIngredient(VanillaItems::BONE()),
				],
				[Items::get(Items::SKELETON_KEY)]
			));
		}

		self::locksmith($manager, new ShapedRecipe(
			[" C ", "123"],
			[
				"C" => new ExactRecipeIngredient(VanillaBlocks::CHAIN()->asItem()),
				"1" => new ExactRecipeIngredient(Items::get(Variant::byId("copper")->blankItemId)),
				"2" => new ExactRecipeIngredient(Items::get(Variant::byId("iron")->blankItemId)),
				"3" => new ExactRecipeIngredient(Items::get(Variant::byId("gold")->blankItemId)),
			],
			[Items::get(Items::KEYCHAIN)]
		));

		$manager->registerPotionTypeRecipe(new PotionTypeRecipe(
			new ExactRecipeIngredient(VanillaItems::POTION()->setType(PotionType::AWKWARD)),
			new ExactRecipeIngredient(Items::get(Variant::byId("iron")->lockItemId)),
			Items::get(Items::POTION)
		));
	}

	private static function locksmith(\pocketmine\crafting\CraftingManager $manager, ShapedRecipe $recipe) : void{
		$manager->registerShapedRecipe($recipe);
	}
}

final class StringToItemParserSafe{
	public static function trialKey() : ?Item{
		if(!Items::has(Items::TRIAL_KEY)){
			$parsed = \pocketmine\item\StringToItemParser::getInstance()->parse("trial_key");
			return $parsed;
		}
		return Items::get(Items::TRIAL_KEY);
	}
}
