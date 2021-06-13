<?php


namespace Hebbinkpro\MagicCrates;


use CortexPE\Commando\PacketHooker;
use Hebbinkpro\MagicCrates\commands\MagicCratesCommand;
use Hebbinkpro\MagicCrates\entity\CrateItem;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\data\bedrock\EntityLegacyIds;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\item\Item;
use pocketmine\lang\Language;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\player\Player;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\world\World;

class Main extends PluginBase
{
    public static self $instance;

    public Config $config;
    public Config $crates;

    public array $createCrates = [];
    public array $removeCrates = [];
    public array $openCrates = [];
    public array $particles = [];

    public static function getInstance():self{
        return self::$instance;
    }

    public function onLoad(): void
    {
        EntityFactory::getInstance()->register(CrateItem::class, function(World $world, CompoundTag $nbt) : CrateItem{

            return new CrateItem(EntityDataHelper::parseLocation($nbt, $world), $nbt);
        }, ['CrateItem'], EntityLegacyIds::ITEM);
    }

    public function onEnable(): void
    {
        self::$instance = $this;

        if(!PacketHooker::isRegistered()) PacketHooker::register($this);

        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->crates = new Config($this->getDataFolder() . "crates.yml", Config::YAML, [
            "crates" => []
        ]);

        // register events
        $this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);

        // register command
        $this->getServer()->getCommandMap()->register("magiccrates", new MagicCratesCommand($this, "magiccrates", "Magic crates command"));

        // add chest particles
        $this->initParticles();
    }

    public function reloadCrates(){
        $this->crates->reload();
    }

    public function initParticles(){
        $this->particles = [];

        foreach ($this->crates->get("crates") as $key=>$crate){
            $pos = new Vector3($crate["x"] + 0.5, $crate["y"] + 1, $crate["z"] + 0.5);
            $level = $crate["level"];
            $types = $this->getConfig()->get("types");
            if(isset($types[$crate["type"]]["name"])){
                $title = $types[$crate["type"]]["name"];
                if(!is_string($title)){
                    $title = $crate["type"] . " crate";
                }
            }else{
                $title = $crate["type"] . " crate";
            }
            $particle = new FloatingTextParticle("", $title);
            $this->particles[$key] = [
                "particle" => $particle,
                "pos" => $pos,
                "level" => $level
            ];

        }
    }

    public function onDisable():void{
        $this->crates->save();
        foreach($this->getServer()->getWorldManager()->getWorlds() as $level){
            foreach($level->getEntities() as $entity){
                if($entity instanceof CrateItem){
                    $entity->flagForDespawn();
                }
            }
        }

        $this->disableAllParticles();
    }

    public function loadAllParticles(?Player $player = null, World $lev = null){
        $this->disableAllParticles($player);

        $this->reloadCrates();
        $this->initParticles();

        $particles = $this->particles;
        foreach($particles as $crate){
            $level = null;
            if($lev instanceof World){
                if($crate["level"] !== ($name = $lev->getFolderName())) continue;

                $level = $lev;
            }

            $particle = $crate["particle"];
            if($particle instanceof FloatingTextParticle){

                $particle->setInvisible(false);
                if($player != null){
                    if(is_null($level)) $level = $player->getWorld();
                    $level->addParticle($crate["pos"], $particle, [$player]);
                }else{
                    if(!$this->getServer()->getWorldManager()->isWorldLoaded($crate["level"])) continue;

                    if(is_null($level)) $level = $this->getServer()->getWorldManager()->getWorldByName($crate["level"]);

                    if(is_null($level)) continue;

                    $level->addParticle($crate["pos"], $particle);
                }
            }
        }
    }

    public function disableAllParticles(?Player $player = null){
        $levels = $this->getServer()->getWorldManager()->getWorlds();
        $particles = $this->particles;
        foreach ($particles as $crate) {
            $particle = $crate["particle"];
            if($particle instanceof FloatingTextParticle){

                $particle->setInvisible(true);
                if($player != null){
                    $level = $player->getWorld();
                    $level->addParticle($crate["pos"], $particle, [$player]);
                }else{
                    if(!$this->getServer()->getWorldManager()->isWorldLoaded($crate["level"])){
                        continue;
                    }
                    $level = $this->getServer()->getWorldManager()->getWorldByName($crate["level"]);
                    if(is_null($level)){
                        continue;
                    }
                    $level->addParticle($crate["pos"], $particle);
                }
            }
        }

    }


    public function sendCommands(string $crateType, Player $player, Item $reward, int $count = 1){

        $types = $this->getConfig()->get("types");
        if(!isset($types[$crateType])){
            return;
        }
        $type = $types[$crateType];

        if(!isset($type["commands"])){
            return;
        }

        foreach($type["commands"] as $cmd){
            $cmd = str_replace("{player}", $player->getName(), $cmd);
            $cmd = str_replace("{crate}", $crateType . " crate", $cmd);
            if($count > 1){
                if($reward->hasCustomName()) {
                    $cmd = str_replace("{reward}", $reward->getCustomName() . " " . $count . "x", $cmd);
                }
                else{
                    $cmd = str_replace("{reward}", $reward->getName() . " ".$count."x", $cmd);
                }

            }else{
                if($reward->hasCustomName()){
                    $cmd = str_replace("{reward}", $reward->getCustomName(), $cmd);
                }
                $cmd = str_replace("{reward}", $reward->getName(), $cmd);
            }

            $consoleSender = new ConsoleCommandSender($this->getServer(), $this->getServer()->getLanguage());
            $consoleSender->recalculatePermissions();
            $this->getServer()->dispatchCommand($consoleSender, $cmd);
        }


    }
}