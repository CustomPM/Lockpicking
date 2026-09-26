<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\ui;

use HungerWars\lockpicking\LockpickingPlugin;
use pocketmine\player\Player;
use pocketmine\world\sound\AnvilUseSound;

final class Guidebook{
	public static function settings(Player $player) : void{
		$plugin = LockpickingPlugin::getInstance();
		if($plugin === null || !$player->hasPermission("lockpicking.settings")){
			$player->sendMessage("§cOperator permissions required to access Settings.");
			return;
		}
		$config = $plugin->getConfig();
		$player->sendForm(new PluginForm([
			"type" => "custom_form",
			"title" => "Settings",
			"content" => [
				["type" => "toggle", "text" => "Locks are Lockpickable", "default" => (bool) $config->get("lockpicking-enabled", true)],
				["type" => "toggle", "text" => "Keep Keys on Death", "default" => (bool) $config->get("keep-keys-on-death", false)],
				["type" => "toggle", "text" => "Allow Breaking Unlocked Blocks", "default" => (bool) $config->get("break-unlocked-blocks", false)],
			],
		], function(Player $player, mixed $data) use ($plugin) : void{
			if(!is_array($data)){
				return;
			}
			$config = $plugin->getConfig();
			$config->set("lockpicking-enabled", (bool) ($data[0] ?? true));
			$config->set("keep-keys-on-death", (bool) ($data[1] ?? false));
			$config->set("break-unlocked-blocks", (bool) ($data[2] ?? false));
			$config->save();
			$player->getWorld()->addSound($player->getPosition(), new AnvilUseSound());
		}));
	}
}
