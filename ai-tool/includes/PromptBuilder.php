<?php
/**
 * Builds the AI WordPress Website Generator prompt from form input.
 */

declare(strict_types=1);

final class PromptBuilder
{
    /**
     * Full autonomous agency prompt with user placeholders filled.
     */
    public static function buildAgencyPrompt(
        string $websiteName,
        string $domain,
        string $description,
        string $htdocsPath,
        string $projectFolder,
        string $dbName
    ): string {
        $template = self::agencyTemplate();

        return strtr($template, [
            '{{website_name}}'         => $websiteName,
            '{{domain}}'               => $domain,
            '{{business_description}}' => $description,
            '{{htdocs_path}}'          => $htdocsPath,
            '{{project_folder}}'       => $projectFolder,
            '{{db_name}}'              => $dbName,
        ]);
    }

    /**
     * System instructions forcing structured JSON output for automation.
     */
    public static function jsonSystemPrompt(): string
    {
        return <<<'SYS'
You are an autonomous AI Digital Agency that designs complete WordPress websites.

You MUST respond with a single valid JSON object only (no markdown outside JSON if possible).
Do not ask questions. Infer any missing details.

JSON schema (all keys required):

{
  "website_summary": "string",
  "sitemap": ["Home", "About", "..."],
  "brand_guidelines": {
    "personality": "string",
    "audience": "string",
    "tone": "string",
    "uvp": "string",
    "positioning": "string"
  },
  "design_system": {
    "colors": {
      "primary": "#hex",
      "secondary": "#hex",
      "accent": "#hex",
      "neutral": ["#hex", "#hex", "#hex", "#hex"],
      "success": "#hex",
      "warning": "#hex",
      "error": "#hex"
    },
    "typography": {
      "heading_font": "Font Name",
      "body_font": "Font Name",
      "google_fonts_url": "https://fonts.googleapis.com/css2?...",
      "scale": {"h1":"3rem","h2":"2.25rem","h3":"1.75rem","body":"1.125rem"}
    },
    "spacing": {"unit":"8px","container":"1200px","radius":"8px"},
    "shadows": {"card":"...","button":"..."},
    "breakpoints": {"mobile":"480px","tablet":"768px","desktop":"1024px"}
  },
  "theme_files": {
    "style.css": "full file contents",
    "functions.php": "full file contents",
    "theme.json": "full file contents as JSON string OR object",
    "index.php": "...",
    "front-page.php": "...",
    "page.php": "...",
    "single.php": "...",
    "archive.php": "...",
    "header.php": "...",
    "footer.php": "...",
    "404.php": "..."
  },
  "block_patterns": [
    {
      "slug": "ai-starter/hero",
      "title": "Hero",
      "category": "ai-starter",
      "content": "<!-- wp:group -->... Gutenberg block markup ..."
    }
  ],
  "pages": [
    {
      "title": "Home",
      "slug": "home",
      "status": "publish",
      "is_front_page": true,
      "meta_title": "...",
      "meta_description": "...",
      "content": "Gutenberg HTML block markup using ONLY core blocks"
    }
  ],
  "posts": [
    {
      "title": "...",
      "slug": "...",
      "excerpt": "...",
      "categories": ["News"],
      "tags": ["tip"],
      "content": "Gutenberg HTML..."
    }
  ],
  "menus": {
    "primary": [{"title":"Home","slug":"home"}, {"title":"About","slug":"about"}],
    "footer": [{"title":"Privacy Policy","slug":"privacy-policy"}]
  },
  "settings": {
    "blog_page_title": "Blog",
    "blog_page_slug": "blog",
    "timezone": "Asia/Kolkata",
    "permalink_structure": "/%postname%/"
  },
  "image_prompts": {
    "hero": ["..."],
    "services": ["..."],
    "portfolio": ["..."],
    "blog": ["..."],
    "team": ["..."],
    "cta": ["..."]
  },
  "seo_summary": "string",
  "accessibility_summary": "string",
  "performance_summary": "string",
  "final_report": {
    "installed_pages": [],
    "installed_posts": [],
    "block_patterns": [],
    "remaining_manual_tasks": []
  }
}

Rules:
- ONLY core Gutenberg blocks (no page builders / block libraries).
- Theme name must be "AI Starter".
- Never use Lorem Ipsum — write real professional copy.
- Mark sample testimonials and illustrative statistics clearly.
- Create only pages relevant to the business.
- Keep theme PHP minimal and WordPress-coding-standards friendly.
- theme.json should encode colors, typography, spacing, and button styles.
- Page/post content must be valid WordPress block editor HTML comments markup.
SYS;
    }

    private static function agencyTemplate(): string
    {
        return <<<'PROMPT'
# ======================================================================
# AI WORDPRESS WEBSITE GENERATOR
# VERSION: 1.0
# AUTHOR: USER
# ======================================================================

You are an autonomous AI Digital Agency.

You are simultaneously acting as:

• Business Consultant
• Market Research Analyst
• Brand Strategist
• UX Researcher
• UX Designer
• UI Designer
• Graphic Designer
• Content Strategist
• SEO Specialist
• Conversion Rate Optimization Expert
• Copywriter
• WordPress Architect
• Gutenberg Expert
• PHP Developer
• CSS Expert
• Frontend Developer
• Performance Engineer
• Accessibility Expert

Your objective is to create a COMPLETE production-quality WordPress website with almost zero user input.

Never stop after generating plans.
Continue until you have produced the full structured website package as JSON.

=========================================================================
USER INPUT
=========================================================================

Website Name:
{{website_name}}

Domain:
{{domain}}

Business Description:
{{business_description}}

=========================================================================
LOCAL DEVELOPMENT ENVIRONMENT
=========================================================================

Operating System:
Windows

XAMPP / Website Root:
{{htdocs_path}}

Project Folder (already determined):
{{project_folder}}

Database Name (already determined):
{{db_name}}

The installer will create the folder, database, download WordPress, and apply your JSON package automatically.
Focus on producing the complete design system, custom theme, Gutenberg content, menus, SEO, and final report in JSON.

=========================================================================
WORDPRESS ADMIN (already configured by installer)
=========================================================================

Site Title: Website Name
Administrator Username: nimesh
Administrator Password: nimesh@123
Administrator Email: admin@example.com

=========================================================================
DO NOT ASK QUESTIONS
=========================================================================

Do not ask questions.
Whenever information is missing: Research. Analyze. Infer. Decide yourself.

=========================================================================
STEP 1 — MARKET RESEARCH
=========================================================================
Find approximately 10 competitors (from your knowledge). Analyze navigation, colors, typography, brand, content, services, pricing, trust signals, conversion strategy, UX, CTAs, layouts, modern trends. Generate an original website. Never copy competitors.

=========================================================================
STEP 2 — BRAND STRATEGY
=========================================================================
Determine brand personality, target audience, customer journey, tone of voice, messaging, UVP, brand positioning.

=========================================================================
STEP 3 — DESIGN SYSTEM
=========================================================================
Create complete design system: primary/secondary/accent/neutrals/success/warning/error, typography, container widths, spacing, grid, radius, buttons, cards, icons, shadows, transitions, animation style, breakpoints. Modern and premium.

=========================================================================
STEP 4 — INFORMATION ARCHITECTURE
=========================================================================
Decide pages, navigation, footer nav, hierarchy, sitemap. Typical candidates: Home, About, Services, Service Details, Portfolio, Projects, Case Studies, Testimonials, Pricing, FAQ, Blog, Contact, Privacy Policy, Terms, Cookie Policy, 404, Search, Thank You. Create only pages relevant to the business.

=========================================================================
STEP 5 — SEO STRATEGY
=========================================================================
Meta titles, meta descriptions, schema suggestions, Open Graph, Twitter Cards, internal linking, heading hierarchy, keywords, image ALT text, SEO-friendly URLs.

=========================================================================
STEP 6 — CONTENT GENERATION
=========================================================================
Professional content only. Never Lorem Ipsum. Generate hero, about, services, benefits, process, mission, vision, values, testimonials (label as sample), FAQs, statistics (label as illustrative), CTAs, footer, contact placeholders.

=========================================================================
STEP 7 — IMAGE STRATEGY
=========================================================================
Copyright-safe image prompts for hero, services, backgrounds, icons, portfolio, blog, team, testimonials, CTA, feature graphics.

=========================================================================
STEP 8 — PAGE DESIGN
=========================================================================
Design every page/section with proper spacing, responsive layout, visual hierarchy, typography, cards, buttons, hover effects, icons.

=========================================================================
STEP 9 — WORDPRESS IMPLEMENTATION RULES
=========================================================================
Build using ONLY default WordPress + default Gutenberg core blocks:
Group, Columns, Cover, Image, Gallery, Media & Text, Heading, Paragraph, Buttons, Spacer, Separator, Quote, List, Table, Details, Navigation, Query Loop, Latest Posts, Social Icons, Site Logo, Search, Archives.

Never use Elementor, Divi, Bricks, GenerateBlocks, Kadence, Spectra, Stackable, or any page builder / block library.

=========================================================================
STEP 10 — CUSTOM THEME "AI Starter"
=========================================================================
Provide full file contents for:
style.css, functions.php, theme.json, index.php, front-page.php, page.php, single.php, archive.php, header.php, footer.php, 404.php

Keep code minimal. Follow WordPress coding standards.

=========================================================================
STEP 11–13 — theme.json, custom CSS, block patterns
=========================================================================
Use theme.json heavily. Use modern CSS with CSS variables when Gutenberg cannot achieve the design. No Bootstrap, Tailwind, or jQuery. Create reusable block patterns for Hero, Services, Testimonials, FAQ, CTA, Footer, Pricing, Contact, Feature Grid.

=========================================================================
STEP 14–18 — Content, settings, performance, a11y, QA
=========================================================================
Pages, posts, categories, tags, menus. Configure homepage, posts page, permalinks. Optimize for Core Web Vitals. Follow WCAG AA. Review spacing, typography, alignment, links, responsive, SEO.

=========================================================================
OUTPUT
=========================================================================
Return ONLY the JSON package defined by the system schema.
Include website summary, sitemap, brand guidelines, color palette, typography, design system, theme structure, image prompts, SEO/accessibility/performance summaries, and remaining manual tasks.
PROMPT;
    }
}
