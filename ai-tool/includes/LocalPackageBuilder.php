<?php
/**
 * Builds a complete website package without external AI APIs.
 * Used when no Anthropic/Claude key is available (Cursor API keys cannot
 * replace Claude chat completions for this local PHP tool).
 */

declare(strict_types=1);

final class LocalPackageBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(string $websiteName, string $description, string $domain): array
    {
        $palette = self::paletteFor($websiteName . $domain);
        $services = self::inferServices($description);
        $industry = self::inferIndustry($description, $websiteName);

        $primary   = $palette['primary'];
        $secondary = $palette['secondary'];
        $accent    = $palette['accent'];

        $headingFont = 'Fraunces';
        $bodyFont    = 'Manrope';
        $fontsUrl    = 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Manrope:wght@400;600;700&display=swap';

        $pages = self::buildPages($websiteName, $description, $domain, $services, $industry, $primary, $accent);
        $posts = self::buildPosts($websiteName, $industry, $services);
        $patterns = self::buildPatterns($websiteName, $services, $primary, $accent);

        $themeJson = [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 2,
            'settings' => [
                'appearanceTools' => true,
                'color' => [
                    'palette' => [
                        ['slug' => 'primary', 'name' => 'Primary', 'color' => $primary],
                        ['slug' => 'secondary', 'name' => 'Secondary', 'color' => $secondary],
                        ['slug' => 'accent', 'name' => 'Accent', 'color' => $accent],
                        ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                        ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111827'],
                        ['slug' => 'muted', 'name' => 'Muted', 'color' => '#6b7280'],
                    ],
                ],
                'typography' => [
                    'fontFamilies' => [
                        [
                            'fontFamily' => "\"{$headingFont}\", Georgia, serif",
                            'name' => 'Heading',
                            'slug' => 'heading',
                        ],
                        [
                            'fontFamily' => "\"{$bodyFont}\", system-ui, sans-serif",
                            'name' => 'Body',
                            'slug' => 'body',
                        ],
                    ],
                ],
                'layout' => [
                    'contentSize' => '720px',
                    'wideSize' => '1180px',
                ],
                'spacing' => [
                    'units' => ['px', 'rem', '%'],
                ],
            ],
            'styles' => [
                'color' => [
                    'background' => 'var(--wp--preset--color--base)',
                    'text' => 'var(--wp--preset--color--contrast)',
                ],
                'typography' => [
                    'fontFamily' => 'var(--wp--preset--font-family--body)',
                    'fontSize' => '1.125rem',
                    'lineHeight' => '1.65',
                ],
                'elements' => [
                    'heading' => [
                        'typography' => [
                            'fontFamily' => 'var(--wp--preset--font-family--heading)',
                            'fontWeight' => '700',
                            'lineHeight' => '1.2',
                        ],
                    ],
                    'link' => [
                        'color' => ['text' => 'var(--wp--preset--color--primary)'],
                    ],
                    'button' => [
                        'color' => [
                            'background' => 'var(--wp--preset--color--primary)',
                            'text' => '#ffffff',
                        ],
                        'border' => ['radius' => '999px'],
                        'spacing' => [
                            'padding' => [
                                'top' => '0.85rem',
                                'bottom' => '0.85rem',
                                'left' => '1.35rem',
                                'right' => '1.35rem',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $styleCss = self::themeStyleCss($primary, $secondary, $accent, $headingFont, $bodyFont);
        $functionsPhp = self::themeFunctionsPhp($fontsUrl);

        return [
            'website_summary' => "{$websiteName} is a locally generated WordPress site for {$industry}. "
                . 'Built with the AI Starter theme, core Gutenberg blocks, and content derived from your business description '
                . '(no external Claude/Cursor chat API required).',
            'sitemap' => array_map(static fn ($p) => $p['title'], $pages),
            'brand_guidelines' => [
                'personality' => 'Confident, modern, and trustworthy',
                'audience' => "People searching for {$industry} solutions",
                'tone' => 'Clear, professional, and helpful',
                'uvp' => "Practical {$industry} expertise delivered with a premium local web presence",
                'positioning' => "A dependable {$industry} brand that makes next steps obvious",
            ],
            'design_system' => [
                'colors' => [
                    'primary' => $primary,
                    'secondary' => $secondary,
                    'accent' => $accent,
                    'neutral' => ['#111827', '#4b5563', '#e5e7eb', '#ffffff'],
                    'success' => '#166534',
                    'warning' => '#92400e',
                    'error' => '#991b1b',
                ],
                'typography' => [
                    'heading_font' => $headingFont,
                    'body_font' => $bodyFont,
                    'google_fonts_url' => $fontsUrl,
                    'scale' => [
                        'h1' => '3rem',
                        'h2' => '2.25rem',
                        'h3' => '1.75rem',
                        'body' => '1.125rem',
                    ],
                ],
                'spacing' => [
                    'unit' => '8px',
                    'container' => '1180px',
                    'radius' => '12px',
                ],
                'shadows' => [
                    'card' => '0 12px 30px rgba(17,24,39,0.08)',
                    'button' => '0 8px 18px rgba(0,0,0,0.12)',
                ],
                'breakpoints' => [
                    'mobile' => '480px',
                    'tablet' => '768px',
                    'desktop' => '1024px',
                ],
            ],
            'theme_files' => [
                'style.css' => $styleCss,
                'functions.php' => $functionsPhp,
                'theme.json' => $themeJson,
                'index.php' => self::fileIndex(),
                'front-page.php' => self::fileFrontPage(),
                'page.php' => self::filePage(),
                'single.php' => self::fileSingle(),
                'archive.php' => self::fileArchive(),
                'header.php' => self::fileHeader(),
                'footer.php' => self::fileFooter(),
                '404.php' => self::file404(),
            ],
            'block_patterns' => $patterns,
            'pages' => $pages,
            'posts' => $posts,
            'menus' => [
                'primary' => [
                    ['title' => 'Home', 'slug' => 'home'],
                    ['title' => 'About', 'slug' => 'about'],
                    ['title' => 'Services', 'slug' => 'services'],
                    ['title' => 'Pricing', 'slug' => 'pricing'],
                    ['title' => 'Blog', 'slug' => 'blog'],
                    ['title' => 'Contact', 'slug' => 'contact'],
                ],
                'footer' => [
                    ['title' => 'Privacy Policy', 'slug' => 'privacy-policy'],
                    ['title' => 'Terms', 'slug' => 'terms'],
                    ['title' => 'Contact', 'slug' => 'contact'],
                ],
            ],
            'settings' => [
                'blog_page_title' => 'Blog',
                'blog_page_slug' => 'blog',
                'timezone' => 'Asia/Kolkata',
                'permalink_structure' => '/%postname%/',
            ],
            'image_prompts' => [
                'hero' => [
                    "Editorial photo for {$websiteName}: {$industry} workplace, natural light, no logos, no text overlays",
                ],
                'services' => array_map(
                    static fn ($s) => "Clean product/service photo representing {$s}, modern, copyright-safe stock style",
                    $services
                ),
                'portfolio' => [
                    "Before/after style project collage for {$industry}, realistic, no watermarks",
                ],
                'blog' => [
                    "Minimal desk flat-lay related to {$industry}, soft daylight",
                ],
                'team' => [
                    'Diverse professional team portrait, friendly, natural office setting',
                ],
                'cta' => [
                    "Wide atmospheric background for {$industry} call-to-action section, subtle depth, no text",
                ],
            ],
            'seo_summary' => "Pages use descriptive titles and meta descriptions focused on {$websiteName}, "
                . "{$domain}, and core {$industry} services. Permalinks use /%postname%/. "
                . 'Add real images with ALT text using the provided prompts before launch.',
            'accessibility_summary' => 'Semantic headings, button links, and keyboard-friendly navigation menus. '
                . 'Maintain WCAG AA contrast when replacing colors or images. Ensure form labels remain visible.',
            'performance_summary' => 'Lightweight custom theme, core Gutenberg blocks only, no page builders, '
                . 'minimal CSS/JS. Prefer compressed WebP images when you add media.',
            'final_report' => [
                'installed_pages' => array_map(static fn ($p) => $p['title'], $pages),
                'installed_posts' => array_map(static fn ($p) => $p['title'], $posts),
                'block_patterns' => array_map(static fn ($p) => $p['title'], $patterns),
                'remaining_manual_tasks' => [
                    'Add real images using the image prompts',
                    'Replace contact placeholders with real phone/email/address',
                    'Review sample testimonials before going live',
                    'Optional: add Anthropic Claude API key later for richer AI-written content',
                ],
            ],
        ];
    }

    /**
     * @return array{primary:string,secondary:string,accent:string}
     */
    private static function paletteFor(string $seed): array
    {
        $palettes = [
            ['primary' => '#0f766e', 'secondary' => '#134e4a', 'accent' => '#d97706'],
            ['primary' => '#1d4ed8', 'secondary' => '#1e3a8a', 'accent' => '#f59e0b'],
            ['primary' => '#b45309', 'secondary' => '#7c2d12', 'accent' => '#0f766e'],
            ['primary' => '#047857', 'secondary' => '#064e3b', 'accent' => '#ea580c'],
            ['primary' => '#0e7490', 'secondary' => '#164e63', 'accent' => '#ca8a04'],
            ['primary' => '#be123c', 'secondary' => '#881337', 'accent' => '#0f766e'],
        ];
        $idx = hexdec(substr(md5($seed), 0, 8)) % count($palettes);
        return $palettes[$idx];
    }

    /**
     * @return list<string>
     */
    private static function inferServices(string $description): array
    {
        $text = strtolower($description);
        $map = [
            'plumbing' => ['Emergency Repairs', 'Drain Cleaning', 'Water Heater Service', 'Pipe Installation'],
            'dental' => ['General Checkups', 'Cosmetic Dentistry', 'Teeth Whitening', 'Orthodontics'],
            'law' => ['Consultation', 'Contract Review', 'Dispute Resolution', 'Compliance Advisory'],
            'restaurant' => ['Dine-In Experience', 'Catering', 'Private Events', 'Online Ordering'],
            'fitness' => ['Personal Training', 'Group Classes', 'Nutrition Coaching', 'Membership Plans'],
            'real estate' => ['Buyer Representation', 'Seller Strategy', 'Property Valuation', 'Relocation Support'],
            'marketing' => ['Brand Strategy', 'Content Creation', 'SEO Growth', 'Paid Campaigns'],
            'software' => ['Product Design', 'Custom Development', 'Integrations', 'Support & Maintenance'],
            'salon' => ['Hair Styling', 'Color Services', 'Skin Treatments', 'Spa Packages'],
            'construction' => ['Renovations', 'New Builds', 'Project Management', 'Design Consultation'],
            'education' => ['Courses', 'Workshops', 'Mentorship', 'Corporate Training'],
            'clinic' => ['Consultations', 'Diagnostics', 'Treatment Plans', 'Follow-up Care'],
        ];

        foreach ($map as $needle => $services) {
            if (str_contains($text, $needle)) {
                return $services;
            }
        }

        // Generic but concrete services from description sentences
        return [
            'Strategy & Discovery',
            'Core Service Delivery',
            'Ongoing Support',
            'Premium Packages',
        ];
    }

    private static function inferIndustry(string $description, string $websiteName): string
    {
        $text = strtolower($description . ' ' . $websiteName);
        $guesses = [
            'plumbing' => 'plumbing services',
            'dental' => 'dental care',
            'law' => 'legal services',
            'restaurant' => 'hospitality',
            'fitness' => 'fitness & wellness',
            'real estate' => 'real estate',
            'marketing' => 'marketing',
            'software' => 'software & technology',
            'salon' => 'beauty & personal care',
            'construction' => 'construction',
            'education' => 'education',
            'clinic' => 'healthcare',
            'agency' => 'creative agency services',
            'consult' => 'consulting',
        ];
        foreach ($guesses as $needle => $label) {
            if (str_contains($text, $needle)) {
                return $label;
            }
        }
        return 'professional services';
    }

    /**
     * @param list<string> $services
     * @return list<array<string, mixed>>
     */
    private static function buildPages(
        string $websiteName,
        string $description,
        string $domain,
        array $services,
        string $industry,
        string $primary,
        string $accent
    ): array {
        $serviceItems = '';
        foreach ($services as $service) {
            $serviceItems .= "<!-- wp:column -->\n<div class=\"wp-block-column\">"
                . "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">"
                . self::e($service) . "</h3>\n<!-- /wp:heading -->\n"
                . "<!-- wp:paragraph -->\n<p>Practical {$industry} support focused on "
                . strtolower(self::e($service)) . " with clear timelines and transparent communication.</p>\n<!-- /wp:paragraph -->\n"
                . "</div>\n<!-- /wp:column -->\n";
        }

        $homeContent = <<<HTML
<!-- wp:cover {"dimRatio":70,"overlayColor":"secondary","minHeight":70,"minHeightUnit":"vh","align":"full"} -->
<div class="wp-block-cover alignfull" style="min-height:70vh"><span aria-hidden="true" class="wp-block-cover__background has-secondary-background-color has-background-dim-70 has-background-dim"></span><div class="wp-block-cover__inner-container">
<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">{$websiteName}</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">{$description}</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Get started</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/services/">View services</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div></div>
<!-- /wp:cover -->

<!-- wp:spacer {"height":"48px"} -->
<div style="height:48px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">What we deliver</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">A clear path from first conversation to finished results for {$industry}.</p>
<!-- /wp:paragraph -->

<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide">
{$serviceItems}
</div>
<!-- /wp:columns -->

<!-- wp:spacer {"height":"40px"} -->
<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"2rem","bottom":"2rem","left":"2rem","right":"2rem"}},"color":{"background":"#f8fafc"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-background" style="background-color:#f8fafc;padding-top:2rem;padding-right:2rem;padding-bottom:2rem;padding-left:2rem">
<!-- wp:heading {"textAlign":"center","level":3} -->
<h3 class="wp-block-heading has-text-align-center">Illustrative results</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><em>Sample statistics for layout demonstration only.</em> 98% client satisfaction · 24–48h response · 10+ years combined experience</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:spacer {"height":"40px"} -->
<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>“Working with {$websiteName} felt organized from day one. Clear updates, strong craft, and no surprises.”</p><cite>Alex Morgan — Sample testimonial</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Book a consultation</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
HTML;

        $about = <<<HTML
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">About {$websiteName}</h1>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>{$description}</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Mission</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Help customers make confident decisions with transparent {$industry} guidance and dependable delivery.</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Vision</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Become the most trusted local digital presence for people searching around {$domain}.</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Values</h2>
<!-- /wp:heading -->
<!-- wp:list -->
<ul class="wp-block-list"><li>Clarity over jargon</li><li>Quality over shortcuts</li><li>Respect for people’s time</li><li>Continuous improvement</li></ul>
<!-- /wp:list -->
HTML;

        $servicesPage = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Services</h1>\n<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph -->\n<p>Explore how {$websiteName} supports your goals with focused {$industry} offerings.</p>\n<!-- /wp:paragraph -->\n"
            . "<!-- wp:columns -->\n<div class=\"wp-block-columns\">\n{$serviceItems}</div>\n<!-- /wp:columns -->\n";

        $pricingRows = '';
        $plans = [
            ['Starter', 'Essential support for getting started', '$199'],
            ['Growth', 'Best for active projects and monthly care', '$499'],
            ['Premium', 'Priority delivery and expanded scope', '$999'],
        ];
        foreach ($plans as [$name, $blurb, $price]) {
            $pricingRows .= "<!-- wp:column -->\n<div class=\"wp-block-column\">"
                . "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">{$name}</h3>\n<!-- /wp:heading -->\n"
                . "<!-- wp:paragraph -->\n<p>{$blurb}</p>\n<!-- /wp:paragraph -->\n"
                . "<!-- wp:paragraph -->\n<p><strong>{$price}</strong> <em>(sample pricing)</em></p>\n<!-- /wp:paragraph -->\n"
                . "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n"
                . "<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"/contact/\">Choose {$name}</a></div>\n"
                . "<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n</div>\n<!-- /wp:column -->\n";
        }

        $pricing = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Pricing</h1>\n<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph -->\n<p>Sample packages for planning conversations. Final quotes depend on scope.</p>\n<!-- /wp:paragraph -->\n"
            . "<!-- wp:columns -->\n<div class=\"wp-block-columns\">\n{$pricingRows}</div>\n<!-- /wp:columns -->\n";

        $faq = <<<HTML
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">FAQ</h1>
<!-- /wp:heading -->
<!-- wp:details -->
<details class="wp-block-details"><summary>How quickly can we start?</summary>
<!-- wp:paragraph -->
<p>Most projects begin within a few business days after a short discovery call.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>Do you work with first-time clients?</summary>
<!-- wp:paragraph -->
<p>Yes. We explain options in plain language and recommend only what fits your goals.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>Can packages be customized?</summary>
<!-- wp:paragraph -->
<p>Absolutely. Pricing cards are starting points — we tailor scope after understanding your needs.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
HTML;

        $contact = <<<HTML
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Contact {$websiteName}</h1>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Tell us what you need. We typically respond within one business day.</p>
<!-- /wp:paragraph -->
<!-- wp:list -->
<ul class="wp-block-list">
<li>Email: hello@{$domain}</li>
<li>Phone: (555) 010-2000 (placeholder)</li>
<li>Address: 123 Main Street, Your City (placeholder)</li>
</ul>
<!-- /wp:list -->
<!-- wp:paragraph -->
<p><a class="wp-block-button__link" href="mailto:hello@{$domain}">Email us</a></p>
<!-- /wp:paragraph -->
HTML;

        $privacy = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Privacy Policy</h1>\n<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph -->\n<p>This is a starter privacy policy for {$websiteName}. Replace with counsel-approved legal text before launch. "
            . "We collect only information you submit via contact forms and use it to respond to inquiries.</p>\n<!-- /wp:paragraph -->\n";

        $terms = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Terms</h1>\n<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph -->\n<p>Starter terms for {$websiteName}. Replace with your legal terms of service before public launch.</p>\n<!-- /wp:paragraph -->\n";

        $blog = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Blog</h1>\n<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph -->\n<p>Updates, guides, and notes from the {$websiteName} team.</p>\n<!-- /wp:paragraph -->\n"
            . "<!-- wp:latest-posts {\"postsToShow\":5,\"displayPostContent\":true,\"excerptLength\":22} /-->\n";

        $thankYou = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Thank you</h1>\n<!-- /wp:heading -->\n"
            . "<!-- wp:paragraph -->\n<p>We received your message and will get back soon.</p>\n<!-- /wp:paragraph -->\n";

        return [
            [
                'title' => 'Home',
                'slug' => 'home',
                'status' => 'publish',
                'is_front_page' => true,
                'meta_title' => "{$websiteName} | {$industry}",
                'meta_description' => self::clip($description, 155),
                'content' => $homeContent,
            ],
            [
                'title' => 'About',
                'slug' => 'about',
                'status' => 'publish',
                'meta_title' => "About {$websiteName}",
                'meta_description' => "Learn about {$websiteName} and our approach to {$industry}.",
                'content' => $about,
            ],
            [
                'title' => 'Services',
                'slug' => 'services',
                'status' => 'publish',
                'meta_title' => "Services | {$websiteName}",
                'meta_description' => "Explore {$industry} services from {$websiteName}.",
                'content' => $servicesPage,
            ],
            [
                'title' => 'Pricing',
                'slug' => 'pricing',
                'status' => 'publish',
                'meta_title' => "Pricing | {$websiteName}",
                'meta_description' => "Sample pricing packages from {$websiteName}.",
                'content' => $pricing,
            ],
            [
                'title' => 'FAQ',
                'slug' => 'faq',
                'status' => 'publish',
                'meta_title' => "FAQ | {$websiteName}",
                'meta_description' => "Frequently asked questions about {$websiteName}.",
                'content' => $faq,
            ],
            [
                'title' => 'Blog',
                'slug' => 'blog',
                'status' => 'publish',
                'meta_title' => "Blog | {$websiteName}",
                'meta_description' => "Articles and updates from {$websiteName}.",
                'content' => $blog,
            ],
            [
                'title' => 'Contact',
                'slug' => 'contact',
                'status' => 'publish',
                'meta_title' => "Contact {$websiteName}",
                'meta_description' => "Contact {$websiteName} for {$industry} help.",
                'content' => $contact,
            ],
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'status' => 'publish',
                'meta_title' => "Privacy Policy | {$websiteName}",
                'meta_description' => "Privacy policy for {$websiteName}.",
                'content' => $privacy,
            ],
            [
                'title' => 'Terms',
                'slug' => 'terms',
                'status' => 'publish',
                'meta_title' => "Terms | {$websiteName}",
                'meta_description' => "Terms of use for {$websiteName}.",
                'content' => $terms,
            ],
            [
                'title' => 'Thank You',
                'slug' => 'thank-you',
                'status' => 'publish',
                'meta_title' => "Thank You | {$websiteName}",
                'meta_description' => 'Confirmation page.',
                'content' => $thankYou,
            ],
        ];
    }

    /**
     * @param list<string> $services
     * @return list<array<string, mixed>>
     */
    private static function buildPosts(string $websiteName, string $industry, array $services): array
    {
        $first = $services[0] ?? 'core services';
        return [
            [
                'title' => "How {$websiteName} approaches {$industry}",
                'slug' => 'how-we-approach-our-work',
                'excerpt' => 'A practical look at our process from first call to delivery.',
                'categories' => ['Guides'],
                'tags' => ['process', 'overview'],
                'content' => "<!-- wp:paragraph -->\n<p>At {$websiteName}, we start with listening. Understanding your constraints early prevents rework later.</p>\n<!-- /wp:paragraph -->\n"
                    . "<!-- wp:paragraph -->\n<p>Our typical path: discovery, proposal, execution, and a clear handoff with next-step recommendations.</p>\n<!-- /wp:paragraph -->\n",
            ],
            [
                'title' => "3 questions to ask before hiring {$industry} help",
                'slug' => 'questions-before-hiring',
                'excerpt' => 'Use these prompts to compare providers with confidence.',
                'categories' => ['Tips'],
                'tags' => ['buying-guide'],
                'content' => "<!-- wp:list -->\n<ul class=\"wp-block-list\"><li>What does success look like in 30 days?</li><li>How do you communicate progress?</li><li>What is included versus optional?</li></ul>\n<!-- /wp:list -->\n"
                    . "<!-- wp:paragraph -->\n<p>If you want help answering these for {$first}, <a href=\"/contact/\">talk to {$websiteName}</a>.</p>\n<!-- /wp:paragraph -->\n",
            ],
            [
                'title' => "A simple checklist for better {$industry} outcomes",
                'slug' => 'simple-checklist',
                'excerpt' => 'Small preparation steps that save time once work begins.',
                'categories' => ['Checklists'],
                'tags' => ['checklist'],
                'content' => "<!-- wp:paragraph -->\n<p>Gather goals, constraints, examples you like, and decision-makers before kickoff. Clarity up front is the highest-leverage step.</p>\n<!-- /wp:paragraph -->\n",
            ],
        ];
    }

    /**
     * @param list<string> $services
     * @return list<array<string, mixed>>
     */
    private static function buildPatterns(string $websiteName, array $services, string $primary, string $accent): array
    {
        $serviceLis = '';
        foreach ($services as $s) {
            $serviceLis .= '<li>' . self::e($s) . '</li>';
        }

        return [
            [
                'slug' => 'ai-starter/hero',
                'title' => 'Hero',
                'category' => 'ai-starter',
                'content' => "<!-- wp:group {\"align\":\"full\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group alignfull\">"
                    . "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">" . self::e($websiteName) . "</h1>\n<!-- /wp:heading -->\n"
                    . "<!-- wp:paragraph -->\n<p>Premium local presence with clear calls to action.</p>\n<!-- /wp:paragraph -->\n"
                    . "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"/contact/\">Contact</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n"
                    . "</div>\n<!-- /wp:group -->",
            ],
            [
                'slug' => 'ai-starter/services',
                'title' => 'Services',
                'category' => 'ai-starter',
                'content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Services</h2>\n<!-- /wp:heading -->\n<!-- wp:list -->\n<ul class=\"wp-block-list\">{$serviceLis}</ul>\n<!-- /wp:list -->",
            ],
            [
                'slug' => 'ai-starter/testimonials',
                'title' => 'Testimonials',
                'category' => 'ai-starter',
                'content' => "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>“Reliable, clear, and easy to work with.”</p><cite>Sample testimonial</cite></blockquote>\n<!-- /wp:quote -->",
            ],
            [
                'slug' => 'ai-starter/faq',
                'title' => 'FAQ',
                'category' => 'ai-starter',
                'content' => "<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>How do we begin?</summary><!-- wp:paragraph -->\n<p>Send a short note through the contact page and we will propose next steps.</p>\n<!-- /wp:paragraph --></details>\n<!-- /wp:details -->",
            ],
            [
                'slug' => 'ai-starter/cta',
                'title' => 'CTA',
                'category' => 'ai-starter',
                'content' => "<!-- wp:group {\"style\":{\"color\":{\"background\":\"{$primary}\"}},\"textColor\":\"base\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group has-base-color has-text-color has-background\" style=\"background-color:{$primary}\">"
                    . "<!-- wp:heading {\"textAlign\":\"center\"} -->\n<h2 class=\"wp-block-heading has-text-align-center\">Ready to talk?</h2>\n<!-- /wp:heading -->\n"
                    . "<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n<div class=\"wp-block-buttons\"><!-- wp:button {\"backgroundColor\":\"accent\"} -->\n"
                    . "<div class=\"wp-block-button\"><a class=\"wp-block-button__link has-accent-background-color has-background wp-element-button\" href=\"/contact/\">Contact us</a></div>\n"
                    . "<!-- /wp:button --></div>\n<!-- /wp:buttons --></div>\n<!-- /wp:group -->",
            ],
            [
                'slug' => 'ai-starter/contact',
                'title' => 'Contact',
                'category' => 'ai-starter',
                'content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Contact</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Email, phone, and address placeholders live on the Contact page.</p>\n<!-- /wp:paragraph -->",
            ],
            [
                'slug' => 'ai-starter/feature-grid',
                'title' => 'Feature Grid',
                'category' => 'ai-starter',
                'content' => "<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Fast</h3>\n<!-- /wp:heading --><!-- wp:paragraph -->\n<p>Clear timelines.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column --><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Trusted</h3>\n<!-- /wp:heading --><!-- wp:paragraph -->\n<p>Transparent communication.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column --><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Premium</h3>\n<!-- /wp:heading --><!-- wp:paragraph -->\n<p>Modern presentation (accent {$accent}).</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->",
            ],
        ];
    }

    private static function themeStyleCss(
        string $primary,
        string $secondary,
        string $accent,
        string $headingFont,
        string $bodyFont
    ): string {
        return <<<CSS
/*
Theme Name: AI Starter
Description: Lightweight custom theme generated by AI Tool (local mode).
Version: 1.0.0
Text Domain: ai-starter
*/

:root {
  --ai-primary: {$primary};
  --ai-secondary: {$secondary};
  --ai-accent: {$accent};
}

body {
  margin: 0;
  font-family: "{$bodyFont}", system-ui, sans-serif;
  color: #111827;
  background: #fff;
}

a { color: var(--ai-primary); }

.site-header,
.site-footer {
  padding: 1.1rem 1.5rem;
}

.site-header {
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  gap: 1.5rem;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.site-title {
  font-family: "{$headingFont}", Georgia, serif;
  font-size: 1.25rem;
  font-weight: 700;
  text-decoration: none;
  color: var(--ai-secondary);
}

.primary-nav .menu,
.site-footer .menu {
  display: flex;
  flex-wrap: wrap;
  gap: 0.85rem 1.1rem;
  list-style: none;
  margin: 0;
  padding: 0;
}

.primary-nav a,
.site-footer a {
  text-decoration: none;
  color: #1f2937;
  font-weight: 600;
}

.primary-nav a:hover,
.site-footer a:hover {
  color: var(--ai-primary);
}

.wp-block-button__link {
  background: var(--ai-primary);
  border-radius: 999px;
}

.site-main {
  max-width: 1180px;
  margin: 0 auto;
  padding: 2rem 1.5rem 4rem;
}

.site-footer {
  border-top: 1px solid #e5e7eb;
  margin-top: 2rem;
  background: #f8fafc;
}

@media (max-width: 768px) {
  .site-header {
    flex-direction: column;
    align-items: flex-start;
  }
}
CSS;
    }

    private static function themeFunctionsPhp(string $fontsUrl): string
    {
        $url = var_export($fontsUrl, true);
        return <<<PHP
<?php
/**
 * AI Starter theme functions.
 */

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('wp-block-styles');
    add_theme_support('editor-styles');
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    add_theme_support('automatic-feed-links');
    register_nav_menus([
        'primary' => __('Primary Menu', 'ai-starter'),
        'footer'  => __('Footer Menu', 'ai-starter'),
    ]);
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('ai-starter-fonts', {$url}, [], null);
    wp_enqueue_style('ai-starter-style', get_stylesheet_uri(), ['ai-starter-fonts'], '1.0.0');
});
PHP;
    }

    private static function fileIndex(): string
    {
        return "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php if (have_posts()) : while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h1><?php the_title(); ?></h1>\n    <?php the_content(); ?>\n  </article>\n<?php endwhile; else : ?>\n  <p><?php esc_html_e('No content found.', 'ai-starter'); ?></p>\n<?php endif; ?>\n</main>\n<?php get_footer();\n";
    }

    private static function fileFrontPage(): string
    {
        return "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php while (have_posts()) : the_post(); the_content(); endwhile; ?>\n</main>\n<?php get_footer();\n";
    }

    private static function filePage(): string
    {
        return "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h1><?php the_title(); ?></h1>\n    <?php the_content(); ?>\n  </article>\n<?php endwhile; ?>\n</main>\n<?php get_footer();\n";
    }

    private static function fileSingle(): string
    {
        return "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h1><?php the_title(); ?></h1>\n    <div class=\"entry-meta\"><?php echo esc_html(get_the_date()); ?></div>\n    <?php the_content(); ?>\n  </article>\n<?php endwhile; ?>\n</main>\n<?php get_footer();\n";
    }

    private static function fileArchive(): string
    {
        return "<?php get_header(); ?>\n<main class=\"site-main\">\n  <h1><?php the_archive_title(); ?></h1>\n<?php if (have_posts()) : while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h2><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h2>\n    <?php the_excerpt(); ?>\n  </article>\n<?php endwhile; the_posts_pagination(); endif; ?>\n</main>\n<?php get_footer();\n";
    }

    private static function fileHeader(): string
    {
        return "<!DOCTYPE html>\n<html <?php language_attributes(); ?>>\n<head>\n<meta charset=\"<?php bloginfo('charset'); ?>\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<?php wp_head(); ?>\n</head>\n<body <?php body_class(); ?>>\n<?php wp_body_open(); ?>\n<header class=\"site-header\">\n  <a class=\"site-title\" href=\"<?php echo esc_url(home_url('/')); ?>\"><?php bloginfo('name'); ?></a>\n  <nav class=\"primary-nav\" aria-label=\"Primary\">\n    <?php wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'fallback_cb' => false]); ?>\n  </nav>\n</header>\n";
    }

    private static function fileFooter(): string
    {
        return "<footer class=\"site-footer\">\n  <nav aria-label=\"Footer\">\n    <?php wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'fallback_cb' => false]); ?>\n  </nav>\n  <p>&copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?></p>\n</footer>\n<?php wp_footer(); ?>\n</body>\n</html>\n";
    }

    private static function file404(): string
    {
        return "<?php get_header(); ?>\n<main class=\"site-main\">\n  <h1><?php esc_html_e('Page not found', 'ai-starter'); ?></h1>\n  <p><?php esc_html_e('The page you requested could not be found.', 'ai-starter'); ?></p>\n  <p><a class=\"wp-block-button__link\" href=\"<?php echo esc_url(home_url('/')); ?>\"><?php esc_html_e('Back to home', 'ai-starter'); ?></a></p>\n</main>\n<?php get_footer();\n";
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if (strlen($value) <= $max) {
            return $value;
        }
        return rtrim(substr($value, 0, $max - 1)) . '…';
    }
}
