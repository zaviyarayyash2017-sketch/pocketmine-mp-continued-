<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software
<?php

declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\translators\v0_7_4\AlphaTranslator;
use pocketmine\utils\SingletonTrait;

class Network {
    use SingletonTrait;

    /** @var int[] Maps active connection identifiers (IP:Port) to their determined protocol versions */
    private array $connectionProtocols = [];

    /** @var NetworkSession[] Track sessions manually for multi-version routing overrides */
    private array $sessions = [];

    /**
     * This is the main entry point where EVERY raw network packet lands 
     * directly from the internet socket interface.
     */
    public function processRawPacket(string $address, int $port, string $buffer) : void {
        // Create a unique key for this specific player connection
        $connectionId = $address . ":" . $port;
        
        // Safety check: Avoid crashing if empty data hits the port
        if (strlen($buffer) < 1) {
            return;
        }

        // Read the absolute first byte (The Packet ID Header)
        $packetId = ord($buffer);

        // =================================================================
        // PHASE 1: ROUTING NEW OR EXISTING ALPHA CLIENTS (v0.6.1 - v0.7.4)
        // =================================================================
        
        // Old alpha clients initiated connections with raw UDP bytes like 0x05, 0x06, or 0x09.
        // They completely lack modern RakNet packet encapsulation headers.
        if ($packetId === 0x05 || $packetId === 0x06 || $packetId === 0x09 || 
            (isset($this->connectionProtocols[$connectionId]) && $this->connectionProtocols[$connectionId] < 20)) {
            
            // If it's a brand new alpha connection, log and track it
            if (!isset($this->connectionProtocols[$connectionId])) {
                // Protocol 7 represents early MCPE Alpha editions
                $this->connectionProtocols[$connectionId] = 7; 
                \pocketmine\Server::getInstance()->getLogger()->info("[$connectionId] Routing to Legacy Alpha Pipeline...");
            }

            // Route to your custom Alpha translation class
            $alphaPipeline = new AlphaTranslator();
            $modernPacket = $alphaPipeline->translateInbound($buffer);

            if ($modernPacket !== null) {
                // Find or create their session and pass the forged modern packet safely to the engine
                $session = $this->getSessionByAddress($address, $port);
                if ($session !== null) {
                    $session->handleInboundPacket($modernPacket);
                }
            }
            return; // HALT. Do not let modern PocketMine code process this raw buffer.
        }

        // =================================================================
        // PHASE 2: ROUTING MODERN CLIENTS (v1.26.30+)
        // =================================================================
        
        // Modern Bedrock packets ALWAYS wrap game data inside a compressed batch packet (0xfe)
        if ($packetId === 0xfe) {
            if (!isset($this->connectionProtocols[$connectionId])) {
                // Track this connection as a modern client version
                $this->connectionProtocols[$connectionId] = 770; // Modern protocol number for 1.26.x
                \pocketmine\Server::getInstance()->getLogger()->info("[$connectionId] Routing to Modern v1.26.30 Pipeline...");
            }

            // Allow the modern, vanilla PocketMine logic to proceed safely
            $session = $this->getSessionByAddress($address, $port);
            if ($session !== null) {
                // Pass directly to vanilla network processor
                $session->handleEncodedPacket($buffer);
            }
            return;
        }

        // =================================================================
        // PHASE 3: UNKNOWN PROTOCOLS (CATCH-ALL DROPS)
        // =================================================================
        return;
    }

    /**
     * Resolves a NetworkSession for a specific address and port
     */
    public function getSessionByAddress(string $address, int $port) : ?NetworkSession {
        $connectionId = $address . ":" . $port;
        if (!isset($this->sessions[$connectionId])) {
            return null;
        }
        return $this->sessions[$connectionId];
    }

    /**
     * Registers a session when a connection initializes
     */
    public function registerSession(string $address, int $port, NetworkSession $session) : void {
        $connectionId = $address . ":" . $port;
        $this->sessions[$connectionId] = $session;
    }

    /**
     * Clears tracked protocols and sessions when a player disconnects
     */
    public function unregisterSession(string $address, int $port) : void {
        $connectionId = $address . ":" . $port;
        unset($this->connectionProtocols[$connectionId]);
        unset($this->sessions[$connectionId]);
    }
}