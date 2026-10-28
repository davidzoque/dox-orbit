/* Dox Orbit: el panel.
   Sin dependencias (solo wp.media para elegir imágenes). Nada de alert(),
   confirm() ni prompt(): bloquean la pestaña. Avisos con toast y <dialog>. */
(function () {
	'use strict';

	var D = window.DXO || {};
	var T = D.i18n || {};
	var $ = function (s, c) { return (c || document).querySelector(s); };
	var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
	var app = $('#dxo-app');
	if (!app) return;

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
			return pos ? args[parseInt(pos, 10) - 1] : args[i++];
		});
	}
	// Texto → base64 (UTF-8). El código HTML viaja así: ciertos cortafuegos paran un POST con <script> o onclick.
	function b64(str) {
		var bytes = new TextEncoder().encode(String(str)), bin = '';
		for (var i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
		return btoa(bin);
	}
	// Los bloques tal como viajan: el HTML de un bloque HTML en base64.
	function wireBlocks(list) {
		return JSON.stringify(list.map(function (b) {
			if (b.type !== 'html') return b;
			return { type: 'html', html_b64: b64(b.html || '') };
		}));
	}
	function num(n) { return Number(n).toLocaleString(document.documentElement.lang || undefined); }
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

	// ─── AJAX ───
	function ajax(action, data) {
		var body = data instanceof FormData ? data : new FormData();
		if (!(data instanceof FormData) && data) {
			Object.keys(data).forEach(function (k) {
				var v = data[k];
				if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
				else if (v !== undefined && v !== null) body.append(k, v);
			});
		}
		body.append('action', 'dxo_' + action);
		body.append('nonce', D.nonce);
		return fetch(D.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: T.error } }; }); })
			.then(function (res) {
				if (!res || !res.success) {
					var err = new Error((res && res.data && res.data.message) || T.error);
					err.data = res && res.data;
					throw err;
				}
				return res.data;
			});
	}

	// ─── Aviso ───
	var toastTimer;
	function toast(msg, bad) {
		var el = $('#dxo-toast');
		if (!el) return;
		el.textContent = msg;
		el.classList.toggle('bad', !!bad);
		el.classList.add('show');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { el.classList.remove('show'); }, bad ? 6000 : 3200);
	}

	// ─── Diálogo ───
	// buttons: [{ label, kind: 'dark'|'gray'|'danger', onClick(dlg) → false para no cerrar }]
	function modal(opts) {
		var dlg = $('#dxo-modal');
		$('.hd h3', dlg).textContent = opts.title || '';
		var bd = $('.bd', dlg);
		bd.innerHTML = '';
		if (typeof opts.body === 'string') bd.innerHTML = opts.body;
		else if (opts.body) bd.appendChild(opts.body);
		var ft = $('.ft', dlg);
		ft.innerHTML = '';
		(opts.buttons || [{ label: T.cancel, kind: 'gray' }]).forEach(function (b) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'dxo-btn dxo-btn-' + (b.kind || 'gray');
			btn.textContent = b.label;
			btn.addEventListener('click', function () {
				var r = b.onClick ? b.onClick(dlg, btn) : true;
				if (r && typeof r.then === 'function') {
					btn.classList.add('is-busy');
					r.then(function (ok) { btn.classList.remove('is-busy'); if (ok !== false) dlg.close(); })
						.catch(function (e) { btn.classList.remove('is-busy'); toast(e.message, true); });
				} else if (r !== false) dlg.close();
			});
			ft.appendChild(btn);
		});
		if (dlg.open) dlg.close();
		dlg.showModal();
		var first = $('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea', bd);
		if (first) setTimeout(function () { first.focus(); }, 50);
		return dlg;
	}
	function confirmBox(text, label, kind) {
		return new Promise(function (resolve) {
			var done = false;
			var dlg = modal({
				title: T.confirm,
				body: '<p style="margin:0">' + esc(text) + '</p>',
				buttons: [
					{ label: T.cancel, kind: 'gray' },
					{ label: label || T.confirm, kind: kind || 'danger', onClick: function () { done = true; resolve(true); } }
				]
			});
			dlg.addEventListener('close', function once() { dlg.removeEventListener('close', once); if (!done) resolve(false); });
		});
	}

	// ─── Entradas escalonadas, una vez ───
	$$('[data-anim]', app).forEach(function (el, i) {
		setTimeout(function () { el.classList.add('in'); }, 60 + i * 90);
	});

	// ─── Filas que llevan a otra pantalla ───
	app.addEventListener('click', function (e) {
		var row = e.target.closest('tr[data-href]');
		if (row && !e.target.closest('a,button,.dxo-menu,input,label')) location.href = row.getAttribute('data-href');
	});

	// ─── Menús de fila ───
	document.addEventListener('click', function (e) {
		var trigger = e.target.closest('[data-menu]');
		$$('.dxo-menu.open').forEach(function (m) { if (!trigger || m !== trigger.parentNode) m.classList.remove('open'); });
		if (trigger) { e.preventDefault(); trigger.parentNode.classList.toggle('open'); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('.dxo-menu.open').forEach(function (m) { m.classList.remove('open'); }); });

	// ─── Nueva campaña: de bloques o de código ───
	// Sin JavaScript el enlace crea una de bloques; con él se elige.
	document.addEventListener('click', function (e) {
		var a = e.target.closest('[data-new-campaign]');
		if (!a || e.ctrlKey || e.metaKey || e.shiftKey || !a.getAttribute('data-html-url')) return;
		e.preventDefault();
		var wrap = document.createElement('div');
		wrap.className = 'dxo-choices';
		[
			[a.getAttribute('data-blocks-url'), T.new_blocks, T.new_blocks_d, '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="13" width="11" height="7" rx="2"/>'],
			[a.getAttribute('data-html-url'), T.new_html, T.new_html_d, '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>']
		].forEach(function (c) {
			var l = document.createElement('a');
			l.className = 'dxo-choice';
			l.href = c[0];
			l.innerHTML = '<div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + c[3] + '</svg></div><b>' + esc(c[1]) + '</b><p class="muted">' + esc(c[2]) + '</p>';
			wrap.appendChild(l);
		});
		modal({ title: T.new_title, body: wrap, buttons: [{ label: T.cancel, kind: 'gray' }] });
	});

	// ─── Acciones sobre campañas (menús, informe) ───
	app.addEventListener('click', function (e) {
		var b = e.target.closest('[data-act]');
		if (!b) return;
		e.preventDefault();
		var act = b.getAttribute('data-act');
		var run = function () {
			b.classList.add('is-busy');
			return ajax(act, { id: b.getAttribute('data-id'), who: b.getAttribute('data-who') })
				.then(function (r) { if (r && r.redirect) location.href = r.redirect; else location.reload(); })
				.catch(function (err) { b.classList.remove('is-busy'); toast(err.message, true); });
		};
		var q = b.getAttribute('data-confirm');
		if (q) confirmBox(q, b.textContent.trim()).then(function (ok) { if (ok) run(); });
		else run();
	});

	// ─── Copiar ───
	app.addEventListener('click', function (e) {
		var b = e.target.closest('[data-copy]');
		if (!b) return;
		var txt = b.getAttribute('data-copy');
		(navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { toast(T.copied); }).catch(function () {
			var t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t); t.select();
			try { document.execCommand('copy'); toast(T.copied); } catch (x) {}
			t.remove();
		});
	});

	// ─── Buscadores: placeholder que rota y "/" para enfocar ───
	$$('input[data-ph]', app).forEach(function (inp) {
		var list = inp.getAttribute('data-ph').split('|');
		if (list.length < 2) return;
		var i = 0;
		setInterval(function () { if (document.activeElement !== inp && !inp.value) { i = (i + 1) % list.length; inp.placeholder = list[i]; } }, 2400);
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== '/' || /input|textarea|select/i.test(document.activeElement.tagName) || document.activeElement.isContentEditable) return;
		var s = $('.dxo-search input[type=search]', app);
		if (s) { e.preventDefault(); s.focus(); }
	});

	// ─── Franja del envío en curso: al día cada 20 s ───
	var bar = $('#dxo-status');
	if (bar) {
		var paint = function (st) {
			if (!st || !st.active) {
				if (!bar.hidden && bar.getAttribute('data-id') !== '0') { bar.hidden = true; if (D.view === 'dashboard' || D.view === 'report') setTimeout(function () { location.reload(); }, 400); }
				return;
			}
			bar.hidden = false;
			bar.setAttribute('data-id', st.id);
			bar.classList.toggle('is-paused', st.paused);
			$('[data-s=title]', bar).textContent = st.title;
			$('[data-s=count]', bar).textContent = st.count;
			$('[data-s=bar]', bar).style.width = st.pct + '%';
			$('[data-s=meta]', bar).textContent = st.meta;
			var tg = $('[data-s=toggle]', bar);
			tg.setAttribute('data-action', st.paused ? 'resume' : 'pause');
			$('span', tg).textContent = st.label;
		};
		var poll = function () { ajax('status').then(paint).catch(function () {}); };
		if (bar.getAttribute('data-id') !== '0') setInterval(poll, 20000);
		$('[data-s=toggle]', bar).addEventListener('click', function () {
			var btn = this;
			btn.classList.add('is-busy');
			ajax(btn.getAttribute('data-action'), { id: bar.getAttribute('data-id') })
				.then(function (st) { btn.classList.remove('is-busy'); paint(st); })
				.catch(function (e) { btn.classList.remove('is-busy'); toast(e.message, true); });
		});
	}

	// ─── Bienvenida encendida o apagada ───
	// Una por idioma con Polylang: cada interruptor lleva el id de su bienvenida.
	$$('[data-welcome]', app).forEach(function (wl) {
		wl.addEventListener('change', function () {
			var on = wl.checked;
			ajax('welcome_toggle', { id: wl.getAttribute('data-welcome'), on: on ? 1 : 0 }).then(function () { location.reload(); }).catch(function (e) { wl.checked = !on; toast(e.message, true); });
		});
	});

	var views = { edit: editor, subscribers: subscribers, forms: forms, settings: settings };
	if (views[D.view]) views[D.view]();

	// ═══════════════════════════════════════════════════════════════════════
	// Editor de campañas
	// ═══════════════════════════════════════════════════════════════════════
	function editor() {
		var dataEl = $('#dxo-data');
		if (!dataEl) return;
		var C = JSON.parse(dataEl.textContent);
		var S = C.strings, L = S.labels;
		var blocks = C.blocks && C.blocks.length ? C.blocks : [];
		var isHtml = C.format === 'html';   // la campaña sale del código pegado, no de los bloques
		var codeEl = $('#dxo-html'), cm = null;
		var later = [];                      // lo que se hace cuando los bloques ya están en pantalla (el editor de código)
		var selected = -1;
		var dirty = false, saving = false, lastSaved = '';
		var saveTimer, previewTimer;
		var subj = $('#dxo-subject'), pre = $('#dxo-preheader');
		var list = $('#dxo-blocks'), frame = $('#dxo-frame');
		var stateEl = $('#dxo-save-state');
		var ICON = {
			heading: '<path d="M4 7V5h16v2M9 19h6M12 5v14"/>', text: '<path d="M4 6h16M4 11h16M4 16h10"/>',
			image: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/>',
			button: '<rect x="4" y="8" width="16" height="8" rx="4"/>', post: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
			html: '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>',
			divider: '<path d="M4 12h16"/>', spacer: '<path d="M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4"/>',
			up: '<path d="m6 15 6-6 6 6"/>', down: '<path d="m6 9 6 6 6-6"/>', trash: '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>',
			grip: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>'
		};
		var svg = function (n) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICON[n] + '</svg>'; };
		$$('[data-label]').forEach(function (s) { s.textContent = T.block[s.getAttribute('data-label')]; });

		function summary(b) {
			var plain = function (h) { var d = document.createElement('div'); d.innerHTML = h || ''; return d.textContent.trim(); };
			switch (b.type) {
				case 'heading': return b.text || T.empty_block;
				case 'text': return plain(b.html) || T.empty_block;
				case 'image': return b.alt || (b.url ? b.url.split('/').pop() : T.empty_block);
				case 'button': return b.text ? b.text + (b.url ? ' → ' + b.url.replace(/^https?:\/\//, '') : '') : T.empty_block;
				case 'post': return b.title || T.empty_block;
				case 'spacer': return (b.height || 24) + ' px';
				case 'html': return (b.html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 90) || T.empty_block;
				default: return '';
			}
		}
		function defaults(type) {
			switch (type) {
				case 'heading': return { type: type, text: '', size: 'large', align: 'left' };
				case 'text': return { type: type, html: '', align: 'left' };
				case 'image': return { type: type, url: '', alt: '', link: '', align: 'left' };
				case 'button': return { type: type, text: '', url: '', style: 'dark', align: 'left' };
				case 'post': return { type: type, post_id: 0, title: '', excerpt: '', image: '', url: '', cta: T.read_more };
				case 'spacer': return { type: type, height: 24 };
				case 'html': return { type: type, html: '' };
				default: return { type: type };
			}
		}

		// ── Campos ──
		function field(label, control, hint) {
			var w = document.createElement('div');
			w.className = 'dxo-field';
			w.innerHTML = '<span class="lbl">' + esc(label) + '</span><div></div>';
			w.lastChild.appendChild(control);
			if (hint) { var h = document.createElement('div'); h.className = 'dxo-hint'; h.innerHTML = '<span>' + esc(hint) + '</span>'; w.lastChild.appendChild(h); }
			return w;
		}
		function input(b, key, ph, type) {
			var i = document.createElement('input');
			i.className = 'dxo-input'; i.type = type || 'text'; i.value = b[key] || ''; if (ph) i.placeholder = ph;
			i.addEventListener('input', function () { b[key] = i.value; changed(true); });
			return i;
		}
		function seg(b, key, opts) {
			var s = document.createElement('div');
			s.className = 'dxo-seg';
			opts.forEach(function (o) {
				var btn = document.createElement('button');
				btn.type = 'button'; btn.textContent = o[1];
				if ((b[key] || opts[0][0]) === o[0]) btn.className = 'on';
				btn.addEventListener('click', function () {
					b[key] = o[0];
					$$('button', s).forEach(function (x) { x.classList.toggle('on', x === btn); });
					changed(true);
				});
				s.appendChild(btn);
			});
			return s;
		}
		var alignOpts = [['left', L.left], ['center', L.center]];

		function rte(b) {
			var wrap = document.createElement('div');
			var barEl = document.createElement('div');
			barEl.className = 'dxo-rte-bar';
			var ed = document.createElement('div');
			ed.className = 'dxo-rte'; ed.contentEditable = 'true'; ed.innerHTML = b.html || '<p><br></p>';
			[['bold', 'B', L.bold], ['italic', 'I', L.italic], ['insertUnorderedList', '•', L.list], ['link', '↗', L.add_link]].forEach(function (c) {
				var btn = document.createElement('button');
				btn.type = 'button'; btn.textContent = c[1]; btn.title = c[2]; btn.setAttribute('aria-label', c[2]);
				if (c[0] === 'italic') btn.style.fontStyle = 'italic';
				btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
				btn.addEventListener('click', function () {
					ed.focus();
					if (c[0] === 'link') return linkBox(ed);
					document.execCommand(c[0], false, null);
					sync();
				});
				barEl.appendChild(btn);
			});
			function sync() { b.html = ed.innerHTML; changed(true); }
			ed.addEventListener('input', sync);
			// Pegar como texto: lo que viene de Word o de una web trae estilos que cada programa pinta distinto.
			ed.addEventListener('paste', function (e) {
				e.preventDefault();
				var text = (e.clipboardData || window.clipboardData).getData('text/plain');
				var html = text.split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
				document.execCommand('insertHTML', false, html);
			});
			wrap.appendChild(barEl); wrap.appendChild(ed);
			return wrap;
		}
		function linkBox(ed) {
			var sel = window.getSelection();
			var range = sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
			var box = document.createElement('div');
			var i = document.createElement('input');
			i.className = 'dxo-input'; i.type = 'url'; i.placeholder = 'https://';
			box.appendChild(field(L.link_prompt, i));
			modal({
				title: L.add_link, body: box,
				buttons: [{ label: T.cancel, kind: 'gray' }, { label: L.apply, kind: 'dark', onClick: function () {
					var url = i.value.trim();
					if (!/^(https?:\/\/|mailto:|tel:|\{)/i.test(url)) { i.classList.add('is-error'); return false; }
					ed.focus();
					if (range) { sel.removeAllRanges(); sel.addRange(range); }
					if (range && range.collapsed) document.execCommand('insertHTML', false, '<a href="' + esc(url) + '">' + esc(url) + '</a>');
					else document.execCommand('createLink', false, url);
					ed.dispatchEvent(new Event('input'));
				} }]
			});
		}

		// El editor de código de WordPress (CodeMirror) sobre un textarea; sin él, el textarea se queda como está.
		function codeMirror(ta, onChange) {
			if (!D.codeEditor || !window.wp || !wp.codeEditor) {
				ta.addEventListener('input', function () { onChange(ta.value); });
				return null;
			}
			var inst = wp.codeEditor.initialize(ta, D.codeEditor);
			inst.codemirror.on('change', function (c) { onChange(c.getValue()); });
			// Si estaba oculto al crearse, no sabe cuánto mide hasta que se enseña.
			setTimeout(function () { inst.codemirror.refresh(); }, 0);
			return inst.codemirror;
		}

		function mediaPicker(b, key, onPick) {
			var wrap = document.createElement('div');
			wrap.className = 'dxo-imgpick';
			var img = document.createElement('img'); img.alt = '';
			var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'dxo-btn dxo-btn-gray dxo-btn-sm';
			var paint = function () { img.hidden = !b[key]; if (b[key]) img.src = b[key]; btn.textContent = b[key] ? L.change : L.choose; };
			btn.addEventListener('click', function () {
				if (!window.wp || !wp.media) return;
				var frameM = wp.media({ title: T.choose_image, button: { text: T.use_image }, library: { type: 'image' }, multiple: false });
				frameM.on('select', function () {
					var a = frameM.state().get('selection').first().toJSON();
					var size = a.sizes && (a.sizes.large || a.sizes.full);
					b[key] = (size && size.url) || a.url;
					if (onPick) onPick(a);
					paint(); changed(true); renderList();
				});
				frameM.open();
			});
			paint();
			wrap.appendChild(img); wrap.appendChild(btn);
			return wrap;
		}

		function postPicker(b) {
			var wrap = document.createElement('div');
			wrap.className = 'dxo-postpick';
			var i = document.createElement('input');
			i.className = 'dxo-input'; i.type = 'search'; i.placeholder = L.search;
			var res = document.createElement('div'); res.className = 'dxo-postres'; res.hidden = true;
			var t;
			var search = function () {
				ajax('posts', { q: i.value }).then(function (posts) {
					res.innerHTML = '';
					if (!posts.length) { res.innerHTML = '<div class="small muted" style="padding:8px">' + esc(T.no_posts) + '</div>'; }
					posts.forEach(function (p) {
						var btn = document.createElement('button'); btn.type = 'button';
						btn.innerHTML = (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '<span class="ph"></span>') + '<div><b>' + esc(p.title) + '</b><span>' + esc(p.date) + '</span></div>';
						btn.addEventListener('click', function () {
							b.post_id = p.post_id; b.title = p.title; b.excerpt = p.excerpt; b.image = p.image; b.url = p.url;
							if (!b.cta) b.cta = T.read_more;
							res.hidden = true; changed(true); renderList();
						});
						res.appendChild(btn);
					});
					res.hidden = false;
				}).catch(function () {});
			};
			i.addEventListener('input', function () { clearTimeout(t); t = setTimeout(search, 250); });
			i.addEventListener('focus', search);
			document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) res.hidden = true; });
			wrap.appendChild(i); wrap.appendChild(res);
			return wrap;
		}

		function body(b, isSel) {
			var f = document.createElement('div');
			f.className = 'dxo-blk-b';
			switch (b.type) {
				case 'heading':
					f.appendChild(field(L.text, input(b, 'text')));
					f.appendChild(field(L.size, seg(b, 'size', [['large', L.large], ['medium', L.medium], ['small', L.small]])));
					f.appendChild(field(L.align, seg(b, 'align', alignOpts)));
					break;
				case 'text':
					f.appendChild(field(L.text, rte(b)));
					f.appendChild(field(L.align, seg(b, 'align', alignOpts)));
					break;
				case 'image':
					f.appendChild(field(L.image, mediaPicker(b, 'url', function (a) { if (!b.alt && a.alt) b.alt = a.alt; })));
					f.appendChild(field(L.alt, input(b, 'alt'), L.alt_hint));
					f.appendChild(field(L.link, input(b, 'link', 'https://', 'url')));
					f.appendChild(field(L.align, seg(b, 'align', alignOpts)));
					break;
				case 'button':
					f.appendChild(field(L.text, input(b, 'text')));
					f.appendChild(field(L.url, input(b, 'url', 'https://', 'url')));
					f.appendChild(field(L.style, seg(b, 'style', [['dark', L.dark], ['accent', L.accent]])));
					f.appendChild(field(L.align, seg(b, 'align', alignOpts)));
					break;
				case 'post':
					f.appendChild(field(L.post, postPicker(b)));
					if (b.url) {
						f.appendChild(field(L.text, input(b, 'title')));
						var ta = document.createElement('textarea'); ta.className = 'dxo-input'; ta.rows = 3; ta.value = b.excerpt || '';
						ta.addEventListener('input', function () { b.excerpt = ta.value; changed(true); });
						f.appendChild(field('', ta));
						f.appendChild(field(L.cta, input(b, 'cta')));
					}
					break;
				case 'html':
					var ta = document.createElement('textarea');
					ta.className = 'dxo-input dxo-code'; ta.rows = 9; ta.spellcheck = false; ta.value = b.html || ''; ta.placeholder = L.html_ph;
					var onCode = function (v) { b.html = v; changed(true); };
					// Solo el bloque abierto lleva CodeMirror: los cerrados no se ven y no sabría medirse.
					if (isSel) later.push(function () { codeMirror(ta, onCode); });
					else ta.addEventListener('input', function () { onCode(ta.value); });
					f.appendChild(field(L.html_code, ta, L.html_hint));
					break;
				case 'spacer':
					var r = document.createElement('input'); r.type = 'range'; r.min = 8; r.max = 96; r.step = 4; r.value = b.height || 24;
					r.addEventListener('input', function () { b.height = parseInt(r.value, 10); changed(true); $('.nm span', f.parentNode).textContent = b.height + ' px'; });
					f.appendChild(field(L.height, r));
					break;
				default:
					return null;
			}
			return f;
		}

		var dragFrom = -1;
		function renderList() {
			if (!list) return;
			list.innerHTML = '';
			later = [];
			blocks.forEach(function (b, i) {
				var el = document.createElement('div');
				el.className = 'dxo-blk' + (i === selected ? ' sel' : '');
				el.innerHTML = '<div class="dxo-blk-h"><span class="grip" draggable="true" title="⇅">' + svg('grip') + '</span><span class="ic">' + svg(b.type) + '</span>'
					+ '<span class="nm"><b>' + esc(T.block[b.type]) + '</b><span>' + esc(summary(b)) + '</span></span>'
					+ '<span class="tools">'
					+ (i > 0 ? '<button type="button" class="dxo-iconbtn" data-t="up" title="' + esc(L.move_up) + '" aria-label="' + esc(L.move_up) + '">' + svg('up') + '</button>' : '')
					+ (i < blocks.length - 1 ? '<button type="button" class="dxo-iconbtn" data-t="down" title="' + esc(L.move_dn) + '" aria-label="' + esc(L.move_dn) + '">' + svg('down') + '</button>' : '')
					+ '<button type="button" class="dxo-iconbtn" data-t="del" title="' + esc(L.remove) + '" aria-label="' + esc(L.remove) + '">' + svg('trash') + '</button></span></div>';
				var bd = body(b, i === selected);
				if (bd) el.appendChild(bd);
				$('.dxo-blk-h', el).addEventListener('click', function (e) {
					var t = e.target.closest('[data-t]');
					if (t) {
						var a = t.getAttribute('data-t');
						if (a === 'del') { blocks.splice(i, 1); if (selected === i) selected = -1; }
						if (a === 'up') { blocks.splice(i - 1, 0, blocks.splice(i, 1)[0]); selected = i - 1; }
						if (a === 'down') { blocks.splice(i + 1, 0, blocks.splice(i, 1)[0]); selected = i + 1; }
						changed(true); renderList(); return;
					}
					selected = selected === i ? -1 : i;
					renderList();
					// El bloque se acaba de pintar de nuevo: el campo está en el elemento nuevo.
					if (selected === i) { var f = $('.dxo-blk-b input:not([type=search]), .dxo-blk-b .dxo-rte', list.children[i]); if (f) f.focus(); }
				});
				// Arrastrar desde el asa para reordenar.
				var grip = $('.grip', el);
				grip.addEventListener('dragstart', function (e) { dragFrom = i; el.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', String(i)); } catch (x) {} });
				grip.addEventListener('dragend', function () { el.classList.remove('dragging'); $$('.over', list).forEach(function (o) { o.classList.remove('over'); }); });
				el.addEventListener('dragover', function (e) { if (dragFrom < 0) return; e.preventDefault(); el.classList.add('over'); });
				el.addEventListener('dragleave', function () { el.classList.remove('over'); });
				el.addEventListener('drop', function (e) {
					e.preventDefault();
					if (dragFrom < 0 || dragFrom === i) return;
					var moved = blocks.splice(dragFrom, 1)[0];
					blocks.splice(dragFrom < i ? i - 1 : i, 0, moved);
					selected = -1; dragFrom = -1;
					changed(true); renderList();
				});
				list.appendChild(el);
			});
			later.forEach(function (fn) { fn(); });
		}

		$$('[data-add]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				blocks.splice(selected >= 0 ? selected + 1 : blocks.length, 0, defaults(btn.getAttribute('data-add')));
				selected = selected >= 0 ? selected + 1 : blocks.length - 1;
				changed(true); renderList();
				var el = list.children[selected];
				if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			});
		});

		// ── Asunto, texto previo y vista de la bandeja ──
		function inbox() {
			$('#dxo-inbox-subject').textContent = subj.value || '(…)';
			$('#dxo-inbox-pre').textContent = pre.value;
			var n = subj.value.length;
			var cnt = $('#dxo-subject-count');
			cnt.textContent = fmt(T.chars, n);
			cnt.className = n > 60 ? 'warn' : '';
			if (subj.value) $('#dxo-title').textContent = subj.value;
		}
		subj.addEventListener('input', function () { inbox(); changed(true); });
		pre.addEventListener('input', function () { inbox(); changed(true); });
		inbox();

		// ── Listas ──
		var lists = $('#dxo-lists');
		if (lists) lists.addEventListener('change', function () { changed(false, true); });
		function chosenLists() { return lists ? $$('input:checked', lists).map(function (i) { return i.value; }) : null; }

		// ── Guardar solo y vista previa ──
		function payload() {
			var p = { id: C.id, subject: subj.value, preheader: pre.value };
			// Cada formato manda solo lo suyo: el contenido del otro se queda como está en la base.
			if (isHtml) p.html_b64 = b64(code()); else p.blocks = wireBlocks(blocks);
			var l = chosenLists();
			if (l) { p.lists = l.length ? l : ['0']; }
			return p;
		}
		function changed(preview, now) {
			dirty = true;
			// El resumen de cada bloque (la línea gris bajo su nombre), al día mientras se escribe.
			if (list) blocks.forEach(function (b, i) { var el = list.children[i]; var sp = el && $('.nm span', el); if (sp) sp.textContent = summary(b); });
			stateEl.textContent = T.unsaved;
			clearTimeout(saveTimer);
			saveTimer = setTimeout(function () { save(); }, now ? 50 : 900);
			if (preview) { clearTimeout(previewTimer); previewTimer = setTimeout(renderPreview, 350); }
			checklist();
		}
		// Un solo guardado a la vez. Si se pide otro mientras uno va, espera a que acabe y guarda lo último
		// (lo que se escribió entretanto): quien llama a save() y espera su promesa siempre sale con todo guardado.
		var inflight = null;
		function save() {
			if (inflight) return inflight.then(function () { return save(); });
			var p = payload();
			var key = JSON.stringify(p);
			if (key === lastSaved) { dirty = false; stateEl.textContent = T.saved; return Promise.resolve(); }
			stateEl.textContent = T.saving;
			inflight = ajax('save_campaign', p).then(function (r) {
				lastSaved = key;
				if (JSON.stringify(payload()) === key) { dirty = false; stateEl.textContent = T.saved; }
				C.problems = r.problems || [];
				var pill = $('#dxo-audience');
				if (pill && r.audience !== undefined) { C.audience = r.audience; pill.textContent = fmt(r.audience === 1 ? S.person : S.people, num(r.audience)); }
				checklist();
			}).catch(function (e) { stateEl.textContent = e.message; toast(e.message, true); }).then(function () { inflight = null; });
			return inflight;
		}
		var lastScroll = 0;
		function renderPreview() {
			try { lastScroll = frame.contentWindow.scrollY || 0; } catch (x) {}
			var pv = { id: C.id, subject: subj.value, preheader: pre.value, format: C.format };
			if (isHtml) pv.html_b64 = b64(code()); else pv.blocks = wireBlocks(blocks);
			ajax('preview', pv).then(function (r) {
				C.warnings = r.warnings || [];
				if (isHtml) C.ownUnsub = !!r.own_unsub;
				checklist();
				frame.onload = function () {
					// La vista previa mide lo que mide el correo: sin barra de scroll dentro de otra.
					try {
						var h = frame.contentDocument.documentElement.scrollHeight;
						frame.style.height = Math.max(420, h) + 'px';
						frame.contentWindow.scrollTo(0, lastScroll);
					} catch (x) {}
				};
				frame.srcdoc = r.html;
			}).catch(function () {});
		}
		window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = T.leave; return T.leave; } });
		lastSaved = JSON.stringify(payload());

		// ── Dispositivo ──
		$$('#dxo-device button').forEach(function (b) {
			b.addEventListener('click', function () {
				$$('#dxo-device button').forEach(function (x) { x.classList.toggle('on', x === b); });
				$('#dxo-stage').classList.toggle('mobile', b.getAttribute('data-d') === 'mobile');
			});
		});

		// ── Antes de enviar ──
		function checklist() {
			var box = $('#dxo-checklist');
			var rows = [];
			var ok = function (html) { rows.push('<div class="ok"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg><span>' + html + '</span></div>'); };
			var warn = function (html) { rows.push('<div class="wa"><svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg><span>' + html + '</span></div>'); };
			var no = function (text) { rows.push('<div class="no"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg><span>' + esc(text) + '</span></div>'); };
			(C.problems || []).forEach(no);
			if (isHtml) {
				// Con su propio enlace de baja el pie es cosa suya; sin él, Orbit pone el suyo (con la dirección).
				if (C.ownUnsub !== undefined) C.ownUnsub ? ok(S.html.own_unsub) : ok(S.html.footer);
				if (C.ownUnsub === false) S.hasAddress ? ok(S.checks_ok.address) : warn(S.checks_bad.address);
				(C.warnings || []).forEach(function (t) { warn(esc(t)); });
			} else {
				ok(S.checks_ok.unsub);
				S.hasAddress ? ok(S.checks_ok.address) : warn(S.checks_bad.address);
				var missingAlt = blocks.some(function (b) { return b.type === 'image' && b.url && !b.alt; });
				missingAlt ? warn(S.checks_bad.alt) : ok(S.checks_ok.alt);
				(C.warnings || []).forEach(function (t) { warn(esc(t)); });
			}
			C.tested ? ok(S.checks_ok.test) : warn(S.checks_bad.test);
			box.innerHTML = rows.join('');
		}

		// ── Prueba ──
		$('[data-ed=test]').addEventListener('click', function () {
			var wrap = document.createElement('div');
			wrap.innerHTML = '<p style="margin:0 0 12px">' + esc(S.test_text) + '</p>';
			var i = document.createElement('input');
			i.className = 'dxo-input'; i.type = 'email'; i.value = C.testTo;
			wrap.appendChild(i);
			modal({
				title: S.test_title, body: wrap,
				buttons: [{ label: T.cancel, kind: 'gray' }, { label: S.send_test, kind: 'dark', onClick: function () {
					return save().then(function () { return ajax('send_test', { id: C.id, to: i.value }); }).then(function () {
						C.testTo = i.value; C.tested = true; checklist(); toast(fmt(T.sent_test, i.value));
					});
				} }]
			});
		});

		// ── Enviar o programar ──
		var launchBtn = $('[data-ed=launch]');
		if (launchBtn) launchBtn.addEventListener('click', function () {
			launchBtn.classList.add('is-busy');
			clearTimeout(saveTimer);
			save().then(function () {
				launchBtn.classList.remove('is-busy');
				if (C.problems && C.problems.length) {
					modal({ title: S.fix_title, body: '<ul class="problems">' + C.problems.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join('') + '</ul>', buttons: [{ label: T.cancel, kind: 'gray' }] });
					return;
				}
				var rate = (D.rate || 0);
				var wrap = document.createElement('div');
				var tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1); tomorrow.setHours(9, 0, 0, 0);
				var pad = function (n) { return (n < 10 ? '0' : '') + n; };
				var def = C.scheduled || (tomorrow.getFullYear() + '-' + pad(tomorrow.getMonth() + 1) + '-' + pad(tomorrow.getDate()) + 'T09:00');
				wrap.innerHTML = '<div class="dxo-choices" style="margin-bottom:12px">'
					+ '<label class="dxo-choice"><input type="radio" name="dxo-when" value="now" ' + (C.scheduled ? '' : 'checked') + '><b>' + esc(S.now) + '</b></label>'
					+ '<label class="dxo-choice"><input type="radio" name="dxo-when" value="later" ' + (C.scheduled ? 'checked' : '') + '><b>' + esc(S.later) + '</b></label></div>'
					+ '<input class="dxo-input" type="datetime-local" data-when value="' + esc(def) + '" ' + (C.scheduled ? '' : 'hidden') + '>'
					+ '<p class="dxo-note" style="margin-top:14px"><span>' + esc(goesTo()) + '</span></p>'
					+ (C.tested ? '' : '<p class="dxo-note" style="color:var(--warn)"><span>' + esc(S.no_test) + '</span></p>');
				var when = $('[data-when]', wrap);
				$$('input[name=dxo-when]', wrap).forEach(function (r) {
					r.addEventListener('change', function () {
						when.hidden = r.value !== 'later' || !r.checked;
						var go = $('.ft .dxo-btn-dark', $('#dxo-modal'));
						if (go) go.textContent = when.hidden ? S.send_now : S.schedule;
					});
				});
				modal({
					title: S.send_title, body: wrap,
					buttons: [{ label: T.cancel, kind: 'gray' }, { label: C.scheduled ? S.schedule : S.send_now, kind: 'dark', onClick: function () {
						var later = $('input[name=dxo-when]:checked', wrap).value === 'later';
						return ajax('launch', { id: C.id, when: later ? when.value : '' }).then(function (r) {
							dirty = false; location.href = r.redirect; return false;
						});
					} }]
				});
			}).catch(function () { launchBtn.classList.remove('is-busy'); });
		});
		function goesTo() {
			var n = C.audience || 0;
			var rate = D.rate || 150;
			var h = n / rate;
			var dur = h < 1 ? fmt(S.minutes, Math.max(1, Math.round(h * 60))) : fmt(S.hours, Math.round(h * 10) / 10);
			return fmt(S.goes_to, fmt(n === 1 ? S.person : S.people, num(n)), num(rate), dur);
		}

		// ── Campaña de código: el editor, importar un archivo y cambiar de formato ──
		function code() { return cm ? cm.getValue() : (codeEl ? codeEl.value : ''); }
		function setCode(v) {
			if (cm) cm.setValue(v);                      // dispara su change → guardar y vista previa
			else if (codeEl) { codeEl.value = v; changed(true); }
		}
		if (isHtml && codeEl) {
			cm = codeMirror(codeEl, function () { changed(true); });
			lastSaved = JSON.stringify(payload());
		}

		var importBtn = $('[data-ed=import]'), importFile = $('#dxo-import-file');
		if (importBtn && importFile) {
			importBtn.addEventListener('click', function () { importFile.value = ''; importFile.click(); });
			importFile.addEventListener('change', function () {
				var f = importFile.files && importFile.files[0];
				if (!f) return;
				if (f.size > 1000000) { toast(S.html.import_big, true); return; }
				var rd = new FileReader();
				rd.onload = function () {
					var text = String(rd.result || '').replace(/^\uFEFF/, '');
					var apply = function () { setCode(text); toast(S.html.imported); };
					if (code().trim()) confirmBox(S.html.import_ask, S.html.import_btn, 'dark').then(function (ok) { if (ok) apply(); });
					else apply();
				};
				rd.readAsText(f);
			});
		}

		// Cambiar de formato no borra nada: el contenido del otro se queda guardado.
		$$('[data-ed=format]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var to = btn.getAttribute('data-to'), X = S.html;
				modal({
					title: to === 'html' ? X.to_html_t : X.to_blocks_t,
					body: '<p style="margin:0">' + esc(to === 'html' ? X.to_html : X.to_blocks) + '</p>',
					buttons: [{ label: T.cancel, kind: 'gray' }, { label: X.switch, kind: 'dark', onClick: function () {
						clearTimeout(saveTimer);
						// Primero todo lo escrito (save espera a un guardado en curso y guarda lo último); si no quedó guardado, no se cambia.
						return save().then(function () {
							if (dirty) throw new Error(T.error);
							return ajax('save_campaign', { id: C.id, format: to });
						}).then(function () {
							dirty = false; location.reload(); return false;
						});
					} }]
				});
			});
		});

		renderList();
		checklist();
		renderPreview();
	}

	// ═══════════════════════════════════════════════════════════════════════
	// Suscriptores
	// ═══════════════════════════════════════════════════════════════════════
	function subscribers() {
		var S = JSON.parse(($('#dxo-sub-strings') || {}).textContent || '{}');
		var tpl = function (id) { var t = $(id); var d = document.createElement('div'); d.appendChild(t.content.cloneNode(true)); return d; };

		function openSub(sub) {
			var box = tpl('#dxo-tpl-sub');
			var isNew = !sub;
			$$('[data-only]', box).forEach(function (el) { el.hidden = el.getAttribute('data-only') !== (isNew ? 'new' : 'edit'); });
			if (sub) {
				$('[name=email]', box).value = sub.email;
				$('[name=first_name]', box).value = sub.first_name;
				$('[name=last_name]', box).value = sub.last_name;
				$$('[name="lists[]"]', box).forEach(function (c) { c.checked = sub.lists.indexOf(parseInt(c.value, 10)) > -1; });
				var cls = { active: 'p-ok', pending: 'p-warn', unsubscribed: 'p-gray', bounced: 'p-bad' }[sub.status];
				$('[data-f=status]', box).innerHTML = '<span class="dxo-pill ' + cls + '"><span class="dot"></span>' + esc(S.statuses[sub.status]) + '</span>';
				$('[data-f=joined]', box).textContent = fmt(S.came, sub.joined, sub.source);
			} else {
				var first = $('[name="lists[]"]', box); if (first) first.checked = true;
			}
			var buttons = [];
			if (sub) {
				buttons.push({ label: S.delete, kind: 'danger', onClick: function () {
					// Primero se cierra esta ficha; la pregunta abre el mismo <dialog> después.
					setTimeout(function () {
						confirmBox(S.del_q, S.delete).then(function (ok) { if (ok) ajax('delete_subscriber', { id: sub.id }).then(function () { location.reload(); }).catch(function (e) { toast(e.message, true); }); });
					}, 0);
				} });
				if (sub.status === 'pending') buttons.push({ label: S.resend, kind: 'gray', onClick: function () { return ajax('resend_confirm', { id: sub.id }).then(function () { toast(S.resent); return false; }); } });
				if (sub.status === 'active') buttons.push({ label: S.unsub, kind: 'gray', onClick: function () { return ajax('subscriber_status', { id: sub.id, status: 'unsubscribed' }).then(function () { location.reload(); }); } });
				if (sub.status === 'bounced') buttons.push({ label: S.reactivate, kind: 'gray', onClick: function () { return ajax('subscriber_status', { id: sub.id, status: 'active' }).then(function () { location.reload(); }); } });
			} else {
				buttons.push({ label: T.cancel, kind: 'gray' });
			}
			buttons.push({ label: S.save, kind: 'dark', onClick: function () {
				var fd = new FormData();
				fd.append('id', sub ? sub.id : 0);
				$$('input', box).forEach(function (i) {
					if ((i.type === 'checkbox') && !i.checked) return;
					fd.append(i.name, i.value);
				});
				return ajax('save_subscriber', fd).then(function () { location.reload(); return false; });
			} });
			modal({ title: sub ? S.edit : S.add, body: box, buttons: buttons });
		}

		function openImport() {
			var box = tpl('#dxo-tpl-import');
			modal({ title: S.import, body: box, buttons: [{ label: T.cancel, kind: 'gray' }, { label: S.import_btn, kind: 'dark', onClick: function () {
				var fd = new FormData();
				var file = $('[name=file]', box).files[0];
				if (file) fd.append('file', file);
				$$('[name="lists[]"]:checked', box).forEach(function (c) { fd.append('lists[]', c.value); });
				if ($('[name=consent]', box).checked) fd.append('consent', '1');
				return ajax('import', fd).then(function (r) {
					toast(fmt(S.imported, num(r.added), num(r.updated), num(r.skipped), num(r.invalid)));
					setTimeout(function () { location.href = location.href.replace(/&import=1/, ''); }, 1600);
					return true;
				});
			} }] });
		}

		function openLists() {
			var box = tpl('#dxo-tpl-lists');
			$$('[data-list]', box).forEach(function (row) {
				var input = $('input', row), id = row.getAttribute('data-list'), orig = input.value;
				input.addEventListener('change', function () {
					if (!input.value.trim() || input.value === orig) return;
					ajax('save_list', { id: id, name: input.value }).then(function () { orig = input.value; toast(T.saved); }).catch(function (e) { toast(e.message, true); });
				});
				$('[data-list-del]', row).addEventListener('click', function () {
					ajax('delete_list', { id: id }).then(function () { row.remove(); toast(T.saved); }).catch(function (e) { toast(e.message, true); });
				});
			});
			$('[data-list-add]', box).addEventListener('click', function () {
				var i = $('[data-list-new]', box);
				if (!i.value.trim()) return i.focus();
				ajax('save_list', { name: i.value }).then(function () { location.reload(); }).catch(function (e) { toast(e.message, true); });
			});
			var dlg = modal({ title: S.lists, body: box, buttons: [{ label: S.done, kind: 'dark' }] });
			dlg.addEventListener('close', function once() { dlg.removeEventListener('close', once); location.reload(); });
		}

		app.addEventListener('click', function (e) {
			var b = e.target.closest('[data-sub]');
			if (b) {
				var a = b.getAttribute('data-sub');
				if (a === 'add') openSub(null);
				if (a === 'import') openImport();
				if (a === 'lists') openLists();
				return;
			}
			var row = e.target.closest('tr[data-subrow]');
			if (row) openSub(JSON.parse(row.getAttribute('data-subrow')));
		});
		if (S.openImport) openImport();
	}

	// ═══════════════════════════════════════════════════════════════════════
	// Formularios
	// ═══════════════════════════════════════════════════════════════════════
	function forms() {
		var form = $('#dxo-form-edit');
		if (!form) return;
		var preview = $('#dxo-form-preview');
		var t;
		function data() {
			var fd = new FormData(form);
			['ask_name', 'show_count', 'enabled'].forEach(function (k) { if (!$('[name=' + k + ']', form).checked) fd.delete(k); });
			return fd;
		}
		function place() {
			var p = ($('[name=placement]:checked', form) || {}).value;
			$$('[data-place]', form).forEach(function (el) { el.hidden = el.getAttribute('data-place').split(' ').indexOf(p) < 0; });
			var tr = $('[name=trigger]', form);
			if (tr) $('[data-unit]', form).textContent = tr.value === 'scroll' ? '%' : tr.options[1].textContent.split(' ').pop();
			preview.classList.toggle('is-bar', p === 'bar');
		}
		function refresh() {
			clearTimeout(t);
			t = setTimeout(function () {
				var fd = data();
				ajax('form_preview', fd).then(function (r) {
					preview.innerHTML = r.html;
					var b = $('.dxo-bar', preview);
					if (b) { b.hidden = false; b.classList.add('is-open'); }
				}).catch(function () {});
			}, 250);
		}
		form.addEventListener('input', refresh);
		form.addEventListener('change', function () { place(); refresh(); });
		$$('[data-radio]', form).forEach(function (s) {
			var name = s.getAttribute('data-radio');
			$$('button', s).forEach(function (b) {
				b.addEventListener('click', function () {
					$$('button', s).forEach(function (x) { x.classList.toggle('on', x === b); });
					$('[name=' + name + ']', form).value = b.getAttribute('data-v');
					refresh();
				});
			});
		});
		place();
		var b0 = $('.dxo-bar', preview);
		if (b0) { b0.hidden = false; b0.classList.add('is-open'); }

		$('[data-form-save]').addEventListener('click', function () {
			var btn = this;
			if (!$('[name=name]', form).value.trim()) { $('[name=name]', form).classList.add('is-error'); $('[name=name]', form).focus(); return; }
			btn.classList.add('is-busy');
			ajax('save_form', data()).then(function (r) { toast(T.saved); setTimeout(function () { location.href = r.redirect; }, 500); })
				.catch(function (e) { btn.classList.remove('is-busy'); toast(e.message, true); });
		});
		var del = $('[data-form-del]');
		if (del) del.addEventListener('click', function () {
			confirmBox(del.textContent.trim() + '?', del.textContent.trim()).then(function (ok) {
				if (ok) ajax('delete_form', { slug: del.getAttribute('data-form-del') }).then(function (r) { location.href = r.redirect; }).catch(function (e) { toast(e.message, true); });
			});
		});
	}

	// ═══════════════════════════════════════════════════════════════════════
	// Ajustes
	// ═══════════════════════════════════════════════════════════════════════
	function settings() {
		var form = $('#dxo-settings');
		if (!form) return;
		var S = JSON.parse(($('#dxo-set-strings') || {}).textContent || '{}');
		var state = $('#dxo-settings-state'), reset = $('[data-settings-reset]');
		var initial = serialize();

		// Pestañas, recordando la elegida en la URL.
		$$('[data-tab]').forEach(function (b) {
			b.addEventListener('click', function () {
				var k = b.getAttribute('data-tab');
				$$('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
				$$('[data-tab-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-tab-panel') !== k; });
				history.replaceState(null, '', location.href.replace(/&tab=[^&]*/, '') + '&tab=' + k);
			});
		});

		function fields() {
			var out = {};
			$$('input[name], select[name], textarea[name]', form).forEach(function (i) {
				if (i.type === 'radio') { if (i.checked) out[i.name] = i.value; return; }
				if (i.type === 'checkbox') { out[i.name] = i.checked ? '1' : '0'; return; }
				out[i.name] = i.value;
			});
			return out;
		}
		function serialize() { return JSON.stringify(fields()); }
		function dirtyCheck() {
			var d = serialize() !== initial;
			state.innerHTML = d ? '<span class="dxo-pill p-warn"><span class="dot"></span>' + esc(S.unsaved) + '</span>' : esc(S.saved);
			reset.hidden = !d;
			return d;
		}
		form.addEventListener('input', dirtyCheck);
		form.addEventListener('change', dirtyCheck);
		window.addEventListener('beforeunload', function (e) { if (dirtyCheck()) { e.preventDefault(); e.returnValue = T.leave; } });
		reset.addEventListener('click', function () { initial = ''; location.reload(); });

		// SES visible solo si está elegido.
		$$('[name=transport]', form).forEach(function (r) { r.addEventListener('change', function () { $('[data-ses]', form).hidden = fields().transport !== 'ses'; speed(); }); });

		// Velocidad y cupo del servidor.
		var range = $('#dxo-speed'), limitIn = $('[name=server_limit]', form);
		function speed() {
			var n = parseInt(range.value, 10), lim = Math.max(1, parseInt(limitIn.value, 10) || 200);
			var ses = fields().transport === 'ses';
			range.max = Math.max(lim, n, ses ? 2000 : 0);
			$('#dxo-speed-pill').textContent = fmt(S.perHour, num(n));
			$('#dxo-qa').style.width = Math.min(100, n * 100 / lim) + '%';
			$('#dxo-qa').textContent = fmt(S.news, num(n));
			$('#dxo-qb').textContent = n < lim ? fmt(S.rest, num(lim - n)) : '';
			var spanLimit = $('[data-limit]'); if (spanLimit) spanLimit.textContent = num(lim);
			var h = S.active / n;
			$('#dxo-eta').textContent = S.active ? (h < 1 ? fmt(S.eta_min, num(S.active), Math.max(1, Math.round(h * 60))) : fmt(S.eta, num(S.active), Math.round(h * 10) / 10)) : '';
			var warn = $('#dxo-speed-warn');
			var bad = !ses && lim - n < 50;
			warn.style.color = bad ? 'var(--bad)' : '';
			$('span', warn).textContent = ses ? S.sesWarn : (bad ? S.badWarn : S.okWarn);
		}
		range.addEventListener('input', speed);
		limitIn.addEventListener('input', speed);
		speed();

		// Logo.
		var lp = $('[data-logo]', form);
		if (lp) {
			$('[data-logo-pick]', lp).addEventListener('click', function () {
				if (!window.wp || !wp.media) return;
				var fm = wp.media({ title: S.logo, button: { text: T.use_image }, library: { type: 'image' }, multiple: false });
				fm.on('select', function () {
					var a = fm.state().get('selection').first().toJSON();
					$('[name=logo_id]', lp).value = a.id;
					var img = $('img', lp); img.src = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url); img.hidden = false;
					$('[data-logo-pick]', lp).textContent = S.change;
					$('[data-logo-clear]', lp).hidden = false;
					dirtyCheck();
				});
				fm.open();
			});
			$('[data-logo-clear]', lp).addEventListener('click', function () {
				$('[name=logo_id]', lp).value = 0; $('img', lp).hidden = true; this.hidden = true;
				$('[data-logo-pick]', lp).textContent = S.choose; dirtyCheck();
			});
		}

		// Guardar.
		$('[data-settings-save]').addEventListener('click', function () {
			var btn = this;
			btn.classList.add('is-busy');
			$$('.is-error', form).forEach(function (i) { i.classList.remove('is-error'); });
			if (fields().transport === 'ses') state.textContent = S.checking;
			ajax('save_settings', fields()).then(function () {
				btn.classList.remove('is-busy');
				$('[name=ses_pass]', form).value = '';
				$('[name=imap_pass]', form).value = '';
				initial = serialize(); dirtyCheck(); toast(T.saved);
			}).catch(function (e) {
				btn.classList.remove('is-busy'); dirtyCheck(); toast(e.message, true);
				if (e.data && e.data.field) {
					var f = $('[name=' + e.data.field + ']', form);
					if (f) {
						var panel = f.closest('[data-tab-panel]');
						if (panel && panel.hidden) $('[data-tab=' + panel.getAttribute('data-tab-panel') + ']').click();
						f.classList.add('is-error'); f.focus();
					}
				}
			});
		});

		// Prueba con lo que hay en pantalla: se guarda antes si hay cambios.
		$('[data-settings-test]').addEventListener('click', function () {
			var btn = this, to = $('#dxo-test-to').value;
			btn.classList.add('is-busy');
			var first = dirtyCheck() ? ajax('save_settings', fields()).then(function () { initial = serialize(); dirtyCheck(); }) : Promise.resolve();
			first.then(function () { return ajax('settings_test', { to: to }); })
				.then(function () { btn.classList.remove('is-busy'); toast(fmt(S.test_ok, to)); })
				.catch(function (e) { btn.classList.remove('is-busy'); toast(e.message, true); });
		});

		// Prueba del buzón de rebotes con lo que hay en pantalla (la contraseña vacía usa la guardada).
		var bt = $('[data-bounce-test]');
		if (bt) bt.addEventListener('click', function () {
			var btn = this, out = $('#dxo-bounce-result'), f = fields();
			btn.classList.add('is-busy');
			$$('.is-error', form).forEach(function (i) { i.classList.remove('is-error'); });
			out.style.color = ''; out.textContent = S.testing;
			ajax('bounce_test', { bounce_email: f.bounce_email, imap_host: f.imap_host, imap_port: f.imap_port, imap_user: f.imap_user, imap_pass: f.imap_pass })
				.then(function (res) { btn.classList.remove('is-busy'); out.style.color = 'var(--ok)'; out.textContent = res.message; })
				.catch(function (e) {
					btn.classList.remove('is-busy'); out.style.color = 'var(--bad)'; out.textContent = e.message;
					var el = e.data && e.data.field ? $('[name=' + e.data.field + ']', form) : null;
					if (el) { el.classList.add('is-error'); el.focus(); }
				});
		});
	}
})();
