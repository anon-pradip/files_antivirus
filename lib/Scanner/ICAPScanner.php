<?php

namespace OCA\Files_Antivirus\Scanner;

use OCA\Files_Antivirus\AppConfig;
use OCA\Files_Antivirus\IScannable;
use OCA\Files_Antivirus\Status;
use OCP\IL10N;
use OCP\ILogger;

class ICAPScanner implements IScanner {
	private IL10N $l10n;

	private string $data = '';
	private string $host;
	private int $port;
	private string $reqService;
	private string $virusHeader;
	private int $sizeLimit;
	private string $filename;

	public function __construct(AppConfig $config, ILogger $logger, IL10N $l10n) {
		$this->host = $config->getAvHost();
		$this->port = $config->getAvPort();
		$this->reqService = $config->getAvRequestService();
		$this->virusHeader = $config->getAvResponseHeader();
		$this->sizeLimit = $config->getAvMaxFileSize();
		$this->l10n = $l10n;
	}

	public function initScanner(string $fileName): void {
		// remove .ocTransferId444531916.part from part files
		$fileName = \preg_replace(
			'|\.ocTransferId\d+\.part$|',
			'',
			$fileName
		);

		$this->filename = $fileName;
	}

	public function onAsyncData($data): void {
		$hasNoSizeLimit = $this->sizeLimit === -1;
		$scannedBytes = \strlen($this->data);
		if ($hasNoSizeLimit || $scannedBytes <= $this->sizeLimit) {
			if ($hasNoSizeLimit === false && $scannedBytes + \strlen($data) > $this->sizeLimit) {
				$data = \substr($data, 0, $this->sizeLimit - $scannedBytes);
			}
			$this->data .= $data;
		}
	}

	/**
	 * @throws InitException
	 */
	public function completeAsyncScan(): Status {
		if ($this->data === '') {
			return Status::create(Status::SCANRESULT_CLEAN);
		}

		$requestHeaders = $this->buildBodyHeaders();
		$requestHeader = implode("\r\n", $requestHeaders);

		$icapHeaders = $this->getICAPHeaders();

		$c = new ICAPClient($this->host, $this->port);
		if ($this->usesReqMod()) {
			$response = $c->reqmod(
				$this->reqService,
				[
				'req-hdr' => "$requestHeader\r\n\r\n",
				'req-body' => $this->data
			],
				$icapHeaders
			);
		} else {
			$response = $c->respmod(
				$this->reqService,
				[
				'req-hdr' => "",
				'res-hdr' => "$requestHeader\r\n\r\n",
				'res-body' => $this->data
			],
				$icapHeaders
			);
		}
		$code = $response['protocol']['code'] ?? 500;
		if ($code === 100 || $code === 200 || $code === 204) {
			// c-icap/clamav reports this header
			$virus = $response['headers'][$this->virusHeader] ?? false;
			if ($virus) {
				return Status::create(Status::SCANRESULT_INFECTED, $virus);
			}

			// kaspersky(pre-2020 product editions) and McAfee handling
			$respHeader = $response['body']['res-hdr']['HTTP_STATUS'] ?? '';
			if (\strpos($respHeader, '403 Forbidden') !== false || \strpos($respHeader, '403 VirusFound') !== false) {
				$message = $this->l10n->t('A malware or virus was detected, your upload was deleted. In doubt or for details please contact your system administrator');
				return Status::create(Status::SCANRESULT_INFECTED, $message);
			}
		} else {
			throw new \RuntimeException('AV failed!');
		}
		return Status::create(Status::SCANRESULT_CLEAN);
	}

	/**
	 * @throws InitException
	 */
	public function scan(IScannable $item): Status {
		$this->initScanner($item->getFilename());
		while (($chunk = $item->fread()) !== false) {
			$this->onAsyncData($chunk);
		}
		$status = $this->completeAsyncScan();

		$this->shutdownScanner();
		return $status;
	}

	public function shutdownScanner(): void {
	}

	protected function getContentLength(): int {
		return \strlen($this->data);
	}

	public function getFileName(): string {
		return $this->filename;
	}

	protected function usesReqMod(): bool {
		return true;
	}

	protected function buildBodyHeaders(): array {
		return [
			'PUT / HTTP/1.0',
			'Host: 127.0.0.1',
		];
	}

	protected function getICAPHeaders(): array {
		return [
			'Allow' => 204
		];
	}
}
