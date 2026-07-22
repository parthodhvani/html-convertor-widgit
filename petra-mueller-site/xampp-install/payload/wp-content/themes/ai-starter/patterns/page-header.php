<?php
/**
 * Page header pattern.
 *
 * @package AI_Starter
 */

return array(
	'title'      => __( 'Page Header', 'ai-starter' ),
	'categories' => array( 'ai-starter' ),
	'content'    => '<!-- wp:group {"align":"full","className":"ais-page-hero","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group alignfull ais-page-hero"><!-- wp:paragraph {"className":"breadcrumb"} -->
<p class="breadcrumb"><a href="/">Home</a> / Seite</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Seitentitel</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Kurze Einleitung, die den Nutzen der Seite in einem Satz klar macht.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->',
);
