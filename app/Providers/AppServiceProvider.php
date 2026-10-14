<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Events\SkillsMP\OrderCompleted;
use App\Events\SkillsMP\DeliveryCompleted;
use App\Events\SkillsMP\ReviewWritten;
use App\Events\SkillsMP\ReferralCompleted;
use App\Events\SkillsMP\DailyLogin;
use App\Events\SkillsMP\PromotionUsed;
use App\Events\SkillsMP\SkillLevelUp;
use App\Events\SkillsMP\BadgeEarned;
use App\Events\SkillsMP\SkillMastered;
use App\Listeners\SkillsMP\AwardExperienceListener;
use App\Listeners\SkillsMP\SkillsMPNotificationListener;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SkillsMP Event Listeners
        Event::listen(OrderCompleted::class, [AwardExperienceListener::class, 'handleOrderCompleted']);
        Event::listen(DeliveryCompleted::class, [AwardExperienceListener::class, 'handleDeliveryCompleted']);
        Event::listen(ReviewWritten::class, [AwardExperienceListener::class, 'handleReviewWritten']);
        Event::listen(ReferralCompleted::class, [AwardExperienceListener::class, 'handleReferralCompleted']);
        Event::listen(DailyLogin::class, [AwardExperienceListener::class, 'handleDailyLogin']);
        Event::listen(PromotionUsed::class, [AwardExperienceListener::class, 'handlePromotionUsed']);

        // SkillsMP Notification Listeners
        Event::listen(SkillLevelUp::class, [SkillsMPNotificationListener::class, 'handleSkillLevelUp']);
        Event::listen(BadgeEarned::class, [SkillsMPNotificationListener::class, 'handleBadgeEarned']);
        Event::listen(SkillMastered::class, [SkillsMPNotificationListener::class, 'handleSkillMastered']);
    }
}
