<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run()
    {
        $users = User::all();
        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'system',
                'title' => 'Welcome to the platform!',
                'body' => 'We are happy to have you. Start ordering now.',
                'data' => ['link' => '/dashboard'],
                'is_read' => false,
            ]);
        }
    }
}