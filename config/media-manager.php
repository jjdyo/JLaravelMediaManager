<?php

return [
    // Human readable upload size limit. Examples: '500KB', '5MB', '1GB'
    // Default to 5MB to avoid excessive memory usage during image decoding/thumbnailing
    'max_file_size' => env('MEDIA_MAX_FILE_SIZE', '5MB'),

    // Root directories visible in the picker. Values are labels for UI.
    'allowed_directories' => [
        'logos' => ['label' => 'Brand Logos'],
        'trainers' => ['label' => 'Trainer Media'],
        'horses' => ['label' => 'Horse Media'],
        'timeslots' => ['label' => 'Timeslot Media'],
        'misc' => ['label' => 'Miscellaneous'],
    ],

    // Scanner bounds to avoid deep traversal or runaway trees
    'scan_depth' => 3,
    // How many levels users can create new subfolders under the chosen root
    'allowed_folder_nest' => 3,

    // Thumbnail generation settings
    'thumbnails' => [
        // name => [width, height, quality]
        '64' => [64, 64, 80],
        '256' => [256, 256, 80],
    ],

    // Soft safety limits for decoding/thumbnailing large raster images
    // If an uploaded image exceeds either of these thresholds, we will skip
    // thumbnail generation to prevent memory exhaustion on GD/Imagick.
    'thumbnail_max_pixels' => env('MEDIA_THUMB_MAX_PIXELS', 40000000), // 40 MP
    'thumbnail_max_filesize_bytes' => env('MEDIA_THUMB_MAX_SIZE', 20 * 1024 * 1024), // 20 MB

    // Disk and visibility
    'disk' => env('MEDIA_DISK', 'public'),
    'visibility' => 'public',

    // Control verbosity of info-level logs during uploads/thumbnailing.
    // Warnings and errors are always logged.
    'verbose_logging' => env('MEDIA_VERBOSE_LOGGING', false),

    // Allow uploading SVG images. SVGs can contain scripts; disable if untrusted.
    'allow_svg' => env('MEDIA_ALLOW_SVG', true),

    // If false, role checking is disabled and all authenticated users can read & upload.
    // If true, role checking applies ONLY to write operations (upload, folder creation).
    // Reads/browsing are allowed for any authenticated user to enable reuse of existing media.
    'enforce_spatie_permission' => env('MEDIA_ENFORCE_SPATIE', false),

    // Permissions by directory using Spatie roles (WRITE/UPLOAD ONLY)
    // Listing/browsing is allowed for any authenticated user regardless of roles.
    'permissions' => [
        'logos' => ['admin', 'marketing'],
        'trainers' => [],
        '*' => ['admin'],
    ],
];
