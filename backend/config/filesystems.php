<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        // Private: milestone submissions, evaluation attachments (Module 3)
        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
            'report' => false,
        ],

        // Private: long-term archive (Module 7) — swap to S3 in production
        'archive' => [
            'driver' => 'local',
            'root'   => storage_path('app/archive'),
            'throw'  => false,
            'report' => false,
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw'      => false,
            'report'     => false,
        ],

        's3' => [
            'driver'   => 's3',
            'key'      => env('AWS_ACCESS_KEY_ID'),
            'secret'   => env('AWS_SECRET_ACCESS_KEY'),
            'region'   => env('AWS_DEFAULT_REGION'),
            'bucket'   => env('AWS_BUCKET'),
            'url'      => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),

            /**
             * Submissions are private, and say so explicitly.
             *
             * Laravel defaults the S3 visibility converter to `public`, which
             * asks the service to make each object world-readable. Supabase
             * ignores object ACLs (so this changes nothing there) and R2 has no
             * ACLs at all — but on a provider that *does* honour them, the
             * default would publish every student's thesis. Naming `private`
             * makes the intent explicit and removes the trap.
             *
             * The real control is the bucket itself being private, which is
             * verified separately: an anonymous fetch of an object path returns
             * 403. Downloads always go through the policy-checked, audited
             * route regardless.
             */
            'visibility' => 'private',

            /**
             * Do not attach a checksum the service may not support.
             *
             * aws-sdk-php 3.337+ defaults `request_checksum_calculation` to
             * `when_supported`, which makes it send CRC32 headers on every
             * upload. Supabase Storage does not implement `Content-MD5` (and
             * documents that), and R2 only implements CRC-32 as COMPOSITE — so
             * a header the service rejects turns every upload into a failure.
             * `when_required` sends a checksum only where the operation demands
             * one, which is the compatible setting for both.
             *
             * The config array is passed straight into `S3Client`, so this key
             * reaches the SDK. Integrity is not lost: `submit()` still records
             * a SHA-256 of every upload in `submission_files.checksum_sha256`,
             * which is what the archive and migration command verify against.
             */
            'request_checksum_calculation' => env('AWS_REQUEST_CHECKSUM_CALCULATION', 'when_required'),

            /**
             * Throw, unlike the local disks above.
             *
             * With `throw => false` Flysystem reports a failed write by
             * returning `false` instead of raising, and the caller has to
             * remember to check. Object storage is where writes actually fail —
             * expired credentials, a wrong bucket, a dropped connection, a
             * region mismatch — and a silently swallowed failure there means a
             * student's thesis is reported as submitted while no bytes exist.
             * Raising makes that a loud 500 instead.
             *
             * `MilestoneController::submit()` handles both modes, so a failed
             * upload cannot become a file row pointing at nothing either way.
             */
            'throw'    => true,
            'report'   => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
