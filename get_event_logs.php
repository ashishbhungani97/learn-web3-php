<?php

use Web3Service;


$rpc = "https://data-seed-prebsc-1-s2.bnbchain.org:8545"; //bsc testnet rpc
$contract_address = "0xeD24FC36d5Ee211Ea25A80239Fb8C4Cfd80f12Ee"; //BUSD token Address 
$abi = [];
$web3service = new Web3Service(
    $rpc
);

$web3service->connectContract(
    $chain->price_oracle_address,
    $abi //place your contract ABI
);

$blocksToProcess = 200; //block limit
$lastSynced = 61545556; // contract deployed block or last block checked saved in database
$currentBlock    = (int) $web3service->getBlockNumber(); // ✅ cast to int
$fromBlock       = max(0, $lastSynced + 1);


// ✅ Single check — removed duplicate
if ($fromBlock > $currentBlock) {
    return json_encode(['success' => true, 'message' => 'Already up to date.']);
}

$toBlock = min($fromBlock + $blocksToProcess - 1, $currentBlock);


// --- Fetch & process events ---
$events   = $web3service->fetchEvent('Transfer', $fromBlock, $toBlock);


return json_encode(['success' => true, 'data' => $events]);