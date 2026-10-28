/**
 * WP MCP Tools: admin screen behaviour (no dependencies).
 * Auth-method switch · client tabs · copy buttons · unsaved-changes hint · tool search/filter · confirm links.
 */
(function () {
	'use strict';
	var root = document.querySelector('.wpmcp');
	if (!root) return;

	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

	/* Auth method: show the matching blocks. */
	function setAuth(m) {
		$$('.wpmcp-auth-only').forEach(function (el) { el.hidden = el.getAttribute('data-auth') !== m; });
		$$('.wpmcp-method').forEach(function (el) { el.classList.toggle('is-active', el.querySelector('input').value === m); });
	}
	var checked = root.querySelector('.wpmcp-method input:checked');
	if (root.querySelector('.wpmcp-auth-only')) setAuth(checked ? checked.value : 'oauth');
	$$('.wpmcp-method input').forEach(function (i) { i.addEventListener('change', function () { setAuth(i.value); }); });

	/* Client tabs (arrow keys move between them). */
	var clients = $$('.wpmcp-client');
	function pick(b) {
		var k = b.getAttribute('data-client');
		clients.forEach(function (x) { var on = x === b; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
		$$('.wpmcp-client-panel').forEach(function (p) { p.hidden = p.getAttribute('data-client') !== k; });
	}
	clients.forEach(function (b, i) {
		b.addEventListener('click', function () { pick(b); });
		b.addEventListener('keydown', function (e) {
			var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
			if (!d) return;
			e.preventDefault();
			var n = clients[(i + d + clients.length) % clients.length];
			n.focus(); pick(n);
		});
	});

	/* Copy buttons. */
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('.wpmcp-copy');
		if (!btn) return;
		var t = btn.closest('.wpmcp-code').querySelector('code').textContent;
		var txt = btn.querySelector('.wpmcp-copy__txt');
		var done = function () {
			btn.classList.add('is-done'); if (txt) txt.textContent = 'Copied';
			setTimeout(function () { btn.classList.remove('is-done'); if (txt) txt.textContent = 'Copy'; }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done); }
		else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); done(); }
	});

	/* Confirm before destructive links. */
	root.addEventListener('click', function (e) {
		var a = e.target.closest('[data-wpmcp-confirm]');
		if (a && !window.confirm(a.getAttribute('data-wpmcp-confirm'))) e.preventDefault();
	});

	/* Unsaved-changes hint on the settings form. */
	var form = document.getElementById('wpmcp-settings');
	if (form) {
		var mark = function () { root.classList.add('is-dirty'); };
		form.addEventListener('change', mark);
		form.addEventListener('input', mark);
		form.addEventListener('submit', function () { root.classList.remove('is-dirty'); });
	}

	/* Tools tab: search + group filter. */
	var search = document.getElementById('wpmcp-tool-search');
	var rows = $$('.wpmcp-tools tbody tr');
	var empty = root.querySelector('.wpmcp-tools-empty');
	var group = '';
	function filter() {
		var q = search ? search.value.trim().toLowerCase() : '';
		var shown = 0;
		rows.forEach(function (r) {
			var ok = (!group || r.getAttribute('data-group') === group) && (!q || r.textContent.toLowerCase().indexOf(q) !== -1);
			r.hidden = !ok; if (ok) shown++;
		});
		if (empty) empty.hidden = shown !== 0;
	}
	if (search) search.addEventListener('input', filter);
	$$('.wpmcp-chip').forEach(function (c) {
		c.addEventListener('click', function () {
			group = c.getAttribute('data-group');
			$$('.wpmcp-chip').forEach(function (x) { x.classList.toggle('is-active', x === c); });
			filter();
		});
	});
})();
