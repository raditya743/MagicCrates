<?php


namespace Hebbinkpro\MagicCrates\entity;

use Hebbinkpro\MagicCrates\Main;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AddItemActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;

use pocketmine\Server;
use function get_class;

class CrateItem extends Entity
{
    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(0.25,0.25);
    }

    public static function getNetworkTypeId(): string
    {
        return EntityIds::ITEM;
    }

    protected string $owner = "";

    protected bool $pickup = false;

    protected Item $item;

    public float $width = 0.25;
    public float $height = 0.25;
    protected float $baseOffset = 0.125;

    public $canCollide = false;

    protected float $spawnX = 0.5;
    protected float $spawnY = 1;
    protected float $spawnZ = 0.5;

    protected int $count = 1;
    protected int $crateKey;
    protected int $delay = 0;
    protected $reward = [];

    /** @var int */
    protected $age = 0;

    protected function initEntity(CompoundTag $nbt) : void{

        parent::initEntity($nbt);

        $this->setMaxHealth(5);
        $this->setImmobile(true);

        $this->setHealth($nbt->getShort("Health", (int) $this->getHealth()));
        $this->age = $nbt->getShort("Age", $this->age);
        $this->owner = $nbt->getString("Owner", $this->owner);
        $this->spawnX = $nbt->getShort("SpawnX", $this->spawnX);
        $this->spawnY = $nbt->getShort("SpawnY", $this->spawnY);
        $this->spawnZ = $nbt->getShort("SpawnZ", $this->spawnZ);
        $this->count = $nbt->getShort("ItemCount", $this->count);
        $this->reward = json_decode($nbt->getString("Reward", json_encode($this->reward)), true);
        $this->crateKey = $nbt->getShort("CrateKey");
        if($this->count < 1) $this->count = 1;

        $itemTag = $nbt->getCompoundTag("Item");
        if($itemTag === null){
            throw new \UnexpectedValueException("Invalid " . get_class($this) . " entity: expected \"Item\" NBT tag not found");
        }

        $this->item = Item::nbtDeserialize($itemTag);
        if($this->item->isNull()){
            throw new \UnexpectedValueException("CrateItem for " . get_class($this) . " is invalid");
        }


        //(new ItemSpawnEvent($this))->call();
    }

    public function entityBaseTick(int $tickDiff = 1) : bool{
        if($this->closed){
            return false;
        }

        $hasUpdate = parent::entityBaseTick($tickDiff);

        if(!$this->isFlaggedForDespawn() and $this->isAlive()){

            $x = $this->getLocation()->getX();
            $y = $this->getLocation()->getY();
            $z = $this->getLocation()->getZ();

            if(($y - $this->spawnY) < 1.1){
                $this->setNameTagAlwaysVisible(false);
                $this->getLocation()->pitch = rad2deg(-pi() / 2);
                $this->move(0,0.05,0);
            }
            if(($y - $this->spawnY) >= 1.1 and $this->age < 100){
                $this->setNameTagAlwaysVisible(true);
                $this->move(0,0,0);
            }
            $this->age += $tickDiff;
            if($this->age >= 100){
                $this->flagForDespawn();
            }

        }

        if($this->isFlaggedForDespawn() and !$this->pickup and $this->owner != ""){
            $owner = Main::getInstance()->getServer()->getPlayerByPrefix($this->owner);

            if($owner instanceof Player){
                $this->pickup = true;

                if($owner->getInventory()->canAddItem($this->item)) {
                    $lore = $this->item->getLore();
                    $key = array_search("§7Pickup: §cfalse", $lore);
                    unset($lore[$key]);
                    $this->item->setLore($lore);
                    $give = 0;
                    while ($give < $this->count) {
                        $owner->getInventory()->addItem($this->item);
                        $give++;
                    }

                    $owner->sendMessage("[§6Magic§cCrates§r] §aYou won §e" . $this->getNameTag());
                }
                elseif(!$owner->getInventory()->canAddItem($this->item)){
                    $owner->sendMessage("[§6Magic§cCrates§r] §cYour inventory is full");
                }

                //execute commands
                $crates = Main::getInstance()->crates->get("crates");
                Main::getInstance()->sendCommands($crates[$this->crateKey]["type"], $owner, $this->item, $this->count);
            }

            //set crate to closed
            Main::getInstance()->openCrates[$this->crateKey] = false;
        }

        return $hasUpdate;
    }

    protected function tryChangeMovement() : void{
        $this->checkObstruction($this->getLocation()->x, $this->getLocation()->y, $this->getLocation()->z);
        parent::tryChangeMovement();
    }

    public function saveNBT() : CompoundTag{
        $nbt = parent::saveNBT();

        $nbt->setTag("CrateItem", $this->item->nbtSerialize(-1));
        $nbt->setShort("Health", (int) $this->getHealth());
        $nbt->setShort("Age", $this->age);
        $nbt->setString("Owner", $this->owner);
        $nbt->setShort("SpawnX", $this->spawnX);
        $nbt->setShort("SpawnY", $this->spawnY);
        $nbt->setShort("SpawnZ", $this->spawnZ);
        $nbt->setShort("ItemCount", $this->count);
        $nbt->setString("Reward", json_encode($this->reward));
        $nbt->setShort("CrateKey", $this->crateKey);

        return $nbt;
    }

    public function getItem() : Item{
        return $this->item;
    }

    protected function sendSpawnPacket(Player $player) : void{

        $pk = new AddItemActorPacket();
        $pk->entityRuntimeId = $this->getId();
        $pk->position = $this->getLocation()->asVector3();
        $pk->motion = $this->getMotion();

        $itemStack = new ItemStack(
            $this->item->getId(),
            $this->item->getMeta(),
            $this->item->getCount(),
            0,
            $this->item->getNamedTag(),
            $this->item->getCanPlaceOn(),
            $this->item->getCanDestroy()
        );
        $pk->item = ItemStackWrapper::legacy($itemStack);
        $pk->metadata = $this->getNetworkProperties()->getAll();

        Server::getInstance()->broadcastPackets([$player], [$pk]);
    }


}
