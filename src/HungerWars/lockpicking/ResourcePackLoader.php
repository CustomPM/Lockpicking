<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ResourcePackException;
use pocketmine\resourcepacks\ZippedResourcePack;
use function strtolower;

/**
 * Extracts the bundled Lockin' & Pickin' resource pack and adds it to the server stack.
 */
final class ResourcePackLoader{
	public static function register(PluginBase $plugin) : void{
		if(!(bool) $plugin->getConfig()->get("resource-pack", true)){
			return;
		}
		if(!$plugin->saveResource("LockinPickin.mcpack", true)){
			$plugin->getLogger()->error("The Lockin' & Pickin' resource pack is missing from the plugin.");
			return;
		}

		$path = $plugin->getDataFolder() . "LockinPickin.mcpack";
		try{
			$pack = new ZippedResourcePack($path);
		}catch(ResourcePackException $e){
			$plugin->getLogger()->error("Could not load the Lockin' & Pickin' resource pack: " . $e->getMessage());
			return;
		}

		$manager = $plugin->getServer()->getResourcePackManager();
		$id = strtolower($pack->getPackId());
		foreach($manager->getResourceStack() as $existing){
			if(strtolower($existing->getPackId()) === $id){
				$plugin->getLogger()->info("Lockin' & Pickin' resource pack is already in the server stack.");
				return;
			}
		}

		$stack = $manager->getResourceStack();
		array_unshift($stack, $pack);
		$manager->setResourceStack($stack);
		if((bool) $plugin->getConfig()->get("force-resource-pack", false)){
			$manager->setResourcePacksRequired(true);
		}
		$plugin->getLogger()->info("Loaded resource pack \"" . $pack->getPackName() . "\" (" . $pack->getPackId() . ").");
	}
}
