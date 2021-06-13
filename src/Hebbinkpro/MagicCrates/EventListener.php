<?php


namespace Hebbinkpro\MagicCrates;

use Hebbinkpro\MagicCrates\forms\CrateForm;
use Hebbinkpro\MagicCrates\tasks\CreateEntityTask;
use pocketmine\entity\EntityDataHelper;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\ItemFactory;
use pocketmine\player\Player;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\ItemIds;
use pocketmine\block\Chest;
use pocketmine\block\Block;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\math\Vector3;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\utils\Config;

class EventListener implements Listener
{

    private Main $main;
    private Config $config;
    private Config $crates;

    public function __construct()
    {
        $this->main = Main::getInstance();
        $this->config = $this->main->getConfig();
        $this->main->reloadCrates();
        $this->crates = $this->main->crates;

    }

    public function onInteractChest(PlayerInteractEvent $e){
        $player = $e->getPlayer();
        $block = $e->getBlock();
        $item = $e->getItem();

        //check if block is a chest
        if($block instanceof Chest){

            $bX = $block->getPos()->getFloorX();
            $bY = $block->getPos()->getFloorY();
            $bZ = $block->getPos()->getFloorZ();
            $bLevel = $block->getPos()->getWorld();

            $crateType = $this->getCrateType($block);

            if(isset($this->main->createCrates[$player->getName()])){
                if($this->main->createCrates[$player->getName()] === true){
                    if(!$crateType){
                        $form = new CrateForm($bX, $bY, $bZ, $bLevel->getFolderName());
                        $form->sendCreateForm($player);
                        $e->cancel();
                        return;
                    }else{
                        $player->sendMessage("[§6Magic§cCrates§r] §cThis crate is already registerd");
                        $e->cancel();
                        return;

                    }
                }
            }

            if(isset($this->main->removeCrates[$player->getName()])){
                if($this->main->removeCrates[$player->getName()] === true){
                    if($crateType != false){
                        $form = new CrateForm($bX, $bY, $bZ, $bLevel->getFolderName());
                        $form->sendRemoveForm($player);
                        $e->cancel();
                        return;
                    }else{
                        $player->sendMessage("[§6Magic§cCrates§r] §cThis chest isn't a crate");
                        $e->cancel();
                        return;
                    }
                }
            }

            if(!$crateType) {
                return;
            }

            $crateKey = $this->getCrateKey($block);
            if(isset($this->main->openCrates[$crateKey])){
                if($this->main->openCrates[$crateKey] != false){
                    $who = $this->main->openCrates[$crateKey];
                    $player->sendMessage("[§6Magic§cCrates§r] §cYou have to wait. §e$who §r§cis opening a crate");
                    $e->cancel();
                    return;
                }
            }

            if($item->getId() === ItemIds::PAPER ){
                if(!in_array("§6Magic§cCrates §7Key - " . $crateType, $item->getLore()) or $item->getCustomName() != "§e" . $crateType . " §r§dCrate Key"){
                    $player->sendMessage("[§6Magic§cCrates§r] §cUse a crate key to open this §e$crateType §r§ccrate");
                    $e->cancel();
                    return;
                }

                //check if a new reward can be add
                if(!$player->getInventory()->canAddItem(ItemFactory::getInstance()->get(298, 0))){
                    $player->sendMessage("[§6Magic§cCrates§r] §cYour inventory is full, come back later when your inventory is cleared!");
                    $e->cancel();
                    return;
                }

                //remove item
                $item->setCount(1);
                $player->getInventory()->removeItem($item);

            }else{
                $player->sendMessage("[§6Magic§cCrates§r] §cUse a crate key to open this §e$crateType §r§ccrate");
                $e->cancel();
                return;
            }

            $crate = $this->getCrateContent($crateType);
            if(!$crate) {
                $player->sendMessage("[§6Magic§cCrates§r] §cSomething went wrong");
                return;
            }

            $reward = $this->getReward($crate["rewards"]);
            if(!$reward) {
                $player->sendMessage("[§6Magic§cCrates§r] §cSomething went wrong");
                return;
            }

            //get reward data
            $rewardItem = $reward["item"];
            $name = $rewardItem["name"];
            $id = $rewardItem["id"];
            $meta = 0;
            if(isset($rewardItem["meta"])){
                $meta = $rewardItem["meta"];
            }
            $count = 1;
            if(isset($rewardItem["amount"])){
                $count = $rewardItem["amount"];
            }
            $lore = null;
            if(isset($rewardItem["lore"])){
                $lore = $rewardItem["lore"];
            }
            $enchantments = [];
            if(isset($rewardItem["enchantments"])){
                $enchantments = $rewardItem["enchantments"];
            }


            //create item
            $item = ItemFactory::getInstance()->get($id, $meta);
            $item->setCustomName($name);
            $item->setLore([$lore, "\n§a$crateType §r§6Crate", "§7Pickup: §cfalse"]);

            $ce = $this->main->getServer()->getPluginManager()->getPlugin("PiggyCustomEnchants");
            foreach ($enchantments as $ench){
                $eName = $ench["name"];
                $lvl = intval($ench["level"]);

                // get enchantment
                $enchantment = VanillaEnchantments::fromString($eName);

                // PCE SUPPORT IS REMOVED

                if($enchantment instanceof Enchantment){
                    $item->addEnchantment(new EnchantmentInstance($enchantment, $lvl));
                }
            }

            // create spawn position
            $spawnX = $bX + 0.5;
            $spawnY = $bY + 1;
            $spawnZ = $bZ + 0.5;
            $spawnPos = new Vector3($spawnX, $spawnY, $spawnZ);

            // set crate in opening state
            $this->main->openCrates[$crateKey] = $player->getName();

            // create nbt
            $nbt = EntityDataHelper::createBaseNBT($spawnPos);
            $nbt->setShort("Health", 5);
            $nbt->setString("Owner", $player->getName());
            $nbt->setShort("SpawnX", $spawnX);
            $nbt->setShort("SpawnY", $spawnY);
            $nbt->setShort("SpawnZ", $spawnZ);
            $nbt->setShort("ItemCount", $count);
            $nbt->setShort("CrateKey", $crateKey);
            $nbt->setString("Reward", json_encode($reward));
            $nbt->setTag("Item", $item->nbtSerialize());

            // create entity
            $delay = $this->config->get("delay") * 20;
            if(!is_int($delay)){
                $delay = 0;
            }

            // open crate
            $this->main->getScheduler()->scheduleDelayedTask(new CreateEntityTask($name, $bLevel, $nbt, $count), $delay);
            $player->sendMessage("§eYou are opening a $crateType crate...");

            $e->cancel();
        }

    }

    public function onPickup(InventoryPickupItemEvent $e){
        $itemEntity = $e->getItemEntity();
        $lore = $itemEntity->getItem()->getLore();
        if(in_array("§7Pickup: §cfalse", $lore)){
            $e->cancel();
        }
    }

    public function onBlockBreak(BlockBreakEvent $e){
        $player = $e->getPlayer();
        $block = $e->getBlock();
        if($player->hasPermission("magiccrates.break.remove")){
            if($this->getCrateType($block) != false){
                $e->cancel();

                $bX = $block->getPos()->getFloorX();
                $bY = $block->getPos()->getFloorY();
                $bZ = $block->getPos()->getFloorZ();
                $bLevel = $block->getPos()->getWorld();

                $form = new CrateForm($bX, $bY, $bZ, $bLevel->getFolderName(), $e);
                $form->sendRemoveForm($player);
                //$e->cancel();
                return;
            }
        }
    }

    public function onJoin(PlayerJoinEvent $e){
        $player = $e->getPlayer();
        if($player instanceof Player){
            $this->main->loadAllParticles($player);
        }
    }

    public function onLevelChange(EntityTeleportEvent $e){
        $player = $e->getEntity();
        $to = $e->getTo();
        $from = $e->getFrom();

        if($to->getWorld()->getFolderName() === $from->getWorld()->getFolderName()) return;

        $dest = $to->getWorld();

        if($player instanceof Player){
            $this->main->loadAllParticles($player, $dest);
        }
    }

    /**
     * @param Block $block
     * @return false|array
     */
    public function getCrateType(Block $block){
        if($block instanceof Chest){
            $cX = $block->getPos()->getFloorX();
            $cY = $block->getPos()->getFloorY();
            $cZ = $block->getPos()->getFloorZ();
            $cLevel = $block->getPos()->getWorld()->getFolderName();

            foreach($this->crates->get("crates") as $crate){
                if($crate["x"] === $cX and $crate["y"] === $cY and $crate["z"] === $cZ and $crate["level"] === $cLevel){
                    return $crate["type"];
                }
            }
        }

        return false;
    }

    /**
     * @param Block $block
     * @return false|int
     */
    public function getCrateKey(Block $block){
        if($block instanceof Chest){
            $cX = $block->getPos()->getFloorX();
            $cY = $block->getPos()->getFloorY();
            $cZ = $block->getPos()->getFloorZ();
            $cLevel = $block->getPos()->getWorld()->getFolderName();

            foreach($this->crates->get("crates") as $key=>$crate){
                if($crate["x"] === $cX and $crate["y"] === $cY and $crate["z"] === $cZ and $crate["level"] === $cLevel){
                    return $key;
                }
            }
        }

        return false;
    }

    /**
     * @param $type
     * @return false|array
     */
    public function getCrateContent($type){
        foreach($this->config->get("types") as $key=>$crateType){
            if($key === $type){
                return $crateType;
            }
        }
        return false;
    }

    public function getReward(array $items){

        $rewards = [];
        foreach($items as $item){
            $change = $item["change"];
            $i = 0;
            while($i < $change){
                $i++;
                $rewards[] = $item;
            }
        }
        if($rewards === []){
            return false;
        }
        $reward = array_rand($rewards, 1);
        return $rewards[$reward];

    }

}
