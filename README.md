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

> **`--ignore-platform-reqs`** skips PHP version and extension checks during install. Useful when your local PHP version doesn't exactly match package constraints, or when installing in CI/Docker environments where some extensions (like `gmp`) may not be pre-loaded.

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

## Web3Service — Method Reference

All methods are available after initialising `Web3Service`. Methods marked with 🔌 additionally require `connectContract()` to be called first.

---

### Initialisation

#### `new Web3Service($rpcUrl, $timeout = 10)`

Creates a new Web3 connection to the given RPC endpoint.

```php
$web3service = new Web3Service(
    "https://data-seed-prebsc-1-s2.bnbchain.org:8545", // RPC URL
    10                                                  // timeout in seconds (default: 10)
);
```

---

#### `connectContract($contractAddress, $abi)`

Attaches a deployed contract to the service instance. Must be called before any contract-level method. Returns `$this`, so it supports chaining.

```php
$abi = json_decode(file_get_contents('abi.json'), true);

$web3service->connectContract(
    "0xeD24FC36d5Ee211Ea25A80239Fb8C4Cfd80f12Ee", // contract address
    $abi                                           // ABI array
);

// or chained on construction:
$web3service = (new Web3Service($rpc))->connectContract($address, $abi);
```

---

### Chain / Block Methods

#### `getBlockNumber()`

Returns the latest block number on the connected chain as a string. Cast to `int` before arithmetic.

```php
$block = (int) $web3service->getBlockNumber();
// e.g. 61545700
```

---

### Balance Methods

#### `getEthBalance(string $address)`

Returns the native coin balance (ETH / BNB / MATIC etc.) as a human-readable decimal string (divided by `10^18`).

```php
$balance = $web3service->getEthBalance("0xYourWalletAddress");
// e.g. "1.250000000000000000"
```

---

#### `getEthBalanceWei(string $address)`

Returns the raw native coin balance in Wei as a string. Use this when you need to do further BigNumber arithmetic without losing precision.

```php
$wei = $web3service->getEthBalanceWei("0xYourWalletAddress");
// e.g. "1250000000000000000"
```

---

#### `getErc20Balance(string $walletAddress)` 🔌

Returns the ERC-20 token balance as a human-readable decimal (divided by the token's own `decimals`). Requires `connectContract()`.

```php
$web3service->connectContract($tokenAddress, $abi);

$balance = $web3service->getErc20Balance("0xYourWalletAddress");
// e.g. "250.000000000000000000"
```

---

#### `getErc20BalanceWei(string $walletAddress)` 🔌

Returns the raw ERC-20 token balance in the token's smallest unit. Requires `connectContract()`.

```php
$rawBalance = $web3service->getErc20BalanceWei("0xYourWalletAddress");
// e.g. "250000000000000000000"
```

---

### Contract Read Methods

#### `getTokenDecimals()` 🔌

Calls the `decimals()` view function on the connected ERC-20 contract.

```php
$decimals = $web3service->getTokenDecimals();
// e.g. 18
```

---

#### `getErc20Metadata()` 🔌

Fetches `name`, `symbol`, and `decimals` from the connected ERC-20 contract in a single grouped call. Returns `null` if any field fails.

```php
$meta = $web3service->getErc20Metadata();
// [
//   'name'     => 'Binance USD',
//   'symbol'   => 'BUSD',
//   'decimals' => 18,
// ]
```

---

#### `getUserNonce(string $walletAddress)` 🔌

Calls the `nextNonce(address)` view function on the connected contract. This is an application-level nonce tracked by the contract itself — not the standard ETH transaction nonce.

```php
$nonce = $web3service->getUserNonce("0xYourWalletAddress");
```

---

### Transaction Methods

#### `getTransactionStatus(string $tx_hash)`

Fetches the transaction receipt for a given hash. Returns the receipt object on success, or `['error' => 'Transaction receipt not found!']` if the tx is still pending or doesn't exist.

```php
$receipt = $web3service->getTransactionStatus("0xabc123def456...");

// status: 1 = success, 0 = reverted
echo $receipt->status;
```

---

### Address Utility Methods

#### `isAddress(string $address)`

Returns `true` if the string is a valid Ethereum address. Handles lowercase, uppercase, and EIP-55 checksum formats.

```php
$web3service->isAddress("0xeD24FC36d5Ee211Ea25A80239Fb8C4Cfd80f12Ee"); // true
$web3service->isAddress("not-an-address");                               // false
```

---

#### `isContract(string $address)`

Returns `true` if the given address has deployed bytecode (i.e. is a smart contract rather than a plain EOA wallet).

```php
$web3service->isContract("0xeD24FC36d5Ee211Ea25A80239Fb8C4Cfd80f12Ee"); // true
$web3service->isContract("0xYourPersonalWalletAddress");                 // false
```

---

### Event Methods

#### `fetchEvent(string $eventName, int $fromBlock, int $toBlock)` 🔌

Fetches and decodes all on-chain events matching `$eventName` between the given block range. Requires `connectContract()`. Internally delegates to `Contract::getEventLogs()`.

```php
$events = $web3service->fetchEvent('Transfer', 61545556, 61545756);
```

Returns an array of decoded event objects — see [Response Format](#response-format) below.

---

### Quick Reference Table

| Method | Needs contract? | Returns |
|---|---|---|
| `getBlockNumber()` | No | `string` (block number) |
| `getEthBalance($addr)` | No | `string` (decimal ETH) |
| `getEthBalanceWei($addr)` | No | `string` (wei) |
| `getErc20Balance($addr)` | Yes 🔌 | `string` (decimal tokens) |
| `getErc20BalanceWei($addr)` | Yes 🔌 | `string` (raw units) |
| `getTokenDecimals()` | Yes 🔌 | `int` |
| `getErc20Metadata()` | Yes 🔌 | `array` or `null` |
| `getUserNonce($addr)` | Yes 🔌 | `array` or `null` |
| `getTransactionStatus($hash)` | No | receipt object or `array` |
| `isAddress($addr)` | No | `bool` |
| `isContract($addr)` | No | `bool` |
| `fetchEvent($name, $from, $to)` | Yes 🔌 | `array` of decoded events |
| `verifySignature($message, $sig)` | No | `string` (recovered address) |

---

## Full Usage Example

```php
<?php

use Web3Service;

$rpc = "https://data-seed-prebsc-1-s2.bnbchain.org:8545"; // BSC Testnet RPC
$abi = json_decode(file_get_contents('abi.json'), true);   // your contract ABI

// 1. Initialise
$web3service = new Web3Service($rpc);

// 2. Chain info — no contract needed
$currentBlock = (int) $web3service->getBlockNumber();
$ethBalance   = $web3service->getEthBalance("0xYourWalletAddress");

echo "Current block : {$currentBlock}\n";
echo "ETH balance   : {$ethBalance} ETH\n";

// 3. Connect contract
$web3service->connectContract(
    "0xeD24FC36d5Ee211Ea25A80239Fb8C4Cfd80f12Ee",
    $abi
);

// 4. Token info
$meta     = $web3service->getErc20Metadata();
$tokenBal = $web3service->getErc20Balance("0xYourWalletAddress");

echo "Token   : {$meta['symbol']} ({$meta['name']})\n";
echo "Balance : {$tokenBal} {$meta['symbol']}\n";

// 5. Address checks
$addr = "0xeD24FC36d5Ee211Ea25A80239Fb8C4Cfd80f12Ee";
echo "Valid address : " . ($web3service->isAddress($addr) ? 'yes' : 'no') . "\n";
echo "Is contract   : " . ($web3service->isContract($addr) ? 'yes' : 'no') . "\n";

// 6. Fetch events in batches
$blocksToProcess = 200;
$lastSynced      = 61545556;
$fromBlock       = max(0, $lastSynced + 1);

if ($fromBlock > $currentBlock) {
    return json_encode(['success' => true, 'message' => 'Already up to date.']);
}

$toBlock = min($fromBlock + $blocksToProcess - 1, $currentBlock);
$events  = $web3service->fetchEvent('Transfer', $fromBlock, $toBlock);

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

## Signature Verification — `verifySignature()`

`Web3Service` includes a reusable, framework-agnostic signature verifier. It recovers the signer's Ethereum address from any EIP-191 personal-sign message and signature pair — no controller coupling, no app-specific logic baked in.

Add this method to your `Web3Service` class:

```php
/**
 * Verify an Ethereum personal_sign signature and return the recovered wallet address.
 *
 * @param  string  $message    The plain-text message that was signed (before hashing).
 * @param  string  $signature  The 0x-prefixed hex signature returned by the wallet (65 bytes / 130 hex chars).
 * @return string              Lowercase recovered Ethereum address (e.g. "0xabc...").
 * @throws \Exception          If recovery fails or the signature is malformed.
 */
public function verifySignature(string $message, string $signature): string
{
    // EIP-191 prefix — matches what MetaMask / ethers.js personal_sign produces
    $prefix      = "\x19Ethereum Signed Message:\n" . strlen($message);
    $messageHash = \kornrunner\Keccak::hash($prefix . $message, 256);

    // Strip 0x and split the 65-byte signature into r, s, v
    $sig = ltrim($signature, '0x');
    $r   = substr($sig, 0,   64);
    $s   = substr($sig, 64,  64);
    $v   = hexdec(substr($sig, 128, 2));

    // Normalise v — some wallets return 0/1 instead of 27/28
    if ($v < 27) {
        $v += 27;
    }

    $ec     = new \Elliptic\EC('secp256k1');
    $pubKey = $ec->recoverPubKey($messageHash, ['r' => $r, 's' => $s], $v - 27);

    // Ethereum address = last 20 bytes of keccak256(uncompressed public key, without 04 prefix)
    $pubKeyBin = hex2bin(substr($pubKey->encode('hex'), 2));
    $address   = '0x' . substr(\kornrunner\Keccak::hash($pubKeyBin, 256), 24);

    return strtolower($address);
}
```

---

### How it works

```
plain message
      │
      ▼
"\x19Ethereum Signed Message:\n" + len(message) + message
      │
      ▼
keccak256  →  messageHash
      │
      ▼
split signature → r (32 bytes) + s (32 bytes) + v (1 byte)
      │
      ▼
EC secp256k1 recoverPubKey(messageHash, {r, s}, v - 27)
      │
      ▼
keccak256(pubKey) → take last 20 bytes → Ethereum address
```

The recovered address is compared against the claimed wallet address to confirm ownership — without any gas cost or on-chain transaction.

---

### Usage examples

#### Basic — verify a plain message

```php
$web3service = new Web3Service($rpc);

$message   = "Login nonce: a8f3kZ";
$signature = "0xabc123..."; // from wallet

$recoveredAddress = $web3service->verifySignature($message, $signature);

if (strtolower($recoveredAddress) === strtolower($claimedAddress)) {
    // ✅ ownership confirmed
} else {
    // ❌ signature mismatch
}
```

---

#### In a Laravel controller — Web3 wallet login

```php
public function login(Request $request)
{
    $request->validate([
        'wallet_address' => 'required|string',
        'signature'      => 'required|string',
        'nonce'          => 'required|string',
    ]);

    $wallet = Str::lower($request->wallet_address);
    $user   = User::where('wallet_address', $wallet)->first();

    if (!$user || $user->nonce !== $request->nonce) {
        return response()->json(['error' => 'Invalid address or nonce.'], 401);
    }

    // Build the same message that the frontend signed
    $message = buildSignMessage(
        walletAddress: $wallet,
        nonce:         $user->nonce,
        appName:       'Zenix Mining',
        extraLines:    ['You agree to our Terms & Conditions.']
    );

    $web3service = new Web3Service(config('web3.rpc_url'));

    try {
        $recovered = $web3service->verifySignature($message, $request->signature);

        if ($recovered !== $wallet) {
            return response()->json(['error' => 'Signature verification failed.'], 401);
        }

        // ✅ Rotate nonce immediately after successful login
        $user->nonce = Str::random(12);
        $user->save();

        return response()->json([
            'token' => $user->createToken('login_token')->plainTextToken
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 401);
    }
}
```

---

#### Standalone — verify a signed admin action (no framework)

```php
// e.g. a signed "approve withdrawal" payload
$recovered = $web3service->verifySignature($message, $incomingSignature);

if ($recovered !== strtolower('0xAdminWallet')) {
    throw new \Exception('Unauthorised action — signature does not match admin wallet.');
}

// ✅ safe to proceed
```

---

### verifySignature — Parameter Reference

| Parameter | Type | Description |
|---|---|---|
| `$message` | `string` | Plain-text message exactly as it was presented to the wallet for signing |
| `$signature` | `string` | `0x`-prefixed 65-byte hex signature from `personal_sign` |
| **Returns** | `string` | Lowercase `0x`-prefixed recovered Ethereum address |
| **Throws** | `\Exception` | If the signature is malformed or EC recovery fails |

> **Important:** The `$message` passed to `verifySignature()` must be byte-for-byte identical to what the wallet signed. Any difference in whitespace, encoding, or newline style (`\n` vs `\r\n`) will produce a wrong recovered address.

---

## Notes

- The `getEventLogs()` patch separates **indexed** and **non-indexed** parameters automatically per the ABI. Indexed parameters are decoded from `topics[]`; non-indexed from `data`.
- Keep `$blocksToProcess` small (200–500) when using public RPC endpoints to avoid hitting rate limits or response size limits.
- Always persist `$toBlock` as the new `lastSynced` value in your database after a successful run to enable incremental syncing.
- The script is stateless — scheduling it via cron or a queue worker is recommended for continuous syncing.

---

## License

MIT