<?php


namespace Hebbinkpro\MagicCrates\tasks;

use Hebbinkpro\MagicCrates\entity\CrateItem;

use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\world\World;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\scheduler\Task;

class CreateEntityTask extends Task
{
    private string $name;
    private World $level;
    private CompoundTag $nbt;
    private int $count;

    public function __construct(string $name, World $level, CompoundTag $nbt, int $count = 1)
    {
        $this->name = $name;
        $this->level = $level;
        $this->nbt = $nbt;
        $this->count = $count;
    }

    public function onRun():void
    {
        //$itemEntity = Entity::createEntity("CrateItem", $this->level, $this->nbt);
        $itemEntity = new CrateItem(EntityDataHelper::parseLocation($this->nbt, $this->level), $this->nbt);

        if($itemEntity instanceof CrateItem){
            $itemEntity->setNameTag($this->name);
            if($this->count > 1){
                $itemEntity->setNameTag($this->name . " §r§6$this->count" . "x");
            }

            $itemEntity->spawnToAll();
        }
    }
}