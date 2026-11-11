<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Landing Page menu: every group and page gets its own icon instead of one shared icon.
return new class extends Migration {
    private const ICONS = [
        'landing-home' => 'House', 'landing-services' => 'Briefcase', 'landing-about' => 'Info',
        'landing-home-perks' => 'Zap', 'landing-home-stats' => 'BarChart3', 'landing-home-categories' => 'Utensils',
        'landing-home-services' => 'Gift', 'landing-home-story' => 'BookOpen', 'landing-home-help' => 'LifeBuoy',
        'landing-services-tools' => 'Wrench', 'landing-services-points' => 'ListChecks', 'landing-services-features' => 'Sparkles',
        'landing-about-numbers' => 'Hash', 'landing-about-pillars' => 'Landmark', 'landing-about-timeline' => 'Milestone', 'landing-about-impact' => 'Leaf',
    ];

    public function up()
    {
        foreach (self::ICONS as $key => $icon) {
            DB::table('navigation_items')->where('key', $key)->update(['icon' => $icon, 'updated_at' => now()]);
        }
    }

    public function down()
    {
        DB::table('navigation_items')->whereIn('key', array_keys(self::ICONS))->update(['icon' => 'LayoutGrid', 'updated_at' => now()]);
    }
};
