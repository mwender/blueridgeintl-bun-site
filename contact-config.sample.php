<?php
/**
 * SMTP2GO config for the contact form — SAMPLE.
 *
 * SETUP (on the server):
 *   1. Copy this file to ONE LEVEL ABOVE the web root and rename it
 *      `contact-config.php`. If the web root is /…/blueridgelogistics.com/public,
 *      put it at /…/blueridgelogistics.com/contact-config.php so it is NOT
 *      web-accessible.
 *      (Or place it anywhere and point the $BRL_CONTACT_CONFIG env var at it.)
 *   2. Fill in the values below.
 *
 * This file is never copied into the build (scripts/html-to-php.mjs skips
 * *.sample.php and contact-config.php), so the real key stays out of the webroot
 * and out of git.
 */

return [
    // SMTP2GO HTTP API key — dashboard → Sending → API Keys.
    'api_key' => 'api-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',

    // Verified sender on your SMTP2GO account. Use a mailbox on a sender
    // domain you've verified there (so SPF/DKIM pass). "Name <addr>" is allowed.
    'sender'  => 'Blue Ridge Website <no-reply@blueridgelogistics.com>',

    // Where submissions are delivered. A single address, or an array for
    // multiple recipients (up to 100).
    'to'      => 'mwender@wenmarkdigital.com',
];
