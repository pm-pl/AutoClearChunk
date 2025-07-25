<?php

/*
 * Copyright (c) 2021-2025 HazardTeam
 *
 * For the full copyright and license information, please view
 * the LICENSE.md file that was distributed with this source code.
 *
 * @see https://github.com/HazardTeam/AutoClearChunk
 */

declare(strict_types=1);

namespace hazardteam\autoclearchunk;

use hazardteam\autoclearchunk\commands\ClearAllChunkCommand;
use hazardteam\autoclearchunk\commands\ClearChunkCommand;
use JackMD\UpdateNotifier\UpdateNotifier;
use pocketmine\event\Listener;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use function array_keys;
use function class_exists;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match_all;
use function sprintf;
use function time;
use function trim;
use const PREG_SET_ORDER;

class AutoClearChunk extends PluginBase implements Listener {
	private bool $enableAutoSchedule;
	private int $clearInterval;
	private string $clearChunkMessage;
	private string $clearChunkBroadcastMessage;
	private string $clearAllChunkMessage;
	private string $clearAllChunkBroadcastMessage;
	private bool $enableBroadcast;
	/** @var array<string> */
	private array $blacklistedWorlds;

	private int $chunkUnloadGracePeriod;

	/** @var array<string> */
	private array $worlds = [];

	/** @var array<string, array<int, int>> Stores [worldName => [chunkHash => timestamp]] for chunks pending unload */
	private array $chunksPendingUnload = [];

	public function onEnable() : void {
		if (!class_exists(UpdateNotifier::class)) {
			$this->getLogger()->error("The 'UpdateNotifier' virion is missing. Please download it from the plugin's page on Poggit: https://poggit.pmmp.io/p/AutoClearChunk");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		try {
			$this->loadConfig();
		} catch (\InvalidArgumentException $e) {
			$this->getLogger()->error('Error loading plugin configuration: ' . $e->getMessage());
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$worldsDirectory = new \DirectoryIterator($this->getServer()->getDataPath() . 'worlds');
		foreach ($worldsDirectory as $fileInfo) {
			if (!$fileInfo->isDot() && $fileInfo->isDir()) {
				$worldName = $fileInfo->getFilename();
				if (!in_array($worldName, $this->getBlacklistedWorlds(), true)) {
					$this->worlds[] = $worldName;
				}
			}
		}

		if ($this->enableAutoSchedule) {
			$this->scheduleAutoClearTask();
		}

		$this->getScheduler()->scheduleRepeatingTask(
			new ClosureTask(fn () => $this->processChunksPendingUnload()),
			20 * 5
		);

		$commandMap = $this->getServer()->getCommandMap();
		$commandMap->registerAll('AutoClearChunk', [
			new ClearChunkCommand($this),
			new ClearAllChunkCommand($this),
		]);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		UpdateNotifier::checkUpdate($this->getDescription()->getName(), $this->getDescription()->getVersion());
	}

	/**
	 * Loads and validates the plugin configuration from the `config.yml` file.
	 * If the configuration is invalid, an exception will be thrown.
	 *
	 * @throws \InvalidArgumentException when the configuration is invalid
	 */
	private function loadConfig() : void {
		$this->saveDefaultConfig();

		$config = $this->getConfig();

		// Validate enable-auto-schedule option
		$enableAutoSchedule = $config->get('enable-auto-schedule');
		if (!is_bool($enableAutoSchedule)) {
			throw new \InvalidArgumentException("Config error: 'enable-auto-schedule' must be a boolean value");
		}

		$this->enableAutoSchedule = $enableAutoSchedule;

		// Validate clear-interval-duration option
		$clearIntervalDuration = $config->get('clear-interval-duration');
		if (!is_string($clearIntervalDuration) || trim($clearIntervalDuration) === '') {
			throw new \InvalidArgumentException("Config error: 'clear-interval-duration' must be a non-empty string");
		}

		$clearInterval = $this->parseDuration($clearIntervalDuration);
		if ($clearInterval === false) {
			throw new \InvalidArgumentException("Config error: 'clear-interval-duration' has an invalid format");
		}

		$this->clearInterval = $clearInterval;

		// Validate chunk-unload-grace-period-duration option
		$chunkUnloadGracePeriodDuration = $config->get('chunk-unload-grace-period-duration');
		if (!is_string($chunkUnloadGracePeriodDuration) || trim($chunkUnloadGracePeriodDuration) === '') {
			throw new \InvalidArgumentException("Config error: 'chunk-unload-grace-period-duration' must be a non-empty string");
		}

		$chunkUnloadGracePeriod = $this->parseDuration($chunkUnloadGracePeriodDuration);
		if ($chunkUnloadGracePeriod === false) {
			throw new \InvalidArgumentException("Config error: 'chunk-unload-grace-period-duration' has an invalid format");
		}

		$this->chunkUnloadGracePeriod = $chunkUnloadGracePeriod;

		// Validate clearchunk-message option
		$clearChunkMessage = $config->get('clearchunk-message');
		if (!is_string($clearChunkMessage) || trim($clearChunkMessage) === '') {
			throw new \InvalidArgumentException("Config error: 'clearchunk-message' must be a non-empty string");
		}

		$this->clearChunkMessage = $clearChunkMessage;

		// Validate clearchunk-broadcast-message option
		$clearChunkBroadcastMessage = $config->get('clearchunk-broadcast-message');
		if (!is_string($clearChunkBroadcastMessage) || trim($clearChunkBroadcastMessage) === '') {
			throw new \InvalidArgumentException("Config error: 'clearchunk-broadcast-message' must be a non-empty string");
		}

		$this->clearChunkBroadcastMessage = $clearChunkBroadcastMessage;

		// Validate clearallchunk-message option
		$clearAllChunkMessage = $config->get('clearallchunk-message');
		if (!is_string($clearAllChunkMessage) || trim($clearAllChunkMessage) === '') {
			throw new \InvalidArgumentException("Config error: 'clearallchunk-message' must be a non-empty string");
		}

		$this->clearAllChunkMessage = $clearAllChunkMessage;

		// Validate clearallchunk-broadcast-message option
		$clearAllChunkBroadcastMessage = $config->get('clearallchunk-broadcast-message');
		if (!is_string($clearAllChunkBroadcastMessage) || trim($clearAllChunkBroadcastMessage) === '') {
			throw new \InvalidArgumentException("Config error: 'clearallchunk-broadcast-message' must be a non-empty string");
		}

		$this->clearAllChunkBroadcastMessage = $clearAllChunkBroadcastMessage;

		// Validate broadcast-message option
		$enableBroadcast = $config->get('broadcast-message');
		if (!is_bool($enableBroadcast)) {
			throw new \InvalidArgumentException("Config error: 'broadcast-message' must be a boolean");
		}

		$this->enableBroadcast = $enableBroadcast;

		// Validate blacklisted-worlds option
		$blacklistedWorlds = $config->get('blacklisted-worlds');
		if (!is_array($blacklistedWorlds)) {
			throw new \InvalidArgumentException("Config error: 'blacklisted-worlds' must be an array");
		}

		foreach ($blacklistedWorlds as $world) {
			if (!is_string($world) || trim($world) === '') {
				throw new \InvalidArgumentException("Config error: 'blacklisted-worlds' must contain non-empty strings");
			}
		}

		$this->blacklistedWorlds = $blacklistedWorlds;
	}

	/**
	 * Parses a duration string and converts it to seconds.
	 *
	 * The duration string should be in the format of "1h30m" (1 hour and 30 minutes).
	 *
	 * @param string $duration the duration string to parse
	 *
	 * @return false|int the duration in seconds, or false if the format is invalid
	 */
	private function parseDuration(string $duration) : false|int {
		$matches = [];
		preg_match_all('/(\d+)([hms])/', $duration, $matches, PREG_SET_ORDER);

		$totalSeconds = 0;
		foreach ($matches as $match) {
			$value = (int) $match[1];
			$unit = $match[2];

			if ($unit === 'h') {
				$totalSeconds += $value * 3600;
			} elseif ($unit === 'm') {
				$totalSeconds += $value * 60;
			} elseif ($unit === 's') {
				$totalSeconds += $value;
			}
		}

		return $totalSeconds > 0 ? $totalSeconds : false;
	}

	/**
	 * Schedules the automatic clearing task for chunks in the configured worlds.
	 * The task will run at the specified interval and call the `clearAllChunk()` method.
	 */
	private function scheduleAutoClearTask() : void {
		$this->getScheduler()->scheduleDelayedRepeatingTask(
			new ClosureTask(fn () => $this->clearAllChunk(function (int $identifiedCount) : void {
				if ($this->isBroadcastEnabled()) {
					$broadcastMessage = sprintf(
						TextFormat::colorize($this->getClearAllChunkBroadcastMessage()),
						$identifiedCount
					);
					$this->getServer()->broadcastMessage($broadcastMessage);
				}
			})),
			20 * $this->clearInterval,
			20 * $this->clearInterval
		);
	}

	/**
	 * Processes chunks that are pending unload, checking if their grace period has expired.
	 * This is the core logic for actually unloading chunks after their grace period.
	 */
	private function processChunksPendingUnload() : void {
		$currentTime = time();
		$worldsToClean = [];

		foreach (array_keys($this->chunksPendingUnload) as $worldName) {
			$world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
			if ($world === null) {
				// World no longer loaded, remove its entries from pending unload
				unset($this->chunksPendingUnload[$worldName]);
				continue;
			}

			$chunksToUnload = [];
			$chunksToKeep = [];

			// Iterate over a copy of the inner array to safely modify it
			foreach ($this->chunksPendingUnload[$worldName] as $chunkHash => $timestamp) {
				World::getXZ($chunkHash, $chunkX, $chunkZ);

				// Check if chunk has players again, or if it's already unloaded by another process
				// If players re-entered or chunk is already gone, don't unload it.
				if (count($world->getChunkPlayers($chunkX, $chunkZ)) > 0 || !$world->isChunkLoaded($chunkX, $chunkZ)) {
					// This chunk is no longer a candidate for unload
					$chunksToKeep[$chunkHash] = $timestamp;
					continue;
				}

				if ($currentTime - $timestamp >= $this->chunkUnloadGracePeriod) {
					// Grace period expired, mark for unload
					$chunksToUnload[] = ['world' => $world, 'chunkX' => $chunkX, 'chunkZ' => $chunkZ];
				} else {
					// Grace period not expired yet, keep it in the list
					$chunksToKeep[$chunkHash] = $timestamp;
				}
			}

			// Perform actual unloads (this must happen on the main thread)
			foreach ($chunksToUnload as $chunkData) {
				$chunkData['world']->unloadChunk($chunkData['chunkX'], $chunkData['chunkZ']);
			}

			// Update the chunksPendingUnload for this world with only those that remain
			$this->chunksPendingUnload[$worldName] = $chunksToKeep;

			// If after processing, the world's pending list is empty, mark it for removal from the main list
			if (count($this->chunksPendingUnload[$worldName]) === 0) {
				$worldsToClean[] = $worldName;
			}
		}

		// Clean up worlds that no longer have pending chunks
		foreach ($worldsToClean as $worldName) {
			unset($this->chunksPendingUnload[$worldName]);
		}
	}

	public function onWorldLoad(WorldLoadEvent $event) : void {
		$worldName = $event->getWorld()->getFolderName();

		if (!in_array($worldName, $this->getBlacklistedWorlds(), true)) {
			$this->worlds[] = $worldName;
		}
	}

	/**
	 * Clears all chunks in the configured worlds.
	 * This method identifies chunks to be unloaded and adds them to the pending unload list.
	 * The actual unloading happens in `processChunksPendingUnload()`.
	 *
	 * @param callable|null $callback optional callback function to be executed after identifying chunks to clear
	 */
	public function clearAllChunk(?callable $callback = null) : void {
		$identifiedCount = 0;

		foreach ($this->worlds as $worldName) {
			$world = $this->getServer()->getWorldManager()->getWorldByName($worldName);

			if ($world !== null) {
				// Initialize chunk pending unload array for this world if not exists
				if (!isset($this->chunksPendingUnload[$worldName])) {
					$this->chunksPendingUnload[$worldName] = [];
				}

				foreach ($world->getLoadedChunks() as $chunkHash => $chunk) {
					World::getXZ($chunkHash, $chunkX, $chunkZ);
					if (count($world->getChunkPlayers($chunkX, $chunkZ)) === 0) {
						// Add to pending unload list with current timestamp
						// Only add if not already in the pending list to avoid resetting grace period.
						if (!isset($this->chunksPendingUnload[$worldName][$chunkHash])) {
							$this->chunksPendingUnload[$worldName][$chunkHash] = time();
							++$identifiedCount;
						}
					}
				}
			}
		}

		if ($callback !== null) {
			$callback($identifiedCount);
		}
	}

	/**
	 * Clears chunks in a specific world.
	 * This method identifies chunks to be unloaded and adds them to the pending unload list.
	 * The actual unloading happens in `processChunksPendingUnload()`.
	 *
	 * @param string|World  $world    the world object or the name of the world to clear chunks from
	 * @param callable|null $callback optional callback function to be executed after identifying chunks to clear
	 *
	 * @return bool true if chunks were identified, false otherwise (e.g., world not found)
	 */
	public function clearChunk(string|World $world, ?callable $callback = null) : bool {
		$identifiedCount = 0;

		if (is_string($world)) {
			$world = $this->getServer()->getWorldManager()->getWorldByName($world);
			if ($world === null) {
				if ($callback !== null) {
					$callback(0);
				}

				return false;
			}
		}

		$worldName = $world->getFolderName();
		if (!isset($this->chunksPendingUnload[$worldName])) {
			$this->chunksPendingUnload[$worldName] = [];
		}

		foreach ($world->getLoadedChunks() as $chunkHash => $chunk) {
			World::getXZ($chunkHash, $chunkX, $chunkZ);
			if (count($world->getChunkPlayers($chunkX, $chunkZ)) === 0) {
				// Add to pending unload list with current timestamp
				// Only add if not already in the pending list to avoid resetting grace period.
				if (!isset($this->chunksPendingUnload[$worldName][$chunkHash])) {
					$this->chunksPendingUnload[$worldName][$chunkHash] = time();
					++$identifiedCount;
				}
			}
		}

		if ($callback !== null) {
			$callback($identifiedCount);
		}

		return true;
	}

	/**
	 * Returns the configured clear chunk message.
	 *
	 * @return string the clear chunk message
	 */
	public function getClearChunkMessage() : string {
		return $this->clearChunkMessage;
	}

	/**
	 * Returns the configured clear chunk broadcast message.
	 *
	 * @return string the clear chunk broadcast message
	 */
	public function getClearChunkBroadcastMessage() : string {
		return $this->clearChunkBroadcastMessage;
	}

	/**
	 * Returns the configured clear all chunk message.
	 *
	 * @return string the clear all chunk message
	 */
	public function getClearAllChunkMessage() : string {
		return $this->clearAllChunkMessage;
	}

	/**
	 * Returns the configured clear all chunk broadcast message.
	 *
	 * @return string the clear all chunk broadcast message
	 */
	public function getClearAllChunkBroadcastMessage() : string {
		return $this->clearAllChunkBroadcastMessage;
	}

	/**
	 * Returns the configured boolean broadcast message.
	 */
	public function isBroadcastEnabled() : bool {
		return $this->enableBroadcast;
	}

	/**
	 * Returns the array of blacklisted worlds.
	 *
	 * @return array<string> the blacklisted worlds
	 */
	public function getBlacklistedWorlds() : array {
		return $this->blacklistedWorlds;
	}
}
