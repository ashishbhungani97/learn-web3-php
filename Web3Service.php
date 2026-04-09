<?php

use App\Enums\DepositStatus;
use App\Models\BridgeSetting;
use Web3\Contract;
use Web3\Web3;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use kornrunner\Keccak;
use Elliptic\EC;
use Web3p\EthereumTx\Transaction;
use Illuminate\Support\Facades\Crypt;



class Web3Service
{
    protected $web3;
    protected $contract;
    protected $rpcUrl;
    protected $contractAddress;
    protected $abi;

    public function __construct($rpcUrl = null, $timeout = 10)
    {
        $this->rpcUrl = $rpcUrl;
        $this->web3   = new Web3(new HttpProvider(
            new HttpRequestManager($rpcUrl,  $timeout * 1000)   // ms
        ));

        return $this;
    }

    public function connectContract($contractAddress = null, $abi = null)
    {
        if(!$this->web3 || !$contractAddress || !$abi){
            return null;
        }
        $this->contractAddress = strtolower($contractAddress);
        $this->abi             = $abi;
        $this->contract        = new Contract($this->web3->provider, $abi);
        $this->contract->at($contractAddress);
        return $this;
    }

    // ── Accessor ───────────────────────────────────────────────

    /**
     * Return the underlying Contract instance.
     * Used by TokenPriceOracleService to call contract methods directly.
     */
    public function getContract(): Contract
    {
        if (!$this->contract) {
            throw new \RuntimeException('Web3Service: contract not initialised');
        }

        return $this->contract;
    }

    // ── Original methods (unchanged) ──────────────────────────

    public function getTokenDecimals()
    {
        try {
            $decimals = null;
            $this->contract->call('decimals', function ($err, $result) use (&$decimals) {
                if (!$err) {
                    $decimals = $result;
                }
            });

            return (int) $decimals[0]->toString();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getBlockNumber()
    {
        try {
            $latest = null;
            $this->web3->eth->blockNumber(function ($err, $block) use (&$latest) {
                if ($err !== null) throw new \Exception($err->getMessage());
                $latest = $block->toString();
            });
            return $latest;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getEthBalance(string $address)
    {
        try {
            if (!$address) return null;

            $balance = 0;
            $this->web3->eth->getBalance($address, function ($err, $bal) use (&$balance) {
                if ($err !== null) {
                    throw new \Exception($err->getMessage());
                }

                $balance = bcdiv(
                    $bal->toString(),
                    bcpow('10', 18),
                    18
                );
            });

            return $balance;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getErc20Balance(string $walletAddress)
    {
        if (!$walletAddress) {
            return null;
        }

        try {
            $rawBalance = null;

            $this->contract->call(
                'balanceOf',
                $walletAddress,
                function ($err, $result) use (&$rawBalance) {
                    if ($err !== null) {
                        throw new \Exception($err->getMessage());
                    }
                    $rawBalance = $result[0]->toString();
                }
            );

            if ($rawBalance === null) {
                return null;
            }

            $decimals = $this->getTokenDecimals();

            return bcdiv(
                $rawBalance,
                bcpow('10', (string) $decimals),
                $decimals
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getTransactionStatus($tx_hash = null)
    {
        if (!$tx_hash) {
            return null;
        }

        $response = null;
        $this->web3->eth->getTransactionReceipt($tx_hash, function ($err, $result) use (&$response) {
            if (!$err && $result) {
                $response = $result;
            }
        });

        if (!$response) {
            return ['error' => 'Transaction receipt not found!'];
        }

        return $response;

    }

    public function isContract(string $address): bool
    {
        try {
            $code = null;
            $this->web3->eth->getCode($address, function ($err, $result) use (&$code) {
                if ($err !== null) {
                    throw new \Exception($err->getMessage());
                }
                $code = $result;
            });

            return $code && $code !== '0x';
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isAddress(string $address): bool
    {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return false;
        }

        if (
            preg_match('/^0x[a-f0-9]{40}$/', $address) ||
            preg_match('/^0x[A-F0-9]{40}$/', $address)
        ) {
            return true;
        }

        return $this->isChecksumAddress($address);
    }

    private function isChecksumAddress(string $address): bool
    {
        $address      = str_replace('0x', '', $address);
        $addressLower = strtolower($address);
        $hash         = Keccak::hash($addressLower, 256);

        for ($i = 0; $i < 40; $i++) {
            $char       = $address[$i];
            $hashNibble = hexdec($hash[$i]);

            if (ctype_alpha($char)) {
                if (
                    ($hashNibble >= 8 && strtoupper($char) !== $char) ||
                    ($hashNibble < 8  && strtolower($char) !== $char)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    public function getErc20Metadata(): ?array
    {
        try {
            if (!$this->contract) {
                return null;
            }

            $name = $symbol = $decimals = null;

            $this->contract->call('name', function ($err, $result) use (&$name) {
                if (!$err && isset($result[0])) {
                    $name = $result[0];
                }
            });

            $this->contract->call('symbol', function ($err, $result) use (&$symbol) {
                if (!$err && isset($result[0])) {
                    $symbol = $result[0];
                }
            });

            $this->contract->call('decimals', function ($err, $result) use (&$decimals) {
                if (!$err && isset($result[0])) {
                    $decimals = (int) $result[0]->toString();
                }
            });

            if ($name === null || $symbol === null || $decimals === null) {
                return null;
            }

            return [
                'name'     => $name,
                'symbol'   => $symbol,
                'decimals' => $decimals,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }


    public function getEthBalanceWei(string $address): string
    {
        try {
            if (!$address) return '0';

            $balance = '0';
            $this->web3->eth->getBalance($address, function ($err, $bal) use (&$balance) {
                if ($err !== null) {
                    throw new \Exception($err->getMessage());
                }
                $balance = $bal->toString();
            });

            return $balance;
        } catch (\Exception $e) {
            return '0';
        }
    }

    public function getErc20BalanceWei(string $walletAddress): string
    {
        if (!$walletAddress) {
            return '0';
        }

        try {
            $rawBalance = '0';

            $this->contract->call(
                'balanceOf',
                $walletAddress,
                function ($err, $result) use (&$rawBalance) {
                    if ($err !== null) {
                        throw new \Exception($err->getMessage());
                    }
                    $rawBalance = $result[0]->toString();
                }
            );

            return $rawBalance;
        } catch (\Exception $e) {
            return '0';
        }
    }


    public function fetchEvent($event_name, $fromBlock, $toBlock)
    {
        if (!$event_name) {
            return [];
        }

        $logs = $this->contract->getEventLogs($event_name, $fromBlock, $toBlock);
        return $logs;
    }

    public function getUserNonce($walletAddress)
    {
        if (!$walletAddress) {
            return null;
        }

        try {
            $nonce = null;

            $this->contract->call(
                'nextNonce',
                $walletAddress,
                function ($err, $result) use (&$nonce) {
                    if ($err !== null) {
                        throw new \Exception($err->getMessage());
                    }
                    $nonce = $result;
                }
            );

            return $nonce;
        } catch (\Exception $e) {
            return null;
        }
    }
}
