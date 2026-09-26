<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\entity;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;

abstract class LockVisual extends Entity{
	protected string $lockKey = "";

	protected bool $locked = true;

	public function getLockKey() : string{ return $this->lockKey; }

	public static function create(string $variant, Location $location, string $lockKey, bool $locked = true) : self{
		$class = match($variant){
			"copper" => CopperLockVisual::class,
			"gold" => GoldLockVisual::class,
			"netherite" => NetheriteLockVisual::class,
			"creative" => CreativeLockVisual::class,
			default => IronLockVisual::class,
		};
		$nbt = CompoundTag::create()
			->setString("LockKey", $lockKey)
			->setString("Variant", $variant)
			->setByte("Locked", $locked ? 1 : 0);
		return new $class($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.3, 0.3);
	}

	protected function getInitialDragMultiplier() : float{ return 1.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->lockKey = $nbt->getString("LockKey", "");
		$this->locked = $nbt->getByte("Locked", 1) !== 0;
		$this->setNameTagVisible(false);
		$this->setNameTagAlwaysVisible(false);
		$this->setHasGravity(false);
		$this->setSilent(true);
		$this->setNoClientPredictions(true);
		$this->setCanSaveWithChunk(true);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setString("LockKey", $this->lockKey);
		$nbt->setByte("Locked", $this->locked ? 1 : 0);
		return $nbt;
	}

	public function setLocked(bool $locked) : void{
		if($this->locked === $locked){
			return;
		}
		$this->locked = $locked;
		$this->networkPropertiesDirty = true;
	}

	public function getName() : string{ return "Lock"; }

	public function canBeCollidedWith() : bool{ return false; }

	public function canCollideWith(Entity $entity) : bool{ return false; }

	public function attack(EntityDamageEvent $source) : void{
		$source->cancel();
	}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::HAS_COLLISION, false);
		$properties->setGenericFlag(EntityMetadataFlags::IMMOBILE, true);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->locked ? 1 : 0);
	}
}
