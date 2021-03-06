<?php
namespace Ekliptor\CashP\BlockchainApi;

use Ekliptor\CashP\CashP;
use Ekliptor\CashP\BlockchainApi\Structs\BchAddress;
use Ekliptor\CashP\BlockchainApi\Structs\SlpToken;
use Ekliptor\CashP\BlockchainApi\Structs\SlpTokenAddress;

class BchdProtoGatewayApi extends AbstractBlockchainApi {
	
	protected function __construct(string $blockchainApiUrl = '') {
		parent::__construct($blockchainApiUrl);
		if (empty($this->blockchainApiUrl))
			$this->blockchainApiUrl = "https://bchd.ny1.simpleledger.io/v1/";
			//$this->blockchainApiUrl = "http://localhost:8080/v1/";
	}
	
	public function getConfirmationCount(string $transactionID): int {
		$txDetails = $this->getTransactionDetails($transactionID);
		if (!$txDetails || !isset($txDetails->transaction) || !isset($txDetails->transaction->confirmations))
			return -1; // not found
		return (int)$txDetails->transaction->confirmations;
	}
	
	public function getBlocktime(string $transactionID): int { 
		$txDetails = $this->getTransactionDetails($transactionID);
		if (!$txDetails || !isset($txDetails->transaction) || !isset($txDetails->transaction->timestamp))
			return -1; // not found
		return (int)$txDetails->transaction->timestamp;
	}
	
	public function createNewAddress(string $xPub, int $addressCount, string $hdPathFormat = '0/%d'): ?BchAddress {
		// hdPathFormat not needed for BCHD
		$url = $this->blockchainApiUrl . 'GetBip44HdAddress';
		$data = array(
				'xpub' => $xPub,
				'change' => true,
				'address_index' => $addressCount,
		);
		$response = $this->httpAgent->post($url, $data);
		if ($response === false)
			return null;
		$jsonRes = json_decode($response);
		if (!$jsonRes)
			return null;
		else if (isset($jsonRes->error) && $jsonRes->error) {
			$this->logError("Error creating new address", $jsonRes->error);
			return null;
		}
		return new BchAddress($jsonRes->cash_addr, '', $jsonRes->slp_addr); // TODO remove legacy addresses?
	}
	
	public function getTokenInfo(string $tokenID): ?SlpToken {
		$tokenIDBase64 = static::ensureBase64Encoding($tokenID, false);
		$url = $this->blockchainApiUrl . 'GetTokenMetadata';
		$data = array(
				'token_ids' => array($tokenIDBase64),
		);
		$response = $this->httpAgent->post($url, $data);
		if ($response === false)
			return null;
		$jsonRes = json_decode($response);
		if (!$jsonRes || empty($jsonRes->token_metadata))
			return null;
		$token = new SlpToken();
		$token->id = $tokenID;
		$this->addTokenMetadata($token, $jsonRes->token_metadata);
		return $token;
	}
	
	public function getAddressBalance(string $address): float {
		$bchAddress = $this->getAddressDetails($address);
		if ($bchAddress === null || !isset($bchAddress->balance))
			return -1.0;
		return $bchAddress->balance;
	}
	
	public function getAddressTokenBalance(string $address, string $tokenID): float {
		$slpAddress = $this->getSlpAddressDetails($address, $tokenID);
		if ($slpAddress === null || !isset($slpAddress->balance))
			return -1.0;
		return $slpAddress->balance;
	}
	
	public function getAddressDetails(string $address): ?BchAddress {
		$url = $this->blockchainApiUrl . 'GetAddressUnspentOutputs';
		$data = array(
				'address' => $address,
				'include_mempool' => true, // TODO add parameter to decide if we want confirmed balance only (needed in all subclasses)
				'include_token_metadata' => false,
		);
		$response = $this->httpAgent->post($url, $data);
		if ($response === false)
			return null;
		$jsonRes = json_decode($response);
		if ($jsonRes === null) // empty object if address is unknown = valid response
			return null;
		else if (isset($jsonRes->error) && $jsonRes->error) {
			$this->logError("Error on receiving BCH address details", $jsonRes->error);
			return null;
		}
		$bchAddress = new BchAddress($address, '', '');
		if (!isset($jsonRes->outputs) || empty($jsonRes->outputs)) // grpc proxy returns empty array []
			return $bchAddress;
		
		foreach ($jsonRes->outputs as $output) {
			$bchAddress->balanceSat += (int)$output->value;
		}
		$bchAddress->balance = CashP::fromSatoshis($bchAddress->balanceSat);
		return $bchAddress;
	}
	
	public function getSlpAddressDetails(string $address, string $tokenID): ?SlpTokenAddress {
		$url = $this->blockchainApiUrl . 'GetAddressUnspentOutputs';
		$data = array(
				'address' => $address,
				'include_mempool' => true,
				'include_token_metadata' => true,
		);
		$response = $this->httpAgent->post($url, $data);
		if ($response === false)
			return null;
		$jsonRes = json_decode($response);
		if ($jsonRes === null) // empty object if address is unknown = valid response
			return null;
		else if (isset($jsonRes->error) && $jsonRes->error) {
			$this->logError("Error on receiving SLP address details", $jsonRes->error);
			return null;
		}
		else if (!isset($jsonRes->token_metadata) || empty($jsonRes->token_metadata)) {
			$this->logError("Missing token metadata on SLP address details", $jsonRes);
			return null;
		}
		
		$token = new SlpToken();
		$token->id = $tokenID;
		$this->addTokenMetadata($token, $jsonRes->token_metadata);
		$slpAddress = SlpTokenAddress::withToken($token, $address);
		if (!isset($jsonRes->outputs) || empty($jsonRes->outputs)) // grpc proxy returns empty array []
			return $slpAddress;
		
		foreach ($jsonRes->outputs as $output) {
			$slpAddress->balanceSat += (int)$output->value;
		}
		$slpAddress->balance = CashP::fromSatoshis($slpAddress->balanceSat);
		
		return $slpAddress;
	}
	
	protected function getTransactionDetails(string $transactionID): ?\stdClass {
		$transactionID = static::ensureBase64Encoding($transactionID);
		if (isset($this->transactionCache[$transactionID]))
			return $this->transactionCache[$transactionID];
		
		$url = $this->blockchainApiUrl . 'GetTransaction';
		$data = array(
				'hash' => $transactionID,
				'include_token_metadata' => true
		);
		$response = $this->httpAgent->post($url, $data);
		if ($response === false)
			return null;
		$jsonRes = json_decode($response);
		if ($jsonRes)
			$this->transactionCache[$transactionID] = $jsonRes;
		// {"error":"transaction not found","code":5,"message":"transaction not found"}
		return $jsonRes;
	}
	
	protected function addTokenMetadata(SlpToken $token, array $bchdTokenMetadata): void {
		foreach ($bchdTokenMetadata as $meta) {
			$id = bin2hex(base64_decode($meta->token_id));
			if ($id !== $token->id)
				continue;
			$type = $meta->token_type;
			$typeKey = "type$type";
			$typeInfo = $meta->$typeKey;
			$token->symbol = base64_decode($typeInfo->token_ticker);
			$token->name = base64_decode($typeInfo->token_name);
			if (isset($typeInfo->decimals)) // not present with all tokens
				$token->decimals = $typeInfo->decimals;
			return;
		}
		$this->logError("Unable to find desired token metadata", $bchdTokenMetadata);
	}
	
	protected static function ensureBase64Encoding(string $hash, bool $reverseBytes = true): string {
		if (preg_match("/^[0-9a-f]+$/i", $hash) !== 1)
			return $hash;
		// BCHD wants TX hashes in reverse order as shown on block explorer
		if ($reverseBytes === true)
			$hash = CashP::reverseBytes($hash);
		// the protobuf gateway expects all byte slices in base64 encoding
		return CashP::hexToBase64($hash);
	}
}
?>