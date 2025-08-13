<?php



require_once 'SolanaWallet.php';

/**
 * Solana Payment CLI Tool
 *
 * Command line interface for the SolanaWallet class
 * Provides wallet management, payment requests, and transaction operations
 */
class SolanaPaymentCLI
{
    private $wallet;
    private $network;

    public function __construct($network = 'mainnet',$cfg)
    {
        $this->network = $network;
        try {
            $this->wallet = new SolanaWallet($network,$cfg);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Main CLI execution handler
     */
    public function run($args)
    {
        if (count($args) < 2) {
            $this->showHelp();
            exit(1);
        }

        $parsedArgs = $this->parseArgs($args);

        try {
            $this->executeCommand($parsedArgs);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Execute the appropriate command based on parsed arguments
     */
    private function executeCommand($args)
    {
        if (isset($args['help'])) {
            $this->showHelp();

        } elseif (isset($args['generate'])) {
            $this->handleGenerate($args);

        } elseif (isset($args['list'])) {
            $this->handleList($args);

        } elseif (isset($args['wallet'])) {
            $this->handleWalletDetails($args);

        } elseif (isset($args['send'])) {
            $this->handleSend($args);

        } elseif (isset($args['monitor'])) {
            $this->handleMonitor($args);

        } elseif (isset($args['sync'])) {
            $this->handleSync($args);

        } elseif (isset($args['balance'])) {
            $this->handleBalance($args);

        } elseif (isset($args['transactions'])) {
            $this->handleTransactions($args);

        } elseif (isset($args['requests'])) {
            $this->handlePaymentRequests($args);

        } else {
            echo "Error: Unknown command\n";
            $this->showHelp();
            exit(1);
        }
    }

    // =============================================================================
    // COMMAND HANDLERS
    // =============================================================================

    /**
     * Handle wallet generation
     */
    private function handleGenerate($args)
    {
        $amount = floatval($args['amount'] ?? 1.0);
        $label = $args['label'] ?? '';
        $message = $args['message'] ?? '';

        echo "Generating new Solana wallet...\n";
        $newWallet = $this->wallet->generateNewWallet($label);

        echo "✅ New wallet generated and stored!\n";
        echo "Wallet ID: {$newWallet['id']}\n";
        echo "Address: {$newWallet['address']}\n";
        echo "⚠️  Private Key: {$newWallet['private_key']}\n";
        echo "⚠️  IMPORTANT: Private key is encrypted and stored in database!\n\n";

        // Create payment request if amount specified
        if ($amount > 0) {
            $request = $this->wallet->createPaymentRequest($newWallet['id'], $amount, $label, $message);
            $this->displayPaymentRequest($request);
        }
    }

    /**
     * Handle wallet listing
     */
    private function handleList($args)
    {
        $wallets = $this->wallet->listWallets();
        $this->displayWalletList($wallets);
    }

    /**
     * Handle wallet details display
     */
    private function handleWalletDetails($args)
    {
        $walletId = $args['wallet'];
        $walletData = $this->wallet->getWallet($walletId);

        if (!$walletData) {
            echo "Error: Wallet not found\n";
            exit(1);
        }

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "                WALLET DETAILS\n";
        echo str_repeat("=", 60) . "\n";
        echo "ID: {$walletData['id']}\n";
        echo "Address: {$walletData['address']}\n";
        echo "Label: " . ($walletData['label'] ?: 'No label') . "\n";
        echo "Created: {$walletData['created_at']}\n";

        $balance = $this->wallet->getBalance($walletData['address']);
        echo "Current Balance: " . number_format($balance, 9) . " SOL\n";
        echo "Last Checked: " . ($walletData['last_checked'] ?: 'Never') . "\n";
        echo str_repeat("=", 60) . "\n";
    }

    /**
     * Handle SOL sending
     */
    private function handleSend($args)
    {
        debug_print_backtrace();
        $fromWalletId = $args['from'] ?? null;
        $toAddress = $args['to'] ?? null;
        $amount = floatval($args['amount'] ?? 0);

        if (!$fromWalletId || !$toAddress || $amount <= 0) {
            echo "Error: --send requires --from <wallet_id>, --to <address>, and --amount <amount>\n";
            exit(1);
        }

        echo "Preparing to send $amount SOL...\n";
        echo "From wallet ID: $fromWalletId\n";
        echo "To address: $toAddress\n";
        echo "Amount: $amount SOL\n";
        echo "Network: {$this->network}\n";
        echo "Method: Solana CLI\n\n";

        echo "⚠️  This will send real SOL! Continue? (y/N): ";
        $confirm = trim(fgets(STDIN));

        if (strtolower($confirm) !== 'y') {
            echo "Transaction cancelled.\n";
            exit(0);
        }

        try {
            echo "Checking balance and sending transaction...\n";
            $result = $this->wallet->sendSol($fromWalletId, $toAddress, $amount);

            echo "\n✅ Transaction completed successfully!\n";
            echo "Signature: {$result['signature']}\n";
            echo "From: {$result['from']}\n";
            echo "To: {$result['to']}\n";
            echo "Amount: {$result['amount']} SOL\n";
            echo "Method: {$result['method']}\n";
            echo "View on Solana Explorer: https://explorer.solana.com/tx/{$result['signature']}\n";

        } catch (Exception $e) {
            echo "❌ Transaction failed: " . $e->getMessage() . "\n\n";

            // Provide helpful guidance based on error type
            if (strpos($e->getMessage(), 'Solana CLI is required') !== false) {
                echo "💡 Setup Instructions:\n";
                echo "1. Install Solana CLI:\n";
                echo "   sh -c \"\$(curl -sSfL https://release.solana.com/v1.17.0/install)\"\n";
                echo "2. Add to PATH (add to ~/.bashrc or ~/.zshrc):\n";
                echo "   export PATH=\"\$HOME/.local/share/solana/install/active_release/bin:\$PATH\"\n";
                echo "3. Verify installation:\n";
                echo "   solana --version\n\n";
            } else {
                echo "💡 Troubleshooting:\n";
                echo "• Check network connectivity\n";
                echo "• Verify wallet has sufficient balance\n";
                echo "• Ensure recipient address is valid\n";
                echo "• Try again in a few seconds (network congestion)\n\n";
            }

            exit(1);
        }
    }

    /**
     * Handle address monitoring
     */
    private function handleMonitor($args)
    {
        $identifier = $args['monitor'];

        // Check if it's a wallet ID or address
        if (is_numeric($identifier)) {
            $walletData = $this->wallet->getWallet($identifier);
            if (!$walletData) {
                echo "Error: Wallet ID $identifier not found\n";
                exit(1);
            }
            $address = $walletData['address'];
            $walletId = $walletData['id'];
        } else {
            $address = $identifier;
            $walletId = null;
        }

        echo "Monitoring address: $address\n";
        echo "Network: {$this->network}\n";
        echo "Press Ctrl+C to stop...\n\n";

        $lastBalance = $this->wallet->getBalance($address);
        echo "Current balance: " . number_format($lastBalance, 9) . " SOL\n\n";

        $syncCounter = 0;
        while (true) {
            sleep(5); // Check every 5 seconds

            $currentBalance = $this->wallet->getBalance($address);

            if ($currentBalance !== $lastBalance) {
                $change = $currentBalance - $lastBalance;
                $changeStr = ($change > 0 ? '+' : '') . number_format($change, 9);

                echo "[" . date('Y-m-d H:i:s') . "] Balance changed: ";
                echo number_format($currentBalance, 9) . " SOL ($changeStr)\n";

                if ($change > 0) {
                    echo "🎉 Payment received!\n";

                    // Sync transactions if this is a stored wallet
                    if ($walletId) {
                        echo "Syncing wallet transactions...\n";
                        $newTxCount = $this->wallet->syncWalletTransactions($walletId);
                        echo "Synced $newTxCount new transactions.\n";
                    }
                }

                $lastBalance = $currentBalance;
            } else {
                echo ".";

                // Periodic sync every 30 seconds (6 cycles)
                if ($walletId && ++$syncCounter % 6 === 0) {
                    echo " [sync]";
                    $this->wallet->syncWalletTransactions($walletId);
                }
            }

            flush();
        }
    }

    /**
     * Handle transaction sync
     */
    private function handleSync($args)
    {
        $walletId = $args['sync'];

        if (!is_numeric($walletId)) {
            echo "Error: Wallet ID must be numeric\n";
            exit(1);
        }

        echo "Syncing wallet $walletId transactions from blockchain...\n";
        $newTxCount = $this->wallet->syncWalletTransactions($walletId);
        echo "✅ Synced $newTxCount new transactions.\n";
    }

    /**
     * Handle balance checking
     */
    private function handleBalance($args)
    {
        $identifier = $args['balance'];

        // Check if it's a wallet ID or address
        if (is_numeric($identifier)) {
            $walletData = $this->wallet->getWallet($identifier);
            if (!$walletData) {
                echo "Error: Wallet ID $identifier not found\n";
                exit(1);
            }
            $address = $walletData['address'];
            echo "Wallet ID: {$walletData['id']}\n";
            echo "Label: " . ($walletData['label'] ?: 'No label') . "\n";
        } else {
            $address = $identifier;
        }

        $balance = $this->wallet->getBalance($address);
        echo "Address: $address\n";
        echo "Balance: " . number_format($balance, 9) . " SOL\n";
        echo "Network: {$this->network}\n";
    }

    /**
     * Handle transaction history display
     */
    private function handleTransactions($args)
    {
        $walletId = $args['transactions'];

        if (!is_numeric($walletId)) {
            echo "Error: Wallet ID must be numeric\n";
            exit(1);
        }

        $walletData = $this->wallet->getWallet($walletId);
        if (!$walletData) {
            echo "Error: Wallet not found\n";
            exit(1);
        }

        echo "Transaction history for wallet: {$walletData['address']}\n";
        echo "Label: " . ($walletData['label'] ?: 'No label') . "\n";

        $transactions = $this->wallet->getWalletTransactions($walletId, 20);
        $this->displayTransactionHistory($transactions);

        if (empty($transactions)) {
            echo "💡 Use --sync $walletId to fetch transactions from blockchain\n";
        }
    }

    /**
     * Handle payment requests display
     */
    private function handlePaymentRequests($args)
    {
        $walletId = $args['requests'];

        if (!is_numeric($walletId)) {
            echo "Error: Wallet ID must be numeric\n";
            exit(1);
        }

        $requests = $this->wallet->getWalletPaymentRequests($walletId);
        $this->displayPaymentRequests($requests);
    }

    // =============================================================================
    // DISPLAY METHODS
    // =============================================================================

    /**
     * Display payment request information
     */
    private function displayPaymentRequest($request)
    {
        $walletData = $request['wallet'];
        $amount = $request['amount'];
        $label = $request['label'];
        $message = $request['message'];
        $payment = $request['payment'];

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "           SOLANA PAYMENT REQUEST\n";
        echo str_repeat("=", 60) . "\n";

        echo "Request ID: {$request['id']}\n";
        echo "Wallet ID:  {$walletData['id']}\n";
        echo "Address:    {$walletData['address']}\n";
        echo "Amount:     $amount SOL\n";

        if ($label) echo "Label:      $label\n";
        if ($message) echo "Message:    $message\n";

        $balance = $this->wallet->getBalance($walletData['address']);
        echo "\nCurrent Balance: " . number_format($balance, 9) . " SOL\n";

        echo "\nSolana URL: " . $payment['solana_url'] . "\n";
        echo "QR Code:    " . $payment['qr_code_url'] . "\n";

        echo "\n" . str_repeat("-", 60) . "\n";
        echo "Share the address above to receive $amount SOL\n";
        echo "Monitor: php " . basename($_SERVER['PHP_SELF']) . " --monitor {$walletData['id']}\n";
        echo str_repeat("=", 60) . "\n";
    }

    /**
     * Display wallet list
     */
    private function displayWalletList($wallets)
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "                              STORED WALLETS\n";
        echo str_repeat("=", 80) . "\n";

        if (empty($wallets)) {
            echo "No wallets found. Use --generate to create a new wallet.\n";
            echo str_repeat("=", 80) . "\n";
            return;
        }

        printf("%-3s %-44s %-15s %-12s %-20s\n", "ID", "Address", "Label", "Balance", "Created");
        echo str_repeat("-", 80) . "\n";

        foreach ($wallets as $walletData) {
            $label = $walletData['label'] ?: 'No label';
            $balance = $walletData['last_balance'] ? number_format($walletData['last_balance'], 4) . ' SOL' : 'Unknown';
            $created = date('Y-m-d H:i', strtotime($walletData['created_at']));

            printf("%-3d %-44s %-15s %-12s %-20s\n",
                $walletData['id'],
                $walletData['address'],
                substr($label, 0, 14),
                $balance,
                $created
            );
        }

        echo str_repeat("=", 80) . "\n";
    }

    /**
     * Display transaction history
     */
    private function displayTransactionHistory($transactions)
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "                                TRANSACTION HISTORY\n";
        echo str_repeat("=", 100) . "\n";

        if (empty($transactions)) {
            echo "No transactions found.\n";
            echo str_repeat("=", 100) . "\n";
            return;
        }

        printf("%-10s %-15s %-15s %-44s %-20s\n", "Type", "Amount", "Fee", "Signature", "Date");
        echo str_repeat("-", 100) . "\n";

        foreach ($transactions as $tx) {
            $amount = ($tx['amount'] > 0 ? '+' : '') . number_format($tx['amount'], 9) . ' SOL';
            $fee = $tx['fee'] ? number_format($tx['fee'], 9) : '0';
            $signature = substr($tx['signature'], 0, 20) . '...';
            $date = date('Y-m-d H:i:s', strtotime($tx['created_at']));

            printf("%-10s %-15s %-15s %-44s %-20s\n",
                ucfirst($tx['type']),
                $amount,
                $fee,
                $signature,
                $date
            );
        }

        echo str_repeat("=", 100) . "\n";
    }

    /**
     * Display payment requests
     */
    private function displayPaymentRequests($requests)
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "                           PAYMENT REQUESTS\n";
        echo str_repeat("=", 80) . "\n";

        if (empty($requests)) {
            echo "No payment requests found.\n";
            echo str_repeat("=", 80) . "\n";
            return;
        }

        printf("%-3s %-12s %-15s %-20s %-10s %-20s\n", "ID", "Amount", "Label", "Message", "Status", "Created");
        echo str_repeat("-", 80) . "\n";

        foreach ($requests as $request) {
            $label = $request['label'] ?: 'No label';
            $message = $request['message'] ? substr($request['message'], 0, 18) . '...' : 'No message';
            $created = date('Y-m-d H:i', strtotime($request['created_at']));

            printf("%-3d %-12s %-15s %-20s %-10s %-20s\n",
                $request['id'],
                number_format($request['amount'], 4) . ' SOL',
                substr($label, 0, 14),
                substr($message, 0, 19),
                $request['status'],
                $created
            );
        }

        echo str_repeat("=", 80) . "\n";
    }

    // =============================================================================
    // UTILITY METHODS
    // =============================================================================

    /**
     * Parse command line arguments
     */
    private function parseArgs($argv)
    {
        $args = [];
        $currentKey = null;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (strpos($arg, '--') === 0) {
                $currentKey = substr($arg, 2);
                $args[$currentKey] = true;
            } elseif ($currentKey) {
                $args[$currentKey] = $arg;
                $currentKey = null;
            }
        }

        return $args;
    }

    /**
     * Handle errors and display helpful information
     */
    private function handleError($e)
    {
        echo "Error: " . $e->getMessage() . "\n";

        if (strpos($e->getMessage(), 'Database connection failed') !== false) {
            echo "\n💡 Database Setup Help:\n";
            echo "1. Install PostgreSQL\n";
            echo "2. Create database: createdb solana_payments\n";
            echo "3. Set environment variables:\n";
            echo "   export DB_HOST=localhost\n";
            echo "   export DB_PORT=5432\n";
            echo "   export DB_NAME=solana_payments\n";
            echo "   export DB_USER=postgres\n";
            echo "   export DB_PASSWORD=your_password\n";
            echo "   export ENCRYPTION_KEY=your_secure_encryption_key\n";
        }

        exit(1);
    }

    /**
     * Show help information
     */
    private function showHelp()
    {
        echo "Solana Payment Tool with PostgreSQL Support\n\n";
        echo "Usage:\n";
        echo "  php " . basename($_SERVER['PHP_SELF']) . " [command] [options]\n\n";
        echo "Wallet Commands:\n";
        echo "  --generate              Generate new wallet and request payment\n";
        echo "  --list                  List all stored wallets\n";
        echo "  --wallet <id>           Show wallet details\n";
        echo "  --send                  Send SOL from stored wallet (requires Solana CLI)\n\n";
        echo "Monitoring Commands:\n";
        echo "  --monitor <id|address>  Monitor address for incoming payments\n";
        echo "  --sync <wallet_id>      Sync wallet transactions from blockchain\n";
        echo "  --balance <id|address>  Check balance of wallet/address\n";
        echo "  --transactions <id>     Show wallet transaction history\n";
        echo "  --requests <id>         Show wallet payment requests\n\n";
        echo "Options:\n";
        echo "  --amount <amount>       Amount to request/send (default: 1.0)\n";
        echo "  --to <address>          Recipient address (for --send)\n";
        echo "  --from <wallet_id>      Source wallet ID (for --send)\n";
        echo "  --label <label>         Label for wallet/payment request\n";
        echo "  --message <message>     Message for payment request\n";
        echo "  --network <network>     Network (mainnet, devnet, testnet)\n\n";
        echo "Database Setup:\n";
        echo "  Set environment variables: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD\n";
        echo "  Set ENCRYPTION_KEY for private key encryption\n\n";
        echo "Solana CLI Setup:\n";
        echo "  Required for sending transactions:\n";
        echo "  sh -c \"\$(curl -sSfL https://release.solana.com/v1.17.0/install)\"\n\n";
        echo "Examples:\n";
        echo "  # Generate new wallet\n";
        echo "  php " . basename($_SERVER['PHP_SELF']) . " --generate --label \"My Wallet\" --amount 0.5\n\n";
        echo "  # Send SOL (requires Solana CLI)\n";
        echo "  php " . basename($_SERVER['PHP_SELF']) . " --send --from 1 --to 7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU --amount 0.1\n\n";
        echo "  # Monitor wallet\n";
        echo "  php " . basename($_SERVER['PHP_SELF']) . " --monitor 1\n\n";
        echo "  # List wallets\n";
        echo "  php " . basename($_SERVER['PHP_SELF']) . " --list\n\n";
        echo "  # View transactions\n";
        echo "  php " . basename($_SERVER['PHP_SELF']) . " --transactions 1\n\n";
    }
}
