<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Delivers one contact-form message. Started in the background by ContactController so the website never waits for SMTP. */
class SendContactMessage extends Command
{
    protected $signature = 'contact:send {file : JSON file written by the controller}';

    protected $description = 'Email a contact-form message to the site owner (internal)';

    public function handle(): int
    {
        $file = $this->argument('file');
        $job = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        @unlink($file);
        if (! $job) {
            return self::FAILURE;
        }

        ['to' => $to, 'body' => $body, 'data' => $data] = $job;
        try {
            Mail::raw($body, function ($mail) use ($to, $data) {
                $mail->to($to)
                    ->replyTo($data['email'], $data['name'])
                    ->subject('[FoodOrder] ' . ($data['category'] ?? 'Contact') . ': ' . $data['subject']);
            });
        } catch (\Throwable $e) {
            Log::error('Contact form email failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}