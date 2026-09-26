<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use HungerWars\lockpicking\entity\CopperLockVisual;
use HungerWars\lockpicking\entity\CreativeLockVisual;
use HungerWars\lockpicking\entity\GoldLockVisual;
use HungerWars\lockpicking\entity\IronLockVisual;
use HungerWars\lockpicking\entity\LockVisual;
use HungerWars\lockpicking\entity\NetheriteLockVisual;
use HungerWars\lockpicking\entity\StationVisual;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\world\Position;
use pocketmine\world\World;
use function max;

/**
 * Spawns the resource-pack lock and locksmith models.
 */
final class Visuals{
	public static function boot() : void{
		$factory = EntityFactory::getInstance();
		$register = static function(string $class, string $saveId) use ($factory) : void{
			if($factory->isRegistered($class)){
				return;
			}
			$factory->register($class, static function(World $world, CompoundTag $nbt) use ($class) : Entity{
				return new $class(EntityDataHelper::parseLocation($nbt, $world), $nbt);
			}, [$saveId]);
		};
		$register(CopperLockVisual::class, "paragonia_lockpick:copper_lock");
		$register(IronLockVisual::class, "paragonia_lockpick:iron_lock");
		$register(GoldLockVisual::class, "paragonia_lockpick:gold_lock");
		$register(NetheriteLockVisual::class, "paragonia_lockpick:netherite_lock");
		$register(CreativeLockVisual::class, "paragonia_lockpick:creative_lock");
		$register(StationVisual::class, "paragonia_lockpick:locksmith_station");

		self::registerActorIdentifiers([
			"paragonia_lockpick:copper_lock",
			"paragonia_lockpick:iron_lock",
			"paragonia_lockpick:gold_lock",
			"paragonia_lockpick:netherite_lock",
			"paragonia_lockpick:creative_lock",
			"paragonia_lockpick:locksmith_station",
		]);
	}

	/**
	 * @param list<string> $ids
	 */
	private static function registerActorIdentifiers(array $ids) : void{
		$cache = StaticPacketCache::getInstance();
		$property = new \ReflectionProperty($cache, "availableActorIdentifiers");
		$packet = $property->getValue($cache);
		$root = $packet->identifiers->getRoot();
		if(!$root instanceof CompoundTag){
			return;
		}
		$list = $root->getListTag("idlist");
		if(!$list instanceof ListTag){
			return;
		}
		$known = [];
		$max = 0;
		foreach($list as $entry){
			if(!$entry instanceof CompoundTag){
				continue;
			}
			$known[$entry->getString("id")] = true;
			$max = max($max, $entry->getInt("rid"));
		}
		$added = false;
		foreach($ids as $id){
			if(isset($known[$id])){
				continue;
			}
			$list->push(CompoundTag::create()
				->setString("bid", "")
				->setByte("hasspawnegg", 0)
				->setString("id", $id)
				->setInt("rid", ++$max)
				->setByte("summonable", 0));
			$added = true;
		}
		if($added){
			$property->setValue($cache, AvailableActorIdentifiersPacket::create(new CacheableNbt($root)));
		}
	}

	public static function syncLock(WorldStore $store, LockRecord $record) : void{
		$position = $record->position();
		if($position === null || !$position->isValid()){
			return;
		}
		$world = $position->getWorld();
		if(!$world->isChunkLoaded($position->getFloorX() >> 4, $position->getFloorZ() >> 4)){
			return;
		}
		self::clearLock($world, $record->key());
		$partner = self::partnerPosition($store, $record);
		[$vector, $yaw] = self::lockPose($position, $partner);
		LockVisual::create($record->variant, Location::fromObject($vector, $world, $yaw, 0.0), $record->key(), $record->locked)->spawnToAll();
	}

	public static function applyLockState(WorldStore $store, LockRecord $record) : void{
		$position = $record->position();
		if($position === null || !$position->isValid()){
			return;
		}
		$world = $position->getWorld();
		if(!$world->isChunkLoaded($position->getFloorX() >> 4, $position->getFloorZ() >> 4)){
			return;
		}
		$found = false;
		foreach($world->getEntities() as $entity){
			if($entity instanceof LockVisual && $entity->getLockKey() === $record->key()){
				$entity->setLocked($record->locked);
				$found = true;
			}
		}
		if(!$found){
			self::syncLock($store, $record);
		}
	}

	public static function clearLock(World $world, string $key) : void{
		foreach($world->getEntities() as $entity){
			if($entity instanceof LockVisual && $entity->getLockKey() === $key){
				$entity->flagForDespawn();
			}
		}
	}

	public static function clearStation(Position $position) : void{
		if(!$position->isValid()){
			return;
		}
		$world = $position->getWorld();
		$chunkX = $position->getFloorX() >> 4;
		$chunkZ = $position->getFloorZ() >> 4;
		if(!$world->isChunkLoaded($chunkX, $chunkZ)){
			return;
		}
		foreach($world->getChunkEntities($chunkX, $chunkZ) as $entity){
			if($entity instanceof StationVisual && $entity->getPosition()->floor()->equals($position->floor())){
				$entity->flagForDespawn();
			}
		}
	}

	public static function onChunk(WorldStore $store, World $world, int $chunkX, int $chunkZ) : void{
		$seen = [];
		foreach($world->getChunkEntities($chunkX, $chunkZ) as $entity){
			if($entity instanceof LockVisual){
				$record = $store->getByKey($entity->getLockKey());
				if($record === null){
					$entity->flagForDespawn();
					continue;
				}
				$entity->setLocked($record->locked);
				if(isset($seen[$entity->getLockKey()])){
					$entity->flagForDespawn();
					continue;
				}
				$seen[$entity->getLockKey()] = true;
			}
		}
		foreach($store->recordsInChunk($world->getFolderName(), $chunkX, $chunkZ) as $record){
			if(!isset($seen[$record->key()])){
				self::syncLock($store, $record);
			}
		}
		foreach($world->getChunkEntities($chunkX, $chunkZ) as $entity){
			if($entity instanceof StationVisual){
				$floor = $entity->getPosition()->floor();
				$block = $world->getBlock($floor);
				$typeId = $block->getTypeId();
				if($typeId === \pocketmine\block\BlockTypeIds::CRAFTING_TABLE || $typeId === \pocketmine\block\BlockTypeIds::BARRIER || $typeId === \pocketmine\block\BlockTypeIds::SMITHING_TABLE){
					$world->setBlock($floor, \pocketmine\block\VanillaBlocks::AIR());
				}
				$entity->flagForDespawn();
			}
		}
		foreach($store->stationPositionsInChunk($world->getFolderName(), $chunkX, $chunkZ) as $position){
			$position = Position::fromObject($position, $world);
			$block = $world->getBlock($position);
			$typeId = $block->getTypeId();
			if($typeId === \pocketmine\block\BlockTypeIds::CRAFTING_TABLE || $typeId === \pocketmine\block\BlockTypeIds::BARRIER || $typeId === \pocketmine\block\BlockTypeIds::SMITHING_TABLE){
				$world->setBlock($position, \pocketmine\block\VanillaBlocks::AIR());
			}
			self::clearStation($position);
			$store->removeStation($position);
		}
	}

	/**
	 * @return array{Vector3, float}
	 */
	private static function lockPose(Position $canonical, ?Position $partner) : array{
		$block = $canonical->getWorld()->getBlock($canonical);
		$facing = Facing::SOUTH;
		if($block instanceof \pocketmine\block\utils\HorizontalFacing || $block instanceof \pocketmine\block\utils\AnyFacing){
			$facing = $block->getFacing();
		}
		if($facing === Facing::UP || $facing === Facing::DOWN){
			$facing = Facing::SOUTH;
		}
		[$ox, $oy, $oz, $yaw] = match($facing){
			Facing::NORTH => [0.5, 0.35, -0.45, 180.0],
			Facing::EAST => [1.45, 0.35, 0.5, 270.0],
			Facing::WEST => [-0.45, 0.35, 0.5, 90.0],
			default => [0.5, 0.35, 1.45, 0.0],
		};
		$x = $canonical->getX() + $ox;
		$z = $canonical->getZ() + $oz;
		if($partner !== null){
			if($facing === Facing::NORTH || $facing === Facing::SOUTH){
				$x = ($canonical->getX() + $partner->getX()) / 2 + 0.5;
			}else{
				$z = ($canonical->getZ() + $partner->getZ()) / 2 + 0.5;
			}
		}
		return [new Vector3($x, $canonical->getY() + $oy, $z), $yaw];
	}

	private static function partnerPosition(WorldStore $store, LockRecord $record) : ?Position{
		$key = $store->partnerOf($record);
		if($key === null){
			return null;
		}
		$parts = explode(":", $key);
		if(count($parts) < 4){
			return null;
		}
		$z = (int) array_pop($parts);
		$y = (int) array_pop($parts);
		$x = (int) array_pop($parts);
		$world = $record->position()?->getWorld();
		if($world === null){
			return null;
		}
		return new Position($x, $y, $z, $world);
	}
}
