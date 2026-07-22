(function () {
	var toggle = document.querySelector('.menu-toggle');
	var nav = document.querySelector('.primary-navigation');
	if (!toggle || !nav) return;

	toggle.addEventListener('click', function () {
		var open = nav.classList.toggle('is-open');
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		toggle.setAttribute('aria-label', open ? 'Menü schliessen' : 'Menü öffnen');
	});

	nav.querySelectorAll('a').forEach(function (link) {
		link.addEventListener('click', function () {
			nav.classList.remove('is-open');
			toggle.setAttribute('aria-expanded', 'false');
		});
	});
})();
