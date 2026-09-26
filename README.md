# Lockpicking

Lockpicking by HungerWars is a lock and key plugin for Altay 5.44.7.

You put a lock on a chest, trapped chest, ender chest, or shulker box and set a 4-digit code. The only way to open it is to hold the matching key in your hand. If the key is just in your inventory or on the keychain, it will not open. Sneak and click with the key in your hand if you want to take the lock off. When you close the chest, it locks itself again, so nobody else can walk in after you.

Copper locks are easy to pick, iron is normal, gold is hard, and netherite is really hard. Creative locks cannot be picked. You click with a lockpick, wait for the click, then click again. If you click too early or wait too long, the lockpick breaks. The Potion of Lockpicking makes that easier for 3 minutes. The skeleton key opens one lock and then breaks. The keychain is only for storing keys.

You craft the locks on a normal crafting table. Two nuggets on top, two ingots under them. Netherite uses scraps instead of nuggets. The HungerWars resource pack comes with the plugin, so players get the lock models when they join.

## Install

Download `Lockpicking.phar` from the [releases](https://github.com/CustomPM/Lockpicking/releases) page, put it in your server `plugins` folder, and restart. API `5.44.7`.

## Commands

- `/lockpick give <player> <item> [count]`
- `/lockpick settings`

## API

Other plugins can use `HungerWars\lockpicking\api\Lockpicking`.
