<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\ui;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

final class PluginForm implements Form{
	/**
	 * @param array<string, mixed> $payload
	 * @param \Closure(Player, mixed) : void $handler
	 */
	public function __construct(
		private array $payload,
		private \Closure $handler
	){}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize() : array{
		return $this->payload;
	}

	public function handleResponse(Player $player, $data) : void{
		if($data !== null && $this->payload["type"] === "form" && !is_int($data)){
			throw new FormValidationException("Expected a button index");
		}
		if($data !== null && $this->payload["type"] === "custom_form" && !is_array($data)){
			throw new FormValidationException("Expected form values");
		}
		($this->handler)($player, $data);
	}
}
