<?php

declare(strict_types=1);

/**
 * Sovereign Engine - Root Entrypoint for Shared Hosting (Hostinger, cPanel, Apache, LiteSpeed).
 * Delegates seamlessly to the public front controller.
 */

require_once __DIR__ . '/public/front.php';
