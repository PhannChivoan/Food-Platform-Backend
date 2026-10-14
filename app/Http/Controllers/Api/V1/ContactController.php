<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ContactController extends Controller
{
    /** Public "Contact us" form: emails the message to the site owner (CONTACT_TO_EMAIL). */
    public function send(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:40',
            'order_id' => 'nullable|string|max:60',
            'category' => 'nullable|string|max:60',
            'subject' => 'required|string|max:190',
            'message' => 'required|string|max:5000',
        ]);

        $to = config('mail.contact_to');
        $body = implode("\n", [
            'New message from the FoodOrder contact form',
            '',
            "Type:     " . ($data['category'] ?? 'General Inquiry'),
            "Name:     {$data['name']}",
            "Email:    {$data['email']}",
            'Phone:    ' . ($data['phone'] ?? '-'),
            'Order ID: ' . ($data['order_id'] ?? '-'),
            '',
            "Subject:  {$data['subject']}",
            '',
            $data['message'],
        ]);

        // Answer the visitor right away. The email is delivered by a separate background process, so a slow
        // SMTP server can never hold up (or block) the website, even on single-threaded dev servers.
        if (! $this->sendInBackground($to, $body, $data)) {
            dispatch(function () use ($to, $body, $data) {
                try {
                    Mail::raw($body, function ($mail) use ($to, $data) {
                        $mail->to($to)
                            ->replyTo($data['email'], $data['name'])
                            ->subject('[FoodOrder] ' . ($data['category'] ?? 'Contact') . ': ' . $data['subject']);
                    });
                } catch (\Throwable $e) {
                    Log::error('Contact form email failed: ' . $e->getMessage());
                }
            })->afterResponse();
        }
        // an explicit Content-Length lets the browser finish as soon as the body arrives, even on servers that keep the connection open while the mail is sent
        $json = json_encode(['message' => 'Your message has been sent.']);

        return response($json, 200, ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($json)]);
    }

    /** Starts `php artisan contact:send` detached from this request. Returns false if it could not be started. */
    private function sendInBackground(string $to, string $body, array $data): bool
    {
        try {
            $file = storage_path('app/contact-' . Str::uuid() . '.json');
            if (file_put_contents($file, json_encode(compact('to', 'body', 'data'))) === false) {
                return false;
            }

            // under php-fpm PHP_BINARY is the fpm binary, so use the CLI "php" there
            $php = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : 'php';
            $command = escapeshellarg($php) . ' ' . escapeshellarg(base_path('artisan')) . ' contact:send ' . escapeshellarg($file);

            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen('start /B "" ' . $command . ' > NUL 2>&1', 'r'));
            } else {
                exec($command . ' > /dev/null 2>&1 &');
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not start the background mailer: ' . $e->getMessage());

            return false;
        }
    }
}