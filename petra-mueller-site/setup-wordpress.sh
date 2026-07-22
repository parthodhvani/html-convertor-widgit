#!/usr/bin/env bash
# Deploy AI Starter theme + content for Petra Müller WordPress site.
set -euo pipefail

SITE_DIR="${SITE_DIR:-/var/www/html/petra-mueller}"
THEME_SRC="${THEME_SRC:-/workspace/petra-mueller-site/theme/ai-starter}"
THEME_DEST="$SITE_DIR/wp-content/themes/ai-starter"
WP="wp --path=$SITE_DIR --allow-root"

echo "==> Sync theme"
rm -rf "$THEME_DEST"
mkdir -p "$SITE_DIR/wp-content/themes"
cp -a "$THEME_SRC" "$THEME_DEST"

# Resolve THEME_URI placeholders inside patterns for local media paths
THEME_URI="$($WP theme list --status=inactive --field=name >/dev/null 2>&1; echo "$($WP eval 'echo get_stylesheet_directory_uri();' 2>/dev/null || true)")"
# Before activation, compute URI manually
THEME_URI_URL="http://127.0.0.1:8080/wp-content/themes/ai-starter"
find "$THEME_DEST/patterns" -name '*.php' -exec sed -i "s|THEME_URI|${THEME_URI_URL}|g" {} +

$WP theme activate ai-starter
$WP rewrite structure '/%postname%/' --hard
$WP rewrite flush --hard

echo "==> Import media"
MEDIA_DIR="$THEME_DEST/assets/images"
declare -A MEDIA_IDS=()
for f in Bild0002_DSF_5715.jpg Bild0036_DSF_5767.jpg Bild0047_DSF_5781.jpg Bild0075_DSF_5821.jpg shutterstock_2680641803.jpg petra_muller_logo.png; do
  if [[ -f "$MEDIA_DIR/$f" ]]; then
    ID=$($WP media import "$MEDIA_DIR/$f" --porcelain 2>/dev/null || true)
    [[ -z "${ID:-}" ]] && continue
    MEDIA_IDS[$f]=$ID
    echo "  imported $f => $ID"
  fi
done

LOGO_ID="${MEDIA_IDS[petra_muller_logo.png]:-}"
if [[ -n "$LOGO_ID" ]]; then
  $WP option update site_logo "$LOGO_ID" || true
  $WP theme mod set custom_logo "$LOGO_ID" || true
fi

# Site icon from favicon - convert not needed; skip if svg unsupported
if [[ -f "$THEME_DEST/assets/icons/favicon.svg" ]]; then
  ICON_ID=$($WP media import "$THEME_DEST/assets/icons/favicon.svg" --porcelain || true)
  if [[ -n "${ICON_ID:-}" ]]; then
    $WP option update site_icon "$ICON_ID" || true
  fi
fi

replace_theme_uri() {
  local content="$1"
  echo "$content" | sed "s|THEME_URI|${THEME_URI_URL}|g"
}

pattern_content() {
  local name="$1"
  $WP eval "\$p = include get_template_directory() . '/patterns/' . '${name}' . '.php'; echo \$p['content'];"
}

build_home() {
  local hero services about benefits process stats testimonials faq cta
  hero=$(pattern_content hero)
  services=$(pattern_content services)
  about=$(pattern_content about-split)
  benefits=$(pattern_content benefits)
  process=$(pattern_content process)
  stats=$(pattern_content stats)
  testimonials=$(pattern_content testimonials)
  faq=$(pattern_content faq)
  cta=$(pattern_content cta)
  printf '%s\n%s\n%s\n%s\n%s\n%s\n%s\n%s\n%s\n' "$hero" "$services" "$about" "$benefits" "$process" "$stats" "$testimonials" "$faq" "$cta"
}

echo "==> Create pages"

create_or_update_page() {
  local slug="$1"
  local title="$2"
  local meta_desc="$3"
  local content="$4"
  local existing
  existing=$($WP post list --post_type=page --name="$slug" --field=ID --format=ids | awk '{print $1}')
  if [[ -n "$existing" ]]; then
    $WP post update "$existing" --post_title="$title" --post_content="$content" --post_status=publish >/dev/null
    echo "$existing"
  else
    $WP post create --post_type=page --post_title="$title" --post_name="$slug" --post_status=publish --post_content="$content" --porcelain
  fi
}

HOME_CONTENT=$(build_home)
HOME_ID=$(create_or_update_page "home" "Home" "Astrologische Beratung und Persönlichkeitsentwicklung in Zug mit Petra Müller." "$HOME_CONTENT")
$WP post meta update "$HOME_ID" _ai_meta_description "Astrologische Beratung, Coaching und Persönlichkeitsentwicklung in Zug. Entdecke mit Petra Müller deinen individuellen Lebensweg."

ANGEBOT=$(cat <<'EOF'
<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Angebot</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Persönlichkeitsentwicklung – Entfalten Sie Ihr volles Potenzial</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Jeder Mensch trägt einzigartige Stärken, Talente und Möglichkeiten in sich. Manchmal braucht es jedoch einen neuen Blickwinkel, um diese bewusst wahrzunehmen. Eine astrologische Beratung verbindet Persönlichkeitsentwicklung mit den Erkenntnissen Ihres Geburtshoroskops.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/buchen/">Kostenloses Kennenlerngespräch vereinbaren</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
EOF
)
ANGEBOT+=$'\n'"$(pattern_content pricing)"
ANGEBOT+=$'\n'"$(pattern_content feature-grid)"
ANGEBOT+=$'\n'"$(pattern_content cta)"
ANGEBOT_ID=$(create_or_update_page "angebot" "Angebot" "Astrologische Beratung, Coaching und Persönlichkeitsentwicklung – Angebot von Petra Müller in Zug." "$ANGEBOT")
$WP post meta update "$ANGEBOT_ID" _ai_meta_description "Entdecke das Angebot von Petra Müller: astrologische Beratung, Persönlichkeitsentwicklung und Coaching in Zug."

MEDITATION=$(cat <<'EOF'
<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Meditation</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Meditation – Innere Ruhe finden</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Meditation unterstützt Sie dabei, zur Ruhe zu kommen, innere Klarheit zu gewinnen und mit mehr Präsenz durch den Alltag zu gehen. In meinen Angeboten verbinden sich achtsame Impulse mit dem tiefen Verständnis Ihrer Persönlichkeit.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"ais-section","layout":{"type":"constrained","contentSize":"760px"}} -->
<div class="wp-block-group alignfull ais-section"><!-- wp:heading -->
<h2 class="wp-block-heading">Warum Meditation und Astrologie zusammenpassen</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Während das Horoskop Ihre Anlagen und Themen sichtbar macht, hilft Meditation dabei, diese Erkenntnisse im Körper und im Alltag zu verankern. So entsteht nicht nur Verstehen, sondern spürbare Veränderung.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Geführte Impulse für mehr Selbstwahrnehmung</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Praktische Übungen für stressige Phasen</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Ergänzung zu Beratung und Persönlichkeitsentwicklung</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
EOF
)
MEDITATION+=$'\n'"$(pattern_content cta)"
create_or_update_page "meditation" "Meditation" "Meditation und achtsame Impulse begleitend zur astrologischen Beratung bei Petra Müller." "$MEDITATION" >/dev/null

VORTRAEGE=$(cat <<'EOF'
<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Vorträge</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Vorträge zu Astrologie &amp; Persönlichkeitsentwicklung</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Inspirierende Keynotes und Impulse für Unternehmen, Vereine und Organisationen – verständlich, praxisnah und ohne esoterischen Ballast.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"ais-section","layout":{"type":"constrained","contentSize":"1200px"}} -->
<div class="wp-block-group alignfull ais-section"><!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"ais-card"} -->
<div class="wp-block-group ais-card"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Astrologie verständlich</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Wie Horoskope Orientierung geben – und warum es nicht um Vorhersagen geht.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"ais-card"} -->
<div class="wp-block-group ais-card"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Burnout-Prävention</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Persönliche Muster erkennen, Ressourcen stärken und frühzeitig gegensteuern.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:group {"className":"ais-card"} -->
<div class="wp-block-group ais-card"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Persönlichkeitsentfaltung</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Impulse für Teams und Events, die Menschen ermutigen, Potenziale zu nutzen.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
EOF
)
VORTRAEGE+=$'\n'"$(pattern_content cta)"
create_or_update_page "vortraege" "Vorträge" "Vorträge zu Astrologie, Burnout-Prävention und Persönlichkeitsentwicklung mit Petra Müller." "$VORTRAEGE" >/dev/null

UEBER=$(cat <<EOF
<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Über mich</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Petra Müller – Astrologin in Zug</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Ich bin dreifache Mutter, habe lange im Gesundheitswesen gearbeitet und begleite heute Frauen und Männer mit Astrologie auf ihrem persönlichen Lebensweg.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

$(pattern_content about-split)

<!-- wp:group {"align":"full","className":"ais-section","layout":{"type":"constrained","contentSize":"760px"}} -->
<div class="wp-block-group alignfull ais-section"><!-- wp:heading -->
<h2 class="wp-block-heading">Mission, Vision &amp; Werte</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Mission</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Menschen dabei unterstützen, sich selbst besser zu verstehen und ihr Leben bewusst zu gestalten – mit Klarheit, Empathie und fundierter Astrologie.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Vision</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Eine Schweiz, in der Selbsterkenntnis und Persönlichkeitsentwicklung selbstverständlich und zugänglich sind – ohne Vorhersagen, mit Verantwortung.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Werte</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Wertschätzung und Augenhöhe</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Verständlichkeit statt Fachjargon</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Diskretion und Vertrauen</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Praxisnahe Impulse</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
EOF
)
create_or_update_page "ueber-mich" "Über mich" "Lernen Sie Petra Müller kennen – Astrologin und Coach für Persönlichkeitsentwicklung in Zug." "$UEBER" >/dev/null

KONTAKT=$(cat <<EOF
<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Kontakt</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Astrologin Schweiz – Ich freue mich auf Ihre Kontaktaufnahme</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Sie möchten mehr über meine Beratungen erfahren oder einen Termin vereinbaren? Dann freue ich mich, Sie kennenzulernen.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

$(pattern_content contact)
$(pattern_content cta)
EOF
)
create_or_update_page "kontakt" "Kontakt" "Kontaktieren Sie Petra Müller in Zug für astrologische Beratung, Coaching oder Vorträge." "$KONTAKT" >/dev/null

BUCHEN=$(cat <<'EOF'
<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Buchen</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Termin buchen</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Wählen Sie Ihr Anliegen und vereinbaren Sie ein kostenloses Kennenlerngespräch oder eine Beratung. (Platzhalter: In Produktion Buchungskalender oder Formular anbinden.)</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Kennenlerngespräch (kostenlos)</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Astrologische Beratung 75–120 Min.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Vortragsanfrage für Events</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>E-Mail: <a href="mailto:kontakt@example.com">kontakt@example.com</a> · Telefon: <a href="tel:+41410000000">+41 41 000 00 00</a></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/kontakt/">Stattdessen Kontaktseite öffnen</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
EOF
)
create_or_update_page "buchen" "Termin buchen" "Termin für astrologische Beratung oder Kennenlerngespräch bei Petra Müller buchen." "$BUCHEN" >/dev/null

FEEDBACKS=$(cat <<EOF
<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Feedbacks</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Feedbacks</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Erfahrungen von Menschen, die sich auf den Weg der Selbsterkenntnis gemacht haben.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

$(pattern_content testimonials)
$(pattern_content cta)
EOF
)
create_or_update_page "feedbacks" "Feedbacks" "Beispiel-Feedbacks zu astrologischer Beratung und Coaching bei Petra Müller." "$FEEDBACKS" >/dev/null

BLOG_PAGE_ID=$(create_or_update_page "blog" "Blog" "Artikel zu Astrologie, Persönlichkeitsentwicklung und Alltagsklarheit." "<!-- wp:paragraph --><p>Impulse und Artikel rund um Astrologie und Persönlichkeitsentwicklung.</p><!-- /wp:paragraph -->")

create_or_update_page "danke" "Vielen Dank" "Ihre Nachricht wurde übermittelt." '<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"700px"}} --><div class="wp-block-group alignfull ais-page-hero"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Vielen Dank</h1><!-- /wp:heading --><!-- wp:paragraph --><p>Ihre Anfrage wurde übermittelt. Ich melde mich in Kürze bei Ihnen.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/">Zur Startseite</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->' >/dev/null

create_or_update_page "datenschutz" "Datenschutzerklärung" "Datenschutzerklärung der Website Petra Müller." '<!-- wp:group {"layout":{"type":"constrained","contentSize":"760px"}} --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Datenschutzerklärung</h1><!-- /wp:heading --><!-- wp:paragraph --><p>Platzhalter: Diese Seite beschreibt, welche personenbezogenen Daten erhoben und wie sie verarbeitet werden. Bitte durch eine rechtlich geprüfte Fassung ersetzen.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Verantwortliche Stelle</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Petra Müller, Praxis Zug, Schweiz. E-Mail: kontakt@example.com</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Hosting &amp; Logs</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Beim Besuch der Website können technisch notwendige Server-Logs (IP-Adresse, Zeitpunkt, User-Agent) verarbeitet werden.</p><!-- /wp:paragraph --></div><!-- /wp:group -->' >/dev/null

create_or_update_page "agb" "Nutzungsbedingungen" "Nutzungsbedingungen der Website Petra Müller." '<!-- wp:group {"layout":{"type":"constrained","contentSize":"760px"}} --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Nutzungsbedingungen</h1><!-- /wp:heading --><!-- wp:paragraph --><p>Platzhalter für AGB / Nutzungsbedingungen. Inhalte dieser Website dienen der Information und ersetzen keine medizinische oder therapeutische Behandlung.</p><!-- /wp:paragraph --></div><!-- /wp:group -->' >/dev/null

create_or_update_page "cookie-richtlinie" "Cookie-Richtlinie" "Cookie-Richtlinie der Website Petra Müller." '<!-- wp:group {"layout":{"type":"constrained","contentSize":"760px"}} --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Cookie-Richtlinie</h1><!-- /wp:heading --><!-- wp:paragraph --><p>Platzhalter: Diese Website kann technisch notwendige Cookies verwenden. Optional können Analyse-Cookies erst nach Einwilligung gesetzt werden.</p><!-- /wp:paragraph --></div><!-- /wp:group -->' >/dev/null

FAQ_PAGE=$(pattern_content faq)$'\n'"$(pattern_content cta)"
create_or_update_page "faq" "FAQ" "Häufige Fragen zur astrologischen Beratung bei Petra Müller." "$FAQ_PAGE" >/dev/null

echo "==> Reading settings"
$WP option update show_on_front page
$WP option update page_on_front "$HOME_ID"
$WP option update page_for_posts "$BLOG_PAGE_ID"
$WP option update blogname "Petra Müller – Astrologie Schweiz"
$WP option update blogdescription "Astrologische Beratung, Coaching und Persönlichkeitsentwicklung in Zug"
$WP option update posts_per_page 6
$WP option update default_comment_status closed
$WP option update default_ping_status closed
$WP option update thumbnail_size_w 400
$WP option update thumbnail_size_h 400
$WP option update medium_size_w 800
$WP option update large_size_w 1600

echo "==> Categories, tags, posts"
CAT_ASTRO=$($WP term create category "Astrologie" --slug=astrologie --porcelain)
CAT_PERS=$($WP term create category "Persönlichkeitsentwicklung" --slug=persoenlichkeitsentwicklung --porcelain 2>/dev/null || $WP term list category --slug=persoenlichkeitsentwicklung --field=term_id)
CAT_ALLTAG=$($WP term create category "Alltag" --slug=alltag --porcelain 2>/dev/null || $WP term list category --slug=alltag --field=term_id)
$WP term create post_tag "Horoskop" --slug=horoskop >/dev/null || true
$WP term create post_tag "Klarheit" --slug=klarheit >/dev/null || true
$WP term create post_tag "Zug" --slug=zug >/dev/null || true

create_post() {
  local title="$1" slug="$2" cat="$3" excerpt="$4" content="$5" image_key="$6"
  local id existing
  existing=$($WP post list --post_type=post --name="$slug" --field=ID --format=ids | awk '{print $1}')
  if [[ -n "$existing" ]]; then
    id=$existing
    $WP post update "$id" --post_title="$title" --post_content="$content" --post_excerpt="$excerpt" --post_status=publish >/dev/null
  else
    id=$($WP post create --post_type=post --post_title="$title" --post_name="$slug" --post_status=publish --post_excerpt="$excerpt" --post_content="$content" --post_category="$cat" --porcelain)
  fi
  local img="${MEDIA_IDS[$image_key]:-}"
  if [[ -n "$img" ]]; then
    $WP post meta update "$id" _thumbnail_id "$img" >/dev/null
  fi
  echo "  post $slug => $id"
}

POST1='<!-- wp:paragraph --><p>Viele Menschen verbinden Astrologie mit Tageshoroskopen. In der Beratung geht es jedoch um etwas anderes: um ein differenziertes Bild Ihrer Anlagen, Muster und Entwicklungsthemen.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Geburtshoroskop statt Vorhersage</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Ihr Geburtshoroskop zeigt Potenziale und Spannungsfelder. Gemeinsam übersetzen wir diese in verständliche Impulse für Beruf, Beziehung und Selbstführung.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Für wen das hilfreich ist</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Besonders wertvoll ist dieser Zugang in Übergangsphasen – wenn alte Strategien nicht mehr tragen und neue Orientierung gefragt ist.</p><!-- /wp:paragraph -->'

POST2='<!-- wp:paragraph --><p>Persönlichkeitsentwicklung beginnt oft mit einer einfachen Frage: Was brauche ich wirklich, um stimmig zu leben?</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Muster erkennen</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Wiederkehrende Konflikte sind selten Zufall. Astrologie kann helfen, innere Dynamiken zu benennen – und Handlungsspielräume zu öffnen.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Kleine Schritte, grosse Wirkung</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Nach einer Beratung zählen nicht spektakuläre Erkenntnisse allein, sondern die konkreten nächsten Schritte im Alltag.</p><!-- /wp:paragraph -->'

POST3='<!-- wp:paragraph --><p>Burnout entsteht selten über Nacht. Oft gehen Warnsignale über Monate hinweg mit Leistungsdruck und Selbstüberforderung einher.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Frühe Signale ernst nehmen</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Erschöpfung, Reizbarkeit und Sinnverlust sind Hinweise, genauer hinzuschauen – beruflich wie persönlich.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Prävention mit Klarheit</h2><!-- /wp:heading --><!-- wp:paragraph --><p>In Vorträgen und Beratungen arbeite ich daran, Ressourcen sichtbar zu machen und Grenzen wieder spürbar zu machen.</p><!-- /wp:paragraph -->'

POST4='<!-- wp:paragraph --><p>Eine gute Beratung braucht Vorbereitung – und die richtigen Angaben zu Geburtstag, Zeit und Ort.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Warum die Geburtszeit zählt</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Die Aszendente und Häuserstruktur werden präziser, je genauer die Geburtszeit bekannt ist. Fehlt sie, arbeiten wir mit Näherungen und Transparenz über Grenzen.</p><!-- /wp:paragraph -->'

POST5='<!-- wp:paragraph --><p>Meditation ist kein Ersatz für Beratung, aber ein kraftvoller Begleiter: Sie schafft Raum, in dem Erkenntnisse wirken können.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Vom Wissen zum Spüren</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Viele Klient:innen berichten, dass kurze Atem- oder Wahrnehmungsübungen helfen, Impulse aus der Sitzung im Alltag zu halten.</p><!-- /wp:paragraph -->'

create_post "Was Astrologie wirklich leisten kann" "was-astrologie-leisten-kann" "$CAT_ASTRO" "Warum Beratung mehr ist als ein Tageshoroskop." "$POST1" "Bild0047_DSF_5781.jpg"
create_post "Persönlichkeitsentwicklung mit Klarheit" "persoenlichkeitsentwicklung-mit-klarheit" "$CAT_PERS" "Muster erkennen und stimmige nächste Schritte finden." "$POST2" "Bild0075_DSF_5821.jpg"
create_post "Burnout-Prävention: Warnsignale ernst nehmen" "burnout-praevention-warnsignale" "$CAT_ALLTAG" "Wie frühe Signale Orientierung geben können." "$POST3" "shutterstock_2680641803.jpg"
create_post "Geburtszeit und Horoskop: Was Sie wissen sollten" "geburtszeit-und-horoskop" "$CAT_ASTRO" "Warum präzise Geburtsdaten die Beratung verbessern." "$POST4" "Bild0036_DSF_5767.jpg"
create_post "Meditation als Alltagskompass" "meditation-als-alltagskompass" "$CAT_PERS" "Wie Achtsamkeit Erkenntnisse aus der Beratung vertieft." "$POST5" "Bild0002_DSF_5715.jpg"

echo "==> Menus"
# Delete old menus if exist
for m in primary-menu footer-menu legal-menu; do
  MID=$($WP menu list --fields=term_id,slug --format=csv | awk -F, -v s="$m" '$2==s{print $1}')
  if [[ -n "${MID:-}" ]]; then $WP menu delete "$MID" >/dev/null || true; fi
done

PRIM=$($WP menu create "Primary" --porcelain)
FOOT=$($WP menu create "Footer" --porcelain)
LEGAL=$($WP menu create "Legal" --porcelain)

add_page_to_menu() {
  local menu="$1" slug="$2" title="$3"
  local pid
  pid=$($WP post list --post_type=page --name="$slug" --field=ID --format=ids | awk '{print $1}')
  $WP menu item add-post "$menu" "$pid" --title="$title" >/dev/null
}

add_page_to_menu "$PRIM" "home" "Home"
add_page_to_menu "$PRIM" "angebot" "Angebot"
add_page_to_menu "$PRIM" "meditation" "Meditation"
add_page_to_menu "$PRIM" "vortraege" "Vorträge"
add_page_to_menu "$PRIM" "ueber-mich" "Über mich"
add_page_to_menu "$PRIM" "blog" "Blog"
add_page_to_menu "$PRIM" "kontakt" "Kontakt"

add_page_to_menu "$FOOT" "angebot" "Angebot"
add_page_to_menu "$FOOT" "vortraege" "Vorträge"
add_page_to_menu "$FOOT" "feedbacks" "Feedbacks"
add_page_to_menu "$FOOT" "faq" "FAQ"
add_page_to_menu "$FOOT" "buchen" "Termin buchen"
add_page_to_menu "$FOOT" "kontakt" "Kontakt"

add_page_to_menu "$LEGAL" "datenschutz" "Datenschutz"
add_page_to_menu "$LEGAL" "agb" "Nutzungsbedingungen"
add_page_to_menu "$LEGAL" "cookie-richtlinie" "Cookie-Richtlinie"

$WP menu location assign "$PRIM" primary
$WP menu location assign "$FOOT" footer
$WP menu location assign "$LEGAL" legal

# Delete Hello World / sample page if present
$WP post delete $($WP post list --name=hello-world --field=ID) --force 2>/dev/null || true
$WP post delete $($WP post list --post_type=page --name=sample-page --field=ID) --force 2>/dev/null || true

echo "==> Done"
$WP theme list
$WP option get page_on_front
$WP option get page_for_posts
$WP post list --post_type=page --fields=ID,post_title,post_name --format=table
$WP post list --post_type=post --fields=ID,post_title,post_name --format=table
