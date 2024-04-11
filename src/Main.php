<?php

declare(strict_types=1);

namespace Mencoreh\Classes;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\projectile\Arrow;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\item\ItemTypeIds;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

// Nota: Todos los efectos giveados por ítems deben tener 1min de cd y 10s de uso. Excepto el bard, ese tiene 10s de cd que se comparte con todas las habilidades.

class Main extends PluginBase implements Listener
{
    private const EFFECT_MAX_DURATION = 2147483647;
    private array $archerTagged = [];

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $armorInventory = $player->getArmorInventory();

        $armorInventory->getListeners()->add(CallbackInventoryListener::onAnyChange(function () use ($player): void {
            $this->handleArmorEffects($player);
        }));

        $this->handleArmorEffects($player);
    }

    public function onQuit(PlayerQuitEvent $event): void {
        if(isset($this->archerTagged[$event->getPlayer()->getName()])) {
            unset($this->archerTagged[$event->getPlayer()->getName()]);
        }
    }

    public function onEntityDamage(EntityDamageByEntityEvent $event): void
    {
        $victim = $event->getEntity();

        // Handlear si está archer taggeado
        if ($victim instanceof Player && isset($this->archerTagged[$victim->getName()])) {
            $tagTime = $this->archerTagged[$victim->getName()];
            if (time() - $tagTime <= 10) {
                $event->setBaseDamage($event->getFinalDamage() * 1.25);
            } else {
                unset($this->archerTagged[$victim->getName()]);
            }
        }

        // Handlear si un archer le pegó un flechazo
        if($event instanceof EntityDamageByChildEntityEvent) {
            $damager = $event->getChild();

            if (!$damager instanceof Arrow) return;
            if (!$victim instanceof Player) return;

            $shooter = $damager->getOwningEntity();

            if (!$shooter instanceof Player) return;
            if($shooter === $victim) return;
            if(!$this->isFullLeatherArmor($shooter->getArmorInventory())) return;

            if ($this->getConfig()->get("archer-victim-message")) $victim->sendMessage($this->getConfig()->get("archer-victim-message"));
            if ($this->getConfig()->get("archer-shooter-message")) $shooter->sendMessage(str_replace("{PLAYER}", $victim->getName(), $this->getConfig()->get("archer-shooter-message")));
            
            $this->archerTagged[$victim->getName()] = time();
        }
    }

    public function onItemUse(PlayerItemUseEvent $event)
    {
        $player = $event->getPlayer();
        $item = $event->getItem();

        if ($item->getTypeId() === ItemTypeIds::SUGAR) {
            $player->sendMessage("Has hecho clic derecho con azúcar!");
            if($this->isFullLeatherArmor($player->getArmorInventory())) {
                $speed = new EffectInstance(VanillaEffects::SPEED(), 200, 2);
                $player->getEffects()->add($speed);
                $item->setCount($item->getCount() - 1);
                $player->getInventory()->setItemInHand($item);
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player): void {
                    $this->handleArmorEffects($player);
                }), 201);
            }
        } else if ($item->getTypeId() === ItemTypeIds::BLAZE_POWDER) {
            $player->sendMessage("Has hecho clic derecho con polvo de blaze!");
        } else if ($item->getTypeId() === ItemTypeIds::IRON_INGOT) {
            $player->sendMessage("Has hecho clic derecho con un lingote de hierro!");
        } else if ($item->getTypeId() === ItemTypeIds::FEATHER) {
            if($this->isFullLeatherArmor($player->getArmorInventory())) {
                $jump = new EffectInstance(VanillaEffects::JUMP_BOOST(), 200, 4);
                $player->getEffects()->add($jump);
                $item->setCount($item->getCount() - 1);
                $player->getInventory()->setItemInHand($item);
            }
        }
    }

    private function handleArmorEffects(Player $player): void
    {
        $this->clearLongEffects($player);
        $armorInventory = $player->getArmorInventory();

        if ($this->isFullLeatherArmor($armorInventory)) {
            $this->addArcherEffects($player);
        } elseif ($this->isFullIronArmor($armorInventory)) {
            $this->addMinerEffects($player);
        } elseif ($this->isFullChainmailArmor($armorInventory)) {
            $this->addRogueEffects($player);
        } elseif ($this->isFullGoldenArmor($armorInventory)) {
            $this->addBardEffects($player);
        }
    }

    public function clearLongEffects(Player $player, int $durationThreshold = 600 * 20): void
    {
        $effectManager = $player->getEffects();
        foreach ($effectManager->all() as $effect) {
            if ($effect->getDuration() > $durationThreshold) {
                $effectManager->remove($effect->getType());
            }
        }
    }

    private function addBardEffects(Player $player): void
    {
        // TODO: Blaze powder para obtener fuerza II (50 maná)
        // Iron ingot para resistencia II (35 maná)
        // Sugar para speed II (25 maná)
        // Sistema de maná (120 max, +1 por segundo), al usar items, ir disminuyendo el maná
        $speed = new EffectInstance(VanillaEffects::SPEED(), self::EFFECT_MAX_DURATION, 0);
        $regeneration = new EffectInstance(VanillaEffects::REGENERATION(), self::EFFECT_MAX_DURATION, 0);
        $resistance = new EffectInstance(VanillaEffects::RESISTANCE(), self::EFFECT_MAX_DURATION, 1);
        $player->getEffects()->add($speed);
        $player->getEffects()->add($regeneration);
        $player->getEffects()->add($resistance);
    }

    private function addArcherEffects(Player $player): void
    {
        // TODO: Archer tag y que al esnifar azúcar te de speed 3 y que al usar una pluma te de jump 5
        $speed = new EffectInstance(VanillaEffects::SPEED(), self::EFFECT_MAX_DURATION, 1);
        $resistance = new EffectInstance(VanillaEffects::RESISTANCE(), self::EFFECT_MAX_DURATION, 1);
        $player->getEffects()->add($speed);
        $player->getEffects()->add($resistance);
    }

    private function addMinerEffects(Player $player): void
    {
        $haste = new EffectInstance(VanillaEffects::HASTE(), self::EFFECT_MAX_DURATION, 1);
        $fireRes = new EffectInstance(VanillaEffects::FIRE_RESISTANCE(), self::EFFECT_MAX_DURATION, 0);
        $night_vision = new EffectInstance(VanillaEffects::NIGHT_VISION(), self::EFFECT_MAX_DURATION, 0);
        $player->getEffects()->add($haste);
        $player->getEffects()->add($fireRes);
        $player->getEffects()->add($night_vision);
    }

    private function addRogueEffects(Player $player): void
    {
        // TODO: Rogue tag, que al snifar azúcar te de speed 5 y que al usar pluma te de jump 5
        $speed = new EffectInstance(VanillaEffects::SPEED(), self::EFFECT_MAX_DURATION, 1);
        $resistance = new EffectInstance(VanillaEffects::RESISTANCE(), self::EFFECT_MAX_DURATION, 1);
        $jump = new EffectInstance(VanillaEffects::JUMP_BOOST(), self::EFFECT_MAX_DURATION, 2);
        $player->getEffects()->add($speed);
        $player->getEffects()->add($resistance);
        $player->getEffects()->add($jump);
    }

    private function isFullIronArmor(ArmorInventory $armorInventory): bool
    {
        return $armorInventory->getHelmet()->getTypeId() === ItemTypeIds::IRON_HELMET &&
            $armorInventory->getChestplate()->getTypeId() === ItemTypeIds::IRON_CHESTPLATE &&
            $armorInventory->getLeggings()->getTypeId() === ItemTypeIds::IRON_LEGGINGS &&
            $armorInventory->getBoots()->getTypeId() === ItemTypeIds::IRON_BOOTS;
    }

    private function isFullLeatherArmor(ArmorInventory $armorInventory): bool
    {
        return $armorInventory->getHelmet()->getTypeId() === ItemTypeIds::LEATHER_CAP &&
            $armorInventory->getChestplate()->getTypeId() === ItemTypeIds::LEATHER_TUNIC &&
            $armorInventory->getLeggings()->getTypeId() === ItemTypeIds::LEATHER_PANTS &&
            $armorInventory->getBoots()->getTypeId() === ItemTypeIds::LEATHER_BOOTS;
    }

    private function isFullChainmailArmor(ArmorInventory $armorInventory): bool
    {
        return $armorInventory->getHelmet()->getTypeId() === ItemTypeIds::CHAINMAIL_HELMET &&
            $armorInventory->getChestplate()->getTypeId() === ItemTypeIds::CHAINMAIL_CHESTPLATE &&
            $armorInventory->getLeggings()->getTypeId() === ItemTypeIds::CHAINMAIL_LEGGINGS &&
            $armorInventory->getBoots()->getTypeId() === ItemTypeIds::CHAINMAIL_BOOTS;
    }

    private function isFullGoldenArmor(ArmorInventory $armorInventory): bool
    {
        return $armorInventory->getHelmet()->getTypeId() === ItemTypeIds::GOLDEN_HELMET &&
            $armorInventory->getChestplate()->getTypeId() === ItemTypeIds::GOLDEN_CHESTPLATE &&
            $armorInventory->getLeggings()->getTypeId() === ItemTypeIds::GOLDEN_LEGGINGS &&
            $armorInventory->getBoots()->getTypeId() === ItemTypeIds::GOLDEN_BOOTS;
    }
}
