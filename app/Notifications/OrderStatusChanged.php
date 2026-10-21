<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to the customer when their order moves forward. Queued, so a slow or down mail
 * server never delays the kitchen. SMS or push can be added as extra channels in via().
 */
class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const COPY = [
        'accepted' => ['Your order was accepted', 'The restaurant accepted order %s and will start cooking soon.'],
        'preparing' => ['Your food is being prepared', 'The kitchen started preparing order %s.'],
        'ready' => ['Your order is ready', 'Order %s is ready and waiting for the driver.'],
        'dispatched' => ['Your order is on the way', 'A driver picked up order %s and is heading to you.'],
        'delivered' => ['Order delivered', 'Order %s was delivered. Enjoy your meal!'],
        'cancelled' => ['Your order was cancelled', 'Order %s was cancelled.'],
        'rejected' => ['The restaurant could not accept your order', 'Order %s was rejected by the restaurant.'],
    ];

    public function __construct(public string $orderNumber, public string $status, public ?string $restaurant = null) {}

    public static function handles(string $status): bool
    {
        return isset(self::COPY[$status]);
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line] = self::COPY[$this->status];

        return (new MailMessage)
            ->subject($subject . ' - ' . $this->orderNumber)
            ->greeting('Hello ' . ($notifiable->name ?? ''))
            ->line(sprintf($line, $this->orderNumber) . ($this->restaurant ? " ({$this->restaurant})" : ''))
            ->action('Track your order', rtrim(config('app.url'), '/') . '/orders');
    }
}
