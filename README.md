# Experimental Solana PHP classes and command line client (cli).

This is a test wallet for the Solana network.  The client is coded to use devnet for testing.
## Pre-requisites

1. PostgresSQL
2. PHP 8.2+ (ish) with the GMP and CURL extensions

# Installation

1. Create a postgres database and user
2. Configure solana.ini (see solana.ini.example)
3. Run solana-cli.php - if the database connection is valid the initial schema will be installed
4. Run solana-cli.php --help for a list of commands


# Examples

Generate a wallet address and issue request for 1 SOL
```
php solana-cli.php --generate

Generating new Solana wallet...
✅ New wallet generated and stored!
Wallet ID: 2
Address: GckpWzCzZ3RSr7gWHYj3yggsFarKiQG5CCQVxQjywDG9
⚠️  Private Key: bff7d4187b248e08c729d4352aa456ca59b00015f2714b75d9965610462ff8b4
⚠️  IMPORTANT: Private key is encrypted and stored in database!


============================================================
           SOLANA PAYMENT REQUEST
============================================================
Request ID: 2
Wallet ID:  2
Address:    GckpWzCzZ3RSr7gWHYj3yggsFarKiQG5CCQVxQjywDG9
Amount:     1 SOL

Current Balance: 0.000000000 SOL

Solana URL: solana:recipient=GckpWzCzZ3RSr7gWHYj3yggsFarKiQG5CCQVxQjywDG9&amount=1
QR Code:    https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=solana%3Arecipient%3DGckpWzCzZ3RSr7gWHYj3yggsFarKiQG5CCQVxQjywDG9%26amount%3D1

------------------------------------------------------------
Share the address above to receive 1 SOL
Monitor: php solana-cli.php --monitor 2
============================================================

```

Monitoring
```
php solana-cli.php --monitor 2
Monitoring address: GckpWzCzZ3RSr7gWHYj3yggsFarKiQG5CCQVxQjywDG9
Network: devnet
Press Ctrl+C to stop...

Current balance: 0.000000000 SOL

.[2025-08-13 08:25:41] Balance changed: 1.000000000 SOL (+1.000000000)
🎉 Payment received!
Syncing wallet transactions...
Synced 1 new transactions.
..... [sync]...... [sync]...... [sync]...... [sync]...... [sync]...... [sync]...... [sync]...
```