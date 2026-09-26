<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\entity;

final class CreativeLockVisual extends LockVisual{
	public static function getNetworkTypeId() : string{ return "paragonia_lockpick:creative_lock"; }
}
