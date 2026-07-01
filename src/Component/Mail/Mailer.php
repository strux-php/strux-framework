<?php

declare(strict_types=1);

namespace Strux\Component\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;
use Strux\Component\Config\Config;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\View\ViewInterface;

class Mailer implements MailerInterface
{
	private Config $config;
	private DirectoryInterface $dirs;
	private ViewInterface $view;
	private LoggerInterface $logger;

	private array $to = [];
	private array $cc = [];
	private array $bcc = [];

	public function __construct(
		Config             $config,
		DirectoryInterface $dirs,
		ViewInterface      $view,
		LoggerInterface    $logger
	) {
		$this->config = $config;
		$this->dirs = $dirs;
		$this->view = $view;
		$this->logger = $logger;
	}

	/**
	 * @inheritDoc
	 */
	public function to(string $address, ?string $name = null): self
	{
		$this->to[] = ['address' => $address, 'name' => $name ?? ''];
		return $this;
	}

	/**
	 * @inheritDoc
	 */
	public function send(string $view, array $data = [], ?callable $callback = null): bool
	{
		try {
			$htmlBody = $this->view->render($view, $data);

			$defaultMailer = $this->config->get('mail.default', 'smtp');

			if ($defaultMailer === 'log') {
				return $this->sendLog($htmlBody, $data);
			}

			$mailer = $this->createMailerInstance();

			// Set recipients
			foreach ($this->to as $recipient) {
				$mailer->addAddress($recipient['address'], $recipient['name']);
			}

			// Set content
			$mailer->isHTML(true);
			$mailer->Subject = $data['subject'] ?? $this->config->get('app.name', 'Application');
			$mailer->Body = $htmlBody;
			$mailer->AltBody = strip_tags($htmlBody);

			// Allow for custom modifications, like adding attachments
			if ($callback) {
				$callback($mailer);
			}

			return $mailer->send();
		} catch (PHPMailerException $e) {
			$this->logger->error("Mailer Error: {$mailer->ErrorInfo}", ['exception' => $e]);
			return false;
		} catch (\Exception $e) {
			$this->logger->error("An error occurred while sending email: {$e->getMessage()}", ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Write email to log file instead of sending.
	 */
	private function sendLog(string $htmlBody, array $data): bool
	{
		$logDir = $this->dirs->get('logs');
		$logFile = $logDir . '/emails.log';
		$timestamp = date('Y-m-d H:i:s');
		$recipients = array_map(fn($r) => $r['address'], $this->to);
		$subject = $data['subject'] ?? $this->config->get('app.name', 'Application');

		$entry = sprintf(
			"[%s] To: %s | Subject: %s\n%s\n---\n",
			$timestamp,
			implode(', ', $recipients),
			$subject,
			$htmlBody
		);

		file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

		$this->logger->info('Email logged instead of sent', [
			'to' => $recipients,
			'subject' => $subject,
			'file' => $logFile,
		]);

		return true;
	}

	/**
	 * Creates and configures a PHPMailer instance based on the application's etc.
	 * @throws PHPMailerException
	 */
	private function createMailerInstance(): PHPMailer
	{
		$mailer = new PHPMailer(true);

		$defaultMailer = $this->config->get('mail.default', 'smtp');
		$config = $this->config->get("mail.mailers.{$defaultMailer}");

		$transport = $config['transport'] ?? 'smtp';

		if ($transport === 'sendmail') {
			$mailer->isSendmail();
			if (!empty($config['path'])) {
				$mailer->Sendmail = $config['path'];
			}
		} elseif ($transport === 'smtp') {
			$mailer->isSMTP();
			$mailer->Host = $config['host'];
			$mailer->SMTPAuth = true;
			$mailer->Username = $config['username'];
			$mailer->Password = $config['password'];
			$mailer->SMTPSecure = $config['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
			$mailer->Port = $config['port'];
		}

		$fromAddress = $this->config->get('mail.from.address', 'hello@example.com');
		$fromName = $this->config->get('mail.from.name', 'Example');
		$mailer->setFrom($fromAddress, $fromName);

		$mailer->SMTPDebug = $this->config->get('mail.debug', 0);

		return $mailer;
	}
}
