<?php

declare(strict_types=1);

namespace HungerWars\lockpicking\ui;

use HungerWars\lockpicking\Items;
use pocketmine\player\Player;

final class EncodeForms{
	/**
	 * @param \Closure(Player, string) : void $onCode
	 */
	public static function lock(Player $player, \Closure $onCode) : void{
		$player->sendForm(new PluginForm([
			"type" => "custom_form",
			"title" => "Encode Lock",
			"content" => self::digits(),
		], function(Player $player, mixed $data) use ($onCode) : void{
			if(!is_array($data)){
				return;
			}
			$code = self::codeFrom($data);
			if($code !== null){
				$onCode($player, $code);
			}
		}));
	}

	/**
	 * @param \Closure(Player, string, string) : void $onKey name, code
	 */
	public static function key(Player $player, \Closure $onKey) : void{
		$player->sendForm(new PluginForm([
			"type" => "custom_form",
			"title" => "Encode Key",
			"content" => array_merge(
				[["type" => "input", "text" => "Key Name", "placeholder" => "Enter a name for this key..."]],
				self::digits()
			),
		], function(Player $player, mixed $data) use ($onKey) : void{
			if(!is_array($data)){
				return;
			}
			$name = trim((string) ($data[0] ?? ""));
			$code = self::codeFrom(array_slice($data, 1));
			if($code !== null){
				$onKey($player, $name, $code);
			}
		}));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function digits() : array{
		$rows = [];
		for($i = 0; $i < 4; $i++){
			$rows[] = ["type" => "slider", "text" => "#", "min" => 0, "max" => 9, "step" => 1, "default" => 0];
		}
		return $rows;
	}

	/**
	 * @param list<mixed> $values
	 */
	private static function codeFrom(array $values) : ?string{
		if(count($values) < 4){
			return null;
		}
		$digits = [];
		foreach(array_slice($values, 0, 4) as $value){
			if(!is_int($value) && !is_float($value)){
				return null;
			}
			$digits[] = (string) max(0, min(9, (int) round((float) $value)));
		}
		$code = implode(":", $digits);
		return Items::validCode($code) ? $code : null;
	}
}
