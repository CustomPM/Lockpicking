<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use HungerWars\lockpicking\event\LockCreateEvent;
use HungerWars\lockpicking\event\LockRemoveEvent;
use HungerWars\lockpicking\event\LockToggleEvent;
use HungerWars\lockpicking\inventory\KeychainInventory;
use HungerWars\lockpicking\ui\EncodeForms;
use pocketmine\block\Block;
use pocketmine\inventory\Inventory;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\scheduler\ClosureTask;
use pocketmine\block\Chest;
use pocketmine\block\EnderChest;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\inventory\CraftingTableInventory;
use pocketmine\block\ShulkerBox;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockExplodeEvent;
use pocketmine\event\block\ChestPairEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\sound\AnvilBreakSound;
use pocketmine\world\sound\ClickSound;
use function base64_decode;
use function base64_encode;

final class LockListener implements Listener{
	/** @var array<int, true> */
	private array $savedKeychains = [];

	/** @var array<int, int> player id => server tick of the last keychain toggle */
	private array $keychainTick = [];

	public function __construct(private LockpickingPlugin $plugin){}

	public function onJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();
		$this->registerKeychainWindow($player);
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		$this->plugin->clearSession($event->getPlayer());
		$this->closeKeychain($event->getPlayer(), true);
	}

	public function onUse(PlayerItemUseEvent $event) : void{
		$player = $event->getPlayer();
		$item = $event->getItem();
		if(Items::isId($item, Items::KEYCHAIN)){
			$event->cancel();
			$window = $player->getCurrentWindow();
			if($window instanceof KeychainInventory){
				$player->removeCurrentWindow();
				return;
			}
			if($window !== null){
				return;
			}
			$this->openKeychain($player);
			return;
		}
		$blank = Items::variantOfBlank($item);
		if($blank !== null){
			$event->cancel();
			$this->encodeBlank($player, $blank);
		}
	}

	public function onInteract(PlayerInteractEvent $event) : void{
		$player = $event->getPlayer();
		$block = $event->getBlock();
		if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
			return;
		}
		$item = $event->getItem();

		if($this->plugin->hasSession($player)){
			$event->cancel();
			$this->plugin->clickSession($player, WorldStore::key($block->getPosition()));
			return;
		}

		$blank = Items::variantOfBlank($item);
		if($blank !== null){
			$event->cancel();
			$this->encodeBlank($player, $blank);
			return;
		}

		if(!$this->isLockable($block)){
			return;
		}

		$lockItem = Items::variantOfLock($item);
		if($lockItem !== null){
			$event->cancel();
			$this->applyLock($player, $block, $lockItem, $event->getFace());
			return;
		}

		$lock = $this->plugin->getStore()->get($block->getPosition());
		if($lock === null){
			return;
		}

		if(Items::isId($item, Items::LOCKPICK)){
			$this->beginPick($event, $player, $block, $lock);
			return;
		}

		if($this->plugin->hasSession($player)){
			$event->cancel();
			return;
		}

		if($player->isSneaking() && (Keys::holding($player, Items::CREATIVE_KEY) || Keys::handOpens($player, $lock))){
			$event->cancel();
			$this->closeClientWindow($player);
			$this->removeLock($player, $lock);
			return;
		}

		if(Keys::holding($player, Items::SKELETON_KEY) && $lock->locked && $lock->variant !== "creative"){
			$this->toggleLock($player, $lock);
			if(!$player->hasFiniteResources() || Keys::takeOne($player, Items::SKELETON_KEY)){
				$player->broadcastSound(new AnvilBreakSound());
				$player->sendActionBarMessage("§7The skeleton key crumbles.");
			}
			return;
		}

		if(Keys::holding($player, Items::CREATIVE_KEY) || Keys::handOpens($player, $lock)){
			if($lock->locked){
				$this->toggleLock($player, $lock);
			}
			return;
		}

		if(!$this->canAccess($player, $lock)){
			$event->cancel();
			$this->closeClientWindow($player);
			$player->sendActionBarMessage($lock->variant === "creative" ? "§cHold a Creative Key to unlock this." : "§cHold the matching key to unlock this.");
			$player->getWorld()->addSound($block->getPosition()->add(0.5, 0.5, 0.5), new ClickSound(0.6));
		}
	}

	public function onOpen(InventoryOpenEvent $event) : void{
		$inventory = $event->getInventory();
		if(!$inventory instanceof BlockInventory || $inventory instanceof KeychainInventory || $inventory instanceof CraftingTableInventory){
			return;
		}
		$position = $inventory->getHolder();
		$lock = $this->plugin->getStore()->get($position);
		if($lock === null){
			return;
		}
		if($this->canAccess($event->getPlayer(), $lock)){
			return;
		}
		$event->cancel();
		$this->closeClientWindow($event->getPlayer());
		$event->getPlayer()->sendActionBarMessage($lock->variant === "creative" ? "§cHold a Creative Key to unlock this." : "§cHold the matching key to unlock this.");
	}

	public function onBreak(BlockBreakEvent $event) : void{
		$block = $event->getBlock();
		$position = $block->getPosition();
		$store = $this->plugin->getStore();
		$lock = $store->get($position);
		if($lock === null){
			return;
		}
		$player = $event->getPlayer();
		if(!$this->canBreak($player, $lock)){
			$event->cancel();
			$player->sendActionBarMessage("§cThis block is locked! You need the key to break it.");
			return;
		}
		if($store->isRedirect($position)){
			$canonical = $store->get($position);
			$store->unlinkPartner($position);
			if($canonical !== null){
				Visuals::syncLock($store, $canonical);
			}
			return;
		}
		$partnerKey = $store->partnerOf($lock);
		if($partnerKey !== null){
			$partner = $this->positionFromKey($partnerKey, $position);
			if($partner !== null){
				$store->migrate($position, $partner);
				$moved = $store->get($partner);
				if($moved !== null){
					Visuals::syncLock($store, $moved);
				}
				return;
			}
		}
		$drops = $event->getDrops();
		$drops[] = Items::get($lock->variantInfo()->lockItemId);
		$event->setDrops($drops);
		Visuals::clearLock($position->getWorld(), $lock->key());
		$store->remove($position);
	}

	public function onPair(ChestPairEvent $event) : void{
		$store = $this->plugin->getStore();
		$left = $event->getLeft()->getPosition();
		$right = $event->getRight()->getPosition();
		$leftLock = $store->get($left);
		$rightLock = $store->get($right);
		if($leftLock !== null && $rightLock !== null && $leftLock->key() !== $rightLock->key()){
			$event->cancel();
			return;
		}
		if($rightLock !== null && $rightLock->key() === WorldStore::key($right)){
			$store->migrate($right, $left);
			$store->linkPartner($left, $right);
			$moved = $store->get($left);
			if($moved !== null){
				Visuals::syncLock($store, $moved);
			}
			return;
		}
		if($leftLock !== null){
			$store->linkPartner($left, $right);
			Visuals::syncLock($store, $leftLock);
		}
	}

	public function onChunkLoad(ChunkLoadEvent $event) : void{
		Visuals::onChunk($this->plugin->getStore(), $event->getWorld(), $event->getChunkX(), $event->getChunkZ());
	}

	public function onEntityExplode(EntityExplodeEvent $event) : void{
		$this->protectExplosion($event->getBlockList(), function(array $blocks) use ($event) : void{
			$event->setBlockList($blocks);
		});
	}

	public function onBlockExplode(BlockExplodeEvent $event) : void{
		$this->protectExplosion($event->getBlockList(), function(array $blocks) use ($event) : void{
			$event->setBlockList($blocks);
		});
	}

	public function onClose(InventoryCloseEvent $event) : void{
		$inventory = $event->getInventory();
		if($inventory instanceof BlockInventory && !$inventory instanceof KeychainInventory && !$inventory instanceof CraftingTableInventory){
			$this->relock($inventory->getHolder());
		}
		if(!$inventory instanceof KeychainInventory){
			return;
		}
		$this->saveKeychain($event->getPlayer(), $inventory);
	}

	private function relock(Position $position) : void{
		$store = $this->plugin->getStore();
		$lock = $store->get($position);
		if($lock === null || $lock->locked){
			return;
		}
		$lock->locked = true;
		$store->update($lock);
		Visuals::applyLockState($store, $lock);
	}

	public function onDeath(PlayerDeathEvent $event) : void{
		if(!(bool) $this->plugin->getConfig()->get("keep-keys-on-death", false) || $event->getKeepInventory()){
			return;
		}
		$kept = [];
		$drops = [];
		$writer = new LittleEndianNbtSerializer();
		foreach($event->getDrops() as $drop){
			if(Items::isKeptOnDeath($drop)){
				$kept[] = ["nbt" => base64_encode($writer->write(new TreeRoot($drop->nbtSerialize())))];
			}else{
				$drops[] = $drop;
			}
		}
		$event->setDrops($drops);
		$this->plugin->getStore()->keepItems($event->getPlayer()->getUniqueId()->toString(), $kept);
	}

	public function onRespawn(PlayerRespawnEvent $event) : void{
		$player = $event->getPlayer();
		$reader = new LittleEndianNbtSerializer();
		foreach($this->plugin->getStore()->takeKeptItems($player->getUniqueId()->toString()) as $row){
			if(!isset($row["nbt"]) || !is_string($row["nbt"])){
				continue;
			}
			$raw = base64_decode($row["nbt"], true);
			if($raw === false){
				continue;
			}
			try{
				$item = Item::nbtDeserialize($reader->read($raw)->mustGetCompoundTag());
			}catch(\Throwable){
				continue;
			}
			foreach($player->getInventory()->addItem($item) as $overflow){
				$player->getWorld()->dropItem($player->getPosition(), $overflow);
			}
		}
	}

	private function beginPick(PlayerInteractEvent $event, Player $player, Block $block, LockRecord $lock) : void{
		if(!$lock->locked){
			return;
		}
		$event->cancel();
		if(!(bool) $this->plugin->getConfig()->get("lockpicking-enabled", true)){
			$player->sendActionBarMessage("§cLockpicking is not enabled in this world.");
			return;
		}
		if($lock->variant === "creative"){
			$player->sendActionBarMessage("§cThis lock cannot be picked.");
			$player->getWorld()->addSound($block->getPosition()->add(0.5, 0.5, 0.5), new ClickSound(0.5));
			return;
		}
		$this->plugin->startSession($player, $lock);
	}

	private function applyLock(Player $player, Block $block, Variant $variant, int $face) : void{
		if($block instanceof ShulkerBox && ($face === Facing::UP || $face === Facing::DOWN)){
			$player->sendActionBarMessage("§cLocks can only be placed on a side face.");
			return;
		}
		$canonical = $this->canonical($block);
		$partner = $this->partner($block, $canonical);
		$store = $this->plugin->getStore();
		if($store->get($canonical) !== null || ($partner !== null && $store->get($partner) !== null && $store->get($partner)?->key() !== WorldStore::key($canonical))){
			$player->sendActionBarMessage("§cThis block is already locked!");
			return;
		}
		$place = function(string $code) use ($player, $variant, $canonical, $partner) : void{
			$fresh = $canonical->getWorld()->getBlock($canonical);
			if(!$this->isLockable($fresh)){
				$player->sendMessage("§cThe block is no longer there.");
				return;
			}
			if($this->plugin->getStore()->get($canonical) !== null){
				$player->sendActionBarMessage("§cThis block is already locked!");
				return;
			}
			$event = new LockCreateEvent($player, $canonical, $variant, $code);
			$event->call();
			if($event->isCancelled()){
				return;
			}
			if($player->hasFiniteResources() && !Keys::takeOne($player, $variant->lockItemId)){
				$player->sendMessage("§cNo lock item in inventory.");
				return;
			}
			$this->plugin->getStore()->put($record = new LockRecord(
				$variant->id,
				$code,
				$player->getName(),
				true,
				$canonical->getWorld()->getFolderName(),
				$canonical->getFloorX(),
				$canonical->getFloorY(),
				$canonical->getFloorZ()
			), $partner);
			Visuals::syncLock($this->plugin->getStore(), $record);
			$player->sendActionBarMessage("§7" . $variant->display . " lock set. Code: §8" . ($code === "" ? "none" : Items::displayCode($code)));
			$player->getWorld()->addSound($canonical->add(0.5, 0.5, 0.5), new ClickSound(1.4));
		};
		if($variant->id === "creative"){
			$place("");
			return;
		}
		EncodeForms::lock($player, function(Player $player, string $code) use ($place) : void{
			$place($code);
		});
	}

	private function encodeBlank(Player $player, Variant $variant) : void{
		EncodeForms::key($player, function(Player $player, string $name, string $code) use ($variant) : void{
			if($player->hasFiniteResources() && !Keys::takeOne($player, $variant->blankItemId)){
				$player->sendMessage("§cNo blank key in inventory.");
				return;
			}
			$key = Items::writeCode(Items::get($variant->keyItemId), $code, $name);
			foreach($player->getInventory()->addItem($key) as $overflow){
				$player->getWorld()->dropItem($player->getPosition(), $overflow);
			}
			$player->sendMessage("§7Key encoded with Code: §8" . Items::displayCode($code));
		});
	}

	private function toggleLock(Player $player, LockRecord $lock) : void{
		$next = !$lock->locked;
		$event = new LockToggleEvent($player, $lock, $next);
		$event->call();
		if($event->isCancelled()){
			return;
		}
		$lock->locked = $next;
		$this->plugin->getStore()->update($lock);
		Visuals::applyLockState($this->plugin->getStore(), $lock);
		$player->sendActionBarMessage($next ? "§cLocked." : "§aUnlocked.");
		$position = $lock->position();
		if($position !== null){
			$player->getWorld()->addSound($position->add(0.5, 0.5, 0.5), new ClickSound($next ? 0.8 : 1.5));
		}
	}

	private function removeLock(Player $player, LockRecord $lock) : void{
		$event = new LockRemoveEvent($player, $lock);
		$event->call();
		if($event->isCancelled()){
			return;
		}
		$position = $lock->position();
		if($position === null){
			return;
		}
		$this->plugin->getStore()->remove($position);
		Visuals::clearLock($position->getWorld(), $lock->key());
		$position->getWorld()->dropItem($position->add(0.5, 0.75, 0.5), Items::get($lock->variantInfo()->lockItemId));
		$player->sendActionBarMessage("§7Lock removed.");
	}

	private function canAccess(Player $player, LockRecord $lock) : bool{
		if(Keys::holding($player, Items::CREATIVE_KEY)){
			return true;
		}
		return Keys::handOpens($player, $lock);
	}

	private function canBreak(Player $player, LockRecord $lock) : bool{
		if(Keys::holding($player, Items::CREATIVE_KEY)){
			return true;
		}
		if((bool) $this->plugin->getConfig()->get("break-unlocked-blocks", false) && !$lock->locked){
			return true;
		}
		return Keys::handOpens($player, $lock);
	}

	private function isLockable(Block $block) : bool{
		return $block instanceof Chest || $block instanceof EnderChest || $block instanceof ShulkerBox;
	}

	private function canonical(Block $block) : Position{
		if(!$block instanceof Chest){
			return $block->getPosition();
		}
		$tile = $block->getPosition()->getWorld()->getTile($block->getPosition());
		if(!$tile instanceof TileChest || !$tile->isPaired()){
			return $block->getPosition();
		}
		$pair = $tile->getPair();
		if($pair === null){
			return $block->getPosition();
		}
		$counterClockwise = Facing::rotateY($block->getFacing(), false);
		if($block->getSide($counterClockwise)->getPosition()->equals($pair->getPosition())){
			return $block->getPosition();
		}
		return $pair->getPosition();
	}

	private function partner(Block $block, Position $canonical) : ?Position{
		if(!$block instanceof Chest){
			return null;
		}
		$tile = $block->getPosition()->getWorld()->getTile($block->getPosition());
		if(!$tile instanceof TileChest || !$tile->isPaired()){
			return null;
		}
		$pair = $tile->getPair();
		if($pair === null){
			return null;
		}
		if($pair->getPosition()->equals($canonical)){
			return $block->getPosition();
		}
		return $pair->getPosition();
	}

	private function positionFromKey(string $key, Position $fallback) : ?Position{
		$parts = explode(":", $key);
		if(count($parts) < 4){
			return null;
		}
		$z = (int) array_pop($parts);
		$y = (int) array_pop($parts);
		$x = (int) array_pop($parts);
		$name = implode(":", $parts);
		$world = $fallback->getWorld();
		if($name !== $world->getFolderName()){
			$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($name);
			if($world === null){
				return null;
			}
		}
		return new Position($x, $y, $z, $world);
	}

	/**
	 * @param Block[] $blocks
	 * @param \Closure(Block[]) : void $apply
	 */
	private function protectExplosion(array $blocks, \Closure $apply) : void{
		$store = $this->plugin->getStore();
		$kept = [];
		foreach($blocks as $block){
			$position = $block->getPosition();
			if($store->get($position) !== null){
				continue;
			}
			$kept[] = $block;
		}
		if(count($kept) !== count($blocks)){
			$apply($kept);
		}
	}

	private function registerKeychainWindow(Player $player) : void{
		$manager = $player->getNetworkSession()->getInvManager();
		if($manager === null){
			return;
		}
		$manager->getContainerOpenCallbacks()->add(static function(int $windowId, Inventory $inventory) use ($player) : ?array{
			if(!$inventory instanceof KeychainInventory){
				return null;
			}
			return [ContainerOpenPacket::entityInv($windowId, WindowTypes::CONTAINER, $player->getId())];
		});
	}

	/**
	 * Drops a container screen the client opened on its own after the server refused the click.
	 * Without this, the chest UI can stay on screen and block the player inventory.
	 */
	private function closeClientWindow(Player $player) : void{
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
			if(!$player->isConnected() || $player->getCurrentWindow() !== null){
				return;
			}
			$player->getNetworkSession()->sendDataPacket(ContainerClosePacket::create(ContainerIds::NONE, WindowTypes::NONE, false));
		}), 1);
	}

	private function openKeychain(Player $player) : void{
		$tick = $player->getServer()->getTick();
		if(($this->keychainTick[$player->getId()] ?? -1) === $tick){
			return;
		}
		$this->keychainTick[$player->getId()] = $tick;

		$current = $player->getCurrentWindow();
		if($current instanceof KeychainInventory){
			$player->removeCurrentWindow();
			return;
		}
		if($current !== null){
			return;
		}

		$inventory = $player->getInventory();
		$slot = $inventory->getHeldItemIndex();
		$item = $inventory->getItem($slot);
		if(!Items::isId($item, Items::KEYCHAIN)){
			return;
		}
		$tag = $item->getNamedTag();
		$id = $tag->getTag("KeychainId") !== null ? $tag->getInt("KeychainId") : 0;
		if($id === 0){
			$id = random_int(1, 999999999);
			$tag->setInt("KeychainId", $id);
			$item->setNamedTag($tag);
			$inventory->setItem($slot, $item);
		}
		$window = new KeychainInventory($id);
		$index = 0;
		foreach(Items::readKeychain($item) as $key){
			if($index >= $window->getSize()){
				break;
			}
			$window->setItem($index++, $key);
		}
		$player->setCurrentWindow($window);
		$player->sendActionBarMessage("§7Keychain");
	}

	private function closeKeychain(Player $player, bool $save) : void{
		$window = $player->getCurrentWindow();
		if($window instanceof KeychainInventory && $save){
			$this->saveKeychain($player, $window);
		}
	}

	private function saveKeychain(Player $player, KeychainInventory $window) : void{
		$identity = spl_object_id($window);
		if(isset($this->savedKeychains[$identity])){
			return;
		}
		$this->savedKeychains[$identity] = true;
		$keys = [];
		$returned = [];
		for($slot = 0; $slot < $window->getSize(); $slot++){
			$item = $window->getItem($slot);
			if($item->isNull()){
				continue;
			}
			if(Items::variantOfKey($item) !== null && Items::readCode($item) !== null){
				$keys[] = $item;
			}else{
				$returned[] = $item;
			}
			$window->setItem($slot, VanillaItems::AIR());
		}
		$inventory = $player->getInventory();
		$found = false;
		for($slot = 0; $slot < $inventory->getSize(); $slot++){
			$item = $inventory->getItem($slot);
			if(!Items::isId($item, Items::KEYCHAIN)){
				continue;
			}
			$tag = $item->getNamedTag();
			$storedId = $tag->getTag("KeychainId") !== null ? $tag->getInt("KeychainId") : 0;
			if($storedId !== $window->getKeychainId()){
				continue;
			}
			if(!$this->sameKeys(Items::readKeychain($item), $keys)){
				$inventory->setItem($slot, Items::writeKeychain($item, $keys));
			}
			$found = true;
			break;
		}
		if(!$found){
			foreach($keys as $key){
				$returned[] = $key;
			}
		}
		foreach($returned as $item){
			foreach($inventory->addItem($item) as $overflow){
				$player->getWorld()->dropItem($player->getPosition(), $overflow);
			}
		}
	}

	/**
	 * @param Item[] $left
	 * @param Item[] $right
	 */
	private function sameKeys(array $left, array $right) : bool{
		if(count($left) !== count($right)){
			return false;
		}
		foreach($left as $index => $item){
			$other = $right[$index] ?? null;
			if(!$other instanceof Item || !$item->equalsExact($other)){
				return false;
			}
		}
		return true;
	}
}
