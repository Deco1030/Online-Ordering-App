<?php
declare(strict_types=1);

// Central configuration. Adjust DB credentials for your environment.

return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'online_ordering_db',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'base_url' => '', // e.g. '/OnlineOrderingSystem' if deployed in subfolder
        'uploads_dir' => __DIR__ . '/uploads',
        'documents_dir' => __DIR__ . '/uploads/documents',
        'invoices_dir' => __DIR__ . '/uploads/invoices',
    ],
];

