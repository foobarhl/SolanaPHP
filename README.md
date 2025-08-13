# Experimental Solana PHP classes and command line client (cli).

This is a test wallet written in PHP for the Solana network.  The client defaults to devnet for testing and was generated with the 
help of Anthropic's Claude code.  There are likely issues to be found and fixed (such as relying on the solana native
client to send transactions because the serializer in this code is broken).

So.. I'm posting this code in case it helps anyone else :)  



## Pre-requisites

1. PostgresSQL
2. PHP 8.2+ (ish) with the GMP and CURL extensions
3. Solana client installed and in path (used for sending transactions) 

# Installation

1. Create a postgres database and user
2. Configure solana.ini (see solana.ini.example)
3. Run solana-cli.php - if the database connection is valid the initial schema will be installed
4. Run solana-cli.php --help for a list of commands


# Examples

```
  # Generate new wallet
  php solana-cli.php --generate --label "My Wallet" --amount 0.5

  # Send SOL
  php solana-cli.php --send --from 1 --to 7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU --amount 0.1

  # Monitor wallet
  php solana-cli.php --monitor 1

  # List wallets
  php solana-cli.php --list

  # View transactions
  php solana-cli.php --transactions 1

```
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