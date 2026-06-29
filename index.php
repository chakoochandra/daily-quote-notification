<?php

/**
 * SCRIPT PENGIRIMAN QUOTE HARIAN OTOMATIS
 *
 * CARA MENGGUNAKAN:
 * 1. Edit file config.json untuk mengatur API URL, session, dan token
 *
 * 2. Jalankan manual untuk testing:
 * 		php index.php test
 *      php index.php send
 *
 * 3. Setup cronjob otomatis (akan menambahkan entry ke crontab):
 *      php index.php setup-cron
 *
 * 4. Quote akan dikirim secara otomatis sesuai jadwal di config.json
 *
 * JIKA MENGGUNAKAN FRAMEWORK LAIN (CI, Laravel, dll):
 * - Include file ini: require_once 'index.php';
 * - Panggil: (new QuoteSender())->sendDaily();
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DialogWaGateway.php';

defined('CRONJOB_TIME') or define('CRONJOB_TIME', '30 6 * * *');

class QuoteSender
{
	private $cron_command;
	private $quotes = [];
	private $targets = [];
	private $config = [];

	public function __construct()
	{
		$this->cron_command = 'php ' . __DIR__ . DIRECTORY_SEPARATOR . 'index.php send';
		$this->_loadEnv();

		$jsonFile = __DIR__ . DIRECTORY_SEPARATOR . 'quotes.json';
		if (file_exists($jsonFile)) {
			$this->quotes = json_decode(file_get_contents($jsonFile), true);
		}
		if (empty($this->quotes)) {
			$this->quotes = [];
		}

		if (isset($this->config['WA_TARGETS'])) {
			$this->targets = array_map('trim', explode(',', $this->config['WA_TARGETS']));
		}
	}

	public function sendDaily()
	{
		$gateway = $this->_getGateway();
		if (!$gateway->checkGateway()) {
			$error_msg = "Gateway tidak tersedia atau token expired";
			error_log($error_msg);
			return ['status' => false, 'message' => $error_msg, 'details' => []];
		}

		$quote = $this->_getRandomQuote();
		if (!$quote) {
			return ['status' => false, 'message' => 'No quotes available', 'details' => []];
		}

		$targets = $this->_cleansePhoneNumber($this->targets);
		$results = [];

		foreach ($targets as $no_wa) {
			$wa_text = "✨ _\"{$quote['en']}\"_\n\n";
			$wa_text .= "_\"{$quote['id']}\"_\n\n";
			$wa_text .= "💪 *Semangat Belajar & Berkarya!*";

			$result = $gateway->sendWa($no_wa, $wa_text);
			$results[] = [
				'target'   => $no_wa,
				'result'   => $result,
				'quote'    => $quote,
			];
		}

		$all_ok = true;
		foreach ($results as $r) {
			if ($r['result']['status'] !== 'completed') {
				$all_ok = false;
				break;
			}
		}

		$summary = "Quote sent to " . count($targets) . " recipient(s)\n";
		foreach ($results as $r) {
			$summary .= "- {$r['target']}: {$r['result']['sent_response']}\n";
		}

		return [
			'status'  => $all_ok,
			'message' => $summary,
			'details' => $results,
		];
	}

	public function setupCron()
	{
		$result = ['ok' => [], 'failed' => []];

		$success = $this->_ensureOneOnly($this->_getCronTime(), $this->cron_command);
		$result[$success ? 'ok' : 'failed'][] = 'daily_quote_wa';

		return $result;
	}

	public function testSend()
	{
		echo "=== TEST QUOTE SEND ===\n\n";
		$res = $this->sendDaily();
		echo "Status: " . ($res['status'] ? 'SUCCESS' : 'FAILED') . "\n";
		echo "Message:\n" . $res['message'] . "\n";
		return $res;
	}

	public function add($cronExpression, $command)
	{
		$desiredLine = $this->_buildLine($cronExpression, $command);
		$currentCron = $this->_readCrontab();

		foreach ($currentCron as $line) {
			if (trim($line) === $desiredLine) {
				return true;
			}
		}

		$currentCron[] = $desiredLine;
		return $this->_writeCrontab($currentCron);
	}

	public function remove($command)
	{
		$currentCron = $this->_readCrontab();
		$newCron = array_values(array_filter($currentCron, function ($line) use ($command) {
			return strpos($line, $command) === false;
		}));

		if (count($newCron) === count($currentCron)) {
			return true;
		}

		return $this->_writeCrontab($newCron);
	}

	public function exists($command)
	{
		foreach ($this->_readCrontab() as $line) {
			if (strpos($line, $command) !== false) {
				return true;
			}
		}
		return false;
	}

	public function listAll()
	{
		return $this->_readCrontab();
	}

	private function _loadEnv()
	{
		$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
		if (!file_exists($envFile)) {
			return;
		}
		$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		foreach ($lines as $line) {
			if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
				continue;
			}
			list($key, $value) = explode('=', $line, 2);
			$this->config[trim($key)] = trim($value);
		}
	}

private function _getGateway()
	{
		$skipSsl = isset($this->config['SKIP_SSL_VERIFY']) && strtolower($this->config['SKIP_SSL_VERIFY']) === 'true';
		return new DialogWaGateway(
			isset($this->config['DIALOGWA_API_URL']) ? $this->config['DIALOGWA_API_URL'] : '',
			isset($this->config['DIALOGWA_SESSION']) ? $this->config['DIALOGWA_SESSION'] : '',
			isset($this->config['DIALOGWA_TOKEN']) ? $this->config['DIALOGWA_TOKEN'] : '',
			$skipSsl
		);
	}

	private function _getCronTime()
	{
		return isset($this->config['CRONJOB_TIME']) ? $this->config['CRONJOB_TIME'] : CRONJOB_TIME;
	}

	private function _getRandomQuote()
	{
		if (empty($this->quotes)) {
			return null;
		}
		$index = array_rand($this->quotes);
		$quote = $this->quotes[$index];
		$english = is_array($quote) ? $quote['en'] : $quote;
		$indonesia = is_array($quote) ? $quote['id'] : $quote;
		return ['en' => $english, 'id' => $indonesia];
	}

	private function _ensureOneOnly($cronExpression, $command)
	{
		$desiredLine = $this->_buildLine($cronExpression, $command);
		$currentCron = $this->_readCrontab();

		$found   = false;
		$newCron = [];

		foreach ($currentCron as $line) {
			$isMatch = strpos($line, $command) !== false;

			if ($isMatch && !$found) {
				if (trim($line) === $desiredLine) {
					return true;
				}
				$newCron[] = $desiredLine;
				$found     = true;
			} elseif ($isMatch && $found) {
				continue;
			} else {
				$newCron[] = $line;
			}
		}

		if (!$found) {
			$newCron[] = $desiredLine;
		}

		return $this->_writeCrontab($newCron);
	}

	private function _ensureCronRegistered()
	{
		if ($this->exists($this->cron_command)) {
			return;
		}
		$this->_ensureOneOnly($this->_getCronTime(), $this->cron_command);
	}

	private function _readCrontab()
	{
		$lines      = [];
		$returnCode = 0;
		exec('crontab -l 2>/dev/null', $lines, $returnCode);
		if ($returnCode === 0 || empty($lines)) {
			return $lines;
		}
		return [];
	}

	private function _writeCrontab($lines)
	{
		$content = implode("\n", $lines) . "\n";
		$tmpFile = tempnam(sys_get_temp_dir(), 'cron_');

		if ($tmpFile === false) {
			return false;
		}

		file_put_contents($tmpFile, $content);
		exec('crontab ' . escapeshellarg($tmpFile), $output, $returnCode);
		unlink($tmpFile);

		return $returnCode === 0;
	}

	private function _buildLine($cronExpression, $command)
	{
		return trim($cronExpression) . ' ' . trim($command);
	}

	private function _cleansePhoneNumber($number)
	{
		$cleaned = [];

		if (is_array($number)) {
			foreach ($number as $num) {
				$cleaned = array_merge($cleaned, $this->_cleansePhoneNumber($num));
			}
			return $cleaned;
		}

		preg_match_all('/[\w.-]+\d+@g\.us/i', $number, $groupMatches);
		if (!empty($groupMatches[0])) {
			foreach ($groupMatches[0] as $gid) {
				$cleaned[] = $gid;
			}
		}
		$number = preg_replace('/[\w.-]+\d+@g\.us/i', '', $number);

		preg_match_all('/(\+?\d+)/', str_replace(['-', ' '], '', $number), $matches);

		if (!empty($matches[0])) {
			foreach ($matches[0] as $match) {
				$digitsOnly = preg_replace('/[^0-9]/', '', $match);

				if (substr($digitsOnly, 0, 1) === '0') {
					$digitsOnly = '62' . ltrim($digitsOnly, '0');
				}

				if (substr($digitsOnly, 0, 2) !== '62' && substr($digitsOnly, 0, 2) !== '60') {
					$digitsOnly = '62' . $digitsOnly;
				}

				if (strlen($digitsOnly) >= 10) {
					$cleaned[] = $digitsOnly;
				}
			}
		}

		return array_values(array_unique($cleaned));
	}
}

// ============================================
// CLI HANDLER
// ============================================

if (PHP_SAPI === 'cli') {
	$action = isset($argv[1]) ? $argv[1] : null;
	$sender = new QuoteSender();

	switch ($action) {
		case 'send':
		case 'send-daily':
			$res = $sender->sendDaily();
			exit($res['status'] ? 0 : 1);

		case 'setup-cron':
			$res = $sender->setupCron();
			if (empty($res['failed'])) {
				echo "✅ Cronjob registered successfully\n";
				print_r($res['ok']);
				exit(0);
			} else {
				echo "❌ Cronjob setup failed\n";
				print_r($res['failed']);
				exit(1);
			}
			break;

		case 'test':
			$sender->testSend();
			exit(0);
			break;

		case 'list-cron':
			$cron = $sender->listAll();
			echo "Current crontab entries:\n";
			foreach ($cron as $line) {
				echo "  $line\n";
			}
			exit(0);
			break;

		default:
			echo "Usage: php index.php [send|setup-cron|test|list-cron]\n";
			exit(1);
	}
} else {
	// Debug output for web access
	header('Content-Type: text/plain; charset=utf-8');
	echo "DEBUG: QuoteSender script accessed via web.\n";
	echo "PHP_SAPI: " . PHP_SAPI . "\n";
	echo "This script is designed to be run from CLI. Use:\n";
	echo "  php index.php test\n";
	echo "  php index.php send\n";
	echo "  php index.php setup-cron\n";
	echo "  php index.php list-cron\n";

	// For testing, we can also try to run sendDaily and show results
	if (isset($_GET['debug']) && $_GET['debug'] == '1') {
		echo "\n--- Running sendDaily for debugging ---\n";
		$sender = new QuoteSender();
		$res = $sender->sendDaily();
		echo "Status: " . ($res['status'] ? 'SUCCESS' : 'FAILED') . "\n";
		echo "Message:\n" . $res['message'] . "\n";
		echo "Details:\n";
		print_r($res['details']);
	}
}
