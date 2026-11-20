<?php

return [

    // Users pay Pixelforge's cost plus this markup.
    'markup_percent' => 25,

    // Free credit every new account starts with, in US dollars.
    'welcome_credit_usd' => '1.00',

    // Fake "Buy credits" packs, in US dollars.
    'credit_packs_usd' => ['5.00', '10.00', '25.00'],

    // How many images one user can have waiting or generating at once.
    'max_in_progress_per_user' => 6,

    // Where finished images are copied, because Pixelforge's URLs expire after 24 hours.
    // Use an object storage disk (s3) in production: app servers' disks are not shared or kept.
    // Falls back to the default disk, which Laravel Cloud sets to the attached bucket.
    'image_disk' => env('GENERATION_IMAGE_DISK', env('FILESYSTEM_DISK', 'local')),

];
