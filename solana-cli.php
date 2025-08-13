<?php
require_once 'src/SolanaWallet.php';
require_once "src/SolanaPaymentCLI.php";

// =============================================================================
// MAIN EXECUTION
// =============================================================================

$dbConfig = parse_ini_file("solana.ini",true);
// Determine network from arguments
$network = 'devnet';
foreach ($argv as $i => $arg) {
    if ($arg === '--network' && isset($argv[$i + 1])) {
        $network = $argv[$i + 1];
        break;
    }
}

// Validate network
$validNetworks = ['mainnet', 'devnet', 'testnet'];
if (!in_array($network, $validNetworks)) {
    echo "Error: Invalid network '$network'. Use: " . implode(', ', $validNetworks) . "\n";
    exit(1);
}

// Create and run CLI
$cli = new SolanaPaymentCLI($network, $dbConfig);
$cli->run($argv);