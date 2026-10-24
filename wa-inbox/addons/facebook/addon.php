<?php
// Facebook and Instagram add-on: a Facebook page (Messenger, comments, posts, insights) and the page's Instagram direct messages are accounts like a number.
// The core reaches them only through function_exists checks and hooks; removing the addons/facebook folder leaves the WhatsApp product working.
require_once __DIR__ . '/facebook.php';
require_once __DIR__ . '/facebook_feed.php';
require_once __DIR__ . '/facebook_link.php';
require_once __DIR__ . '/instagram.php';
wi_hook_add('api_handlers', fn(array $H) => array_merge($H, ['wf_api_fbfeed', 'wf_api_farieq_instagram']));
