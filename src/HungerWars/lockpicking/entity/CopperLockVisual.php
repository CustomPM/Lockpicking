<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\entity;

final class CopperLockVisual extends LockVisual{
	public static function getNetworkTypeId() : string{ return "paragonia_lockpick:copper_lock"; }
}
