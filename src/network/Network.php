<?php

//
 //
 //  ____            _        _   __  __ _                  __  __ ____
 // |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 // | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 // |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 // |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 //
 // This program is free software: you can redistribute it and/or modify
 // it under the terms of the GNU Lesser General Public License as published by
// the Free Software





declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\network\mcpe\NetworkSession;
use pocketmine\utils\SingletonTrait;

class Network {
    use SingletonTrait;

    /** @var int[] Maps active connection identifiers (IP:Port) to their precise protocol numbers */
    private array $connectionProtocols = [];

    /** @var NetworkSession[] Track active player sessions across all historical versions */
    private array $sessions = [];

    private array $translatorCache = [];
    /**
     * Entry point where every raw network packet hits the server port directly.
     */
    public function processRawPacket(string $address, int $port, string $buffer) : void {
        $connectionId = this->makeConnectionId ($address, $port);
        
        if (strlen($buffer) < 1) {
            return;
        }

        $packetId = ord($buffer);

        // 1. Instant routing bypass if we already mapped this player's version number
        if (isset($this->connectionProtocols[$connectionId])) {
            $this->routeToPipeline($connectionId, $this->connectionProtocols[$connectionId], $address, $port, $buffer);
            return;
        }

        // =================================================================
        // DYNAMIC PROTOCOL DISCOVERY ENGINE
        // =================================================================
        $discoveredProtocol = null;

        // --- Era A: Ancient Alpha (0.6.1 - 0.8.1) ---
        if ($packetId === 0x05 || $packetId === 0x06 || $packetId === 0x09) {
            $discoveredProtocol = 7; // Classic Alpha Protocol Token
        }

        // --- Era B: Mid-Pocket Edition (0.9.0 - 0.14.3) ---
        elseif ($packetId === 0x84 || $packetId === 0x8f) {
            if (strlen($buffer) >= 5) {
                $discoveredProtocol = unpack("n", substr($buffer, 1, 2)) ?? 20; 
            } else {
                $discoveredProtocol = 20; 
            }
        }

        // --- Era C & D: Modern Compressed Eras (1.0 to 1.26.x+) ---
        elseif ($packetId === 0xfe) {
            // Read past the 0xfe wrapper to extract the exact internal protocol integer
            $discoveredProtocol = $this->peekCompressedProtocolVersion($buffer);
        }

        // =================================================================
        // REGISTER VERSION AND ROUTE
        // =================================================================
        if ($discoveredProtocol !== null) {
            $this->connectionProtocols[$connectionId] = $discoveredProtocol;
            \pocketmine\Server::getInstance()->getLogger()->info("[$connectionId] Identified Protocol ID #{$discoveredProtocol}. Activating target pipeline...");
            $this->routeToPipeline($connectionId, $discoveredProtocol, $address, $port, $buffer);
        } else {
            return; // Safety drop
        }
    }

    /**
     * Inspects a compressed modern packet payload to pull out the exact game version integer.
     */
    private function peekCompressedProtocolVersion(string $buffer) : int {
        try {
            $payload = substr($buffer, 1);
            
            // Attempt standard zlib decoding (works for 1.0 up through 1.20)
            $decompressed = @zlib_decode($payload);
            
            // If zlib fails, check for modern 1.21+ compressed variations
            if ($decompressed === false) {
                // Modern protocols utilize distinct compression headers or wrapper bytes depending on settings
                // We attempt a raw deflate stream check as a modern algorithm fallback
                $decompressed = @gzinflate($payload);
            }

            if ($decompressed !== false && strlen($decompressed) > 5) {
                $innerPid = ord($decompressed);
                if ($innerPid === 0x01) { // Universal LoginPacket ID Header
                    return unpack("N", substr($decompressed, 1, 4))[1] ?? 770;
                }
            }
        } catch (\Throwable $e) {
            // Fail safely without crashing the network thread
        }
        
        return 770; // Hard fallback default to the native version (v1.26.x protocol)
    }

    /**
     * Segregates traffic seamlessly into custom milestone translator pipelines.
     */
    private function routeToPipeline(string $id, int $protocol, string $address, int $port, string $buffer) : void {
        $session = $this->getSessionByAddress($address, $port);
        if ($session === null) return;

        // --- ROUTING MATRIX BY HISTORICAL VERSIONS ---
        
        if ($protocol <= 10) {
            // Milestone 1: Classic Alpha (0.6.1 / 0.7.4)
            $translator = new \pocketmine\network\translators\v0_7_4\AlphaTranslator();
            $packet = $translator->translateInbound($buffer);
            if ($packet !== null) $session->handleInboundPacket($packet);
            
        } elseif ($protocol > 10 && $protocol <= 29) {
            // Milestone 2: Old PE (0.14.0)
            $translator = new \pocketmine\network\translators\v0_14_0\PeTranslator();
            $packet = $translator->translateInbound($buffer);
            if ($packet !== null) $session->handleInboundPacket($packet);
            
        } elseif ($protocol >= 30 && $protocol <= 400) {
            // Milestone 3: Early Bedrock Engine (1.0 to 1.15)
            $translator = new \pocketmine\network\translators\v1_15_0\LegacyBedrockTranslator();
            $packet = $translator->translateInbound($buffer);
            if ($packet !== null) $session->handleInboundPacket($packet);
            
        } elseif ($protocol > 400 && $protocol <= 550) {
            // Milestone 4: Mid-Modern Era (1.19 series)
            // 1.19 completely refactored block states and item tags compared to modern 1.26
            $translator = new \pocketmine\network\translators\v1_19_0\MidModernTranslator();
            $packet = $translator->translateInbound($buffer);
            if ($packet !== null) $session->handleInboundPacket($packet);
            
        } elseif ($protocol > 550 && $protocol <= 700) {
            // Milestone 5: Modern Transition Era (1.21 series)
            // 1.21 adjusted networking rules and introduced heavy protocol cryptographic changes
            $translator = new \pocketmine\network\translators\v1_21_0\TransitionTranslator();
            $packet = $translator->translateInbound($buffer);
            if ($packet !== null) $session->handleInboundPacket($packet);
            
        } else {
            // Milestone 6: Native Engine Direct (1.26.x current branch)
            // Passes directly to your core source folder with no translation overhead
            $session->handleEncodedPacket($buffer);
        }
    }

    public function getSessionByAddress(string $address, int $port) : ?NetworkSession {
        return $this->sessions[$address . ":" . $port] ?? null;
    }

    public function registerSession(string $address, int $port, NetworkSession $session) : void {
        $this->sessions[$address . ":" . $port] = $session;
    }

    public function unregisterSession(string $address, int $port) : void {
        $connectionId = $address . ":" . $port;
        unset($this->connectionProtocols[$connectionId]);
        unset($this->sessions[$connectionId]);
    }
}
