<?php

/**
 * Copyright 2018 CzechPMDevs
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace buildertools\editors;

use buildertools\BuilderTools;
use buildertools\editors\object\BlockList;
use buildertools\editors\object\EditorResult;
use buildertools\task\async\FillAsyncTask;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\utils\SubChunkIteratorManager;
use pocketmine\math\Vector3;
use pocketmine\Player;

/**
 * Class Filler
 * @package buildertools\editors
 */
class Filler extends Editor {

    /**
     * @param Vector3 $pos1
     * @param Vector3 $pos2
     * @param Level $level
     * @param string $blockArgs
     * @return BlockList $blocks
     */
    public function prepareFill(Vector3 $pos1, Vector3 $pos2, Level $level, string $blockArgs): BlockList {
        $blockList = new BlockList;
        $blockList->setLevel($level);

        for($x = min($pos1->getX(), $pos2->getX()); $x <= max($pos1->getX(), $pos2->getX()); $x++) {
            for($y = min($pos1->getY(), $pos2->getY()); $y <= max($pos1->getY(), $pos2->getY()); $y++) {
                for($z = min($pos1->getZ(), $pos2->getZ()); $z <= max($pos1->getZ(), $pos2->getZ()); $z++) {
                    $blockList->addBlock(new Vector3($x, $y, $z), $this->getBlockFromString($blockArgs));
                }
            }
        }

        return $blockList;
    }

    /**
     * @param Vector3 $pos1
     * @param Vector3 $pos2
     * @param Level $level
     * @param string $blockArg
     * @param Player $player
     */
    public function fillAsync(Vector3 $pos1, Vector3 $pos2, Level $level, string $blockArg, Player $player) {
        $fillData = [
            "pos1" => [$pos1->getX(), $pos1->getY(), $pos1->getZ(), $level->getFolderName()],
            "pos2" => [$pos2->getX(), $pos2->getY(), $pos2->getZ(), $level->getFolderName()],
            "blocks" => $blockArg,
            "player" => $player->getName()
        ];

        $this->getPlugin()->getServer()->getAsyncPool()->submitTask(new FillAsyncTask($fillData));

    }

    /**
     * @param string $string
     * @return Block $block
     */
    public function getBlockFromString(string $string): Block {
        $itemArgs = explode(",", $string);
        $block = Item::fromString($itemArgs[array_rand($itemArgs, 1)])->getBlock();

        if(!$block instanceof Block) {
            return Block::get(Block::AIR);
        }

        return $block;
    }


    /**
     * @param Player $player
     * @param BlockList $blockList
     * @param bool[] $settings
     *
     * @return EditorResult
     */
    public function fill(Player $player, BlockList $blockList, array $settings = []): EditorResult {
        $startTime = microtime(true);
        $blocks = $blockList->getAll();

        /** @var bool $fastFill */
        $fastFill = true;
        /** @var bool $saveUndo */
        $saveUndo = false;
        /** @var bool $saveRedo */
        $saveRedo = true;

        if(isset($settings["fastFill"]) && is_bool($settings["fastFill"])) $fastFill = $settings["fastFill"];
        if(isset($settings["saveUndo"]) && is_bool($settings["saveUndo"])) $saveUndo = $settings["saveUndo"];
        if(isset($settings["saveRedo"]) && is_bool($settings["saveRedo"])) $saveRedo = $settings["saveRedo"];

        $undoList = new BlockList;
        $redoList = new BlockList;

        if($saveUndo) $undoList->setLevel($blockList->getLevel());
        if($saveRedo) $redoList->setLevel($blockList->getLevel());

        if(!$fastFill) {
            foreach ($blocks as $block) {
                if($saveUndo) {
                    $undoList->addBlock($block->asVector3(), $block->getLevel()->getBlock($block->asVector3()));
                }
                if($saveRedo) {
                    $redoList->addBlock($block->asVector3(), $block->getLevel()->getBlock($block->asVector3()));
                }
                $block->getLevel()->setBlock($block->asVector3(), $block, false, false);
            }
            /** @var Canceller $canceller */
            $canceller = BuilderTools::getEditor(static::CANCELLER);
            $canceller->addStep($player, $undoList);
            return new EditorResult(count($blocks), microtime(true)-$startTime);
        }

        $iterator = new SubChunkIteratorManager($blockList->getLevel(), true);

        /** @var int $minX */
        $minX = null;
        /** @var int $maxX */
        $maxX = null;
        /** @var int $minZ */
        $minZ = null;
        /** @var int $maxZ */
        $maxZ = null;

        /**
         * @param Level $level
         * @param int $x1
         * @param int $z1
         * @param $x2
         * @param $z2
         */
        $reloadChunks = function (Level $level, int $x1, int $z1, int $x2, int $z2) {
            for($x = $x1 >> 4; $x <= $x2 >> 4; $x++) {
                for($z = $z1 >> 4; $z <= $z2 >> 4; $z++) {
                    $level->setChunk($x, $z, $level->getChunk($x, $z));
                }
            }
        };

        foreach ($blocks as $block) {
            // min and max positions
            if($minX === null || $block->getX() < $minX) $minX = $block->getX();
            if($minZ === null || $block->getZ() < $minZ) $minZ = $block->getZ();
            if($maxX === null || $block->getX() > $maxX) $maxX = $block->getX();
            if($maxZ === null || $block->getZ() > $maxZ) $maxZ = $block->getZ();

            $iterator->moveTo($block->getX(), $block->getY(), $block->getZ());
            $undoList->addBlock($block->asVector3(), Block::get($iterator->currentSubChunk->getBlockId($block->getX() & 0x0f, $block->getY() & 0x0f, $block->getZ() & 0x0f), $iterator->currentSubChunk->getBlockData($block->getX() & 0x0f, $block->getY() & 0x0f, $block->getZ() & 0x0f)));
            $iterator->currentSubChunk->setBlock($block->getX() & 0x0f, $block->getY() & 0x0f, $block->getZ() & 0x0f, $block->getId(), $block->getDamage());
        }

        $reloadChunks($blockList->getLevel(), $minX, $minZ, $maxX, $maxZ);

        if($saveUndo) {
            /** @var Canceller $canceller */
            $canceller = BuilderTools::getEditor(static::CANCELLER);
            $canceller->addStep($player, $undoList);
        }

        if($saveRedo) {
            /** @var Canceller $canceller */
            $canceller = BuilderTools::getEditor(static::CANCELLER);
            $canceller->addRedo($player, $redoList);
        }


        return new EditorResult(count($blocks), microtime(true)-$startTime);
    }



    public function getName(): string {
        return "Filler";
    }
}