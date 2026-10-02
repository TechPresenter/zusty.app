<?php

declare(strict_types=1);

namespace Zuste\Admin;

/**
 * The third-party integration catalogue.
 *
 * Every integration is declared here as data, so the hub renders ~50 services
 * through one tested screen. Adding a service is a new entry, not a new file.
 *
 * Three declarations matter most:
 *
 *  - `settings_map` mirrors a credential into the `settings` row an existing
 *    service already reads. MailService, PaymentService, SmsService and
 *    FirebaseService therefore keep working untouched — the hub becomes the UI
 *    for credentials they already consume, rather than a second copy of them.
 *
 *  - `delegate` marks an integration that another module already owns in full.
 *    The hub links to it and reports its status instead of duplicating it.
 *
 *  - `test` declares how a connection check is performed. `http` makes a real
 *    request; `internal` calls a service; `config` only verifies that the
 *    required fields are present, which is the honest answer for a service
 *    with no cheap, side-effect-free probe.
 */
final class Integration
{
    public const CATEGORIES = [
        'seo'        => ['label' => 'SEO & Search',        'icon' => 'search',      'tone' => '#2563EB'],
        'analytics'  => ['label' => 'Analytics & Tracking', 'icon' => 'chart',      'tone' => '#7C3AED'],
        'push'       => ['label' => 'Push Notifications',  'icon' => 'bell',        'tone' => '#DB2777'],
        'email'      => ['label' => 'Email',               'icon' => 'inbox',       'tone' => '#0891B2'],
        'whatsapp'   => ['label' => 'WhatsApp',            'icon' => 'chat',        'tone' => '#059669'],
        'sms'        => ['label' => 'SMS Gateways',        'icon' => 'chat',        'tone' => '#0D9488'],
        'payments'   => ['label' => 'Payments',            'icon' => 'card',        'tone' => '#B80049'],
        'social'     => ['label' => 'Social Login',        'icon' => 'user-check',  'tone' => '#4F46E5'],
        'storage'    => ['label' => 'Cloud Storage',       'icon' => 'database',    'tone' => '#EA580C'],
        'maps'       => ['label' => 'Maps & Location',     'icon' => 'flag',        'tone' => '#16A34A'],
        'ai'         => ['label' => 'AI Services',         'icon' => 'star',        'tone' => '#632CE5'],
        'monitoring' => ['label' => 'Monitoring & Errors', 'icon' => 'alert',       'tone' => '#DC2626'],
        'cdn'        => ['label' => 'CDN & Performance',   'icon' => 'trending-up', 'tone' => '#0284C7'],
        'security'   => ['label' => 'Security',            'icon' => 'shield',      'tone' => '#B45309'],
        'platform'   => ['label' => 'Platform Operations', 'icon' => 'settings',    'tone' => '#475569'],
        // The three built-in sign-in methods. They carry no credentials of their
        // own — their credentials already live in the `sms`, `mail` and
        // `security` settings groups — but they need a home for an enable flag,
        // a status, a connection test and an activity log, all of which the hub
        // already provides. Declaring an internal capability here is the
        // established pattern (xml_sitemap, robots_txt, cron_jobs and five
        // others do the same). google_login and apple_login deliberately stay
        // in 'social' so no existing card moves.
        'auth'       => ['label' => 'Authentication & Login', 'icon' => 'key',     'tone' => '#B80049'],
    ];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string,array<string,array<string,mixed>>> keyed by category */
    public static function byCategory(): array
    {
        $grouped = [];

        foreach (self::CATEGORIES as $slug => $meta) {
            $grouped[$slug] = [];
        }

        foreach (self::all() as $key => $definition) {
            $grouped[$definition['category']][$key] = $definition;
        }

        return array_filter($grouped, static fn (array $set): bool => $set !== []);
    }

    /** Field names that hold a secret, for one integration. @return list<string> */
    public static function secretFields(string $key): array
    {
        $definition = self::find($key);

        if ($definition === null) {
            return [];
        }

        $secrets = [];

        foreach ($definition['fields'] as $field) {
            if (($field['secret'] ?? false) === true) {
                $secrets[] = $field['name'];
            }
        }

        return $secrets;
    }

    // -----------------------------------------------------------------
    // Declaration helpers — keep the catalogue below readable.
    // -----------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>> $fields
     * @param  array<string,mixed>       $extra
     * @return array<string,mixed>
     */
    private static function make(
        string $name,
        string $category,
        string $vendor,
        string $description,
        string $doc,
        array $fields,
        array $extra = []
    ): array {
        return array_merge([
            'name'         => $name,
            'category'     => $category,
            'vendor'       => $vendor,
            'description'  => $description,
            'doc'          => $doc,
            'icon'         => self::CATEGORIES[$category]['icon'] ?? 'grid',
            'auth'         => 'api_key',
            'fields'       => $fields,
            'test'         => ['type' => 'config'],
            'webhook'      => false,
            // Does this platform actually expose an endpoint that receives the
            // provider's callbacks? `webhook` only says the provider can send
            // them. The hub used to advertise a callback URL for every
            // integration with webhook=true, and nine of the ten 404'd —
            // deliveries pasted into a provider dashboard would have been
            // dropped without a trace. Only turn this on together with a route
            // in routes/api.php.
            'webhook_receiver' => false,
            'sync'         => false,
            'settings_map' => [],
            'delegate'     => null,
            'notes'        => '',
        ], $extra);
    }

    /** @return array<string,mixed> */
    private static function text(string $name, string $label, string $hint = '', string $rules = 'nullable|string|max:255'): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'text', 'secret' => false, 'optional' => false, 'hint' => $hint, 'rules' => $rules];
    }

    /** @return array<string,mixed> */
    private static function secret(string $name, string $label, string $hint = '', string $rules = 'nullable|string|max:2000'): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'password', 'secret' => true, 'optional' => false, 'hint' => $hint, 'rules' => $rules];
    }

    /** @return array<string,mixed> */
    private static function textarea(string $name, string $label, string $hint = '', bool $isSecret = false): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'textarea', 'secret' => $isSecret, 'optional' => false,
                'hint' => $hint, 'rules' => 'nullable|string|max:20000'];
    }

    /** @return array<string,mixed> */
    private static function choice(string $name, string $label, array $options, string $hint = ''): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'select', 'secret' => false, 'optional' => false, 'hint' => $hint,
                'options' => $options, 'rules' => 'nullable|in:' . implode(',', array_keys($options))];
    }

    /** @return array<string,mixed> */
    private static function toggle(string $name, string $label, string $hint = ''): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'switch', 'secret' => false, 'optional' => true, 'hint' => $hint, 'rules' => 'nullable|boolean'];
    }

    /**
     * Mark a field as not required for the integration to work.
     *
     * "Configured" and "the connection test passes" have to agree, or the
     * detail screen shows two verdicts on one page — which it did: the pill
     * read Connected off the first non-empty field while the test underneath
     * listed what was still missing. Both now read the same list of required
     * fields, so a field that genuinely is not needed (a build-time value
     * recorded for reference, a token only the server-side flow uses) has to
     * say so here rather than be silently tolerated by one side.
     *
     * @param  array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function optional(array $field): array
    {
        $field['optional'] = true;

        return $field;
    }

    // -----------------------------------------------------------------
    // The catalogue
    // -----------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private static function build(): array
    {
        return array_merge(
            self::seo(),
            self::analytics(),
            self::push(),
            self::email(),
            self::whatsapp(),
            self::sms(),
            self::payments(),
            self::social(),
            self::authMethods(),
            self::storage(),
            self::maps(),
            self::ai(),
            self::monitoring(),
            self::cdn(),
            self::security(),
            self::platform(),
        );
    }

    /** @return array<string,array<string,mixed>> */
    private static function seo(): array
    {
        return [
            'google_search_console' => self::make(
                'Google Search Console', 'seo', 'Google',
                'Submit sitemaps and read search performance for the public site.',
                'https://developers.google.com/webmaster-tools',
                [
                    self::text('site_url', 'Verified property URL', 'Exactly as it appears in Search Console, e.g. https://zustee.com/'),
                    self::textarea('service_account_json', 'Service account JSON', 'Grant the service account read access to the property.', true),
                ],
                ['auth' => 'service_account', 'sync' => true,
                 'notes' => 'Sync pulls the last 28 days of impressions, clicks and average position.']
            ),

            'google_indexing_api' => self::make(
                'Google Indexing API', 'seo', 'Google',
                'Ask Google to crawl a URL as soon as content is published or removed.',
                'https://developers.google.com/search/apis/indexing-api/v3/quickstart',
                [
                    self::textarea('service_account_json', 'Service account JSON', 'Needs the Indexing API enabled on the project.', true),
                    self::toggle('auto_submit', 'Submit automatically', 'Ping Google when a page or blog post is published.'),
                ],
                ['auth' => 'service_account']
            ),

            'bing_webmaster' => self::make(
                'Bing Webmaster Tools', 'seo', 'Microsoft',
                'Submit URLs and read Bing search performance.',
                'https://learn.microsoft.com/en-us/bingwebmaster/getting-access',
                [
                    self::text('site_url', 'Verified site URL'),
                    self::secret('api_key', 'API key'),
                ],
                ['test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://ssl.bing.com/webmaster/api.svc/json/GetUserSites?apikey={api_key}',
                            'expect' => 200]]
            ),

            'xml_sitemap' => self::make(
                'XML Sitemap', 'seo', 'Zusty',
                'The generator that writes public/sitemap.xml from pages, blogs, categories and creators.',
                'https://www.sitemaps.org/protocol.html',
                [],
                ['auth' => 'none', 'delegate' => '/admin/sitemap',
                 'notes' => 'Owned by the Sitemap module — configured and generated there.']
            ),

            'robots_txt' => self::make(
                'Robots.txt', 'seo', 'Zusty',
                'Crawler directives and the Sitemap: line served at /robots.txt.',
                'https://developers.google.com/search/docs/crawling-indexing/robots/intro',
                [],
                ['auth' => 'none', 'delegate' => '/admin/sitemap',
                 'notes' => 'Written by the Sitemap module alongside the sitemap.']
            ),

            'schema_org' => self::make(
                'Schema.org structured data', 'seo', 'Open standard',
                'JSON-LD emitted on public pages so search engines understand creators, reviews and offers.',
                'https://schema.org/docs/gs.html',
                [
                    self::text('organisation_name', 'Organisation name'),
                    self::text('logo_url', 'Logo URL', 'Absolute https URL, at least 112x112.'),
                    self::toggle('creator_profiles', 'Mark up creator profiles'),
                    self::toggle('breadcrumbs', 'Emit breadcrumb markup'),
                ],
                ['auth' => 'none']
            ),

            'open_graph' => self::make(
                'Open Graph & Twitter cards', 'seo', 'Open standard',
                'Link preview metadata for anything shared to social platforms and chat apps.',
                'https://ogp.me/',
                [
                    self::text('default_title', 'Fallback title'),
                    self::text('default_image', 'Fallback share image URL', 'Recommended 1200x630.'),
                    self::text('twitter_site', 'Twitter/X handle', 'Including the @.'),
                ],
                ['auth' => 'none']
            ),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function analytics(): array
    {
        return [
            'google_analytics_4' => self::make(
                'Google Analytics 4', 'analytics', 'Google',
                'Page views, events and conversions for the web app.',
                'https://developers.google.com/analytics/devguides/collection/ga4',
                [
                    self::text('measurement_id', 'Measurement ID', 'Looks like G-XXXXXXXXXX.'),
                    self::optional(self::secret('api_secret', 'Measurement Protocol API secret', 'Only needed for server-side events.')),
                    self::text('property_id', 'Property ID', 'Numeric; needed to read reports.'),
                    self::textarea('service_account_json', 'Service account JSON', 'Grants read access to the Data API.', true),
                ],
                ['sync' => true, 'notes' => 'Sync pulls sessions, users and conversions for the last 30 days.']
            ),

            'google_tag_manager' => self::make(
                'Google Tag Manager', 'analytics', 'Google',
                'Container snippet injected into the public site and web app.',
                'https://developers.google.com/tag-platform/tag-manager',
                [self::text('container_id', 'Container ID', 'Looks like GTM-XXXXXXX.')],
                ['auth' => 'none']
            ),

            'meta_pixel' => self::make(
                'Meta Pixel', 'analytics', 'Meta',
                'Conversion tracking and audience building for Facebook and Instagram ads.',
                'https://developers.facebook.com/docs/meta-pixel',
                [
                    self::text('pixel_id', 'Pixel ID'),
                    self::optional(self::secret('access_token', 'Conversions API access token', 'Only for server-side events.')),
                ]
            ),

            'linkedin_insight' => self::make(
                'LinkedIn Insight Tag', 'analytics', 'LinkedIn',
                'Conversion tracking and retargeting for LinkedIn campaigns.',
                'https://www.linkedin.com/help/lms/answer/a418880',
                [self::text('partner_id', 'Partner ID')],
                ['auth' => 'none']
            ),

            'microsoft_clarity' => self::make(
                'Microsoft Clarity', 'analytics', 'Microsoft',
                'Free session recordings and heatmaps.',
                'https://learn.microsoft.com/en-us/clarity/',
                [self::text('project_id', 'Project ID')],
                ['auth' => 'none']
            ),

            'hotjar' => self::make(
                'Hotjar', 'analytics', 'Hotjar',
                'Heatmaps, recordings and on-site surveys.',
                'https://help.hotjar.com/hc/en-us/articles/115009336727',
                [self::text('site_id', 'Site ID', 'Numeric.')],
                ['auth' => 'none']
            ),

            'firebase_analytics' => self::make(
                'Firebase Analytics', 'analytics', 'Google',
                'App-side analytics for the Android, iOS and web clients.',
                'https://firebase.google.com/docs/analytics',
                [self::text('measurement_id', 'Web measurement ID', 'Shared with the GA4 property.')],
                ['auth' => 'none', 'notes' => 'Credentials come from the Firebase integration; this row only records the web measurement id.']
            ),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function push(): array
    {
        return [
            'firebase_cloud_messaging' => self::make(
                'Firebase Cloud Messaging', 'push', 'Google',
                'Push notifications to the Android, iOS and web clients.',
                'https://firebase.google.com/docs/cloud-messaging',
                [
                    self::text('project_id', 'Firebase project ID', '', 'nullable|string|max:120'),
                    self::textarea('service_account_json', 'Service account JSON', 'Used to mint the FCM v1 access token.', true),
                    self::text('web_api_key', 'Web API key'),
                ],
                [
                    'auth'         => 'service_account',
                    'test'         => ['type' => 'internal', 'handler' => 'firebase'],
                    'settings_map' => [
                        'project_id'           => ['firebase', 'project_id'],
                        'service_account_json' => ['firebase', 'service_account_json'],
                        'web_api_key'          => ['firebase', 'web_api_key'],
                    ],
                    'notes' => 'Shares credentials with Firebase Storage and Social Login; saving here updates the Firebase settings the app already reads.',
                ]
            ),

            'onesignal' => self::make(
                'OneSignal', 'push', 'OneSignal',
                'Cross-platform push with segmentation and scheduling.',
                'https://documentation.onesignal.com/reference/rest-api-overview',
                [
                    self::text('app_id', 'App ID'),
                    self::secret('rest_api_key', 'REST API key'),
                ],
                ['test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://api.onesignal.com/apps/{app_id}',
                            'auth' => 'header', 'header' => 'Authorization: Basic {rest_api_key}', 'expect' => 200],
                 'webhook' => true]
            ),

            'web_push' => self::make(
                'Web Push (VAPID)', 'push', 'Open standard',
                'Browser push for the web app without a third-party service.',
                'https://developer.mozilla.org/en-US/docs/Web/API/Push_API',
                [
                    self::text('public_key', 'VAPID public key'),
                    self::secret('private_key', 'VAPID private key'),
                    self::text('subject', 'Contact subject', 'mailto: address or site URL for the push service.'),
                ]
            ),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function email(): array
    {
        return [
            'smtp' => self::make(
                'SMTP', 'email', 'Any provider',
                'Transactional email: OTPs, booking updates, invoices and campaigns.',
                'https://datatracker.ietf.org/doc/html/rfc5321',
                [
                    self::text('host', 'Host', 'e.g. smtp.hostinger.com'),
                    self::text('port', 'Port', '587 for STARTTLS, 465 for implicit TLS.', 'nullable|integer|min_value:1|max_value:65535'),
                    self::choice('encryption', 'Encryption', ['tls' => 'STARTTLS', 'ssl' => 'Implicit TLS', 'none' => 'None']),
                    self::text('username', 'Username'),
                    self::secret('password', 'Password'),
                    self::text('from_address', 'From address', '', 'nullable|email'),
                    self::text('from_name', 'From name'),
                ],
                [
                    'auth'         => 'basic',
                    'test'         => ['type' => 'internal', 'handler' => 'smtp'],
                    'settings_map' => [
                        'host'         => ['mail', 'host'],
                        'port'         => ['mail', 'port'],
                        'encryption'   => ['mail', 'encryption'],
                        'username'     => ['mail', 'username'],
                        'password'     => ['mail', 'password'],
                        'from_address' => ['mail', 'from_address'],
                        'from_name'    => ['mail', 'from_name'],
                    ],
                    'notes' => 'MailService reads these settings directly, so saving here changes what the platform actually sends with.',
                ]
            ),

            'gmail_smtp' => self::make(
                'Gmail SMTP', 'email', 'Google',
                'Send through a Google Workspace or Gmail account using an app password.',
                'https://support.google.com/mail/answer/185833',
                [
                    self::text('username', 'Gmail address', '', 'nullable|email'),
                    self::secret('app_password', 'App password', '16 characters, generated under Google Account security.'),
                ],
                ['auth' => 'basic', 'test' => ['type' => 'internal', 'handler' => 'smtp_gmail'],
                 'notes' => 'Applying this fills the SMTP integration with smtp.gmail.com:587 and your credentials.']
            ),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function whatsapp(): array
    {
        $wa = static fn (string $name, string $vendor, string $desc, string $doc, array $fields, array $extra = []): array
            => self::make($name, 'whatsapp', $vendor, $desc, $doc, $fields, array_merge(['webhook' => true], $extra));

        return [
            'whatsapp_cloud_api' => $wa(
                'WhatsApp Cloud API', 'Meta',
                'Meta-hosted WhatsApp Business messaging: templates, session replies and delivery receipts.',
                'https://developers.facebook.com/docs/whatsapp/cloud-api',
                [
                    self::text('phone_number_id', 'Phone number ID'),
                    self::text('business_account_id', 'WhatsApp Business Account ID'),
                    self::secret('access_token', 'Permanent access token'),
                    self::secret('verify_token', 'Webhook verify token', 'You choose this; Meta echoes it when verifying the callback.'),
                ],
                ['test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://graph.facebook.com/v21.0/{phone_number_id}',
                            'auth' => 'bearer', 'token_field' => 'access_token', 'expect' => 200]]
            ),

            'whatsapp_business_api' => $wa(
                'WhatsApp Business API (on-premise)', 'Meta',
                'Self-hosted WhatsApp Business API client.',
                'https://developers.facebook.com/docs/whatsapp/on-premises',
                [
                    self::text('base_url', 'Client base URL', 'e.g. https://wa.example.com/v1'),
                    self::text('username', 'Username'),
                    self::secret('password', 'Password'),
                ],
                ['auth' => 'basic']
            ),

            'twilio_whatsapp' => $wa(
                'Twilio WhatsApp', 'Twilio',
                'WhatsApp messaging through a Twilio sender.',
                'https://www.twilio.com/docs/whatsapp',
                [
                    self::text('account_sid', 'Account SID'),
                    self::secret('auth_token', 'Auth token'),
                    self::text('from_number', 'WhatsApp sender', 'e.g. whatsapp:+14155238886'),
                ],
                ['auth' => 'basic', 'test' => ['type' => 'http', 'method' => 'GET',
                    'url' => 'https://api.twilio.com/2010-04-01/Accounts/{account_sid}.json',
                    'auth' => 'basic', 'user_field' => 'account_sid', 'pass_field' => 'auth_token', 'expect' => 200]]
            ),

            'wati' => $wa('WATI', 'WATI', 'WhatsApp team inbox and broadcast platform.',
                'https://docs.wati.io/reference/introduction',
                [self::text('base_url', 'Tenant base URL', 'e.g. https://live-server-000.wati.io'),
                 self::secret('access_token', 'Access token')]),

            'interakt' => $wa('Interakt', 'Interakt', 'WhatsApp commerce and support automation.',
                'https://www.interakt.shop/resource-center/api-documentation/',
                [self::secret('api_key', 'Secret key')]),

            'aisensy' => $wa('AiSensy', 'AiSensy', 'WhatsApp campaign and chatbot platform.',
                'https://docs.aisensy.com/',
                [self::secret('api_key', 'API key'), self::text('campaign_name', 'Default campaign name')]),

            'gupshup' => $wa('Gupshup', 'Gupshup', 'WhatsApp and omnichannel messaging.',
                'https://docs.gupshup.io/docs/whatsapp-api-documentation',
                [self::secret('api_key', 'API key'), self::text('app_name', 'App name'),
                 self::text('source_number', 'Source number')]),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function sms(): array
    {
        return [
            'twilio_sms' => self::make('Twilio SMS', 'sms', 'Twilio',
                'Transactional SMS and OTP delivery.',
                'https://www.twilio.com/docs/sms',
                [self::text('account_sid', 'Account SID'), self::secret('auth_token', 'Auth token'),
                 self::text('from_number', 'Sender number', 'E.164, e.g. +14155238886')],
                ['auth' => 'basic', 'webhook' => true,
                 'test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://api.twilio.com/2010-04-01/Accounts/{account_sid}.json',
                            'auth' => 'basic', 'user_field' => 'account_sid', 'pass_field' => 'auth_token', 'expect' => 200],
                 'settings_map' => ['auth_token' => ['sms', 'api_key']]]),

            'msg91' => self::make('MSG91', 'sms', 'MSG91',
                'Indian SMS and OTP gateway with DLT template support.',
                'https://docs.msg91.com/',
                [self::secret('auth_key', 'Auth key'), self::text('sender_id', 'Sender ID', '6 characters, DLT registered.'),
                 self::text('otp_template_id', 'OTP template ID')],
                // The legacy balance API answers HTTP 200 with the plain body
                // "0" for an invalid key, which is indistinguishable from a
                // zero balance — so a status-only check passed for any key,
                // and msg91 mirrors that key into sms.api_key, which the
                // platform really sends OTPs with. The v5 endpoint names the
                // problem in the body instead. It is only asked to verify an
                // OTP with no OTP supplied, so nothing is sent or consumed;
                // a good key fails on the missing parameters, not on the key,
                // which is why this rejects on the refusal rather than
                // requiring a success flag.
                ['test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://control.msg91.com/api/v5/otp/verify?authkey={auth_key}',
                            'expect' => 200,
                            'reject_body'     => ['invalid authkey', 'authkey is invalid', 'authentication failure'],
                            'reject_message'  => 'MSG91 rejected this auth key.',
                            'success_message' => 'MSG91 accepted the auth key — it answered without an authentication error.',
                            'note' => 'Register the sender ID and DLT template with MSG91 before the first real send.'],
                 'settings_map' => ['auth_key' => ['sms', 'api_key'], 'sender_id' => ['sms', 'sender_id']]]),

            'textlocal' => self::make('Textlocal', 'sms', 'Textlocal',
                'Bulk SMS for India and the UK.',
                'https://api.textlocal.in/docs/',
                [self::secret('api_key', 'API key'), self::text('sender', 'Sender name')],
                ['test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://api.textlocal.in/balance/?apikey={api_key}', 'expect' => 200]]),

            'fast2sms' => self::make('Fast2SMS', 'sms', 'Fast2SMS',
                'Indian SMS gateway with a quick-send API.',
                'https://docs.fast2sms.com/',
                [self::secret('api_key', 'Authorization key'), self::text('sender_id', 'Sender ID')],
                ['test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://www.fast2sms.com/dev/wallet?authorization={api_key}', 'expect' => 200]]),

            'aws_sns' => self::make('AWS SNS', 'sms', 'Amazon',
                'SMS delivery through Amazon Simple Notification Service.',
                'https://docs.aws.amazon.com/sns/latest/dg/sns-mobile-phone-number-as-subscriber.html',
                [self::text('region', 'Region', 'e.g. ap-south-1'), self::text('access_key_id', 'Access key ID'),
                 self::secret('secret_access_key', 'Secret access key'), self::text('sender_id', 'Sender ID')],
                ['notes' => 'SigV4 signing means a connection test needs a signed request; configure and send a real test message to confirm.']),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function payments(): array
    {
        return [
            'cashfree' => self::make('Cashfree Payments', 'payments', 'Cashfree',
                'Indian payment gateway: UPI, cards, net banking, and payouts to creators.',
                'https://docs.cashfree.com/reference/pg-new-apis-endpoint',
                [
                    self::text('app_id', 'App ID'),
                    self::secret('secret_key', 'Secret key'),
                    self::choice('environment', 'Environment', ['sandbox' => 'Sandbox', 'production' => 'Production']),
                    self::secret('webhook_secret', 'Webhook signing secret'),
                ],
                [// POST /api/v1/webhooks/cashfree exists and checks the signature.
                 'webhook' => true, 'webhook_receiver' => true,
                 // PaymentService authenticates from the `payment` settings
                 // group (cashfreeSetting() reads payment.cashfree_*), not from
                 // the integrations row. Without this map, configuring Cashfree
                 // here saved credentials the gateway never read, and checkout
                 // still failed with "Cashfree is not configured" while the hub
                 // showed it connected.
                 // webhook_secret is deliberately NOT mapped: Cashfree signs its
                 // webhooks with the same secret_key, and
                 // verifyCashfreeWebhookSignature() reads that. Mirroring it
                 // would create a settings row nothing ever consumes.
                 'settings_map' => [
                     'app_id'      => ['payment', 'cashfree_app_id'],
                     'secret_key'  => ['payment', 'cashfree_secret_key'],
                     'environment' => ['payment', 'cashfree_mode'],
                 ],
                 'test' => ['type' => 'http', 'method' => 'GET',
                            'url' => '{base}/orders/nonexistent-probe',
                            'base_map' => ['sandbox' => 'https://sandbox.cashfree.com/pg', 'production' => 'https://api.cashfree.com/pg'],
                            'headers' => ['x-client-id: {app_id}', 'x-client-secret: {secret_key}', 'x-api-version: 2023-08-01'],
                            'expect_any' => [200, 404],
                            'note' => 'A 404 for a made-up order id proves the credentials were accepted; a 401 proves they were not.'],
                 'notes' => 'Razorpay and Stripe are configured under Settings → Payments; this adds Cashfree alongside them.']),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function social(): array
    {
        return [
            'google_login' => self::make('Google Sign-In', 'social', 'Google',
                'Let brands and creators sign in with a Google account.',
                'https://developers.google.com/identity/protocols/oauth2',
                [
                    self::text('web_client_id', 'Web client ID', 'Also used as the Android serverClientId.',
                        'nullable|string|max:255|regex:/\.apps\.googleusercontent\.com$/'),
                    self::text('android_client_id', 'Android client ID', '',
                        'nullable|string|max:255|regex:/\.apps\.googleusercontent\.com$/'),
                    self::text('ios_client_id', 'iOS client ID', '',
                        'nullable|string|max:255|regex:/\.apps\.googleusercontent\.com$/'),
                    self::optional(self::secret('client_secret', 'Client secret', 'Only needed for the server-side flow.')),
                    self::text('web_redirect_uri', 'Web redirect URI', 'Must match an authorised redirect URI in the Google console.', 'nullable|url|max:255'),
                    self::optional(self::text('authorised_origins', 'Authorised JavaScript origins', 'Comma-separated. Reference only — set these in the Google console.')),
                    self::optional(self::text('android_package_name', 'Android package name', 'Build-time value. Recorded here so it can be checked against the console.',
                        'nullable|string|max:255|regex:/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i')),
                    self::optional(self::text('android_sha1', 'Android signing SHA-1', 'Build-time value: comes from the keystore that signs the APK.',
                        'nullable|string|max:255|regex:/^([A-Fa-f0-9]{2}:){19}[A-Fa-f0-9]{2}$/')),
                    self::optional(self::text('android_sha256', 'Android signing SHA-256', 'Build-time value.',
                        'nullable|string|max:255|regex:/^([A-Fa-f0-9]{2}:){31}[A-Fa-f0-9]{2}$/')),
                    self::optional(self::text('ios_bundle_id', 'iOS bundle identifier', 'Build-time value.',
                        'nullable|string|max:255|regex:/^[A-Za-z0-9][A-Za-z0-9\-]*(\.[A-Za-z0-9\-]+)+$/')),
                ],
                ['auth' => 'oauth2',
                 // Mirrored into the `auth` settings group, which is what
                 // /api/v1/app/config reads. The previous target was
                 // ['social','google_client_id'] — but the whole `social` group
                 // is dumped verbatim to an unauthenticated endpoint, so that
                 // mirror published the client ID to anyone who asked.
                 'settings_map' => [
                     'web_client_id'     => ['auth', 'google_web_client_id'],
                     'android_client_id' => ['auth', 'google_android_client_id'],
                     'ios_client_id'     => ['auth', 'google_ios_client_id'],
                     'web_redirect_uri'  => ['auth', 'google_web_redirect_uri'],
                 ],
                 'test'  => ['type' => 'internal', 'handler' => 'google_login'],
                 'notes' => 'Configured and monitored under Authentication & Login (/admin/login-methods). Toggling here has the same effect.']),

            'apple_login' => self::make('Sign in with Apple', 'social', 'Apple',
                'Required by App Store review when any other social sign-in is offered on iOS.',
                'https://developer.apple.com/documentation/sign_in_with_apple',
                [
                    self::text('service_id', 'Service ID', 'The identifier configured as a Services ID.'),
                    self::text('team_id', 'Team ID', '', 'nullable|string|max:20|regex:/^[A-Z0-9]{10}$/'),
                    self::text('key_id', 'Key ID', '', 'nullable|string|max:20|regex:/^[A-Z0-9]{10}$/'),
                    self::textarea('private_key', 'AuthKey .p8 contents', 'Paste the whole key including the BEGIN and END lines.', true),
                    self::text('redirect_uri', 'Return URL', 'Must match a Return URL on the Services ID.', 'nullable|url|max:255'),
                    self::optional(self::text('bundle_id', 'iOS bundle identifier', 'Build-time value.',
                        'nullable|string|max:255|regex:/^[A-Za-z0-9][A-Za-z0-9\-]*(\.[A-Za-z0-9\-]+)+$/')),
                ],
                ['auth' => 'oauth2',
                 // team_id, key_id and private_key are deliberately NOT mirrored:
                 // no client ever needs them, and the settings group they would
                 // land in is the one the public config reads.
                 'settings_map' => [
                     'service_id'   => ['auth', 'apple_service_id'],
                     'redirect_uri' => ['auth', 'apple_redirect_uri'],
                 ],
                 'test'  => ['type' => 'internal', 'handler' => 'apple_login'],
                 'notes' => 'Configured and monitored under Authentication & Login (/admin/login-methods).']),

            'facebook_login' => self::make('Facebook Login', 'social', 'Meta',
                'Sign in with a Facebook account, exchanged through Firebase like the other providers.',
                'https://developers.facebook.com/docs/facebook-login/',
                [
                    self::text('app_id', 'App ID'),
                    self::secret('app_secret', 'App secret'),
                ],
                ['auth' => 'oauth2',
                 'settings_map' => ['app_id' => ['auth', 'facebook_app_id']],
                 // A client-credentials grant is a real proof of both values and
                 // has no side effect.
                 'test' => ['type' => 'http', 'method' => 'GET',
                            'url'  => 'https://graph.facebook.com/oauth/access_token'
                                    . '?client_id={app_id}&client_secret={app_secret}&grant_type=client_credentials',
                            'expect' => 200],
                 'notes' => 'Requires flutter_facebook_auth in the mobile build; enabling this alone does not add it.']),
        ];
    }

    /**
     * The three built-in sign-in methods.
     *
     * Each declares no fields on purpose. Their configuration already has a
     * home — SMS credentials in the `sms` settings group, SMTP in `mail`, OTP
     * shape and password policy in `security`, signup-per-role in `app` — and
     * re-declaring any of it here would create a second store that drifts from
     * the first. What they need from the hub is the part the hub is good at:
     * an enable flag, a derived status, a connection test, and an activity log.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function authMethods(): array
    {
        return [
            'email_password' => self::make('Email & Password', 'auth', 'Zusty',
                'The built-in sign-in with an email address or phone number and a password.',
                '',
                [],
                ['test'  => ['type' => 'internal', 'handler' => 'email_password'],
                 'notes' => 'Password policy lives under Settings → Security; delivery under Settings → Email.']),

            'mobile_otp' => self::make('Mobile Number OTP', 'auth', 'Zusty',
                'One-time codes sent by SMS, for sign-in and for verifying a phone number.',
                '',
                [],
                ['test'  => ['type' => 'internal', 'handler' => 'mobile_otp'],
                 'notes' => 'Gateway credentials live under Settings → SMS; code length and lifetime under Settings → Security.']),

            'guest_login' => self::make('Guest Access', 'auth', 'Zusty',
                'Let someone browse without an account, then upgrade in place without losing what they did.',
                '',
                [],
                ['test'  => ['type' => 'internal', 'handler' => 'guest_login'],
                 'notes' => 'Guest scope and retention are set under Authentication & Login.']),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function storage(): array
    {
        return [
            'firebase_storage' => self::make('Firebase Storage', 'storage', 'Google',
                'Media uploads from the apps: avatars, portfolio images and deliverables.',
                'https://firebase.google.com/docs/storage',
                [self::text('bucket', 'Storage bucket', 'e.g. zustee.appspot.com')],
                ['auth' => 'service_account', 'test' => ['type' => 'internal', 'handler' => 'firebase'],
                 'settings_map' => ['bucket' => ['firebase', 'storage_bucket']],
                 'notes' => 'Uses the service account from the Firebase Cloud Messaging integration.']),

            'aws_s3' => self::make('Amazon S3', 'storage', 'Amazon',
                'Object storage for uploads and database backups.',
                'https://docs.aws.amazon.com/AmazonS3/latest/API/Welcome.html',
                [
                    self::text('bucket', 'Bucket'),
                    self::text('region', 'Region', 'e.g. ap-south-1'),
                    self::text('access_key_id', 'Access key ID'),
                    self::secret('secret_access_key', 'Secret access key'),
                    self::optional(self::text('endpoint', 'Custom endpoint', 'For S3-compatible services such as Wasabi or R2.')),
                ],
                ['sync' => true, 'notes' => 'Sync uploads any local backups that are not yet in the bucket.']),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function maps(): array
    {
        return [
            'google_maps' => self::make('Google Maps Platform', 'maps', 'Google',
                'Address autocomplete, geocoding and map tiles for creator locations.',
                'https://developers.google.com/maps/documentation',
                [
                    self::secret('api_key', 'API key', 'Restrict it by referrer and to the APIs below.'),
                    self::toggle('places', 'Places API enabled'),
                    self::toggle('geocoding', 'Geocoding API enabled'),
                ],
                ['test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://maps.googleapis.com/maps/api/geocode/json?address=Mumbai&key={api_key}',
                            'expect' => 200, 'json_ok' => ['status' => 'OK']]]),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function ai(): array
    {
        $delegate = static fn (string $name, string $vendor, string $desc, string $doc): array
            => self::make($name, 'ai', $vendor, $desc, $doc, [],
                ['auth' => 'none', 'delegate' => '/admin/ai/providers',
                 'notes' => 'Configured in AI → Providers, where each model also carries its own pricing for cost reporting.']);

        return [
            'openai'     => $delegate('OpenAI', 'OpenAI', 'GPT models for content assistance and moderation.', 'https://platform.openai.com/docs/api-reference'),
            'claude_api' => $delegate('Claude API', 'Anthropic', 'Claude models for content assistance and moderation.', 'https://platform.claude.com/docs'),
            'gemini'     => $delegate('Google Gemini', 'Google', 'Gemini models via the Generative Language API.', 'https://ai.google.dev/docs'),
            'openrouter' => $delegate('OpenRouter', 'OpenRouter', 'One key routing to many upstream models.', 'https://openrouter.ai/docs'),
            'deepseek'   => $delegate('DeepSeek', 'DeepSeek', 'OpenAI-compatible models from DeepSeek.', 'https://api-docs.deepseek.com/'),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function monitoring(): array
    {
        return [
            'sentry' => self::make('Sentry', 'monitoring', 'Sentry',
                'Backend and app exception tracking with release health.',
                'https://docs.sentry.io/platforms/php/',
                [self::text('dsn', 'DSN', 'https://<key>@<org>.ingest.sentry.io/<project>'),
                 self::text('environment', 'Environment', 'e.g. production'),
                 self::text('traces_sample_rate', 'Traces sample rate', '0 to 1.', 'nullable|numeric|min_value:0|max_value:1')]),

            'crashlytics' => self::make('Firebase Crashlytics', 'monitoring', 'Google',
                'Crash reporting for the Android and iOS apps.',
                'https://firebase.google.com/docs/crashlytics',
                [self::toggle('enabled_android', 'Android reporting'), self::toggle('enabled_ios', 'iOS reporting')],
                ['auth' => 'none', 'notes' => 'Uses the Firebase project configured under Push Notifications.']),

            'logrocket' => self::make('LogRocket', 'monitoring', 'LogRocket',
                'Session replay tied to frontend errors.',
                'https://docs.logrocket.com/docs',
                [self::text('app_id', 'App ID', 'e.g. org/project')],
                ['auth' => 'none']),

            'uptime_monitor' => self::make('Uptime monitoring', 'monitoring', 'Any provider',
                'External health checks against the public health endpoint.',
                'https://uptimerobot.com/api/',
                [self::text('provider', 'Provider name'), self::secret('api_key', 'API key'),
                 self::text('monitor_url', 'Monitored URL', 'Point it at /api/v1/health.')],
                ['sync' => true, 'notes' => 'Sync records the current uptime percentage reported by the provider.']),

            'error_logs' => self::make('Application error logs', 'monitoring', 'Zusty',
                'The platform\'s own log files and the system log table.',
                'https://www.php.net/manual/en/function.error-log.php',
                [], ['auth' => 'none', 'delegate' => '/admin/system',
                     'notes' => 'Owned by the System module — viewed, rotated and cleared there.']),

            'health_monitoring' => self::make('Health monitoring', 'monitoring', 'Zusty',
                'Database, cache, queue, disk and cron health checks.',
                'https://platform.claude.com/docs',
                [], ['auth' => 'none', 'delegate' => '/admin/system',
                     'notes' => 'Owned by the System module and surfaced on the dashboard health widget.']),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function cdn(): array
    {
        return [
            'cloudflare' => self::make('Cloudflare', 'cdn', 'Cloudflare',
                'CDN, DNS and cache purging for the public site.',
                'https://developers.cloudflare.com/api/',
                [self::text('zone_id', 'Zone ID'), self::secret('api_token', 'API token', 'Scoped token with Zone.Cache Purge.'),
                 self::text('account_id', 'Account ID')],
                ['sync' => true,
                 'test' => ['type' => 'http', 'method' => 'GET',
                            'url' => 'https://api.cloudflare.com/client/v4/zones/{zone_id}',
                            'auth' => 'bearer', 'token_field' => 'api_token', 'expect' => 200],
                 'notes' => 'Sync purges the edge cache for this zone.']),

            'image_optimization' => self::make('Image optimisation', 'cdn', 'Zusty',
                'Resizing, WebP conversion and thumbnail generation on upload.',
                'https://www.php.net/manual/en/book.image.php',
                [self::toggle('convert_webp', 'Convert uploads to WebP'),
                 self::text('max_width', 'Maximum width (px)', '', 'nullable|integer|min_value:100|max_value:8000'),
                 self::text('quality', 'JPEG/WebP quality', '1-100.', 'nullable|integer|min_value:1|max_value:100')],
                ['auth' => 'none', 'test' => ['type' => 'internal', 'handler' => 'image_support']]),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function security(): array
    {
        return [
            'recaptcha' => self::make('Google reCAPTCHA v3', 'security', 'Google',
                'Score-based bot protection on sign-up, login and the contact form.',
                'https://developers.google.com/recaptcha/docs/v3',
                [
                    // Google cannot be asked whether a secret is real without a
                    // genuine response token, so shape is the one thing that can
                    // be checked before a live user hits the probe: a v3 key pair
                    // is always 6L… — this catches a truncated paste or the wrong
                    // value entirely, which is what the connection test cannot.
                    self::text('site_key', 'Site key', 'Starts with 6L.',
                        'nullable|string|max:255|regex:/^6L[0-9A-Za-z_-]{20,60}$/'),
                    self::secret('secret_key', 'Secret key', 'Starts with 6L; never shown in the browser.',
                        'nullable|string|max:2000|regex:/^6L[0-9A-Za-z_-]{20,60}$/'),
                    self::text('threshold', 'Score threshold', '0 to 1; 0.5 is the usual starting point.', 'nullable|numeric|min_value:0|max_value:1'),
                    self::toggle('protect_login', 'Protect sign-in'),
                    self::toggle('protect_signup', 'Protect sign-up'),
                    self::toggle('protect_contact', 'Protect the contact form'),
                ],
                // siteverify answers HTTP 200 unconditionally, so a status-only
                // check green-ticked any secret at all. `reject_body` catches
                // the codes Google uses when the secret itself is the problem,
                // and `success_message` stops the pass claiming more than the
                // probe can prove: Google validates the response token before
                // the secret, and this probe token is deliberately invalid, so
                // a passing check means "not refused", not "correct".
                ['test' => ['type' => 'http', 'method' => 'POST',
                            'url' => 'https://www.google.com/recaptcha/api/siteverify',
                            'form' => ['secret' => '{secret_key}', 'response' => 'connection-probe'],
                            'expect' => 200,
                            'reject_body'     => ['invalid-input-secret', 'missing-input-secret', 'bad-request'],
                            'reject_message'  => 'Google rejected the secret key.',
                            'success_message' => 'Google\'s siteverify endpoint is reachable and did not reject this secret.',
                            'note' => 'It checks the response token before the secret, and the probe token here is deliberately '
                                    . 'invalid, so only a real sign-up or sign-in finally proves the secret.']]),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function platform(): array
    {
        return [
            'scheduled_backups' => self::make('Scheduled database backups', 'platform', 'Zusty',
                'Nightly mysqldump with retention, plus manual backups and restore.',
                'https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html',
                [], ['auth' => 'none', 'delegate' => '/admin/system',
                     'notes' => 'Owned by the System module; the schedule is the backup:database cron job.']),

            'cloud_backups' => self::make('Cloud backup copies', 'platform', 'Amazon',
                'Copy each nightly dump off the server into object storage.',
                'https://docs.aws.amazon.com/AmazonS3/latest/userguide/upload-objects.html',
                [self::toggle('enabled', 'Copy backups to S3'),
                 self::text('prefix', 'Object key prefix', 'e.g. zustee/backups/')],
                ['auth' => 'none', 'sync' => true,
                 'notes' => 'Uses the Amazon S3 integration\'s credentials. Sync uploads anything not yet copied.']),

            'cron_jobs' => self::make('Scheduled jobs', 'platform', 'Zusty',
                'The nine cron jobs that drive queues, expiry, escrow release, roll-ups and backups.',
                'https://man7.org/linux/man-pages/man5/crontab.5.html',
                [], ['auth' => 'none', 'delegate' => '/admin/system',
                     'notes' => 'Owned by the System module — run, enable and inspect each job there.']),
        ];
    }
}
