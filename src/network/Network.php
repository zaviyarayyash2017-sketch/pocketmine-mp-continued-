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





<?php

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

    /** @var array Cache instantiated translator classes to save memory */
    private array $translatorCache = [];

    /**
     * Helper method to generate a unique connection key string.
     */
    private function makeConnectionId(string $address, int $port) : string {
        return $address . ":" . $port;
    }

    /**
     * Entry point where every raw network packet hits the server port directly.
     */
    public function processRawPacket(string $address, int $port, string $buffer) : void {
        // FIXED: Added missing $ to $this
        $connectionId = $this->makeConnectionId($address, $port);
        
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
                $unpacked = unpack("n", substr($buffer, 1, 2));
                // FIXED: Direct array verification to prevent safe array offsets from failing
                $discoveredProtocol = ($unpacked !== false && isset($unpacked[1])) ? $unpacked[1] : 20; 
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
                $decompressed = @gzinflate($payload);
            }

            if ($decompressed !== false && strlen($decompressed) > 5) {
                $innerPid = ord($decompressed);
                if ($innerPid === 0x01) { // Universal LoginPacket ID Header
                    $unpacked = unpack("N", substr($decompressed, 1, 4));
                    // FIXED: Clean validation checking index 1 for PHP unpack syntax rules
                    return ($unpacked !== false && isset($unpacked[1])) ? $unpacked[1] : 770;
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

        // --- ROUTING MATRIX BY HISTORICAL VERSIONS (OPTIMIZED WITH CACHE) ---
        
        if ($protocol <= 10) {
            // Milestone 1: Classic Alpha (0.6.1 / 0.7.4)
            if (!isset($this->translatorCache['alpha']) && class_exists('\pocketmine\network\translators\v0_7_4\AlphaTranslator')) {
                $this->translatorCache['alpha'] = new \pocketmine\network\translators\v0_7_4\AlphaTranslator();
            }
            if (isset($this->translatorCache['alpha'])) {
                $packet = $this->translatorCache['alpha']->translateInbound($buffer);
                if ($packet !== null) $session->handleEncodedPacket($packet);
            }
            
        } elseif ($protocol > 10 && $protocol <= 29) {
            // Milestone 2: Old PE (0.14.0)
            if (!isset($this->translatorCache['pe']) && class_exists('\pocketmine\network\translators\v0_14_0\PeTranslator')) {
                $this->translatorCache['pe'] = new \pocketmine\network\translators\v0_14_0\PeTranslator();
            }
            if (isset($this->translatorCache['pe'])) {
                $packet = $this->translatorCache['pe']->translateInbound($buffer);
                if ($packet !== null) $session->handleEncodedPacket($packet);
            }
            
        } elseif ($protocol >= 30 && $protocol <= 400) {
            // Milestone 3: Early Bedrock Engine (1.0 to 1.15)
            if (!isset($this->translatorCache['legacy_bedrock']) && class_exists('\pocketmine\network\translators\v1_15_0\LegacyBedrockTranslator')) {
                $this->translatorCache['legacy_bedrock'] = new \pocketmine\network\translators\v1_15_0\LegacyBedrockTranslator();
            }
            if (isset($this->translatorCache['legacy_bedrock'])) {
                $packet = $this->translatorCache['legacy_bedrock']->translateInbound($buffer);
                if ($packet !== null) $session->handleEncodedPacket($packet);
            }
            
        } elseif ($protocol > 400 && $protocol <= 550) {
            // Milestone 4: Mid-Modern Era (1.19 series)
            if (!isset($this->translatorCache['mid_modern']) && class_exists('\pocketmine\network\translators\v1_19_0\MidModernTranslator')) {
                $this->translatorCache['mid_modern'] = new \pocketmine\network\translators\v1_19_0\MidModernTranslator();
            }
            if (isset($this->translatorCache['mid_modern'])) {
                $packet = $this->translatorCache['mid_modern']->translateInbound($buffer);
                if ($packet !== null) $session->handleEncodedPacket($packet);
            }
            
        } elseif ($protocol > 550 && $protocol <= 700) {
            // Milestone 5: Modern Transition Era (1.21 series)
            if (!isset($this->translatorCache['transition']) && class_exists('\pocketmine\network\translators\v1_21_0\TransitionTranslator')) {
                $this->translatorCache['transition'] = new \pocketmine\network\translators\v1_21_0\TransitionTranslator();
            }
            if (isset($this->translatorCache['transition'])) {
                $packet = $this->translatorCache['transition']->translateInbound($buffer);
                if ($packet !== null) $session->handleEncodedPacket($packet);
            }
            
        } else {
            // Milestone 6: Native Engine Direct (1.26.x current branch)
            $session->handleEncodedPacket($buffer);
        }
    }

    public function getSessionByAddress(string $address, int $port) : ?NetworkSession {
        return $this->sessions[$this->makeConnectionId($address, $port)] ?? null;
    }

    public function registerSession(string $address, int $port, NetworkSession $session) : void {
        $this->sessions[$this->makeConnectionId($address, $port)] = $session;
    }

    public function unregisterSession(string $address, int $port) : void {
        $connectionId = $this->makeConnectionId($address, $port);
        unset($this->connectionProtocols[$connectionId]);
        unset($this->sessions[$connectionId]);
    }
}
