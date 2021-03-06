<?php

namespace LockIt;

use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;

class Main extends PluginBase implements CommandExecutor, Listener {

    private $session;
    private $prefs;
    private $locked;
    public $tasks;

    public function onEnable() {
        if (!file_exists($this->getDataFolder())) {
            mkdir($this->getDataFolder());
        }

        $this->session = [];
        $this->tasks = [];

        $this->prefs = new Config($this->getDataFolder() . "prefs.yml", CONFIG::YAML, array(
            "TakeKey" => true,
            "AutoClose" => true,
            "Delay" => 5
        ));

        $lockedYml = new Config($this->getDataFolder() . "locked.yml", Config::YAML, array());
        $this->locked = $lockedYml->getAll();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $cmd, $label, array $args) {
        if ($sender instanceof Player) {

            if (!isset($args[0]))
                return false;

            switch ($args[0]) {

                case "off":
                case "stop":
                    unset($this->session[$sender->getPlayer()->getName()]);
                    $sender->sendMessage(TEXTFORMAT::RED . "LockIt Tap Mode : OFF");

                    return true;
                //more commands here...

                default:
                    break;
            }



            if (!is_numeric($args[0])) {
                $sender->sendMessage("Invalid ID number");
                return false;
            }
            $block = Item::get($args[0]);
            if (!$block instanceof Item) {
                $sender->sendMessage("Invalid ID");
                return false;
            }

            $this->session[$sender->getName()] = $args[0];
            $sender->sendMessage(TEXTFORMAT::GREEN . "LockIt Tap Mode : ON");
            $sender->sendMessage(TEXTFORMAT::YELLOW . "Touch the BOTTOM of doors to lock them");
            $sender->sendMessage(TEXTFORMAT::YELLOW . "Type " . TEXTFORMAT::RED . "/lockit off" . TEXTFORMAT::YELLOW . " to stop");
        } else {
            $sender->sendMessage(TEXTFORMAT::RED . "Please run the command in the game");
        }
        return true;
    }

    public function onPlayerInteract(PlayerInteractEvent $event) {

        if (!($event->getBlock()->getId() === 71 )) {
            return;
        }

        if (isset($this->session[$event->getPlayer()->getName()])) {

            // if it's someone who is locking doors...
            if ($event->getBlock()->getLevel()->getBlock(new vector3($event->getBlock()->getX(), $event->getBlock()->getY() - 1, $event->getBlock()->getZ()))->getId() === 71) {
                $event->getPlayer()->sendMessage(TEXTFORMAT::RED . "Please Tap The Bootom Of The Door");
                return;
            }
            $block = $event->getBlock();
            $keyid = $this->session[$event->getPlayer()->getName()];

            $this->locked[$block->getX() . ":" . $block->getY() . ":" . $block->getZ() . ":" . $block->getLevel()->getFolderName()] = array(
                "keyid" => $keyid,
                "owner" => $event->getPlayer()->getName()
            );

            $this->saveLocked();

            $event->getPlayer()->sendMessage(TEXTFORMAT::GREEN . "Door locked. Key: $keyid");
            $event->setCancelled(true);
            return true;
        } else {

            // regular player taps a door, check above and below
            //Check Item in Hand INVENTORY

            $block = $event->getBlock();
            $inv = $event->getPlayer()->getInventory();
            $inhand = $inv->getItemInHand();

            //CHECK THE BLOCK ITSELF

            if (isset($this->locked[$event->getBlock()->x . ":" . $event->getBlock()->y . ":" . $event->getBlock()->z . ":" . $event->getPlayer()->getLevel()->getName()])) {
                //it's a locked door

                $taskstring = $event->getBlock()->x . ":" . ($event->getBlock()->y) . ":" . $event->getBlock()->z . ":" . $event->getPlayer()->getLevel()->getName();

                if (in_array($taskstring, $this->tasks)) {
                    $event->setCancelled(true);
                    return;
                }

                $locked = $this->locked[$event->getBlock()->x . ":" . $event->getBlock()->y . ":" . $event->getBlock()->z . ":" . $event->getPlayer()->getLevel()->getName()];
                $keyid = $locked["keyid"];

                $event->getPlayer()->sendMessage(TextFormat::RED . "Click the Door's Top Panel To Unlock");
                $event->setCancelled(true);
            }

            //CHECK IF IT IS THE TOP PANEL

            if (isset($this->locked[$event->getBlock()->x . ":" . ($event->getBlock()->y - 1) . ":" . $event->getBlock()->z . ":" . $event->getPlayer()->getLevel()->getName()])) {
                //it's a locked door

                $locked = $this->locked[$event->getBlock()->x . ":" . ($event->getBlock()->y - 1) . ":" . $event->getBlock()->z . ":" . $event->getPlayer()->getLevel()->getName()];
                $keyid = $locked["keyid"];

                if ($inhand->getId() == $keyid) {
                    //open door for DELAY seconds

                    if ($this->prefs->get("AutoClose")) {
                        $belowblock = $block->getLevel()->getBlock(new Vector3($event->getBlock()->getX(), $event->getBlock()->getY() - 1, $event->getBlock()->getZ()));
                        $taskstring = $event->getBlock()->x . ":" . ($event->getBlock()->y - 1) . ":" . $event->getBlock()->z . ":" . $event->getPlayer()->getLevel()->getName();

                        if (!(in_array($taskstring, $this->tasks))) {

                            $task = new CloseTask($this, $belowblock);
                            $taskid = $this->getServer()->getScheduler()->scheduleDelayedTask($task, 20 * $this->prefs->get("Delay"));
                            $task->setHandler($taskid);
                            $this->tasks[$taskstring] = $taskstring;
                        } else {
                            $event->setCancelled(true);
                            return;
                        }
                    }

                    if ($this->prefs->get("TakeKey")) {
                        --$inhand->count;
                        $inv->setItemInHand($inhand);
                    }


                    return;
                } else {
                    $event->getPlayer()->sendMessage(TextFormat::RED . "You don't have the key ");
                    $event->setCancelled(true);
                }
            }
        }
    }

    public function onBlockBreak(BlockBreakEvent $event) {
        $block = $event->getBlock();

        if (!($block->getID() === 71 ))
            return;

        if (isset($this->locked[$block->getX() . ":" . $block->getY() . ":" . $block->getZ() . ":" . $block->getLevel()->getFolderName()])) {
            $player = $event->getPlayer();
            $locked = $this->locked[$block->getX() . ":" . $block->getY() . ":" . $block->getZ() . ":" . $block->getLevel()->getFolderName()];

            $owner = $locked["owner"];

            if (!($player->hasPermission("lockit.admin") || (strtolower($owner) === strtolower($player->getName())))) {
                $player->sendMessage("You do not have permission to break this door. Owner: " . $player->getName());
                $event->setCancelled(true);
                return;
            }

            //Delete this locked door
            unset($this->locked[$block->getX() . ":" . $block->getY() . ":" . $block->getZ() . ":" . $block->getLevel()->getFolderName()]);
            $this->saveLocked();
        }

        if (isset($this->locked[$block->getX() . ":" . ($block->getY() - 1) . ":" . $block->getZ() . ":" . $block->getLevel()->getFolderName()])) {
            $player = $event->getPlayer();
            $locked = $this->locked[$block->getX() . ":" . ($block->getY() - 1) . ":" . $block->getZ() . ":" . $block->getLevel()->getFolderName()];

            $owner = $locked["owner"];

            if (!($player->hasPermission("lockit.admin") || (strtolower($owner) === strtolower($player->getName())))) {
                $player->sendMessage("You do not have permission to break this door. Owner: " . $player->getNmae());
                $event->setCancelled(true);
                return;
            }

            //Delete this locked door
            unset($this->locked[$block->getX() . ":" . ($block->getY() - 1) . ":" . $block->getZ() . ":" . $block->getLevel()->getFolderName()]);
            $this->saveLocked();
        }
    }

    function saveLocked() {
        $lockedYml = new Config($this->getDataFolder() . "locked.yml", Config::YAML);
        $lockedYml->setAll($this->locked);
        $lockedYml->save();
    }

}
