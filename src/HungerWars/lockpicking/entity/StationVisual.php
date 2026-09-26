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

final class StationVisual extends Entity{
	public static function getNetworkTypeId() : string{ return "paragonia_lockpick:locksmith_station"; }

	public static function create(Location $location) : self{
		return new self($location, CompoundTag::create()->setByte("Station", 1));
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.0, 1.0);
	}

	protected function getInitialDragMultiplier() : float{ return 1.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setNameTagVisible(false);
		$this->setNameTagAlwaysVisible(false);
		$this->setHasGravity(false);
		$this->setSilent(true);
		$this->setNoClientPredictions(true);
		$this->setCanSaveWithChunk(true);
	}

	public function getName() : string{ return "Locksmith Station"; }

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
	}
}
