#!/usr/bin/env node
'use strict';

/**
 * Build a diverse real-world HTML corpus for continuous accuracy optimization.
 * Pages use framework CDNs or self-contained CSS mirroring production patterns.
 */

const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const CORPUS = path.join(ROOT, 'corpus');

function write(rel, html) {
  const full = path.join(CORPUS, rel);
  fs.mkdirSync(path.dirname(full), { recursive: true });
  fs.writeFileSync(full, html);
  return rel;
}

const BS = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
const FA = 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css';

function shell(title, body, extraHead = '') {
  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<link href="${BS}" rel="stylesheet">
${extraHead}
</head>
<body>
${body}
</body>
</html>`;
}

const pages = [];

function add(id, category, tags, rel, html) {
  pages.push({ id, category, tags, path: write(rel, html) });
}

// --- Bootstrap marketing / landing ---
add('bs-hero-features', 'framework', ['bootstrap', 'hero', 'features', 'landing'],
  'framework/bs-hero-features.html',
  shell('Bootstrap Hero Features', `
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container"><a class="navbar-brand" href="#">Acme</a>
  <div class="navbar-nav ms-auto"><a class="nav-link" href="#">Home</a><a class="nav-link" href="#">Features</a><a class="nav-link" href="#">Pricing</a></div></div>
</nav>
<section class="px-4 py-5 text-center bg-primary text-white">
  <div class="py-5"><h1 class="display-5 fw-bold">Build faster with Acme</h1>
  <p class="col-lg-6 mx-auto lead">Production-ready components for modern marketing sites.</p>
  <a class="btn btn-light btn-lg px-4" href="#">Get started</a></div>
</section>
<div class="container px-4 py-5"><div class="row g-4 py-4">
  <div class="col-md-4"><div class="card h-100 shadow-sm"><div class="card-body"><h3 class="h4">Speed</h3><p>Ship landing pages in hours, not weeks.</p></div></div></div>
  <div class="col-md-4"><div class="card h-100 shadow-sm"><div class="card-body"><h3 class="h4">Scale</h3><p>Responsive grids that adapt to every device.</p></div></div></div>
  <div class="col-md-4"><div class="card h-100 shadow-sm"><div class="card-body"><h3 class="h4">Trust</h3><p>Battle-tested patterns used by thousands of teams.</p></div></div></div>
</div></div>
<footer class="bg-dark text-white py-4"><div class="container d-flex justify-content-between"><span>© Acme</span><span>Privacy · Terms</span></div></footer>`));

add('bs-pricing', 'framework', ['bootstrap', 'pricing', 'cards'],
  'framework/bs-pricing.html',
  shell('Bootstrap Pricing', `
<div class="container py-5"><header class="text-center mb-5"><h1>Pricing</h1><p class="text-muted">Simple plans for every team.</p></header>
<div class="row row-cols-1 row-cols-md-3 mb-3 text-center">
  <div class="col"><div class="card mb-4 rounded-3 shadow-sm"><div class="card-header py-3"><h4 class="my-0 fw-normal">Free</h4></div>
  <div class="card-body"><h1 class="card-title pricing-card-title">$0<small class="text-muted fw-light">/mo</small></h1>
  <ul class="list-unstyled mt-3 mb-4"><li>10 users</li><li>2 GB storage</li><li>Email support</li></ul>
  <button class="w-100 btn btn-lg btn-outline-primary">Sign up</button></div></div></div>
  <div class="col"><div class="card mb-4 rounded-3 shadow-sm border-primary"><div class="card-header py-3 text-bg-primary border-primary"><h4 class="my-0 fw-normal">Pro</h4></div>
  <div class="card-body"><h1 class="card-title pricing-card-title">$15<small class="text-muted fw-light">/mo</small></h1>
  <ul class="list-unstyled mt-3 mb-4"><li>20 users</li><li>10 GB storage</li><li>Priority support</li></ul>
  <button class="w-100 btn btn-lg btn-primary">Get started</button></div></div></div>
  <div class="col"><div class="card mb-4 rounded-3 shadow-sm"><div class="card-header py-3"><h4 class="my-0 fw-normal">Enterprise</h4></div>
  <div class="card-body"><h1 class="card-title pricing-card-title">$29<small class="text-muted fw-light">/mo</small></h1>
  <ul class="list-unstyled mt-3 mb-4"><li>30 users</li><li>15 GB storage</li><li>Phone support</li></ul>
  <button class="w-100 btn btn-lg btn-outline-primary">Contact us</button></div></div></div>
</div></div>`));

add('bs-album', 'framework', ['bootstrap', 'gallery', 'portfolio'],
  'framework/bs-album.html',
  shell('Bootstrap Album', `
<header class="bg-dark text-white py-5 text-center"><h1>Album example</h1><p class="lead">Portfolio-style image grid.</p></header>
<main class="container py-5"><div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
${[1, 2, 3, 4, 5, 6].map((n) => `<div class="col"><div class="card shadow-sm"><svg width="100%" height="180" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#555"/><text x="50%" y="50%" fill="#eceeef" dy=".3em" text-anchor="middle">Thumb ${n}</text></svg>
<div class="card-body"><p class="card-text">Project ${n} description with supporting text.</p>
<div class="d-flex justify-content-between"><button class="btn btn-sm btn-outline-secondary">View</button><small class="text-muted">9 mins</small></div></div></div></div>`).join('')}
</div></main>`));

add('bs-blog', 'framework', ['bootstrap', 'blog', 'sidebar'],
  'framework/bs-blog.html',
  shell('Bootstrap Blog', `
<div class="container"><header class="border-bottom lh-1 py-3"><h1 class="h3">The Blog</h1></header>
<main class="row g-5 py-4">
  <div class="col-md-8"><article class="blog-post mb-4"><h2>Sample blog post</h2><p class="text-muted">January 1, 2024 by <a href="#">Mark</a></p>
  <p>This blog post shows a few different types of content that's supported and styled with Bootstrap.</p>
  <h3>Blockquotes</h3><blockquote class="blockquote"><p>Quoted text here.</p></blockquote>
  <p>More body copy with <strong>emphasis</strong> and <a href="#">links</a>.</p></article>
  <article class="blog-post"><h2>Another post</h2><p>Cum sociis natoque penatibus et magnis dis parturient montes.</p></article></div>
  <aside class="col-md-4"><div class="p-4 mb-3 bg-light rounded"><h4>About</h4><p class="mb-0">Sidebar about the blog author and topics.</p></div>
  <div class="p-4"><h4>Archives</h4><ol class="list-unstyled mb-0"><li><a href="#">March 2024</a></li><li><a href="#">February 2024</a></li></ol></div></aside>
</main></div>`));

add('bs-navbar-form', 'framework', ['bootstrap', 'form', 'navbar'],
  'framework/bs-navbar-form.html',
  shell('Bootstrap Form', `
<nav class="navbar navbar-light bg-light"><div class="container"><span class="navbar-brand mb-0 h1">Contact</span></div></nav>
<div class="container py-5"><div class="row justify-content-center"><div class="col-md-7">
  <h1 class="mb-4">Get in touch</h1>
  <form><div class="mb-3"><label class="form-label">Name</label><input class="form-control" type="text" value="Ada Lovelace"></div>
  <div class="mb-3"><label class="form-label">Email</label><input class="form-control" type="email" value="ada@example.com"></div>
  <div class="mb-3"><label class="form-label">Message</label><textarea class="form-control" rows="4">Hello from the form page.</textarea></div>
  <button class="btn btn-primary" type="submit">Send message</button></form>
</div></div></div>`));

// --- Tailwind-like utility pages (self-contained) ---
const twBase = `<style>
*{box-sizing:border-box}body{margin:0;font-family:ui-sans-serif,system-ui,sans-serif;color:#0f172a;background:#f8fafc}
.container{max-width:1120px;margin:0 auto;padding:0 1.5rem}
.flex{display:flex}.items-center{align-items:center}.justify-between{justify-content:space-between}.justify-center{justify-content:center}
.gap-4{gap:1rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}
.grid{display:grid}.grid-3{grid-template-columns:repeat(3,1fr)}.grid-2{grid-template-columns:repeat(2,1fr)}
.text-center{text-align:center}.font-bold{font-weight:700}.text-sm{font-size:.875rem}.text-xl{font-size:1.25rem}.text-3xl{font-size:1.875rem}.text-5xl{font-size:3rem}
.text-slate-500{color:#64748b}.text-white{color:#fff}.bg-white{background:#fff}.bg-slate-900{background:#0f172a}.bg-indigo-600{background:#4f46e5}
.rounded-xl{border-radius:0.75rem}.rounded-full{border-radius:9999px}.shadow{box-shadow:0 10px 30px rgba(15,23,42,.08)}
.p-6{padding:1.5rem}.p-8{padding:2rem}.py-4{padding-top:1rem;padding-bottom:1rem}.py-16{padding-top:4rem;padding-bottom:4rem}.py-24{padding-top:6rem;padding-bottom:6rem}
.px-5{padding-left:1.25rem;padding-right:1.25rem}.mt-4{margin-top:1rem}.mb-2{margin-bottom:.5rem}.mb-6{margin-bottom:1.5rem}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:.75rem 1.25rem;border-radius:.5rem;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600}
.btn-ghost{background:transparent;border:1px solid #cbd5e1;color:#0f172a}
img{max-width:100%;display:block}
@media(max-width:768px){.grid-3,.grid-2{grid-template-columns:1fr}.hide-mobile{display:none}}
</style>`;

add('tw-saas-landing', 'framework', ['tailwind', 'saas', 'hero', 'pricing'],
  'framework/tw-saas-landing.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SaaSify</title>${twBase}</head><body>
<header class="py-4 bg-white shadow"><div class="container flex items-center justify-between"><strong class="text-xl">SaaSify</strong>
<nav class="flex gap-6 hide-mobile"><a href="#">Product</a><a href="#">Pricing</a><a href="#">Docs</a></nav>
<a class="btn" href="#">Start free</a></div></header>
<section class="py-24 text-center"><div class="container"><h1 class="text-5xl font-bold mb-6">Ship products faster</h1>
<p class="text-slate-500 text-xl mb-6">The modern toolkit for product teams who care about velocity.</p>
<div class="flex justify-center gap-4"><a class="btn" href="#">Get started</a><a class="btn btn-ghost" href="#">Book demo</a></div></div></section>
<section class="py-16"><div class="container grid grid-3 gap-6">
${['Analytics', 'Automation', 'Security'].map((t) => `<div class="bg-white rounded-xl shadow p-8"><h3 class="text-xl font-bold mb-2">${t}</h3><p class="text-slate-500">Everything you need to ${t.toLowerCase()} at scale.</p></div>`).join('')}
</div></section>
<section class="py-16 bg-slate-900 text-white"><div class="container text-center"><h2 class="text-3xl font-bold mb-6">Simple pricing</h2>
<div class="grid grid-2 gap-8" style="max-width:720px;margin:0 auto">
<div class="bg-white text-slate-900 rounded-xl p-8"><h3 class="font-bold text-xl">Starter</h3><p class="text-3xl font-bold my-4">$29</p><a class="btn" href="#">Choose</a></div>
<div class="bg-indigo-600 rounded-xl p-8"><h3 class="font-bold text-xl">Growth</h3><p class="text-3xl font-bold my-4">$99</p><a class="btn" style="background:#fff;color:#4f46e5" href="#">Choose</a></div>
</div></div></section>
<footer class="py-4 text-center text-sm text-slate-500">© 2024 SaaSify</footer></body></html>`);

add('tw-dashboard', 'framework', ['tailwind', 'dashboard', 'sidebar'],
  'framework/tw-dashboard.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard</title>${twBase}
<style>.layout{display:flex;min-height:100vh}.side{width:240px;background:#0f172a;color:#fff;padding:1.5rem}.main{flex:1;padding:2rem}.stat{background:#fff;border-radius:12px;padding:1.25rem;box-shadow:0 4px 16px rgba(0,0,0,.06)}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem}@media(max-width:900px){.layout{flex-direction:column}.side{width:100%}.stats{grid-template-columns:1fr 1fr}}</style></head><body>
<div class="layout"><aside class="side"><strong>Orbit</strong><nav style="margin-top:2rem;display:flex;flex-direction:column;gap:1rem"><a style="color:#fff" href="#">Overview</a><a style="color:#94a3b8" href="#">Customers</a><a style="color:#94a3b8" href="#">Billing</a></nav></aside>
<main class="main"><h1 class="text-3xl font-bold mb-6">Overview</h1>
<div class="stats">${[['MRR', '$48.2k'], ['Active', '1,204'], ['Churn', '1.8%'], ['NPS', '62']].map(([k, v]) => `<div class="stat"><div class="text-sm text-slate-500">${k}</div><div class="text-3xl font-bold">${v}</div></div>`).join('')}</div>
<div class="bg-white rounded-xl shadow p-8 mt-4" style="margin-top:1.5rem"><h2 class="text-xl font-bold mb-2">Recent activity</h2><p class="text-slate-500">12 customers upgraded this week.</p></div>
</main></div></body></html>`);

// --- Shopify / ecommerce ---
add('shop-product', 'marketing', ['shopify', 'ecommerce', 'product'],
  'marketing/shop-product.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Product</title>
<style>
body{margin:0;font-family:Georgia,'Times New Roman',serif;color:#1a1a1a;background:#faf7f2}
.top{display:flex;justify-content:space-between;padding:1rem 2rem;border-bottom:1px solid #e8e0d4;background:#fff;font-family:system-ui,sans-serif;font-size:.85rem;letter-spacing:.08em;text-transform:uppercase}
.wrap{max-width:1100px;margin:2rem auto;display:grid;grid-template-columns:1.1fr 1fr;gap:3rem;padding:0 1.5rem}
.gallery{background:#e8e0d4;min-height:480px;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#8a7b66}
.price{font-size:1.5rem;margin:1rem 0}.btn{display:inline-block;background:#1a1a1a;color:#fff;padding:1rem 2rem;text-decoration:none;font-family:system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase;font-size:.8rem}
.swatches{display:flex;gap:.5rem;margin:1rem 0}.swatch{width:28px;height:28px;border-radius:50%;border:1px solid #ccc}
.related{max-width:1100px;margin:3rem auto;padding:0 1.5rem}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem}
.card{background:#fff;padding:1rem}.thumb{background:#e8e0d4;height:160px;margin-bottom:.75rem}
@media(max-width:800px){.wrap,.grid{grid-template-columns:1fr}}
</style></head><body>
<div class="top"><span>Maison</span><span>Shop · About · Cart (0)</span></div>
<div class="wrap"><div class="gallery">Linen Coat</div>
<div><p style="font-family:system-ui;letter-spacing:.12em;text-transform:uppercase;font-size:.75rem;color:#8a7b66">New arrival</p>
<h1>Relaxed Linen Coat</h1><div class="price">$248.00</div>
<p>Breathable mid-weight linen with a soft drape. Designed for transitional weather.</p>
<div class="swatches"><div class="swatch" style="background:#c4b7a6"></div><div class="swatch" style="background:#2f3a2e"></div><div class="swatch" style="background:#1a1a1a"></div></div>
<a class="btn" href="#">Add to cart</a></div></div>
<section class="related"><h2>You may also like</h2><div class="grid">
${[1, 2, 3, 4].map((n) => `<div class="card"><div class="thumb"></div><strong>Item ${n}</strong><div>$98</div></div>`).join('')}
</div></section></body></html>`);

add('shop-collection', 'marketing', ['shopify', 'collection', 'grid'],
  'marketing/shop-collection.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Collection</title>
<style>body{margin:0;font-family:Helvetica,Arial,sans-serif}header{padding:2rem;text-align:center;border-bottom:1px solid #eee}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;max-width:1100px;margin:2rem auto;padding:0 1rem}
.item img,.item .ph{width:100%;aspect-ratio:3/4;background:linear-gradient(135deg,#ddd,#bbb);object-fit:cover}
.item h3{font-size:1rem;margin:.75rem 0 .25rem;font-weight:500}.item .p{color:#666;font-size:.9rem}
@media(max-width:700px){.grid{grid-template-columns:1fr 1fr}}</style></head><body>
<header><h1>Summer Collection</h1><p>12 products</p></header>
<div class="grid">${Array.from({ length: 6 }, (_, i) => `<div class="item"><div class="ph"></div><h3>Product ${i + 1}</h3><div class="p">$${(40 + i * 12)}.00</div></div>`).join('')}</div>
</body></html>`);

// --- Webflow / agency ---
add('webflow-agency', 'marketing', ['webflow', 'agency', 'hero', 'absolute'],
  'marketing/webflow-agency.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Studio North</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;700&family=Instrument+Serif:ital@0;1&display=swap');
body{margin:0;font-family:'DM Sans',sans-serif;background:#0b0d10;color:#f4f1ea}
.nav{display:flex;justify-content:space-between;padding:1.5rem 2.5rem;position:relative;z-index:2}
.hero{position:relative;min-height:85vh;overflow:hidden;padding:4rem 2.5rem}
.hero-bg{position:absolute;inset:0;background:radial-gradient(circle at 70% 30%,#2a3344,transparent 50%),linear-gradient(160deg,#12151c,#0b0d10);z-index:0}
.hero-content{position:relative;z-index:1;max-width:640px;margin-top:8vh}
.hero h1{font-family:'Instrument Serif',serif;font-size:clamp(3rem,8vw,5.5rem);line-height:1;font-weight:400;margin:0 0 1rem}
.hero p{font-size:1.15rem;opacity:.8;max-width:420px}
.cta{display:inline-block;margin-top:1.5rem;padding:.9rem 1.4rem;background:#f4f1ea;color:#0b0d10;text-decoration:none;border-radius:999px;font-weight:700}
.float{position:absolute;right:8%;bottom:12%;width:280px;height:180px;border:1px solid rgba(244,241,234,.2);border-radius:16px;background:rgba(255,255,255,.04);backdrop-filter:blur(8px);padding:1.25rem;z-index:1}
.services{padding:5rem 2.5rem;display:grid;grid-template-columns:repeat(3,1fr);gap:2rem}
.svc h3{font-family:'Instrument Serif',serif;font-size:1.8rem;font-weight:400}
@media(max-width:800px){.services{grid-template-columns:1fr}.float{display:none}}
</style></head><body>
<div class="nav"><strong>Studio North</strong><span>Work · Studio · Contact</span></div>
<section class="hero"><div class="hero-bg"></div>
<div class="hero-content"><h1>Design that feels inevitable</h1>
<p>Brand systems and digital products for ambitious companies.</p>
<a class="cta" href="#">View selected work</a></div>
<div class="float"><div style="opacity:.6;font-size:.8rem">FEATURED</div><strong>Helix Rebrand</strong><p style="opacity:.7;font-size:.9rem">Identity + website for a climate fund.</p></div>
</section>
<section class="services">
<div class="svc"><h3>Brand</h3><p>Identity systems with lasting clarity.</p></div>
<div class="svc"><h3>Product</h3><p>Interfaces that respect attention.</p></div>
<div class="svc"><h3>Web</h3><p>High-fidelity marketing experiences.</p></div>
</section></body></html>`);

add('squarespace-portfolio', 'marketing', ['squarespace', 'portfolio', 'gallery'],
  'marketing/squarespace-portfolio.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Portfolio</title>
<style>body{margin:0;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;background:#fff;color:#111}
nav{display:flex;justify-content:space-between;padding:1.75rem 2rem;font-size:.8rem;letter-spacing:.14em;text-transform:uppercase}
.hero{height:70vh;background:linear-gradient(120deg,#d9d2c5,#b7c4b7);display:flex;align-items:flex-end;padding:3rem}
.hero h1{font-weight:400;font-size:3rem;margin:0;max-width:10ch}
.grid{columns:2;column-gap:1rem;padding:2rem;max-width:1000px;margin:0 auto}
.grid .item{break-inside:avoid;margin-bottom:1rem;background:#f3f1ec}
.grid .item .ph{height:220px;background:#cfc8ba}.grid .item:nth-child(2n) .ph{height:320px}
.grid .item .cap{padding:1rem;font-size:.9rem}
@media(max-width:700px){.grid{columns:1}.hero{height:50vh}}</style></head><body>
<nav><span>Elena Voss</span><span>Work · About · Contact</span></nav>
<section class="hero"><h1>Selected photography</h1></section>
<div class="grid">
${[1, 2, 3, 4, 5, 6].map((n) => `<div class="item"><div class="ph"></div><div class="cap">Series ${n}</div></div>`).join('')}
</div></body></html>`);

// --- Corporate / WP-like ---
add('corp-homepage', 'marketing', ['corporate', 'wordpress', 'sections'],
  'marketing/corp-homepage.html',
  shell('Northwind Corp', `
<header class="border-bottom"><div class="container d-flex align-items-center justify-content-between py-3">
  <strong class="fs-4">Northwind</strong>
  <nav class="d-none d-md-flex gap-3"><a class="link-dark text-decoration-none" href="#">Solutions</a><a class="link-dark text-decoration-none" href="#">Industries</a><a class="link-dark text-decoration-none" href="#">About</a></nav>
  <a class="btn btn-primary" href="#">Contact sales</a></div></header>
<section class="container py-5"><div class="row align-items-center g-5">
  <div class="col-lg-6"><h1 class="display-5 fw-bold">Enterprise logistics, simplified</h1>
  <p class="lead">Unify inventory, shipping, and customer experience on one platform.</p>
  <a class="btn btn-primary btn-lg me-2" href="#">Request demo</a><a class="btn btn-outline-secondary btn-lg" href="#">Watch video</a></div>
  <div class="col-lg-6"><div class="bg-light border rounded-3" style="height:320px"></div></div>
</div></section>
<section class="bg-light py-5"><div class="container"><div class="row text-center g-4">
  <div class="col-md-3"><div class="display-6 fw-bold">120+</div><div class="text-muted">Countries</div></div>
  <div class="col-md-3"><div class="display-6 fw-bold">4.9</div><div class="text-muted">CSAT</div></div>
  <div class="col-md-3"><div class="display-6 fw-bold">18k</div><div class="text-muted">Shipments / day</div></div>
  <div class="col-md-3"><div class="display-6 fw-bold">99.99%</div><div class="text-muted">Uptime</div></div>
</div></div></section>
<footer class="container py-4 border-top d-flex justify-content-between"><span>© Northwind</span><span class="text-muted">Careers · Legal</span></footer>`));

add('wp-twenty-blog', 'marketing', ['wordpress', 'blog', 'typography'],
  'marketing/wp-twenty-blog.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Twenty Blog</title>
<style>
body{margin:0;font-family:Georgia,serif;background:#fff;color:#111;line-height:1.7}
.site{max-width:720px;margin:0 auto;padding:3rem 1.25rem}
.site-title{font-size:2.5rem;font-weight:400;margin:0 0 2rem;border-bottom:1px solid #ddd;padding-bottom:1rem}
article{margin-bottom:3rem}h2{font-size:1.75rem;font-weight:400;margin:0 0 .5rem}a{color:#0066cc}
.meta{color:#666;font-size:.9rem;margin-bottom:1rem}p{margin:0 0 1rem}
</style></head><body><div class="site">
<h1 class="site-title">Field Notes</h1>
<article><h2><a href="#">Morning light in Lisbon</a></h2><div class="meta">June 12, 2024 · Travel</div>
<p>The tram climbed the hill and the city opened like a map of terracotta roofs.</p>
<p>We stopped for coffee and watched the Tagus change color.</p></article>
<article><h2><a href="#">Notes on quieter software</a></h2><div class="meta">May 3, 2024 · Design</div>
<p>Interfaces should fade once the task is clear. Ornament is not the same as care.</p></article>
</div></body></html>`);

// --- Layout stress tests ---
add('flex-complex', 'layouts', ['flexbox', 'nested', 'alignment'],
  'layouts/flex-complex.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Complex Flex</title>
<style>body{margin:0;font-family:system-ui,sans-serif}.row{display:flex;gap:16px;padding:24px;align-items:stretch}
.col{flex:1;background:#f1f5f9;padding:16px;border-radius:8px}.col.aside{flex:0 0 240px;background:#e2e8f0}
.inner{display:flex;gap:12px}.card{flex:1;background:#fff;padding:12px;border:1px solid #cbd5e1;border-radius:6px}
.bar{display:flex;justify-content:space-between;align-items:center;padding:12px 24px;background:#0f172a;color:#fff}
@media(max-width:768px){.row{flex-direction:column}.col.aside{flex-basis:auto}}</style></head><body>
<div class="bar"><strong>Flex Lab</strong><span>Docs · API · Status</span></div>
<div class="row"><div class="col"><h2>Main</h2><div class="inner"><div class="card"><h3>A</h3><p>Card A content</p></div><div class="card"><h3>B</h3><p>Card B content</p></div></div></div>
<aside class="col aside"><h3>Sidebar</h3><p>Sticky notes and filters live here.</p></aside></div>
</body></html>`);

add('css-grid-complex', 'layouts', ['grid', 'complex'],
  'layouts/css-grid-complex.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CSS Grid</title>
<style>body{margin:0;font-family:system-ui,sans-serif;background:#fafafa}
.grid{display:grid;grid-template-columns:repeat(4,1fr);grid-auto-rows:140px;gap:16px;padding:24px;max-width:1100px;margin:0 auto}
.a{grid-column:span 2;grid-row:span 2;background:#2563eb;color:#fff;padding:1.25rem;border-radius:12px}
.b,.c,.d,.e,.f{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem}
.e{grid-column:span 2}@media(max-width:700px){.grid{grid-template-columns:1fr 1fr}.a{grid-column:span 2;grid-row:span 1}}</style></head><body>
<div class="grid"><div class="a"><h1>Featured</h1><p>Spanning tile</p></div>
<div class="b"><h3>B</h3></div><div class="c"><h3>C</h3></div><div class="d"><h3>D</h3></div>
<div class="e"><h3>Wide E</h3><p>Two columns</p></div><div class="f"><h3>F</h3></div></div>
</body></html>`);

add('absolute-hero', 'layouts', ['absolute', 'overlay', 'hero'],
  'layouts/absolute-hero.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Absolute Hero</title>
<style>body{margin:0;font-family:system-ui,sans-serif}.hero{position:relative;height:520px;background:#111;color:#fff;overflow:hidden}
.hero img,.hero .bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.55;background:linear-gradient(135deg,#334155,#0f172a)}
.content{position:absolute;left:10%;top:35%;max-width:480px;z-index:2}h1{font-size:3rem;margin:0 0 .75rem}p{opacity:.9;margin:0 0 1.25rem}
.btn{display:inline-block;background:#f59e0b;color:#111;padding:.8rem 1.2rem;border-radius:6px;text-decoration:none;font-weight:700}
.badge{position:absolute;right:8%;top:12%;background:rgba(255,255,255,.12);padding:.5rem .8rem;border-radius:999px;z-index:2;font-size:.85rem}
</style></head><body>
<section class="hero"><div class="bg"></div><div class="badge">Limited seats</div>
<div class="content"><h1>Summit 2026</h1><p>Three days of product craft in Berlin.</p><a class="btn" href="#">Register</a></div></section>
<section style="padding:3rem 10%"><h2>Speakers</h2><p>Announcing the full lineup next month.</p></section>
</body></html>`);

add('sticky-header', 'layouts', ['sticky', 'header', 'scroll'],
  'layouts/sticky-header.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sticky</title>
<style>body{margin:0;font-family:system-ui,sans-serif}header{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:1rem 2rem;display:flex;justify-content:space-between;z-index:10}
main{padding:2rem;max-width:720px;margin:0 auto}section{margin:3rem 0;padding:2rem;background:#f8fafc;border-radius:12px}</style></head><body>
<header><strong>Sticky Co</strong><nav><a href="#a">One</a> · <a href="#b">Two</a> · <a href="#c">Three</a></nav></header>
<main><h1>Long reading page</h1>
${['a', 'b', 'c', 'd', 'e'].map((id, i) => `<section id="${id}"><h2>Section ${i + 1}</h2><p>${'Lorem ipsum dolor sit amet. '.repeat(12)}</p></section>`).join('')}
</main></body></html>`);

// --- Themes ---
add('dark-landing', 'themes', ['dark', 'gradient', 'cta'],
  'themes/dark-landing.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dark</title>
<style>body{margin:0;background:#050505;color:#f5f5f5;font-family:Inter,system-ui,sans-serif}
.hero{min-height:90vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:2rem;
background:radial-gradient(ellipse at top,#1a1033,#050505 60%)}
h1{font-size:clamp(2.5rem,6vw,4.5rem);margin:0 0 1rem;background:linear-gradient(90deg,#fff,#c4b5fd);-webkit-background-clip:text;color:transparent}
p{color:#a1a1aa;max-width:34rem;font-size:1.15rem}.btn{margin-top:1.5rem;padding:.85rem 1.4rem;border-radius:999px;background:#8b5cf6;color:#fff;text-decoration:none;font-weight:600}
.logos{display:flex;gap:2rem;opacity:.5;margin-top:4rem;flex-wrap:wrap;justify-content:center}</style></head><body>
<section class="hero"><h1>Intelligence at the edge</h1><p>Deploy models closer to users with a single control plane.</p>
<a class="btn" href="#">Start building</a>
<div class="logos"><span>Nova</span><span>Helix</span><span>Orbit</span><span>Pulse</span></div></section>
</body></html>`);

add('light-editorial', 'themes', ['light', 'editorial', 'serif'],
  'themes/light-editorial.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editorial</title>
<style>body{margin:0;background:#f7f3eb;color:#1c1917;font-family:Georgia,'Times New Roman',serif}
header{padding:2rem;border-bottom:1px solid #d6d3d1;display:flex;justify-content:space-between;font-family:system-ui,sans-serif;font-size:.8rem;letter-spacing:.12em;text-transform:uppercase}
.issue{max-width:680px;margin:4rem auto;padding:0 1.25rem}h1{font-size:3rem;font-weight:400;line-height:1.15}
.deck{font-size:1.25rem;color:#57534e;margin:1rem 0 2rem}.by{font-family:system-ui,sans-serif;font-size:.85rem;color:#78716c;margin-bottom:2rem}
p{font-size:1.125rem;line-height:1.8;margin:0 0 1.25rem}</style></head><body>
<header><span>Quarterly</span><span>Issue 18</span></header>
<article class="issue"><h1>The quiet return of craft</h1>
<p class="deck">Why slower tools are winning in a louder market.</p>
<div class="by">By Mira Chen · 12 min read</div>
<p>There is a particular pleasure in software that does one thing well and refuses to grow a toolbar.</p>
<p>Designers are rediscovering constraints as a creative material rather than a bug.</p>
</article></body></html>`);

// --- Forms / FAQ / contact ---
add('faq-accordion', 'forms', ['faq', 'details', 'accordion'],
  'forms/faq-accordion.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FAQ</title>
<style>body{margin:0;font-family:system-ui,sans-serif;background:#fff}.wrap{max-width:720px;margin:3rem auto;padding:0 1.25rem}
h1{margin-bottom:1.5rem}details{border:1px solid #e5e7eb;border-radius:10px;padding:1rem 1.25rem;margin-bottom:.75rem;background:#fafafa}
summary{font-weight:600;cursor:pointer}details[open] summary{margin-bottom:.75rem}details p{margin:0;color:#4b5563;line-height:1.6}</style></head><body>
<div class="wrap"><h1>Frequently asked questions</h1>
<details open><summary>How does billing work?</summary><p>You are billed monthly. Cancel anytime from settings.</p></details>
<details open><summary>Can I export my data?</summary><p>Yes. CSV and JSON exports are available on all plans.</p></details>
<details open><summary>Do you offer SSO?</summary><p>SSO is included on the Enterprise plan.</p></details>
</div></body></html>`);

add('contact-map', 'forms', ['contact', 'form', 'map'],
  'forms/contact-map.html',
  shell('Contact', `
<div class="container py-5"><div class="row g-5">
  <div class="col-md-6"><h1>Contact</h1><p class="text-muted">We reply within one business day.</p>
  <form class="mt-4"><div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" value="hello@example.com"></div>
  <div class="mb-3"><label class="form-label">Message</label><textarea class="form-control" rows="5">Looking for a partnership.</textarea></div>
  <button class="btn btn-dark">Send</button></form></div>
  <div class="col-md-6"><div class="bg-light border rounded" style="height:360px;display:flex;align-items:center;justify-content:center">
  <iframe title="map" width="100%" height="100%" style="border:0" src="https://maps.google.com/maps?q=Berlin&output=embed"></iframe></div>
  <p class="mt-3 mb-0"><strong>Berlin HQ</strong><br>Friedrichstraße 100<br>+49 30 123456</p></div>
</div></div>`));

add('testimonial-cards', 'marketing', ['testimonials', 'cards'],
  'marketing/testimonial-cards.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Testimonials</title>
<style>body{margin:0;font-family:system-ui,sans-serif;background:#f9fafb}.wrap{max-width:1000px;margin:0 auto;padding:4rem 1.25rem;text-align:center}
h1{margin-bottom:.5rem}.sub{color:#6b7280;margin-bottom:2.5rem}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;text-align:left}
.card{background:#fff;border-radius:16px;padding:1.5rem;box-shadow:0 8px 24px rgba(0,0,0,.06)}.quote{font-size:1.05rem;line-height:1.6;margin:0 0 1rem}
.who{font-weight:600;font-size:.9rem}.role{color:#6b7280;font-size:.85rem}@media(max-width:800px){.grid{grid-template-columns:1fr}}</style></head><body>
<div class="wrap"><h1>Loved by teams</h1><p class="sub">What customers say after switching.</p>
<div class="grid">
${[['“Setup took an afternoon.”', 'Priya N.', 'Head of Ops'], ['“Our conversion jumped 18%.”', 'Jon R.', 'Growth Lead'], ['“Finally editable without engineers.”', 'Sam K.', 'Marketing']].map(([q, n, r]) => `<div class="card"><p class="quote">${q}</p><div class="who">${n}</div><div class="role">${r}</div></div>`).join('')}
</div></div></body></html>`);

add('logo-cloud', 'marketing', ['logos', 'social-proof'],
  'marketing/logo-cloud.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Logos</title>
<style>body{margin:0;font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem}
.logos{display:flex;flex-wrap:wrap;justify-content:center;gap:2.5rem;margin-top:2rem;opacity:.7}
.logo{width:120px;height:40px;background:#e5e7eb;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:700;color:#374151}</style></head><body>
<p style="letter-spacing:.12em;text-transform:uppercase;color:#6b7280;font-size:.8rem">Trusted by</p>
<h1>Teams shipping every week</h1>
<div class="logos">${['Nova', 'Helix', 'Orbit', 'Pulse', 'Vertex', 'Loom'].map((n) => `<div class="logo">${n}</div>`).join('')}</div>
</body></html>`);

add('timeline', 'layouts', ['timeline', 'vertical'],
  'layouts/timeline.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Timeline</title>
<style>body{margin:0;font-family:system-ui,sans-serif;background:#fff}.wrap{max-width:640px;margin:3rem auto;padding:0 1rem}
.item{display:grid;grid-template-columns:80px 1fr;gap:1rem;padding:1.25rem 0;border-left:3px solid #ddd;margin-left:40px;padding-left:1.5rem;position:relative}
.item:before{content:'';position:absolute;left:-8px;top:1.5rem;width:12px;height:12px;border-radius:50%;background:#2563eb}
.year{font-weight:700;color:#2563eb}</style></head><body>
<div class="wrap"><h1>Our story</h1>
<div class="item"><div class="year">2019</div><div><h3>Founded</h3><p>Started in a small Berlin studio.</p></div></div>
<div class="item"><div class="year">2021</div><div><h3>Series A</h3><p>Expanded to three continents.</p></div></div>
<div class="item"><div class="year">2024</div><div><h3>Platform</h3><p>Launched the compiler suite.</p></div></div>
</div></body></html>`);

add('team-grid', 'marketing', ['team', 'cards', 'images'],
  'marketing/team-grid.html',
  `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Team</title>
<style>body{margin:0;font-family:system-ui,sans-serif}.wrap{max-width:960px;margin:0 auto;padding:3rem 1rem;text-align:center}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-top:2rem}
.card{background:#f8fafc;border-radius:12px;padding:1rem}.avatar{width:96px;height:96px;border-radius:50%;background:linear-gradient(135deg,#94a3b8,#64748b);margin:0 auto 1rem}
@media(max-width:700px){.grid{grid-template-columns:1fr 1fr}}</style></head><body>
<div class="wrap"><h1>Meet the team</h1><p style="color:#64748b">Operators, designers, and engineers.</p>
<div class="grid">${[['Ava', 'CEO'], ['Noah', 'Design'], ['Mia', 'Eng'], ['Leo', 'Sales']].map(([n, r]) => `<div class="card"><div class="avatar"></div><strong>${n}</strong><div style="color:#64748b">${r}</div></div>`).join('')}</div>
</div></body></html>`);

// Rewrite fetched Bootstrap docs examples to use CDN + strip scripts that break offline
function localizeBootstrapExample(srcPath, outRel, id, tags) {
  if (!fs.existsSync(srcPath)) return;
  let html = fs.readFileSync(srcPath, 'utf8');
  // Bootstrap docs examples often link ../assets - replace with CDN and inline minimal body extraction
  html = html.replace(/href="[^"]*bootstrap(\.min)?\.css"/g, `href="${BS}"`);
  html = html.replace(/<script[\s\S]*?<\/script>/gi, '');
  if (!/bootstrap@5/.test(html)) {
    html = html.replace('</head>', `<link href="${BS}" rel="stylesheet"></head>`);
  }
  // Fix relative asset roots that 404
  html = html.replace(/href="\.\.\/assets\/[^"]+"/g, 'href="#"');
  html = html.replace(/src="\.\.\/assets\/[^"]+"/g, 'src=""');
  pages.push({ id, category: 'framework', tags, path: write(outRel, html) });
}

localizeBootstrapExample('/tmp/corpus-fetch/bs-features.html', 'framework/real-bs-features.html', 'real-bs-features', ['bootstrap', 'real', 'features']);
localizeBootstrapExample('/tmp/corpus-fetch/bs-pricing.html', 'framework/real-bs-pricing.html', 'real-bs-pricing', ['bootstrap', 'real', 'pricing']);
localizeBootstrapExample('/tmp/corpus-fetch/bs-album.html', 'framework/real-bs-album.html', 'real-bs-album', ['bootstrap', 'real', 'album']);
localizeBootstrapExample('/tmp/corpus-fetch/bs-jumbotron.html', 'framework/real-bs-jumbotron.html', 'real-bs-jumbotron', ['bootstrap', 'real', 'jumbotron']);

// Register existing fixtures already copied
const existingDir = path.join(CORPUS, 'existing');
if (fs.existsSync(existingDir)) {
  for (const file of fs.readdirSync(existingDir).filter((f) => f.endsWith('.html'))) {
    const id = 'existing-' + file.replace(/\.html$/, '');
    pages.push({
      id,
      category: 'existing',
      tags: ['production-fixture', file.startsWith('petra') || file.includes('petra') ? 'petra' : 'fixture'],
      path: path.join('existing', file),
    });
  }
}

const manifest = {
  generated_at: new Date().toISOString(),
  count: pages.length,
  pages,
};
fs.writeFileSync(path.join(CORPUS, 'manifest.json'), JSON.stringify(manifest, null, 2));
console.log(`Corpus built: ${pages.length} pages -> ${path.join(CORPUS, 'manifest.json')}`);
