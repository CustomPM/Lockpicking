<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

use pocketmine\math\Vector3;
use pocketmine\world\Position;
use function file_exists;
use function file_put_contents;
use function json_decode;
use function json_encode;

/**
 * Persists locks and double-chest links.
 */
final class WorldStore{
	/** @var array<string, LockRecord> */
	private array $locks = [];

	/** @var array<string, string> partner position key => canonical key */
	private array $redirects = [];

	/** @var array<string, true> */
	private array $stations = [];

	/** @var array<string, true> */
	private array $joined = [];

	/** @var array<string, int> player uuid => expiry unix time */
	private array $effects = [];

	/** @var array<string, list<array<string, mixed>>> uuid => kept item nbt payloads */
	private array $keptOnDeath = [];

	public function __construct(private string $path){
		$directory = dirname($this->path);
		if(!is_dir($directory)){
			mkdir($directory, 0777, true);
		}
		$this->load();
	}

	public static function key(Position $position) : string{
		return $position->getWorld()->getFolderName() . ":" . $position->getFloorX() . ":" . $position->getFloorY() . ":" . $position->getFloorZ();
	}

	public function get(Position $position) : ?LockRecord{
		$key = self::key($position);
		if(isset($this->redirects[$key])){
			$key = $this->redirects[$key];
		}
		return $this->locks[$key] ?? null;
	}

	public function update(LockRecord $record) : void{
		$this->locks[$record->key()] = $record;
		$this->save();
	}

	public function put(LockRecord $record, ?Position $partner = null) : void{
		$this->clearPartner($record->key());
		$this->locks[$record->key()] = $record;
		if($partner !== null){
			$this->redirects[self::key($partner)] = $record->key();
		}
		$this->save();
	}

	public function remove(Position $position) : ?LockRecord{
		$key = self::key($position);
		if(isset($this->redirects[$key])){
			$canonical = $this->redirects[$key];
			unset($this->redirects[$key]);
			if(isset($this->locks[$canonical])){
				$this->save();
			}
			return $this->locks[$canonical] ?? null;
		}
		$record = $this->locks[$key] ?? null;
		if($record === null){
			return null;
		}
		unset($this->locks[$key]);
		$this->clearPartner($key);
		$this->save();
		return $record;
	}

	/**
	 * Moves a lock onto the surviving half of a double chest.
	 */
	public function migrate(Position $from, Position $to) : void{
		$record = $this->get($from);
		if($record === null){
			return;
		}
		$this->remove($from);
		$this->remove($to);
		$record->world = $to->getWorld()->getFolderName();
		$record->x = $to->getFloorX();
		$record->y = $to->getFloorY();
		$record->z = $to->getFloorZ();
		$this->put($record, null);
	}

	public function linkPartner(Position $canonical, Position $partner) : void{
		$record = $this->locks[self::key($canonical)] ?? null;
		if($record === null){
			return;
		}
		$this->redirects[self::key($partner)] = $record->key();
		$this->save();
	}

	public function unlinkPartner(Position $partner) : void{
		unset($this->redirects[self::key($partner)]);
		$this->save();
	}

	public function isRedirect(Position $position) : bool{
		return isset($this->redirects[self::key($position)]);
	}

	public function partnerOf(LockRecord $record) : ?string{
		$canonical = $record->key();
		foreach($this->redirects as $partner => $target){
			if($target === $canonical){
				return $partner;
			}
		}
		return null;
	}

	public function getByKey(string $key) : ?LockRecord{
		if(isset($this->redirects[$key])){
			$key = $this->redirects[$key];
		}
		return $this->locks[$key] ?? null;
	}

	/**
	 * @return list<LockRecord>
	 */
	public function recordsInChunk(string $world, int $chunkX, int $chunkZ) : array{
		$found = [];
		foreach($this->locks as $record){
			if($record->world === $world && ($record->x >> 4) === $chunkX && ($record->z >> 4) === $chunkZ){
				$found[] = $record;
			}
		}
		return $found;
	}

	/**
	 * @return list<Vector3>
	 */
	public function stationPositionsInChunk(string $world, int $chunkX, int $chunkZ) : array{
		$found = [];
		foreach(array_keys($this->stations) as $key){
			$parts = explode(":", $key);
			if(count($parts) < 4){
				continue;
			}
			$z = (int) array_pop($parts);
			$y = (int) array_pop($parts);
			$x = (int) array_pop($parts);
			if(implode(":", $parts) === $world && ($x >> 4) === $chunkX && ($z >> 4) === $chunkZ){
				$found[] = new Vector3($x, $y, $z);
			}
		}
		return $found;
	}

	public function isStation(Position $position) : bool{
		return isset($this->stations[self::key($position)]);
	}

	public function addStation(Position $position) : void{
		$this->stations[self::key($position)] = true;
		$this->save();
	}

	public function removeStation(Position $position) : void{
		unset($this->stations[self::key($position)]);
		$this->save();
	}

	public function hasJoined(string $uuid) : bool{
		return isset($this->joined[$uuid]);
	}

	public function markJoined(string $uuid) : void{
		$this->joined[$uuid] = true;
		$this->save();
	}

	public function effectExpiry(string $uuid) : int{
		return $this->effects[$uuid] ?? 0;
	}

	public function setEffectExpiry(string $uuid, int $expiry) : void{
		if($expiry <= time()){
			unset($this->effects[$uuid]);
		}else{
			$this->effects[$uuid] = $expiry;
		}
		$this->save();
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	public function keepItems(string $uuid, array $items) : void{
		if($items === []){
			unset($this->keptOnDeath[$uuid]);
		}else{
			$this->keptOnDeath[$uuid] = $items;
		}
		$this->save();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function takeKeptItems(string $uuid) : array{
		$items = $this->keptOnDeath[$uuid] ?? [];
		unset($this->keptOnDeath[$uuid]);
		if($items !== []){
			$this->save();
		}
		return $items;
	}

	private function clearPartner(string $canonical) : void{
		foreach($this->redirects as $partner => $target){
			if($target === $canonical){
				unset($this->redirects[$partner]);
			}
		}
	}

	private function load() : void{
		if(!file_exists($this->path)){
			return;
		}
		$decoded = json_decode((string) file_get_contents($this->path), true);
		if(!is_array($decoded)){
			return;
		}
		foreach($decoded["locks"] ?? [] as $row){
			if(!is_array($row)){
				continue;
			}
			$record = LockRecord::fromArray($row);
			if($record !== null && Variant::byId($record->variant) !== null){
				$this->locks[$record->key()] = $record;
			}
		}
		foreach($decoded["redirects"] ?? [] as $from => $to){
			if(is_string($from) && is_string($to)){
				$this->redirects[$from] = $to;
			}
		}
		foreach($decoded["stations"] ?? [] as $key){
			if(is_string($key)){
				$this->stations[$key] = true;
			}
		}
		foreach($decoded["joined"] ?? [] as $uuid){
			if(is_string($uuid)){
				$this->joined[$uuid] = true;
			}
		}
		foreach($decoded["effects"] ?? [] as $uuid => $expiry){
			if(is_string($uuid) && is_int($expiry)){
				$this->effects[$uuid] = $expiry;
			}
		}
		foreach($decoded["kept"] ?? [] as $uuid => $items){
			if(is_string($uuid) && is_array($items)){
				$this->keptOnDeath[$uuid] = $items;
			}
		}
	}

	public function save() : void{
		$locks = [];
		foreach($this->locks as $record){
			$locks[] = $record->toArray();
		}
		$payload = json_encode([
			"locks" => $locks,
			"redirects" => $this->redirects,
			"stations" => array_keys($this->stations),
			"joined" => array_keys($this->joined),
			"effects" => $this->effects,
			"kept" => $this->keptOnDeath,
		], JSON_PRETTY_PRINT);
		if($payload !== false){
			file_put_contents($this->path, $payload);
		}
	}
}
