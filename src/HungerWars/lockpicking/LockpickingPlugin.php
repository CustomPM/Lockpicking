<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use HungerWars\lockpicking\event\LockpickResultEvent;
use HungerWars\lockpicking\ui\Guidebook;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\color\Color;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\DustParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\ClickSound;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\sound\XpLevelUpSound;
use function array_pop;
use function array_slice;
use function count;
use function explode;
use function implode;
use function intdiv;
use function max;
use function sprintf;

final class LockpickingPlugin extends PluginBase{
	private static ?self $instance = null;

	private WorldStore $store;

	/** @var array<int, PickSession> */
	private array $sessions = [];

	public static function getInstance() : ?self{
		return self::$instance;
	}

	public function getStore() : WorldStore{
		return $this->store;
	}

	protected function onLoad() : void{
		self::$instance = $this;
		ItemRegistrar::register();
		Visuals::boot();
	}

	protected function onEnable() : void{
		$this->saveDefaultConfig();
		$this->store = new WorldStore($this->getDataFolder() . "data.json");
		ResourcePackLoader::register($this);
		Recipes::register();
		$this->getServer()->getPluginManager()->registerEvents(new LockListener($this), $this);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$this->tick();
		}), 1);
	}

	protected function onDisable() : void{
		if(isset($this->store)){
			$this->store->save();
		}
		self::$instance = null;
	}

	public function hasSession(Player $player) : bool{
		return isset($this->sessions[$player->getId()]);
	}

	public function clearSession(Player $player) : void{
		unset($this->sessions[$player->getId()]);
	}

	public function startSession(Player $player, LockRecord $lock) : void{
		if($this->hasSession($player)){
			return;
		}
		$timing = $lock->variantInfo()->timing;
		if($timing === null){
			return;
		}
		if($this->store->effectExpiry($player->getUniqueId()->toString()) > time()){
			$timing["min"] = intdiv($timing["min"], 2);
			$timing["max"] = intdiv($timing["max"], 2);
			$timing["window"] *= 2;
		}
		$tick = $this->getServer()->getTick();
		$span = $timing["max"] - $timing["min"];
		$this->sessions[$player->getId()] = new PickSession(
			$player->getId(),
			$lock->key(),
			$tick,
			$tick + $timing["min"] + ($span > 0 ? random_int(0, $span) : 0),
			max(1, $timing["window"])
		);
		$player->sendActionBarMessage("§7Picking the lock...");
	}

	public function clickSession(Player $player, string $positionKey) : void{
		$session = $this->sessions[$player->getId()] ?? null;
		if($session === null){
			return;
		}
		$tick = $this->getServer()->getTick();
		$success = $positionKey === $session->positionKey && $tick >= $session->windowStart && $tick < $session->windowEnd();
		$this->resolve($player, $success);
	}

	private function tick() : void{
		$tick = $this->getServer()->getTick();
		foreach($this->getServer()->getOnlinePlayers() as $player){
			$session = $this->sessions[$player->getId()] ?? null;
			if($session !== null){
				if($tick >= $session->windowEnd() || $tick > $session->startTick + 1300){
					$this->resolve($player, false);
				}elseif(!$session->prompted && $tick >= $session->windowStart){
					$session->prompted = true;
					$player->sendActionBarMessage("§aThe lock clicks — click again now!");
					$player->getWorld()->addSound($player->getPosition(), new ClickSound(1.8));
				}elseif(!$session->prompted && $tick % 20 === 0){
					$player->sendActionBarMessage("§7Picking the lock...");
				}
				continue;
			}
			$expiry = $this->store->effectExpiry($player->getUniqueId()->toString());
			$left = $expiry - time();
			if($left <= 0){
				continue;
			}
			if($tick % 10 === 0){
				$player->getWorld()->addParticle($player->getPosition()->add(0, 1, 0), new DustParticle(new Color(212, 168, 55)));
			}
			if($tick % 20 === 0){
				$player->sendActionBarMessage(sprintf("§tFortify Lockpicking §7(%d:%02d)", intdiv($left, 60), $left % 60));
			}
		}
	}

	private function resolve(Player $player, bool $success) : void{
		$session = $this->sessions[$player->getId()] ?? null;
		if($session === null){
			return;
		}
		unset($this->sessions[$player->getId()]);
		$lock = $this->lockFromKey($session->positionKey);
		if($lock === null){
			return;
		}
		if($success){
			$event = new LockpickResultEvent($player, $lock, true);
			$event->call();
			if($event->isCancelled()){
				$player->sendActionBarMessage("§cThe lock holds.");
				return;
			}
			$lock->locked = false;
			$this->store->update($lock);
			Visuals::applyLockState($this->store, $lock);
			$player->sendActionBarMessage("§aLock picked!");
			$player->broadcastSound(new XpLevelUpSound(10));
			return;
		}
		(new LockpickResultEvent($player, $lock, false))->call();
		$player->sendActionBarMessage("§cThe lockpick snapped.");
		$player->broadcastSound(new ItemBreakSound());
		if($player->hasFiniteResources()){
			Keys::takeOne($player, Items::LOCKPICK);
		}
	}

	private function lockFromKey(string $key) : ?LockRecord{
		$parts = explode(":", $key);
		if(count($parts) < 4){
			return null;
		}
		$z = (int) array_pop($parts);
		$y = (int) array_pop($parts);
		$x = (int) array_pop($parts);
		$world = $this->getServer()->getWorldManager()->getWorldByName(implode(":", $parts));
		if($world === null){
			return null;
		}
		return $this->store->get(new Position($x, $y, $z, $world));
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		$sub = strtolower($args[0] ?? "");
		if($sub === "settings"){
			if(!$sender instanceof Player){
				$sender->sendMessage("Players only.");
				return true;
			}
			Guidebook::settings($sender);
			return true;
		}
		if($sub === "give"){
			if(!$sender->hasPermission("lockpicking.give")){
				$sender->sendMessage("§cYou cannot give lockpicking items.");
				return true;
			}
			$playerName = $args[1] ?? "";
			$itemName = strtolower($args[2] ?? "");
			$target = $playerName === "" ? null : $this->getServer()->getPlayerByPrefix($playerName);
			if(!$target instanceof Player || $itemName === ""){
				$sender->sendMessage("§7Usage: /lockpick give <player> <item> [count] [code] [name]");
				$sender->sendMessage("§7Items: lockpick, keychain, potion, skeleton_key, creative_key, creative_lock, copper_lock, iron_lock, gold_lock, netherite_lock, copper_blank, iron_blank, gold_blank, netherite_blank, copper_key, iron_key, gold_key, netherite_key");
				return true;
			}
			$identifier = $this->itemAlias($itemName);
			if($identifier === null){
				$sender->sendMessage("§cUnknown item.");
				return true;
			}
			$count = max(1, min(64, (int) ($args[3] ?? 1)));
			$item = Items::get($identifier);
			$item->setCount($count);
			$code = $args[4] ?? "";
			if($code !== "" && Items::variantOfKey($item) !== null){
				if(!Items::validCode($code)){
					$sender->sendMessage("§cCode must look like 1:2:3:4");
					return true;
				}
				$name = implode(" ", array_slice($args, 5));
				$item = Items::writeCode($item, $code, $name);
				$item->setCount(1);
			}
			foreach($target->getInventory()->addItem($item) as $overflow){
				$target->getWorld()->dropItem($target->getPosition(), $overflow);
			}
			$sender->sendMessage("§aGave " . $item->getName() . " to " . $target->getName() . ".");
			return true;
		}
		$sender->sendMessage("§7/lockpick settings §8- world settings");
		$sender->sendMessage("§7/lockpick give <player> <item> [count] [code] [name]");
		return true;
	}

	private function itemAlias(string $name) : ?string{
		$fixed = [
			"lockpick" => Items::LOCKPICK,
			"keychain" => Items::KEYCHAIN,
			"potion" => Items::POTION,
			"skeleton_key" => Items::SKELETON_KEY,
			"skeleton" => Items::SKELETON_KEY,
			"creative_key" => Items::CREATIVE_KEY,
			"creative_lock" => "paragonia_lockpick:creative_lock_item",
		];
		if(isset($fixed[$name])){
			return $fixed[$name];
		}
		foreach(Variant::all() as $variant){
			if($name === $variant->id . "_lock"){
				return $variant->lockItemId;
			}
			if($name === $variant->id . "_blank" && $variant->blankItemId !== ""){
				return $variant->blankItemId;
			}
			if($name === $variant->id . "_key" && $variant->keyItemId !== ""){
				return $variant->keyItemId;
			}
		}
		return Items::has($name) ? $name : null;
	}
}
