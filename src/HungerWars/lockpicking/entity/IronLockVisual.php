<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\entity;

final class IronLockVisual extends LockVisual{
	public static function getNetworkTypeId() : string{ return "paragonia_lockpick:iron_lock"; }
}
