<?php

declare(strict_types=1);

namespace HungerWars\lockpicking;

final class PickSession{
	public function __construct(
		public int $entityId,
		public string $positionKey,
		public int $startTick,
		public int $windowStart,
		public int $windowTicks,
		public bool $prompted = false
	){}

	public function windowEnd() : int{
		return $this->windowStart + $this->windowTicks;
	}
}
