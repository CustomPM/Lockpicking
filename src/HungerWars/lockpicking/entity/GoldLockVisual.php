<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\entity;

final class GoldLockVisual extends LockVisual{
	public static function getNetworkTypeId() : string{ return "paragonia_lockpick:gold_lock"; }
}
