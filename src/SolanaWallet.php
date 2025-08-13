<?php

/**
 * Solana Wallet Management Class with PostgreSQL Support
 *
 * Provides functionality for:
 * - Wallet generation and storage
 * - Balance monitoring via RPC
 * - Transaction management and sending via Solana CLI
 * - Payment request generation
 */
class SolanaWallet
{
    private $rpcUrl;
    private $pdo;
    private $network;
    private $isWindows=false;

    public function __construct($network = 'mainnet', $dbConfig = null)
    {
        if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            $this->isWindows=true;

        $this->network = $network;
        $this->setRpcUrl($network);
        $this->initDatabase($dbConfig);
    }

    /**
     * Set RPC URL based on network
     */
    private function setRpcUrl($network)
    {
        $rpcUrls = [
            'mainnet' => 'https://api.mainnet-beta.solana.com',
            'devnet' => 'https://api.devnet.solana.com',
            'testnet' => 'https://api.testnet.solana.com'
        ];

        if (!isset($rpcUrls[$network])) {
            throw new InvalidArgumentException("Invalid network: $network");
        }

        $this->rpcUrl = $rpcUrls[$network];
    }

    /**
     * Initialize PostgreSQL database connection and create tables
     */
    private function initDatabase($dbConfig)
    {
        if (!$dbConfig) {
            throw new Exception("Specify a dbConfig array");
            /*$dbConfig = [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => $_ENV['DB_PORT'] ?? '5432',
                'dbname' => $_ENV['DB_NAME'] ?? 'solana_payments',
                'user' => $_ENV['DB_USER'] ?? 'postgres',
                'password' => $_ENV['DB_PASSWORD'] ?? ''
            ];*/
        }

        try {
            $dsn = "pgsql:host={$dbConfig['database']['host']};port={$dbConfig['database']['port']};dbname={$dbConfig['database']['dbname']}";
            $this->pdo = new PDO($dsn, $dbConfig['database']['user'], $dbConfig['database']['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);


            $this->createTables();

        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Create necessary database tables
     */
    private function createTables()
    {
        $queries = [
            // Wallets table
            "CREATE TABLE IF NOT EXISTS wallets (
                id SERIAL PRIMARY KEY,
                address VARCHAR(44) UNIQUE NOT NULL,
                private_key_encrypted TEXT NOT NULL,
                label VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_balance DECIMAL(20,9) DEFAULT 0,
                last_checked TIMESTAMP
            )",

            // Payment requests table
            "CREATE TABLE IF NOT EXISTS payment_requests (
                id SERIAL PRIMARY KEY,
                wallet_id INTEGER REFERENCES wallets(id),
                amount DECIMAL(20,9) NOT NULL,
                label VARCHAR(255),
                message TEXT,
                qr_code_url TEXT,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                fulfilled_at TIMESTAMP
            )",

            // Transactions table
            "CREATE TABLE IF NOT EXISTS transactions (
                id SERIAL PRIMARY KEY,
                wallet_id INTEGER REFERENCES wallets(id),
                signature VARCHAR(88) UNIQUE NOT NULL,
                type VARCHAR(20) NOT NULL, -- 'incoming', 'outgoing'
                amount DECIMAL(20,9) NOT NULL,
                from_address VARCHAR(44),
                to_address VARCHAR(44),
                block_time TIMESTAMP,
                slot BIGINT,
                fee DECIMAL(20,9),
                status VARCHAR(20) DEFAULT 'confirmed',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",

            // Indexes
            "CREATE INDEX IF NOT EXISTS idx_wallets_address ON wallets(address)",
            "CREATE INDEX IF NOT EXISTS idx_transactions_wallet_id ON transactions(wallet_id)",
            "CREATE INDEX IF NOT EXISTS idx_transactions_signature ON transactions(signature)",
            "CREATE INDEX IF NOT EXISTS idx_payment_requests_wallet_id ON payment_requests(wallet_id)"
        ];

        foreach ($queries as $query) {
            $this->pdo->exec($query);
        }
    }

    // =============================================================================
    // WALLET MANAGEMENT
    // =============================================================================

    /**
     * Generate a new Solana keypair and store in database
     */
    public function generateNewWallet($label = '')
    {
        // Generate 32 random bytes for the private key seed
        $privateKeySeed = random_bytes(32);

        // Create Ed25519 keypair from seed
        $keypair = sodium_crypto_sign_seed_keypair($privateKeySeed);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Convert to base58 address format
        $address = $this->base58Encode($publicKey);

        // Store the seed (not the full keypair) as the private key
        $privateKeyHex = bin2hex($privateKeySeed);
        $encryptedPrivateKey = $this->encryptPrivateKey($privateKeyHex);

        // Store in database
        $stmt = $this->pdo->prepare("
            INSERT INTO wallets (address, private_key_encrypted, label) 
            VALUES (?, ?, ?) 
            RETURNING id
        ");
        $stmt->execute([$address, $encryptedPrivateKey, $label]);
        $walletId = $stmt->fetch()['id'];

        return [
            'id' => $walletId,
            'address' => $address,
            'private_key' => $privateKeyHex,
            'label' => $label
        ];
    }

    /**
     * Get wallet from database by ID or address
     */
    public function getWallet($identifier, $includePrivateKey = false)
    {
        if (is_numeric($identifier)) {
            $stmt = $this->pdo->prepare("SELECT * FROM wallets WHERE id = ?");
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM wallets WHERE address = ?");
        }

        $stmt->execute([$identifier]);
        $wallet = $stmt->fetch();

        if ($wallet && $includePrivateKey) {
            $wallet['private_key'] = $this->decryptPrivateKey($wallet['private_key_encrypted']);
        }

        return $wallet;
    }

    /**
     * List all wallets
     */
    public function listWallets()
    {
        $stmt = $this->pdo->query("
            SELECT id, address, label, last_balance, created_at, last_checked 
            FROM wallets 
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Update wallet label
     */
    public function updateWalletLabel($walletId, $label)
    {
        $stmt = $this->pdo->prepare("UPDATE wallets SET label = ? WHERE id = ?");
        return $stmt->execute([$label, $walletId]);
    }

    /**
     * Delete wallet (use with caution!)
     */
    public function deleteWallet($walletId)
    {
        $this->pdo->beginTransaction();
        try {
            // Delete related records first
            $this->pdo->prepare("DELETE FROM transactions WHERE wallet_id = ?")->execute([$walletId]);
            $this->pdo->prepare("DELETE FROM payment_requests WHERE wallet_id = ?")->execute([$walletId]);
            $this->pdo->prepare("DELETE FROM wallets WHERE id = ?")->execute([$walletId]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // =============================================================================
    // PAYMENT REQUESTS
    // =============================================================================

    /**
     * Create a payment request and store in database
     */
    public function createPaymentRequest($walletId, $amount, $label = '', $message = '')
    {
        $wallet = $this->getWallet($walletId);
        if (!$wallet) {
            throw new Exception("Wallet not found");
        }

        $payment = $this->generatePaymentQR($wallet['address'], $amount, $label, $message);

        $stmt = $this->pdo->prepare("
            INSERT INTO payment_requests (wallet_id, amount, label, message, qr_code_url)
            VALUES (?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$walletId, $amount, $label, $message, $payment['qr_code_url']]);
        $requestId = $stmt->fetch()['id'];

        return [
            'id' => $requestId,
            'wallet' => $wallet,
            'amount' => $amount,
            'label' => $label,
            'message' => $message,
            'payment' => $payment
        ];
    }

    /**
     * Get payment request by ID
     */
    public function getPaymentRequest($requestId)
    {
        $stmt = $this->pdo->prepare("
            SELECT pr.*, w.address, w.label as wallet_label 
            FROM payment_requests pr 
            JOIN wallets w ON pr.wallet_id = w.id 
            WHERE pr.id = ?
        ");
        $stmt->execute([$requestId]);
        return $stmt->fetch();
    }

    /**
     * List payment requests for a wallet
     */
    public function getWalletPaymentRequests($walletId, $status = null)
    {
        $sql = "SELECT * FROM payment_requests WHERE wallet_id = ?";
        $params = [$walletId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // =============================================================================
    // BLOCKCHAIN OPERATIONS (RPC ONLY)
    // =============================================================================

    /**
     * Check balance of a Solana address
     */
    public function getBalance($address)
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getBalance',
            'params' => [$address]
        ];

        $response = $this->makeRpcCall($payload);

        if (isset($response['result']['value'])) {
            // Convert lamports to SOL (1 SOL = 1,000,000,000 lamports)
            return $response['result']['value'] / 1000000000;
        }

        return null;
    }

    /**
     * Get recent transactions for an address
     */
    public function getAddressTransactions($address, $limit = 5)
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getSignaturesForAddress',
            'params' => [$address, ['limit' => $limit]]
        ];

        $response = $this->makeRpcCall($payload);
        return $response['result'] ?? [];
    }

    /**
     * Get transaction details from blockchain
     */
    private function getTransactionDetails($signature)
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getTransaction',
            'params' => [$signature, ['encoding' => 'json']]
        ];

        $response = $this->makeRpcCall($payload);
        return $response['result'] ?? null;
    }

    /**
     * Make RPC call to Solana network
     */
    private function makeRpcCall($payload)
    {
        $ch = curl_init($this->rpcUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        if ($this->isWindows) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_error($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("HTTP error: $httpCode");
        }

        return json_decode($response, true);
    }

    // =============================================================================
    // TRANSACTION SENDING (SOLANA CLI)
    // =============================================================================

    /**
     * Send SOL from a stored wallet to another address using Solana CLI
     */
    public function sendSol($fromWalletId, $toAddress, $amount)
    {
        $wallet = $this->getWallet($fromWalletId, true);
        if (!$wallet) {
            throw new Exception("Wallet not found");
        }

        // Check balance first
        $balance = $this->getBalance($wallet['address']);
        if ($balance < $amount) {
            throw new Exception("Insufficient balance. Current: {$balance} SOL, Required: {$amount} SOL");
        }

        // Check if Solana CLI is available
        if (!$this->isSolanaCLIAvailable()) {
            throw new Exception(
                "Solana CLI is required for sending transactions.\n" .
                "Install it from: https://docs.solana.com/cli/install-solana-cli-tools\n" .
                "Current balance: {$balance} SOL (verified via RPC)"
            );
        }

        // Send via CLI
        return $this->sendSolViaCLI($wallet, $toAddress, $amount);
    }

    function findInPath(string $program): ?string
    {
        // If a path is already specified, just check if that file exists.
        if (strpos($program, DIRECTORY_SEPARATOR) !== false) {
            return is_file($program) ? $program : null;
        }

        // Get the system's PATH directories as an array. PATH_SEPARATOR is ';' on Windows.
        $pathDirs = explode(PATH_SEPARATOR, getenv('PATH'));

        // Get executable file extensions. Provide a fallback for safety.
        $pathExts = getenv('PATHEXT') ? explode(';', getenv('PATHEXT')) : ['.COM', '.EXE', '.BAT', '.CMD'];

        // Also check for the name exactly as given (no extension).
        array_unshift($pathExts, '');

        // Iterate through each directory in the PATH.
        foreach ($pathDirs as $dir) {
            // Skip empty entries that can result from a misconfigured PATH (e.g., ";;").
            if (empty($dir)) {
                continue;
            }

            // Check for the program with each possible extension.
            foreach ($pathExts as $ext) {
                $fullPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $program . $ext;

                // is_file() is a reliable check. is_executable() can be misleading on Windows.
                if (is_file($fullPath)) {
                    return $fullPath;
                }
            }
        }

        // Return null if the program was not found after checking all paths.
        return null;
    }

    /**
     * Check if Solana CLI is available
     */
    private function isSolanaCLIAvailable()
    {
        if(!$this->isWindows) {
            $output = shell_exec('which solana 2>/dev/null');
            return !empty(trim($output));
        } else {
            $res = $this->findInPath("solana.exe");// = trim(shell_exec('where.exe solana 2>/dev/nul'));
            return !empty(trim($res));
        }
    }

    /**
     * Send SOL using Solana CLI tools
     */
    private function sendSolViaCLI($wallet, $toAddress, $amount)
    {
        // Create temporary keypair file
        $tempDir = sys_get_temp_dir();
        $keypairFile = $tempDir . '/solana_keypair_' . uniqid() . '.json';

        try {
            // Create proper keypair format for Solana CLI
            $keypairData = $this->createKeypairFile($wallet['private_key']);
            file_put_contents($keypairFile, json_encode($keypairData));

            // Set network URL based on current network
            $networkUrl = $this->getRpcUrlForCLI();

            // Execute transfer command
            $command = sprintf(
                'solana transfer --url %s --keypair %s --allow-unfunded-recipient %s %s 2>&1',
                escapeshellarg($networkUrl),
                escapeshellarg($keypairFile),
                escapeshellarg($toAddress),
                escapeshellarg($amount)
            );

            $output = shell_exec($command);

            // Parse output for signature
            if (preg_match('/Signature: ([A-Za-z0-9]{87,88})/', $output, $matches)) {
                $signature = $matches[1];

                // Store transaction in database
                $this->storeTransaction([
                    'wallet_id' => $wallet['id'],
                    'signature' => $signature,
                    'type' => 'outgoing',
                    'amount' => -$amount,
                    'from_address' => $wallet['address'],
                    'to_address' => $toAddress,
                    'fee' => 0.000005 // Approximate fee
                ]);

                return [
                    'signature' => $signature,
                    'from' => $wallet['address'],
                    'to' => $toAddress,
                    'amount' => $amount,
                    'method' => 'solana-cli'
                ];

            } else {
                // Parse for error messages
                if (strpos($output, 'insufficient funds') !== false) {
                    throw new Exception("Insufficient funds in wallet");
                } elseif (strpos($output, 'Invalid recipient address') !== false) {
                    throw new Exception("Invalid recipient address: $toAddress");
                } else {
                    throw new Exception("Transaction failed: " . trim($output));
                }
            }

        } finally {
            // Clean up temporary file
            if (file_exists($keypairFile)) {
                unlink($keypairFile);
            }
        }
    }

    /**
     * Create keypair file data in Solana CLI format
     */
    private function createKeypairFile($privateKeyHex)
    {
        // Convert hex private key to bytes
        $privateKeySeed = hex2bin($privateKeyHex);

        // Create Ed25519 keypair
        $keypair = sodium_crypto_sign_seed_keypair($privateKeySeed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        // Solana CLI expects the secret key as an array of 64 bytes
        return array_values(unpack('C*', $secretKey));
    }

    /**
     * Get RPC URL in format expected by Solana CLI
     */
    private function getRpcUrlForCLI()
    {
        switch ($this->network) {
            case 'mainnet':
                return 'mainnet-beta';
            case 'devnet':
                return 'devnet';
            case 'testnet':
                return 'testnet';
            default:
                return $this->rpcUrl;
        }
    }

    // =============================================================================
    // TRANSACTION MANAGEMENT
    // =============================================================================

    /**
     * Sync wallet transactions from blockchain
     */
    public function syncWalletTransactions($walletId)
    {
        $wallet = $this->getWallet($walletId);
        if (!$wallet) {
            throw new Exception("Wallet not found");
        }

        $transactions = $this->getAddressTransactions($wallet['address'], 10);
        $newTxCount = 0;

        foreach ($transactions as $tx) {
            // Check if transaction already exists
            $stmt = $this->pdo->prepare("SELECT id FROM transactions WHERE signature = ?");
            $stmt->execute([$tx['signature']]);

            if (!$stmt->fetch()) {
                // Get transaction details
                $txDetails = $this->getTransactionDetails($tx['signature']);
                if ($txDetails) {
                    $this->storeTransactionFromBlockchain($walletId, $wallet['address'], $txDetails);
                    $newTxCount++;
                }
            }
        }

        // Update wallet balance
        $balance = $this->getBalance($wallet['address']);
        $stmt = $this->pdo->prepare("
            UPDATE wallets 
            SET last_balance = ?, last_checked = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$balance, $walletId]);

        return $newTxCount;
    }

    /**
     * Get wallet transaction history from database
     */
    public function getWalletTransactions($walletId, $limit = 10)
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM transactions 
            WHERE wallet_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$walletId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Store transaction in database
     */
    private function storeTransaction($txData)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions 
            (wallet_id, signature, type, amount, from_address, to_address, fee, block_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");

        $stmt->execute([
            $txData['wallet_id'],
            $txData['signature'],
            $txData['type'],
            $txData['amount'],
            $txData['from_address'] ?? null,
            $txData['to_address'] ?? null,
            $txData['fee'] ?? 0
        ]);
    }

    /**
     * Store transaction from blockchain data
     */
    private function storeTransactionFromBlockchain($walletId, $walletAddress, $txDetails)
    {
        $meta = $txDetails['meta'];
        $transaction = $txDetails['transaction'];

        // Determine transaction type and amount
        $preBalances = $meta['preBalances'];
        $postBalances = $meta['postBalances'];

        // Find wallet's account index
        $accountKeys = array_column($transaction['message']['accountKeys'], 'pubkey');
        $accountIndex = array_search($walletAddress, $accountKeys);

        if ($accountIndex !== false) {
            $balanceChange = ($postBalances[$accountIndex] - $preBalances[$accountIndex]) / 1000000000;
            $type = $balanceChange > 0 ? 'incoming' : 'outgoing';

            $this->storeTransaction([
                'wallet_id' => $walletId,
                'signature' => $transaction['signatures'][0],
                'type' => $type,
                'amount' => $balanceChange,
                'from_address' => $type === 'incoming' ? null : $walletAddress,
                'to_address' => $type === 'outgoing' ? null : $walletAddress,
                'fee' => $meta['fee'] / 1000000000
            ]);
        }
    }

    // =============================================================================
    // PAYMENT QR GENERATION
    // =============================================================================

    /**
     * Generate QR code URL for payment request
     */
    public function generatePaymentQR($address, $amount, $label = '', $message = '')
    {
        $params = [
            'recipient' => $address,
            'amount' => $amount
        ];

        if ($label) $params['label'] = $label;
        if ($message) $params['message'] = $message;

        $solanaUrl = 'solana:' . http_build_query($params);

        // Generate QR code using QR Server API
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($solanaUrl);

        return [
            'solana_url' => $solanaUrl,
            'qr_code_url' => $qrUrl
        ];
    }

    // =============================================================================
    // UTILITY METHODS
    // =============================================================================

    /**
     * Simple encryption for private keys (use proper encryption in production)
     */
    private function encryptPrivateKey($privateKey)
    {
        $key = hash('sha256', $_ENV['ENCRYPTION_KEY'] ?? 'default_key_change_in_production', true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($privateKey, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Simple decryption for private keys
     */
    private function decryptPrivateKey($encryptedData)
    {
        $key = hash('sha256', $_ENV['ENCRYPTION_KEY'] ?? 'default_key_change_in_production', true);
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Base58 encoding for Solana addresses
     */
    private function base58Encode($data)
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);

        // Convert binary data to big integer
        $num = gmp_init('0x' . bin2hex($data));

        if (gmp_cmp($num, 0) == 0) {
            return $alphabet[0];
        }

        $result = '';
        while (gmp_cmp($num, 0) > 0) {
            $remainder = gmp_mod($num, $base);
            $result = $alphabet[gmp_intval($remainder)] . $result;
            $num = gmp_div($num, $base);
        }

        // Handle leading zeros
        $leadingZeros = 0;
        for ($i = 0; $i < strlen($data); $i++) {
            if (ord($data[$i]) === 0) {
                $leadingZeros++;
            } else {
                break;
            }
        }

        return str_repeat($alphabet[0], $leadingZeros) . $result;
    }

    /**
     * Base58 decoding
     */
    private function base58Decode($encoded)
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);

        $num = gmp_init(0);
        $multi = gmp_init(1);

        for ($i = strlen($encoded) - 1; $i >= 0; $i--) {
            $char = $encoded[$i];
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                throw new Exception("Invalid character in base58 string: $char");
            }
            $num = gmp_add($num, gmp_mul($multi, $pos));
            $multi = gmp_mul($multi, $base);
        }

        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        return hex2bin($hex);
    }

    // =============================================================================
    // GETTERS
    // =============================================================================

    public function getNetwork()
    {
        return $this->network;
    }

    public function getRpcUrl()
    {
        return $this->rpcUrl;
    }

    public function getDatabaseConnection()
    {
        return $this->pdo;
    }
}