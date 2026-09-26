<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

/**
 * One lock and key material from the Lockin' & Pickin' add-on.
 */
final class Variant{
	/**
	 * @param array{min: int, max: int, window: int}|null $timing tick timings for the pick window
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $display,
		public readonly string $lockItemId,
		public readonly string $blankItemId,
		public readonly string $keyItemId,
		public readonly ?string $lockTexture,
		public readonly ?string $blankTexture,
		public readonly ?string $keyTexture,
		public readonly ?array $timing,
		public readonly bool $craftable,
		public readonly bool $pickable
	){}

	/**
	 * @return list<Variant>
	 */
	public static function all() : array{
		return [
			new self("copper", "Copper", "paragonia_lockpick:copper_lock_item", "paragonia_lockpick:copper_key_blank", "paragonia_lockpick:copper_key", "paragonia_lockpick_copper_lock", "paragonia_lockpick_copper_key_blank", "paragonia_lockpick_copper_key", ["min" => 20, "max" => 100, "window" => 50], true, true),
			new self("iron", "Iron", "paragonia_lockpick:iron_lock_item", "paragonia_lockpick:iron_key_blank", "paragonia_lockpick:iron_key", "paragonia_lockpick_iron_lock", "paragonia_lockpick_iron_key_blank", "paragonia_lockpick_iron_key", ["min" => 100, "max" => 200, "window" => 25], true, true),
			new self("gold", "Gold", "paragonia_lockpick:gold_lock_item", "paragonia_lockpick:gold_key_blank", "paragonia_lockpick:gold_key", "paragonia_lockpick_gold_lock", "paragonia_lockpick_gold_key_blank", "paragonia_lockpick_gold_key", ["min" => 200, "max" => 400, "window" => 10], true, true),
			new self("netherite", "Netherite", "paragonia_lockpick:netherite_lock_item", "paragonia_lockpick:netherite_key_blank", "paragonia_lockpick:netherite_key", "paragonia_lockpick_netherite_lock", "paragonia_lockpick_netherite_key_blank", "paragonia_lockpick_netherite_key", ["min" => 600, "max" => 1200, "window" => 5], true, true),
			new self("creative", "Creative", "paragonia_lockpick:creative_lock_item", "", "", "paragonia_lockpick_creative_lock", null, null, null, false, false),
		];
	}

	public static function byId(string $id) : ?self{
		foreach(self::all() as $variant){
			if($variant->id === $id){
				return $variant;
			}
		}
		return null;
	}
}
