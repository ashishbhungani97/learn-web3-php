# get_event_logs.php

A lightweight PHP script to fetch and decode on-chain events from any EVM-compatible smart contract using the `Web3Service` wrapper built on top of [`sc0vu/web3.php`](https://github.com/sc0vu/web3.php).

---

## Overview

This script connects to a BSC Testnet (or any EVM RPC), attaches a deployed contract, and fetches decoded event logs between a range of block numbers — making it easy to sync on-chain events (like `Transfer`) into your database incrementally.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | >= 7.4 |
| [`sc0vu/web3.php`](https://packagist.org/packages/sc0vu/web3.php) | latest |
| [`kornrunner/keccak`](https://packagist.org/packages/kornrunner/keccak) | latest |
| [`elliptic-php/elliptic`](https://packagist.org/packages/elliptic/elliptic) | latest |
| [`web3p/ethereum-tx`](https://packagist.org/packages/web3p/ethereum-tx) | latest |
| bcmath PHP extension | any |

Install via Composer:

```bash
composer require sc0vu/web3.php kornrunner/keccak elliptic/elliptic web3p/ethereum-tx --ignore-platform-reqs
```

---

## Setup

### 1. Patch `Contract.php`

The `getEventLogs()` method is **not included** in the default `sc0vu/web3.php` package. You must manually add it to:

```
vendor/sc0vu/web3.php/src/Contract.php
```

Append the following method inside the `Contract` class:

```php
/**
 * getEventLogs
 *
 * @param string $eventName
 * @param string|int $fromBlock
 * @param string|int $toBlock
 * @return array
 */
public function getEventLogs(string $eventName, $fromBlock = 'latest', $toBlock = 'latest')
{
    if ($fromBlock !== 'latest') {
        if (!is_int($fromBlock) || $fromBlock < 1) {
            throw new InvalidArgumentException('Please make sure fromBlock is a valid block number');
        } else if ($toBlock !== 'latest' && $fromBlock > $toBlock) {
            throw new InvalidArgumentException('Please make sure fromBlock is equal or less than toBlock');
        }
    }

    if ($toBlock !== 'latest') {
        if (!is_int($toBlock) || $toBlock < 1) {
            throw new InvalidArgumentException('Please make sure toBlock is a valid block number');
        } else if ($fromBlock === 'latest') {
            throw new InvalidArgumentException('Please make sure toBlock is equal or greater than fromBlock');
        }
    }

    $eventLogData = [];

    if (!array_key_exists($eventName, $this->events)) {
        throw new InvalidArgumentException("'{$eventName}' does not exist in the ABI for this contract");
    }

    $eventParameterNames        = [];
    $eventParameterTypes        = [];
    $eventIndexedParameterNames = [];
    $eventIndexedParameterTypes = [];

    foreach ($this->events[$eventName]['inputs'] as $input) {
        if ($input['indexed']) {
            $eventIndexedParameterNames[] = $input['name'];
            $eventIndexedParameterTypes[] = $input['type'];
        } else {
            $eventParameterNames[] = $input['name'];
            $eventParameterTypes[] = $input['type'];
        }
    }

    $this->eth->getLogs([
        'fromBlock' => (is_int($fromBlock)) ? '0x' . dechex($fromBlock) : $fromBlock,
        'toBlock'   => (is_int($toBlock))   ? '0x' . dechex($toBlock)   : $toBlock,
        'topics'    => [$this->ethabi->encodeEventSignature($this->events[$eventName])],
        'address'   => $this->toAddress
    ],
    function ($error, $result) use (&$eventLogData, $eventParameterTypes, $eventParameterNames, $eventIndexedParameterTypes, $eventIndexedParameterNames) {
        if ($error !== null) {
            throw new InvalidArgumentException($error->getMessage());
        }

        $numEventIndexedParameterNames = count($eventIndexedParameterNames);

        foreach ($result as $object) {
            $decodedData = array_combine(
                $eventParameterNames,
                $this->ethabi->decodeParameters($eventParameterTypes, $object->data)
            );

            for ($i = 0; $i < $numEventIndexedParameterNames; $i++) {
                $decodedData[$eventIndexedParameterNames[$i]] = $this->ethabi->decodeParameters(
                    [$eventIndexedParameterTypes[$i]],
                    $object->topics[$i + 1]
                )[0];
            }

            $eventLogData[] = [
                'transactionHash' => $object->transactionHash,
                'blockHash'       => $object->blockHash,
                'blockNumber'     => hexdec($object->blockNumber),
                'data'            => $decodedData
            ];
        }
    });

    return $eventLogData;
}
```

> **Note:** Since this modifies a vendor file, it will be overwritten by `composer update`. Consider forking the package or using a [Composer patch plugin](https://github.com/cweagans/composer-patches) to persist this change.

---

### 2. Usage

```php
<?php

use Web3Service;

$rpc              = "https://data-seed-prebsc-1-s2.bnbchain.org:8545"; // BSC Testnet RPC
$contract_address = "0xeD24FC36d5Ee211Ea25A80239Fb8C4Cfd80f12Ee";       // BUSD token address
$abi              = []; // Place your contract ABI array here

$web3service = new Web3Service($rpc);

$web3service->connectContract($contract_address, $abi);

$blocksToProcess = 200;        // Max block range per batch
$lastSynced      = 61545556;   // Last block synced (from DB or contract deploy block)
$currentBlock    = (int) $web3service->getBlockNumber();
$fromBlock       = max(0, $lastSynced + 1);

if ($fromBlock > $currentBlock) {
    return json_encode(['success' => true, 'message' => 'Already up to date.']);
}

$toBlock = min($fromBlock + $blocksToProcess - 1, $currentBlock);

// Fetch decoded Transfer events
$events = $web3service->fetchEvent('Transfer', $fromBlock, $toBlock);

return json_encode(['success' => true, 'data' => $events]);
```

---

## How It Works

```
lastSynced block + 1
        │
        ▼
┌───────────────────┐
│  fromBlock        │──────────────────────────────────────────────────┐
│  toBlock          │  = min(fromBlock + blocksToProcess - 1, current) │
└───────────────────┘                                                  │
        │                                                              │
        ▼                                                              │
  fetchEvent('Transfer', fromBlock, toBlock)                           │
        │                                                              │
        ▼                                                              │
  Contract::getEventLogs()                                             │
     - encodes event signature topic                                   │
     - calls eth_getLogs via RPC                                       │
     - decodes indexed + non-indexed parameters                        │
        │                                                              │
        ▼                                                              │
  Returns array of:                                                    │
  { transactionHash, blockHash, blockNumber, data{} }                  │
        │                                                              │
        ▼                                                              │
  Save $toBlock as new lastSynced ◄────────────────────────────────────┘
```

---

## Response Format

Each event in the returned array has this shape:

```json
{
  "transactionHash": "0xabc123...",
  "blockHash": "0xdef456...",
  "blockNumber": 61545700,
  "data": {
    "from": "0xSenderAddress",
    "to": "0xReceiverAddress",
    "value": "1000000000000000000"
  }
}
```

---

## Configuration Reference

| Variable | Description |
|---|---|
| `$rpc` | RPC endpoint URL of the target EVM network |
| `$contract_address` | Deployed contract address to listen on |
| `$abi` | ABI array for the contract (must include event definitions) |
| `$blocksToProcess` | Max number of blocks to scan per run (keep ≤ 1000 for public RPCs) |
| `$lastSynced` | Last block number already processed — update this in your DB after each run |

---

## Notes

- The `getEventLogs()` patch separates **indexed** and **non-indexed** parameters automatically per the ABI. Indexed parameters are decoded from `topics[]`; non-indexed from `data`.
- Keep `$blocksToProcess` small (200–500) when using public RPC endpoints to avoid hitting rate limits or response size limits.
- Always persist `$toBlock` as the new `lastSynced` value in your database after a successful run to enable incremental syncing.
- The script is stateless — scheduling it via cron or a queue worker is recommended for continuous syncing.

---

## License

MIT