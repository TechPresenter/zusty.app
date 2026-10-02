<?php

declare(strict_types=1);

namespace Zuste\Admin;

use Zuste\Core\Database;
use Zuste\Core\HttpException;

/**
 * Declarative definition of an admin CRUD resource.
 *
 * The CMS entities (categories, pages, blogs, banners, FAQs, testimonials)
 * are all the same shape: a filtered list, a form, validation, soft deletes
 * and an audit entry. Describing them here means that behaviour is written —
 * and tested — exactly once, in ResourceController, instead of six times.
 *
 * Anything with real business rules (bookings, payouts, settings) keeps its
 * own controller.
 */
final class Resource
{
    /** @var array<string,self>|null */
    private static ?array $registry = null;

    /**
     * @param list<array{key:string,label:string,type?:string,sortable?:bool,width?:string,align?:string}> $columns
     * @param list<array{name:string,label:string,type:string,rules?:string,options?:array<string,string>,hint?:string,rows?:int,group?:string,default?:mixed}> $fields
     * @param list<array{name:string,label:string,options:array<string,string>}> $filters
     * @param list<string> $searchable
     * @param list<string> $sortable
     */
    private function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly string $singular,
        public readonly string $plural,
        public readonly string $icon,
        public readonly string $viewPermission,
        public readonly string $managePermission,
        public readonly array $columns,
        public readonly array $fields,
        public readonly array $filters = [],
        public readonly array $searchable = [],
        public readonly array $sortable = ['id'],
        public readonly string $defaultSort = 'id',
        public readonly string $defaultDirection = 'DESC',
        public readonly bool $softDeletes = false,
        public readonly bool $sortableRows = false,
        public readonly bool $creatable = true,
        public readonly bool $deletable = true,
        // Column holding the on/off state, or null when the resource has none.
        // Not every table calls it `is_active`: feature flags use `is_enabled`,
        // and an article's visibility is its `status` enum, not a boolean.
        public readonly ?string $activeColumn = 'is_active',
        // Column holding the "featured" flag; null on the resources without one.
        public readonly ?string $featuredColumn = null,
        public readonly ?string $slugSource = null,
        public readonly string $emptyMessage = 'Nothing here yet.',
        public readonly string $description = '',
    ) {
    }

    public static function find(string $key): self
    {
        $registry = self::registry();

        if (!isset($registry[$key])) {
            throw HttpException::notFound('Unknown admin resource: ' . $key);
        }

        return $registry[$key];
    }

    public static function exists(string $key): bool
    {
        return isset(self::registry()[$key]);
    }

    /** @return array<string,self> */
    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        return self::$registry = [
            // Content
            'categories'   => self::categories(),
            'pages'        => self::pages(),
            'blogs'        => self::blogs(),
            'banners'      => self::banners(),
            'faqs'         => self::faqs(),
            'testimonials' => self::testimonials(),
            'onboarding-slides' => self::onboardingSlides(),

            // Enterprise modules
            'coupons'      => self::coupons(),
            'popups'       => self::popups(),
            'redirects'    => self::redirects(),
            'flags'        => self::featureFlags(),
            'sections'     => self::homepageSections(),
            'ai-prompts'   => self::aiPrompts(),
        ];
    }

    /** Validation rule set for create/update. */
    public function rules(bool $isUpdate = false, ?int $id = null): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rule = $field['rules'] ?? 'nullable|string|max:255';

            // `unique:table,column` needs the current row excluded on update.
            if ($isUpdate && $id !== null && str_contains($rule, 'unique:')) {
                $rule = (string) preg_replace(
                    '/unique:([a-z_]+),([a-z_]+)/',
                    'unique:$1,$2,' . $id,
                    $rule
                );
            }

            // A required field stays required on create but becomes optional
            // on update, so a partial form submission cannot blank it.
            $rules[$field['name']] = $rule;
        }

        return $rules;
    }

    /** Fields grouped for the form layout. */
    public function fieldGroups(): array
    {
        $groups = [];

        foreach ($this->fields as $field) {
            $groups[$field['group'] ?? 'General'][] = $field;
        }

        return $groups;
    }

    /** Human label for a row, used in toasts and the audit trail. */
    public function labelFor(array $row): string
    {
        foreach (['title', 'name', 'question', 'author_name', 'display_name'] as $candidate) {
            if (!empty($row[$candidate])) {
                return (string) $row[$candidate];
            }
        }

        return '#' . (string) ($row['id'] ?? '');
    }

    /** Highest sort_order currently in use, for appending a new row. */
    public function nextSortOrder(): int
    {
        if (!$this->sortableRows) {
            return 0;
        }

        $max = Database::scalar('SELECT MAX(`sort_order`) FROM ' . Database::quoteIdentifier($this->table));

        return (int) ($max ?? 0) + 1;
    }

    // -----------------------------------------------------------------
    // Definitions
    // -----------------------------------------------------------------

    private static function categories(): self
    {
        return new self(
            key: 'categories',
            table: 'categories',
            singular: 'Category',
            plural: 'Categories',
            icon: 'grid',
            viewPermission: 'categories.view',
            managePermission: 'categories.manage',
            description: 'Categories drive discovery on the Home and Categories screens.',
            columns: [
                ['key' => 'name', 'label' => 'Name', 'type' => 'title', 'sortable' => true],
                ['key' => 'slug', 'label' => 'Slug', 'type' => 'mono'],
                ['key' => 'icon', 'label' => 'Icon', 'type' => 'text'],
                ['key' => 'creator_count', 'label' => 'Creators', 'type' => 'number', 'align' => 'right', 'sortable' => true],
                ['key' => 'is_featured', 'label' => 'Featured', 'type' => 'boolean'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
                ['key' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'align' => 'right', 'sortable' => true],
            ],
            fields: [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => 'required|string|min:2|max:100'],
                ['name' => 'slug', 'label' => 'Slug', 'type' => 'text', 'rules' => 'nullable|slug|max:120|unique:categories,slug', 'hint' => 'Leave blank to generate from the name.'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rules' => 'nullable|string|max:500', 'rows' => 3],
                ['name' => 'icon', 'label' => 'Material Symbols icon', 'type' => 'text', 'rules' => 'nullable|string|max:64', 'hint' => 'e.g. checkroom, flight_takeoff, restaurant'],
                ['name' => 'icon_url', 'label' => 'Round thumbnail URL', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'hint' => 'Shown in the Home carousel.', 'group' => 'Media'],
                ['name' => 'cover_image', 'label' => 'Cover image URL', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'hint' => 'Shown on the Categories grid.', 'group' => 'Media'],
                ['name' => 'color_hex', 'label' => 'Accent colour', 'type' => 'color', 'rules' => 'nullable|hex_color', 'default' => '#B80049', 'group' => 'Media'],
                ['name' => 'is_featured', 'label' => 'Feature on Home', 'type' => 'switch', 'rules' => 'nullable|boolean', 'group' => 'Visibility'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Visibility'],
                ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'group' => 'Visibility'],
                ['name' => 'meta_title', 'label' => 'Meta title', 'type' => 'text', 'rules' => 'nullable|string|max:191', 'group' => 'SEO'],
                ['name' => 'meta_description', 'label' => 'Meta description', 'type' => 'textarea', 'rules' => 'nullable|string|max:255', 'rows' => 2, 'group' => 'SEO'],
            ],
            filters: [
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
                ['name' => 'is_featured', 'label' => 'Featured', 'options' => ['' => 'All', '1' => 'Featured only']],
            ],
            searchable: ['name', 'slug', 'description'],
            sortable: ['id', 'name', 'sort_order', 'creator_count'],
            defaultSort: 'sort_order',
            defaultDirection: 'ASC',
            softDeletes: true,
            sortableRows: true,
            featuredColumn: 'is_featured',
            slugSource: 'name',
            emptyMessage: 'No categories yet. Add one so creators can be discovered.',
        );
    }

    private static function pages(): self
    {
        return new self(
            key: 'pages',
            table: 'pages',
            singular: 'Page',
            plural: 'Pages',
            icon: 'file',
            viewPermission: 'cms.view',
            managePermission: 'cms.pages',
            description: 'Static and legal pages rendered inside the apps and on the website.',
            columns: [
                ['key' => 'title', 'label' => 'Title', 'type' => 'title', 'sortable' => true],
                ['key' => 'slug', 'label' => 'Slug', 'type' => 'mono'],
                ['key' => 'template', 'label' => 'Template', 'type' => 'chip'],
                ['key' => 'show_in_app', 'label' => 'In app', 'type' => 'boolean'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
                ['key' => 'updated_at', 'label' => 'Updated', 'type' => 'datetime', 'sortable' => true],
            ],
            fields: [
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'rules' => 'required|string|min:2|max:191'],
                ['name' => 'slug', 'label' => 'Slug', 'type' => 'text', 'rules' => 'nullable|slug|max:120|unique:pages,slug', 'hint' => 'Used in the URL: /pages/{slug}'],
                ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'rules' => 'nullable|string|max:255'],
                ['name' => 'content', 'label' => 'Content', 'type' => 'html', 'rules' => 'required|string', 'rows' => 18],
                ['name' => 'template', 'label' => 'Template', 'type' => 'select', 'rules' => 'nullable|in:default,legal,landing,contact', 'options' => ['default' => 'Default', 'legal' => 'Legal', 'landing' => 'Landing', 'contact' => 'Contact'], 'group' => 'Visibility'],
                ['name' => 'show_in_app', 'label' => 'Show in the mobile app', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Visibility'],
                ['name' => 'is_active', 'label' => 'Published', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Visibility'],
                ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'group' => 'Visibility'],
                ['name' => 'hero_image', 'label' => 'Hero image URL', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'group' => 'Media'],
                ['name' => 'meta_title', 'label' => 'Meta title', 'type' => 'text', 'rules' => 'nullable|string|max:191', 'group' => 'SEO'],
                ['name' => 'meta_description', 'label' => 'Meta description', 'type' => 'textarea', 'rules' => 'nullable|string|max:300', 'rows' => 2, 'group' => 'SEO'],
                ['name' => 'meta_keywords', 'label' => 'Meta keywords', 'type' => 'text', 'rules' => 'nullable|string|max:300', 'group' => 'SEO'],
                ['name' => 'og_image', 'label' => 'Open Graph image', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'group' => 'SEO'],
            ],
            filters: [
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Published', '0' => 'Draft']],
                ['name' => 'template', 'label' => 'Template', 'options' => ['' => 'Any template', 'default' => 'Default', 'legal' => 'Legal', 'landing' => 'Landing', 'contact' => 'Contact']],
            ],
            searchable: ['title', 'slug', 'content'],
            sortable: ['id', 'title', 'sort_order', 'updated_at'],
            defaultSort: 'sort_order',
            defaultDirection: 'ASC',
            sortableRows: true,
            slugSource: 'title',
            emptyMessage: 'No pages yet.',
        );
    }

    private static function blogs(): self
    {
        $categories = [];

        try {
            foreach (Database::select('SELECT `id`, `name` FROM `blog_categories` WHERE `is_active` = 1 ORDER BY `sort_order`') as $row) {
                $categories[(string) $row['id']] = (string) $row['name'];
            }
        } catch (\Throwable) {
            $categories = [];
        }

        return new self(
            key: 'blogs',
            table: 'blogs',
            singular: 'Article',
            plural: 'Blog',
            icon: 'file',
            viewPermission: 'cms.view',
            managePermission: 'cms.blogs',
            description: 'Long-form content for SEO and creator education.',
            columns: [
                ['key' => 'title', 'label' => 'Title', 'type' => 'title', 'sortable' => true],
                ['key' => 'category_name', 'label' => 'Category', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ['key' => 'views_count', 'label' => 'Views', 'type' => 'number', 'align' => 'right', 'sortable' => true],
                ['key' => 'is_featured', 'label' => 'Featured', 'type' => 'boolean'],
                ['key' => 'published_at', 'label' => 'Published', 'type' => 'date', 'sortable' => true],
            ],
            fields: [
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'rules' => 'required|string|min:3|max:191'],
                ['name' => 'slug', 'label' => 'Slug', 'type' => 'text', 'rules' => 'nullable|slug|max:191|unique:blogs,slug'],
                ['name' => 'excerpt', 'label' => 'Excerpt', 'type' => 'textarea', 'rules' => 'nullable|string|max:500', 'rows' => 3, 'hint' => 'Shown in listings and social previews.'],
                ['name' => 'content', 'label' => 'Content', 'type' => 'html', 'rules' => 'required|string', 'rows' => 20],
                ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'rules' => 'nullable|integer', 'options' => ['' => 'Uncategorised'] + $categories, 'group' => 'Publishing'],
                ['name' => 'author_name', 'label' => 'Author name', 'type' => 'text', 'rules' => 'nullable|string|max:120', 'group' => 'Publishing'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'rules' => 'nullable|in:draft,scheduled,published,archived', 'options' => ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'], 'default' => 'draft', 'group' => 'Publishing'],
                ['name' => 'published_at', 'label' => 'Publish at', 'type' => 'datetime', 'rules' => 'nullable|datetime', 'group' => 'Publishing'],
                ['name' => 'is_featured', 'label' => 'Featured', 'type' => 'switch', 'rules' => 'nullable|boolean', 'group' => 'Publishing'],
                ['name' => 'reading_minutes', 'label' => 'Reading time (min)', 'type' => 'number', 'rules' => 'nullable|integer|min_value:1|max_value:60', 'default' => 3, 'group' => 'Publishing'],
                ['name' => 'cover_image', 'label' => 'Cover image URL', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'group' => 'Media'],
                ['name' => 'meta_title', 'label' => 'Meta title', 'type' => 'text', 'rules' => 'nullable|string|max:191', 'group' => 'SEO'],
                ['name' => 'meta_description', 'label' => 'Meta description', 'type' => 'textarea', 'rules' => 'nullable|string|max:300', 'rows' => 2, 'group' => 'SEO'],
                ['name' => 'og_image', 'label' => 'Open Graph image', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'group' => 'SEO'],
            ],
            filters: [
                ['name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status', 'draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived']],
                ['name' => 'category_id', 'label' => 'Category', 'options' => ['' => 'Any category'] + $categories],
            ],
            searchable: ['title', 'excerpt', 'content'],
            sortable: ['id', 'title', 'published_at', 'views_count'],
            defaultSort: 'id',
            softDeletes: true,
            // An article has no on/off switch — publishing is the `status`
            // enum, which the edit form and the list filter already expose.
            activeColumn: null,
            featuredColumn: 'is_featured',
            slugSource: 'title',
            emptyMessage: 'No articles yet. Publishing helps organic discovery.',
        );
    }

    private static function banners(): self
    {
        return new self(
            key: 'banners',
            table: 'banners',
            singular: 'Banner',
            plural: 'Banners',
            icon: 'image',
            viewPermission: 'cms.view',
            managePermission: 'cms.banners',
            description: 'Promotional slots on Home, Search and Wallet.',
            columns: [
                ['key' => 'image_url', 'label' => '', 'type' => 'thumbnail', 'width' => '84px'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'title', 'sortable' => true],
                ['key' => 'position', 'label' => 'Slot', 'type' => 'chip'],
                ['key' => 'audience', 'label' => 'Audience', 'type' => 'chip'],
                ['key' => 'impressions', 'label' => 'Views', 'type' => 'number', 'align' => 'right'],
                ['key' => 'clicks', 'label' => 'Clicks', 'type' => 'number', 'align' => 'right'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
            ],
            fields: [
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'rules' => 'required|string|min:2|max:191'],
                ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'rules' => 'nullable|string|max:255'],
                ['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text', 'rules' => 'nullable|string|max:60', 'hint' => 'e.g. Explore Now'],
                ['name' => 'image_url', 'label' => 'Image URL', 'type' => 'image', 'rules' => 'required|string|max:500', 'group' => 'Media'],
                ['name' => 'image_url_dark', 'label' => 'Image URL (dark mode)', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'group' => 'Media'],
                ['name' => 'position', 'label' => 'Slot', 'type' => 'select', 'rules' => 'required|in:home_hero,home_mid,search_top,category_top,wallet,profile', 'options' => ['home_hero' => 'Home hero', 'home_mid' => 'Home mid', 'search_top' => 'Search top', 'category_top' => 'Category top', 'wallet' => 'Wallet', 'profile' => 'Profile'], 'group' => 'Placement'],
                ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'rules' => 'nullable|in:all,brands,creators,guests', 'options' => ['all' => 'Everyone', 'brands' => 'Brands only', 'creators' => 'Creators only', 'guests' => 'Signed-out visitors'], 'default' => 'all', 'group' => 'Placement'],
                ['name' => 'action_type', 'label' => 'Tap action', 'type' => 'select', 'rules' => 'nullable|in:none,category,creator,url,campaign,screen', 'options' => ['none' => 'Do nothing', 'category' => 'Open category', 'creator' => 'Open creator', 'url' => 'Open URL', 'campaign' => 'Open campaign', 'screen' => 'Open app screen'], 'default' => 'none', 'group' => 'Placement'],
                ['name' => 'action_value', 'label' => 'Action value', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'hint' => 'Category slug, creator handle, URL or screen path.', 'group' => 'Placement'],
                ['name' => 'starts_at', 'label' => 'Starts at', 'type' => 'datetime', 'rules' => 'nullable|datetime', 'group' => 'Schedule'],
                ['name' => 'ends_at', 'label' => 'Ends at', 'type' => 'datetime', 'rules' => 'nullable|datetime', 'group' => 'Schedule'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Schedule'],
                ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'group' => 'Schedule'],
            ],
            filters: [
                ['name' => 'position', 'label' => 'Slot', 'options' => ['' => 'Any slot', 'home_hero' => 'Home hero', 'home_mid' => 'Home mid', 'search_top' => 'Search top', 'wallet' => 'Wallet']],
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
            ],
            searchable: ['title', 'subtitle'],
            sortable: ['id', 'title', 'sort_order'],
            defaultSort: 'sort_order',
            defaultDirection: 'ASC',
            sortableRows: true,
            emptyMessage: 'No banners yet. Add one to promote a campaign on Home.',
        );
    }

    private static function faqs(): self
    {
        return new self(
            key: 'faqs',
            table: 'faqs',
            singular: 'FAQ',
            plural: 'FAQs',
            icon: 'info',
            viewPermission: 'cms.view',
            managePermission: 'cms.faqs',
            description: 'Answers shown on the in-app FAQs screen and the Help centre.',
            columns: [
                ['key' => 'question', 'label' => 'Question', 'type' => 'title'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'chip'],
                ['key' => 'audience', 'label' => 'Audience', 'type' => 'chip'],
                ['key' => 'helpful_yes', 'label' => 'Helpful', 'type' => 'number', 'align' => 'right'],
                ['key' => 'helpful_no', 'label' => 'Not helpful', 'type' => 'number', 'align' => 'right'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
                ['key' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'align' => 'right', 'sortable' => true],
            ],
            fields: [
                ['name' => 'question', 'label' => 'Question', 'type' => 'text', 'rules' => 'required|string|min:5|max:300'],
                ['name' => 'answer', 'label' => 'Answer', 'type' => 'html', 'rules' => 'required|string', 'rows' => 8],
                ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'rules' => 'nullable|in:general,booking,payment,wallet,creator,brand,account,security', 'options' => ['general' => 'General', 'booking' => 'Bookings', 'payment' => 'Payments', 'wallet' => 'Wallet', 'creator' => 'For creators', 'brand' => 'For brands', 'account' => 'Account', 'security' => 'Security'], 'default' => 'general', 'group' => 'Visibility'],
                ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'rules' => 'nullable|in:all,brands,creators', 'options' => ['all' => 'Everyone', 'brands' => 'Brands only', 'creators' => 'Creators only'], 'default' => 'all', 'group' => 'Visibility'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Visibility'],
                ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'group' => 'Visibility'],
            ],
            filters: [
                ['name' => 'category', 'label' => 'Category', 'options' => ['' => 'Any category', 'general' => 'General', 'booking' => 'Bookings', 'payment' => 'Payments', 'wallet' => 'Wallet', 'creator' => 'For creators', 'brand' => 'For brands', 'account' => 'Account', 'security' => 'Security']],
                ['name' => 'audience', 'label' => 'Audience', 'options' => ['' => 'Any audience', 'all' => 'Everyone', 'brands' => 'Brands', 'creators' => 'Creators']],
            ],
            searchable: ['question', 'answer'],
            sortable: ['id', 'sort_order'],
            defaultSort: 'sort_order',
            defaultDirection: 'ASC',
            sortableRows: true,
            emptyMessage: 'No FAQs yet. Good answers cut support volume.',
        );
    }

    private static function testimonials(): self
    {
        return new self(
            key: 'testimonials',
            table: 'testimonials',
            singular: 'Testimonial',
            plural: 'Testimonials',
            icon: 'star',
            viewPermission: 'cms.view',
            managePermission: 'cms.testimonials',
            description: 'Social proof shown on the website and onboarding.',
            columns: [
                ['key' => 'author_name', 'label' => 'Author', 'type' => 'title'],
                ['key' => 'author_role', 'label' => 'Role', 'type' => 'text'],
                ['key' => 'company', 'label' => 'Company', 'type' => 'text'],
                ['key' => 'rating', 'label' => 'Rating', 'type' => 'rating', 'align' => 'right'],
                ['key' => 'is_featured', 'label' => 'Featured', 'type' => 'boolean'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
            ],
            fields: [
                ['name' => 'author_name', 'label' => 'Author name', 'type' => 'text', 'rules' => 'required|string|min:2|max:120'],
                ['name' => 'author_role', 'label' => 'Role', 'type' => 'text', 'rules' => 'nullable|string|max:150', 'hint' => 'e.g. Head of Marketing'],
                ['name' => 'company', 'label' => 'Company', 'type' => 'text', 'rules' => 'nullable|string|max:150'],
                ['name' => 'content', 'label' => 'Quote', 'type' => 'textarea', 'rules' => 'required|string|min:20|max:2000', 'rows' => 5],
                ['name' => 'avatar_url', 'label' => 'Avatar URL', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'group' => 'Media'],
                ['name' => 'rating', 'label' => 'Rating', 'type' => 'select', 'rules' => 'nullable|integer|min_value:1|max_value:5', 'options' => ['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star'], 'default' => 5, 'group' => 'Visibility'],
                ['name' => 'is_featured', 'label' => 'Featured', 'type' => 'switch', 'rules' => 'nullable|boolean', 'group' => 'Visibility'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Visibility'],
                ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'group' => 'Visibility'],
            ],
            filters: [
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
            ],
            searchable: ['author_name', 'company', 'content'],
            sortable: ['id', 'sort_order', 'rating'],
            defaultSort: 'sort_order',
            defaultDirection: 'ASC',
            sortableRows: true,
            featuredColumn: 'is_featured',
            emptyMessage: 'No testimonials yet.',
        );
    }

    /**
     * The intro slides shown before sign-up.
     *
     * `GET /app/onboarding` has served this table since the slides stopped
     * being a const list in the Flutter source, but nothing in the admin panel
     * could write to it — so the first thing a new user ever sees was editable
     * only by hand-writing SQL.
     */
    private static function onboardingSlides(): self
    {
        return new self(
            key: 'onboarding-slides',
            table: 'onboarding_slides',
            singular: 'Onboarding slide',
            plural: 'Onboarding slides',
            icon: 'sliders',
            viewPermission: 'cms.view',
            managePermission: 'cms.pages',
            description: 'The intro carousel shown before sign-up. Reorder by dragging.',
            columns: [
                ['key' => 'title', 'label' => 'Title', 'type' => 'title'],
                ['key' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text'],
                ['key' => 'audience', 'label' => 'Audience', 'type' => 'badge'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
                ['key' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'align' => 'right'],
            ],
            fields: [
                ['name' => 'title', 'label' => 'Headline', 'type' => 'text', 'rules' => 'required|string|min:3|max:191', 'hint' => 'Kept short — it is set in the display type.'],
                ['name' => 'subtitle', 'label' => 'Supporting line', 'type' => 'textarea', 'rules' => 'nullable|string|max:500', 'rows' => 3],
                ['name' => 'image_url', 'label' => 'Artwork', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'hint' => 'The large hero photo. Leave empty to use the seeded placeholder composition.', 'group' => 'Media'],
                ['name' => 'image_url_2', 'label' => 'Supporting photo 1', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'hint' => 'Optional. Shown as a smaller portrait overlapping the hero.', 'group' => 'Media'],
                ['name' => 'image_url_3', 'label' => 'Supporting photo 2', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'hint' => 'Optional. A second smaller portrait in the collage.', 'group' => 'Media'],
                ['name' => 'icon', 'label' => 'Icon', 'type' => 'text', 'rules' => 'nullable|string|max:60', 'hint' => 'Material symbol name, used when there is no artwork.', 'group' => 'Media'],
                ['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text', 'rules' => 'nullable|string|max:60', 'hint' => 'Overrides the default Next / Get started label.'],
                ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'rules' => 'nullable|in:all,brands,creators', 'options' => ['all' => 'Everyone', 'brands' => 'Brands only', 'creators' => 'Creators only'], 'default' => 'all', 'group' => 'Visibility'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Visibility'],
                ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'group' => 'Visibility'],
            ],
            filters: [
                ['name' => 'audience', 'label' => 'Audience', 'options' => ['' => 'Any audience', 'all' => 'Everyone', 'brands' => 'Brands', 'creators' => 'Creators']],
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
            ],
            searchable: ['title', 'subtitle'],
            sortable: ['id', 'sort_order'],
            defaultSort: 'sort_order',
            defaultDirection: 'ASC',
            sortableRows: true,
            emptyMessage: 'No slides yet. The app falls back to a single built-in slide until you add one.',
        );
    }

    // -----------------------------------------------------------------
    // Enterprise modules
    // -----------------------------------------------------------------

    private static function coupons(): self
    {
        $categories = ['' => 'Any category'];

        try {
            foreach (Database::select('SELECT `id`, `name` FROM `categories` WHERE `is_active` = 1 ORDER BY `sort_order`') as $row) {
                $categories[(string) $row['id']] = (string) $row['name'];
            }
        } catch (\Throwable) {
            // Table may not exist during installation.
        }

        return new self(
            key: 'coupons',
            table: 'coupons',
            singular: 'Coupon',
            plural: 'Coupons',
            icon: 'card',
            viewPermission: 'marketing.view',
            managePermission: 'marketing.manage',
            description: 'Discount codes applied at booking checkout.',
            columns: [
                ['key' => 'code', 'label' => 'Code', 'type' => 'mono', 'sortable' => true],
                ['key' => 'name', 'label' => 'Name', 'type' => 'title'],
                ['key' => 'discount_type', 'label' => 'Type', 'type' => 'chip'],
                ['key' => 'discount_value', 'label' => 'Value', 'type' => 'number', 'align' => 'right'],
                ['key' => 'used_count', 'label' => 'Used', 'type' => 'number', 'align' => 'right', 'sortable' => true],
                ['key' => 'usage_limit', 'label' => 'Limit', 'type' => 'number', 'align' => 'right'],
                ['key' => 'expires_at', 'label' => 'Expires', 'type' => 'date', 'sortable' => true],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
            ],
            fields: [
                ['name' => 'code', 'label' => 'Coupon code', 'type' => 'text', 'rules' => 'required|string|min:3|max:32|unique:coupons,code', 'hint' => 'Shown to users. Uppercase is conventional, e.g. MONSOON20.'],
                ['name' => 'name', 'label' => 'Internal name', 'type' => 'text', 'rules' => 'required|string|min:2|max:150'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rules' => 'nullable|string|max:500', 'rows' => 2],
                ['name' => 'discount_type', 'label' => 'Discount type', 'type' => 'select', 'rules' => 'required|in:percentage,fixed', 'options' => ['percentage' => 'Percentage off', 'fixed' => 'Fixed amount off'], 'group' => 'Discount'],
                ['name' => 'discount_value', 'label' => 'Discount value', 'type' => 'number', 'rules' => 'required|numeric|min_value:1', 'hint' => 'A percentage (1–100) or a fixed amount.', 'group' => 'Discount'],
                ['name' => 'max_discount', 'label' => 'Maximum discount', 'type' => 'number', 'rules' => 'nullable|numeric|min_value:0', 'hint' => 'Caps a percentage discount. Blank = uncapped.', 'group' => 'Discount'],
                ['name' => 'min_booking_amount', 'label' => 'Minimum booking amount', 'type' => 'number', 'rules' => 'nullable|numeric|min_value:0', 'default' => 0, 'group' => 'Discount'],
                ['name' => 'applies_to', 'label' => 'Applies to', 'type' => 'select', 'rules' => 'nullable|in:all,brands,creators,first_booking,category', 'options' => ['all' => 'Everyone', 'brands' => 'Brands only', 'creators' => 'Creators only', 'first_booking' => 'First booking only', 'category' => 'A single category'], 'default' => 'all', 'group' => 'Eligibility'],
                ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'rules' => 'nullable|integer', 'options' => $categories, 'hint' => 'Only used when "Applies to" is set to a category.', 'group' => 'Eligibility'],
                ['name' => 'usage_limit', 'label' => 'Total redemption limit', 'type' => 'number', 'rules' => 'nullable|integer|min_value:1', 'hint' => 'Blank = unlimited.', 'group' => 'Limits'],
                ['name' => 'usage_limit_per_user', 'label' => 'Per-user limit', 'type' => 'number', 'rules' => 'nullable|integer|min_value:1', 'default' => 1, 'group' => 'Limits'],
                ['name' => 'starts_at', 'label' => 'Starts at', 'type' => 'datetime', 'rules' => 'nullable|datetime', 'group' => 'Schedule'],
                ['name' => 'expires_at', 'label' => 'Expires at', 'type' => 'datetime', 'rules' => 'nullable|datetime', 'group' => 'Schedule'],
                ['name' => 'is_public', 'label' => 'List publicly in the app', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Schedule'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Schedule'],
            ],
            filters: [
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
                ['name' => 'discount_type', 'label' => 'Type', 'options' => ['' => 'Any type', 'percentage' => 'Percentage', 'fixed' => 'Fixed']],
            ],
            searchable: ['code', 'name', 'description'],
            sortable: ['id', 'code', 'used_count', 'expires_at'],
            softDeletes: true,
            emptyMessage: 'No coupons yet. Create one to run a promotion.',
        );
    }

    private static function popups(): self
    {
        return new self(
            key: 'popups',
            table: 'popups',
            singular: 'Popup',
            plural: 'Popups',
            icon: 'megaphone',
            viewPermission: 'marketing.view',
            managePermission: 'marketing.manage',
            description: 'Modals, slide-ins and the announcement bar shown in the apps.',
            columns: [
                ['key' => 'name', 'label' => 'Name', 'type' => 'title', 'sortable' => true],
                ['key' => 'type', 'label' => 'Type', 'type' => 'chip'],
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'chip'],
                ['key' => 'audience', 'label' => 'Audience', 'type' => 'chip'],
                ['key' => 'impressions', 'label' => 'Views', 'type' => 'number', 'align' => 'right'],
                ['key' => 'clicks', 'label' => 'Clicks', 'type' => 'number', 'align' => 'right'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
            ],
            fields: [
                ['name' => 'name', 'label' => 'Internal name', 'type' => 'text', 'rules' => 'required|string|min:2|max:150'],
                ['name' => 'title', 'label' => 'Headline', 'type' => 'text', 'rules' => 'nullable|string|max:191'],
                ['name' => 'content', 'label' => 'Body', 'type' => 'html', 'rules' => 'nullable|string', 'rows' => 6],
                ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'rules' => 'required|in:modal,banner,slide_in,announcement_bar,full_screen', 'options' => ['modal' => 'Modal dialog', 'banner' => 'Inline banner', 'slide_in' => 'Slide-in', 'announcement_bar' => 'Announcement bar', 'full_screen' => 'Full screen'], 'default' => 'modal', 'group' => 'Behaviour'],
                ['name' => 'placement', 'label' => 'Show on', 'type' => 'select', 'rules' => 'nullable|in:all,home,search,creator,booking,wallet,checkout', 'options' => ['all' => 'Every screen', 'home' => 'Home', 'search' => 'Search', 'creator' => 'Creator profile', 'booking' => 'Bookings', 'wallet' => 'Wallet', 'checkout' => 'Checkout'], 'default' => 'all', 'group' => 'Behaviour'],
                ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'rules' => 'nullable|in:all,brands,creators,guests,new_users', 'options' => ['all' => 'Everyone', 'brands' => 'Brands', 'creators' => 'Creators', 'guests' => 'Signed out', 'new_users' => 'New users'], 'default' => 'all', 'group' => 'Behaviour'],
                ['name' => 'trigger_type', 'label' => 'Trigger', 'type' => 'select', 'rules' => 'nullable|in:immediate,delay,scroll,exit_intent,session_count', 'options' => ['immediate' => 'Immediately', 'delay' => 'After a delay', 'scroll' => 'After scrolling', 'exit_intent' => 'On exit intent', 'session_count' => 'After N sessions'], 'default' => 'immediate', 'group' => 'Behaviour'],
                ['name' => 'trigger_value', 'label' => 'Trigger value', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'hint' => 'Seconds, scroll percent, or session count.', 'group' => 'Behaviour'],
                ['name' => 'frequency', 'label' => 'Frequency', 'type' => 'select', 'rules' => 'nullable|in:always,once,once_per_session,once_per_day', 'options' => ['always' => 'Every time', 'once' => 'Once ever', 'once_per_session' => 'Once per session', 'once_per_day' => 'Once per day'], 'default' => 'once_per_session', 'group' => 'Behaviour'],
                ['name' => 'image_url', 'label' => 'Image URL', 'type' => 'image', 'rules' => 'nullable|string|max:500', 'group' => 'Appearance'],
                ['name' => 'background_color', 'label' => 'Background colour', 'type' => 'color', 'rules' => 'nullable|hex_color', 'default' => '#B80049', 'group' => 'Appearance'],
                ['name' => 'text_color', 'label' => 'Text colour', 'type' => 'color', 'rules' => 'nullable|hex_color', 'default' => '#FFFFFF', 'group' => 'Appearance'],
                ['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text', 'rules' => 'nullable|string|max:60', 'group' => 'Appearance'],
                ['name' => 'cta_action', 'label' => 'Button action', 'type' => 'select', 'rules' => 'nullable|in:none,url,screen,dismiss', 'options' => ['none' => 'None', 'url' => 'Open a URL', 'screen' => 'Open an app screen', 'dismiss' => 'Dismiss'], 'default' => 'none', 'group' => 'Appearance'],
                ['name' => 'cta_value', 'label' => 'Button target', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'group' => 'Appearance'],
                ['name' => 'is_dismissible', 'label' => 'Dismissible', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Schedule'],
                ['name' => 'starts_at', 'label' => 'Starts at', 'type' => 'datetime', 'rules' => 'nullable|datetime', 'group' => 'Schedule'],
                ['name' => 'ends_at', 'label' => 'Ends at', 'type' => 'datetime', 'rules' => 'nullable|datetime', 'group' => 'Schedule'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Schedule'],
                ['name' => 'priority', 'label' => 'Priority', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'hint' => 'Higher wins when several could show.', 'group' => 'Schedule'],
            ],
            filters: [
                ['name' => 'type', 'label' => 'Type', 'options' => ['' => 'Any type', 'modal' => 'Modal', 'announcement_bar' => 'Announcement bar', 'slide_in' => 'Slide-in', 'banner' => 'Banner']],
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
            ],
            searchable: ['name', 'title', 'content'],
            sortable: ['id', 'name', 'priority', 'impressions'],
            defaultSort: 'priority',
            softDeletes: true,
            emptyMessage: 'No popups configured.',
        );
    }

    private static function redirects(): self
    {
        return new self(
            key: 'redirects',
            table: 'redirects',
            singular: 'Redirect',
            plural: 'Redirects',
            icon: 'external',
            viewPermission: 'cms.view',
            managePermission: 'cms.pages',
            description: 'Preserve link equity when a URL changes.',
            columns: [
                ['key' => 'source_path', 'label' => 'From', 'type' => 'mono', 'sortable' => true],
                ['key' => 'target_path', 'label' => 'To', 'type' => 'mono'],
                ['key' => 'status_code', 'label' => 'Code', 'type' => 'chip'],
                ['key' => 'is_regex', 'label' => 'Regex', 'type' => 'boolean'],
                ['key' => 'hit_count', 'label' => 'Hits', 'type' => 'number', 'align' => 'right', 'sortable' => true],
                ['key' => 'last_hit_at', 'label' => 'Last hit', 'type' => 'datetime'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
            ],
            fields: [
                ['name' => 'source_path', 'label' => 'Source path', 'type' => 'text', 'rules' => 'required|string|max:255|unique:redirects,source_path', 'hint' => 'Start with a slash, e.g. /old-page'],
                ['name' => 'target_path', 'label' => 'Target', 'type' => 'text', 'rules' => 'required|string|max:500', 'hint' => 'A path or an absolute URL.'],
                ['name' => 'status_code', 'label' => 'HTTP status', 'type' => 'select', 'rules' => 'nullable|in:301,302,307,308', 'options' => ['301' => '301 — moved permanently', '302' => '302 — found (temporary)', '307' => '307 — temporary redirect', '308' => '308 — permanent redirect'], 'default' => '301', 'group' => 'Options'],
                ['name' => 'is_regex', 'label' => 'Treat source as a regular expression', 'type' => 'switch', 'rules' => 'nullable|boolean', 'group' => 'Options'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Options'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'group' => 'Options'],
            ],
            filters: [
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
                ['name' => 'status_code', 'label' => 'Code', 'options' => ['' => 'Any code', '301' => '301', '302' => '302']],
            ],
            searchable: ['source_path', 'target_path', 'notes'],
            sortable: ['id', 'source_path', 'hit_count'],
            emptyMessage: 'No redirects. Add one when you rename or retire a URL.',
        );
    }

    private static function featureFlags(): self
    {
        return new self(
            key: 'flags',
            table: 'feature_flags',
            singular: 'Feature flag',
            plural: 'Feature flags',
            icon: 'flag',
            viewPermission: 'settings.view',
            managePermission: 'settings.manage',
            description: 'Turn features on and off, and roll them out gradually.',
            columns: [
                ['key' => 'flag_key', 'label' => 'Key', 'type' => 'mono', 'sortable' => true],
                ['key' => 'name', 'label' => 'Name', 'type' => 'title'],
                ['key' => 'audience', 'label' => 'Audience', 'type' => 'chip'],
                ['key' => 'rollout_percent', 'label' => 'Rollout %', 'type' => 'number', 'align' => 'right'],
                ['key' => 'min_app_version', 'label' => 'Min version', 'type' => 'text'],
                ['key' => 'is_enabled', 'label' => 'Enabled', 'type' => 'boolean'],
            ],
            fields: [
                ['name' => 'flag_key', 'label' => 'Flag key', 'type' => 'text', 'rules' => 'required|string|min:3|max:80|unique:feature_flags,flag_key', 'hint' => 'Referenced in code, e.g. creator_video_calls. Do not rename once shipped.'],
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => 'required|string|min:2|max:150'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rules' => 'nullable|string|max:500', 'rows' => 3],
                ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'switch', 'rules' => 'nullable|boolean', 'group' => 'Rollout'],
                ['name' => 'rollout_percent', 'label' => 'Rollout percentage', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0|max_value:100', 'default' => 100, 'hint' => 'A stable hash of the user id decides, so a user never flips back and forth.', 'group' => 'Rollout'],
                ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'rules' => 'nullable|in:all,brands,creators,beta,internal', 'options' => ['all' => 'Everyone', 'brands' => 'Brands', 'creators' => 'Creators', 'beta' => 'Beta testers', 'internal' => 'Internal only'], 'default' => 'all', 'group' => 'Rollout'],
                ['name' => 'min_app_version', 'label' => 'Minimum app version', 'type' => 'text', 'rules' => 'nullable|string|max:20', 'hint' => 'Older clients never receive the flag.', 'group' => 'Rollout'],
            ],
            filters: [
                ['name' => 'is_enabled', 'label' => 'State', 'options' => ['' => 'Any state', '1' => 'Enabled', '0' => 'Disabled']],
                ['name' => 'audience', 'label' => 'Audience', 'options' => ['' => 'Any audience', 'all' => 'Everyone', 'beta' => 'Beta', 'internal' => 'Internal']],
            ],
            searchable: ['flag_key', 'name', 'description'],
            sortable: ['id', 'flag_key', 'rollout_percent'],
            defaultSort: 'flag_key',
            defaultDirection: 'ASC',
            activeColumn: 'is_enabled',
            emptyMessage: 'No feature flags yet.',
        );
    }

    private static function homepageSections(): self
    {
        return new self(
            key: 'sections',
            table: 'homepage_sections',
            singular: 'Section',
            plural: 'Homepage sections',
            icon: 'dashboard',
            viewPermission: 'cms.view',
            managePermission: 'cms.pages',
            description: 'Compose the app Home screen without shipping a release.',
            columns: [
                ['key' => 'title', 'label' => 'Title', 'type' => 'title', 'sortable' => true],
                ['key' => 'section_type', 'label' => 'Type', 'type' => 'chip'],
                ['key' => 'data_source', 'label' => 'Source', 'type' => 'chip'],
                ['key' => 'layout', 'label' => 'Layout', 'type' => 'chip'],
                ['key' => 'audience', 'label' => 'Audience', 'type' => 'chip'],
                ['key' => 'item_limit', 'label' => 'Items', 'type' => 'number', 'align' => 'right'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
                ['key' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'align' => 'right', 'sortable' => true],
            ],
            fields: [
                ['name' => 'title', 'label' => 'Section title', 'type' => 'text', 'rules' => 'required|string|min:2|max:150', 'hint' => 'Shown as the section heading in the app.'],
                ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'rules' => 'nullable|string|max:255'],
                ['name' => 'section_type', 'label' => 'Section type', 'type' => 'select', 'rules' => 'required|in:banner_carousel,category_strip,creator_grid,creator_carousel,testimonials,cta_card,blog_strip,stats,custom_html', 'options' => ['creator_carousel' => 'Creator carousel', 'creator_grid' => 'Creator grid', 'category_strip' => 'Category strip', 'banner_carousel' => 'Banner carousel', 'testimonials' => 'Testimonials', 'blog_strip' => 'Blog strip', 'cta_card' => 'Call-to-action card', 'stats' => 'Statistics', 'custom_html' => 'Custom HTML'], 'default' => 'creator_carousel', 'group' => 'Content'],
                ['name' => 'data_source', 'label' => 'Data source', 'type' => 'select', 'rules' => 'nullable|in:featured,top_rated,trending,newest,category,manual,saved', 'options' => ['top_rated' => 'Top rated', 'trending' => 'Trending', 'featured' => 'Featured', 'newest' => 'Newest', 'category' => 'A category', 'manual' => 'Hand-picked ids', 'saved' => 'The viewer\'s saved creators'], 'default' => 'top_rated', 'group' => 'Content'],
                ['name' => 'source_value', 'label' => 'Source value', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'hint' => 'Category slug, or a comma-separated id list for hand-picked.', 'group' => 'Content'],
                ['name' => 'item_limit', 'label' => 'Item limit', 'type' => 'number', 'rules' => 'nullable|integer|min_value:1|max_value:50', 'default' => 8, 'group' => 'Content'],
                ['name' => 'layout', 'label' => 'Layout', 'type' => 'select', 'rules' => 'nullable|in:grid_2,grid_3,carousel,list,full', 'options' => ['carousel' => 'Horizontal carousel', 'grid_2' => 'Two-column grid', 'grid_3' => 'Three-column grid', 'list' => 'Vertical list', 'full' => 'Full width'], 'default' => 'carousel', 'group' => 'Appearance'],
                ['name' => 'cta_label', 'label' => 'Link label', 'type' => 'text', 'rules' => 'nullable|string|max:60', 'hint' => 'e.g. View all', 'group' => 'Appearance'],
                ['name' => 'cta_action', 'label' => 'Link target', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'group' => 'Appearance'],
                ['name' => 'custom_html', 'label' => 'Custom HTML', 'type' => 'html', 'rules' => 'nullable|string', 'rows' => 6, 'hint' => 'Only used when the type is Custom HTML.', 'group' => 'Appearance'],
                ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'rules' => 'nullable|in:all,brands,creators,guests', 'options' => ['all' => 'Everyone', 'brands' => 'Brands', 'creators' => 'Creators', 'guests' => 'Signed out'], 'default' => 'all', 'group' => 'Visibility'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Visibility'],
                ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'rules' => 'nullable|integer|min_value:0', 'group' => 'Visibility'],
            ],
            filters: [
                ['name' => 'section_type', 'label' => 'Type', 'options' => ['' => 'Any type', 'creator_carousel' => 'Creator carousel', 'creator_grid' => 'Creator grid', 'category_strip' => 'Category strip', 'banner_carousel' => 'Banner carousel']],
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
            ],
            searchable: ['title', 'subtitle'],
            sortable: ['id', 'title', 'sort_order'],
            defaultSort: 'sort_order',
            defaultDirection: 'ASC',
            sortableRows: true,
            emptyMessage: 'No sections. The app falls back to its built-in Home layout.',
        );
    }

    private static function aiPrompts(): self
    {
        return new self(
            key: 'ai-prompts',
            table: 'ai_prompts',
            singular: 'Prompt',
            plural: 'AI prompts',
            icon: 'star',
            viewPermission: 'ai.view',
            managePermission: 'ai.manage',
            description: 'Versioned prompt templates for every AI-assisted feature.',
            columns: [
                ['key' => 'code', 'label' => 'Code', 'type' => 'mono', 'sortable' => true],
                ['key' => 'name', 'label' => 'Name', 'type' => 'title'],
                ['key' => 'purpose', 'label' => 'Purpose', 'type' => 'chip'],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text'],
                ['key' => 'temperature', 'label' => 'Temp', 'type' => 'text', 'align' => 'right'],
                ['key' => 'version', 'label' => 'Version', 'type' => 'number', 'align' => 'right'],
                ['key' => 'is_active', 'label' => 'Active', 'type' => 'boolean'],
            ],
            fields: [
                ['name' => 'code', 'label' => 'Prompt code', 'type' => 'text', 'rules' => 'required|string|min:3|max:60|unique:ai_prompts,code', 'hint' => 'Referenced in code, e.g. creator_match.'],
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => 'required|string|min:2|max:150'],
                ['name' => 'system_prompt', 'label' => 'System prompt', 'type' => 'textarea', 'rules' => 'nullable|string', 'rows' => 5, 'hint' => 'Sets the assistant\'s role and constraints.'],
                ['name' => 'user_prompt', 'label' => 'User prompt', 'type' => 'textarea', 'rules' => 'required|string', 'rows' => 8, 'hint' => 'Supports {{placeholders}} substituted at call time.'],
                ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'select', 'rules' => 'nullable|in:creator_match,campaign_brief,bio_writer,moderation,support_reply,tag_suggest,other', 'options' => ['creator_match' => 'Creator matching', 'campaign_brief' => 'Campaign brief writer', 'bio_writer' => 'Creator bio writer', 'moderation' => 'Content moderation', 'support_reply' => 'Support reply draft', 'tag_suggest' => 'Tag suggestions', 'other' => 'Other'], 'default' => 'other', 'group' => 'Model'],
                ['name' => 'model', 'label' => 'Model', 'type' => 'text', 'rules' => 'nullable|string|max:80', 'hint' => 'e.g. claude-sonnet-5', 'group' => 'Model'],
                ['name' => 'temperature', 'label' => 'Temperature', 'type' => 'text', 'rules' => 'nullable|numeric|min_value:0|max_value:2', 'default' => '0.70', 'group' => 'Model'],
                ['name' => 'max_tokens', 'label' => 'Max tokens', 'type' => 'number', 'rules' => 'nullable|integer|min_value:64|max_value:32000', 'default' => 1024, 'group' => 'Model'],
                ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'rules' => 'nullable|boolean', 'default' => 1, 'group' => 'Model'],
            ],
            filters: [
                ['name' => 'purpose', 'label' => 'Purpose', 'options' => ['' => 'Any purpose', 'creator_match' => 'Creator matching', 'campaign_brief' => 'Campaign brief', 'moderation' => 'Moderation', 'support_reply' => 'Support reply']],
                ['name' => 'is_active', 'label' => 'Status', 'options' => ['' => 'Any status', '1' => 'Active', '0' => 'Inactive']],
            ],
            searchable: ['code', 'name', 'user_prompt'],
            sortable: ['id', 'code', 'version'],
            defaultSort: 'code',
            defaultDirection: 'ASC',
            emptyMessage: 'No prompts configured yet.',
        );
    }
}
