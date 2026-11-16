<?php

declare(strict_types=1);

return [
    'seo.status' => 'Search engine readiness',
    'seo.origin_ready' => 'The owner-configured canonical site address is available.',
    'seo.origin_missing' => 'Set a valid public site address before enabling sitemap '
        . 'discovery. Request host names are never used as a fallback.',
    'seo.complete' => 'The sitemap is ready. Every page still checks current public visibility.',
    'seo.building' => 'The sitemap is being prepared in small saved batches. Visits or '
        . 'the manual task runner continue it.',
    'seo.capacity' => 'The sitemap reached its documented capacity. It remains unpublished until the owner reviews it.',
    'seo.idle' => 'A sitemap request starts preparation once the site is configured.',
    'seo.feed.latest' => 'Latest discussions',
];
