<?php

namespace App\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoApiTransport extends AbstractTransport
{
    protected string $apiKey;

    public function __construct(string $apiKey)
    {
        parent::__construct();
        $this->apiKey = $apiKey;
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (!$email instanceof Email) {
            throw new \RuntimeException('BrevoApiTransport only supports Email messages.');
        }

        $fromAddress = $email->getFrom()[0] ?? null;

        $payload = [
            'sender' => [
                'email' => $fromAddress?->getAddress(),
                'name'  => $fromAddress?->getName() ?: null,
            ],
            'to' => array_map(fn ($addr) => array_filter([
                'email' => $addr->getAddress(),
                'name'  => $addr->getName() ?: null,
            ]), $email->getTo()),
            'subject'     => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody() ?: null,
            'textContent' => $email->getTextBody() ?: null,
        ];

        // Retry once — the first request to an external API host from
        // Railway can occasionally time out on a cold connection.
        $attempts = 0;
        $response = null;

        while ($attempts < 2) {
            $attempts++;
            try {
                $response = Http::withHeaders([
                    'api-key'      => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])->timeout(30)->post('https://api.brevo.com/v3/smtp/email', $payload);

                if ($response->successful()) {
                    return;
                }

                Log::warning('BrevoApiTransport attempt failed', [
                    'attempt' => $attempts,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
            } catch (\Exception $e) {
                Log::warning('BrevoApiTransport attempt exception', [
                    'attempt' => $attempts,
                    'error'   => $e->getMessage(),
                ]);
                if ($attempts >= 2) {
                    throw $e;
                }
            }
        }

        if ($response && !$response->successful()) {
            throw new \RuntimeException(
                'Brevo API send failed: ' . $response->status() . ' ' . $response->body()
            );
        }
    }

    public function __toString(): string
    {
        return 'brevo+api';
    }
}