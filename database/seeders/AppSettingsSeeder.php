<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingsSeeder extends Seeder
{
    /**
     * Default billing / general settings for the Elyek platform.
     *
     * @var array<int, array{key: string, value: string, type: string, group: string}>
     */
    protected array $defaults = [
        [
            'key' => 'default_session_price',
            'value' => '500',
            'type' => 'integer',
            'group' => 'billing',
        ],
        [
            'key' => 'session_currency',
            'value' => 'EGP',
            'type' => 'string',
            'group' => 'billing',
        ],
        [
            'key' => 'fawry_expiry_hours',
            'value' => '24',
            'type' => 'integer',
            'group' => 'billing',
        ],
        [
            'key' => 'auto_refund_on_session_cancel',
            'value' => '1',
            'type' => 'boolean',
            'group' => 'billing',
        ],
    ];

    public function run(): void
    {
        foreach ($this->defaults as $setting) {
            AppSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'group' => $setting['group'],
                ]
            );
        }
    }
}
