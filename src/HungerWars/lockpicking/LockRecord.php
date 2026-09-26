<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;

final class LockRecord{
	public function __construct(
		public string $variant,
		public string $code,
		public string $owner,
		public bool $locked,
		public string $world,
		public int $x,
		public int $y,
		public int $z
	){}

	public function key() : string{
		return $this->world . ":" . $this->x . ":" . $this->y . ":" . $this->z;
	}

	public function position() : ?Position{
		$world = Server::getInstance()->getWorldManager()->getWorldByName($this->world);
		if(!$world instanceof World){
			return null;
		}
		return new Position($this->x, $this->y, $this->z, $world);
	}

	public function variantInfo() : Variant{
		return Variant::byId($this->variant) ?? Variant::all()[0];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray() : array{
		return [
			"variant" => $this->variant,
			"code" => $this->code,
			"owner" => $this->owner,
			"locked" => $this->locked,
			"world" => $this->world,
			"x" => $this->x,
			"y" => $this->y,
			"z" => $this->z,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data) : ?self{
		if(!isset($data["variant"], $data["code"], $data["owner"], $data["world"], $data["x"], $data["y"], $data["z"])){
			return null;
		}
		return new self(
			(string) $data["variant"],
			(string) $data["code"],
			(string) $data["owner"],
			(bool) ($data["locked"] ?? true),
			(string) $data["world"],
			(int) $data["x"],
			(int) $data["y"],
			(int) $data["z"]
		);
	}
}
