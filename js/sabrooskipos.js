/* =====================================================================
   SabrooskiPOS — app del punto de venta táctil
   Usa los endpoints del TakePOS nativo de Dolibarr:
   - ajax.php          → productos por categoría (JSON)
   - invoice.php       → addline / deleteline / delete (factura provisional)
   - pay.php           → cobro (colorbox iframe)
   Y el endpoint propio del módulo:
   - /custom/sabrooskipos/ajax/ajax.php → getModalData / addItem
   ===================================================================== */
(function () {
	'use strict';

	/* Datos inyectados por PHP */
	var CONFIG = window.SABROOSKI_POS || {};
	var BASE = CONFIG.base || '';
	var AJAX = CONFIG.ajaxUrl || (BASE + '/custom/sabrooskipos/ajax/ajax.php');
	var socid = CONFIG.socid || 0;
	var place = '0';

	var currentCat = CONFIG.rootCategoryId ? String(CONFIG.rootCategoryId) : 'all';
	var products = {};     // catId -> array de productos
	var invoiceid = '';    // id de la factura provisional (se obtiene al agregar)
	var cart = [];         // líneas en memoria para el resumen rápido
	var current = null;    // estado del modal de personalización

	/* ---------- helpers ---------- */
	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	/* fetch que devuelve JSON y detecta si el servidor respondió HTML (login/fatal) */
	function fetchJSON(url, opts) {
		return fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}))
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				var ct = (r.headers.get('content-type') || '');
				return r.text().then(function (t) {
					if (ct.indexOf('json') === -1) {
						throw new Error('Respuesta no-JSON (¿sesión caducada?): ' + t.slice(0, 120));
					}
					return JSON.parse(t);
				});
			});
	}

	function el(tag, className, html) {
		var n = document.createElement(tag);
		if (className) { n.className = className; }
		if (html !== undefined) { n.innerHTML = html; }
		return n;
	}

	function token() {
		if (CONFIG.token) { return CONFIG.token; }
		return typeof newToken === 'function' ? newToken() : '';
	}

	function fmtUsd(n) { return '$' + Number(n || 0).toFixed(2); }

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	/* ---------- categorías ---------- */
	function renderCategories() {
		var bar = $('#categories');
		bar.innerHTML = '';

		var all = el('button', 'cat-btn active', 'Todos');
		all.addEventListener('click', function () {
			selectCat(CONFIG.rootCategoryId ? String(CONFIG.rootCategoryId) : 'all', all);
		});
		bar.appendChild(all);

		(CONFIG.categories || []).forEach(function (cat) {
			var b = el('button', 'cat-btn', cat.label);
			b.addEventListener('click', function () { selectCat(cat.rowid, b); });
			bar.appendChild(b);
		});
	}

	function selectCat(id, btn) {
		currentCat = id;
		$$('.cat-btn').forEach(function (b) { b.classList.remove('active'); });
		btn.classList.add('active');
		renderGrid(id);
	}

	/* ---------- productos ---------- */
	function fetchProducts(catId) {
		var url = BASE + '/takepos/ajax/ajax.php?action=getProducts&token=' + token() +
			'&thirdpartyid=' + socid + '&category=' + encodeURIComponent(catId) +
			'&tosell=1&limit=100&offset=0';
		return fetchJSON(url);
	}

	function renderGrid(catId) {
		var grid = $('#productGrid');
		grid.innerHTML = '<p style="grid-column:1/-1;color:#8f7fc7;">Cargando…</p>';

		if (products[catId]) {
			drawProducts(products[catId]);
			return;
		}

		/* "Todos" = unir productos de todas las subcategorías + la categoría raíz */
		if (catId === String(CONFIG.rootCategoryId) || catId === 'all') {
			var cats = CONFIG.categories || [];
			// Incluimos también la categoría raíz por si tiene productos directos.
			var rootId = CONFIG.rootCategoryId ? String(CONFIG.rootCategoryId) : null;
			var sources = cats.map(function (c) { return String(c.rowid); });
			if (rootId && sources.indexOf(rootId) === -1) { sources.push(rootId); }

			if (!sources.length) {
				grid.innerHTML = '<p style="grid-column:1/-1;color:#8f7fc7;">Sin productos.</p>';
				return;
			}
			Promise.all(sources.map(function (cid) {
				return fetchProducts(cid).catch(function () { return []; });
			})).then(function (results) {
				var merged = [];
				var seen = {};
				results.forEach(function (list) {
					(list || []).forEach(function (p) {
						if (!seen[p.id]) { seen[p.id] = 1; merged.push(p); }
					});
				});
				products[catId] = merged;
				drawProducts(merged);
			});
			return;
		}

		fetchProducts(catId)
			.then(function (data) {
				products[catId] = data || [];
				drawProducts(products[catId]);
			})
			.catch(function (err) {
				console.error('getProducts error (' + catId + '):', err);
				grid.innerHTML = '<p style="grid-column:1/-1;color:var(--danger);">No se pudieron cargar los productos. ' +
					esc(err && err.message ? err.message : '') + '</p>';
			});
	}

	function drawProducts(list) {
		var grid = $('#productGrid');
		grid.innerHTML = '';
		if (!list.length) {
			grid.innerHTML = '<p style="grid-column:1/-1;color:#8f7fc7;">Sin productos en esta categoría.</p>';
			return;
		}
		list.forEach(function (p) {
			var card = el('button', 'card');
			card.type = 'button';
			var imgUrl = BASE + '/takepos/genimg/index.php?query=pro&id=' + p.id;
			card.innerHTML =
				'<div class="swatch"><img src="' + imgUrl + '" alt="" loading="lazy" onerror="this.style.display=\'none\'"></div>' +
				'<h3>' + esc(p.label || p.ref || '') + '</h3>' +
				(p.description_short ? '<p></p>' : '') +
				'<div class="price">' + esc(p.price_ttc_formated || '') + '</div>';
			card.addEventListener('click', function () { openProduct(p.id); });
			grid.appendChild(card);
		});
	}

	/* ---------- modal de personalización ---------- */
	function openProduct(idproduct) {
		var url = AJAX + '?action=getModalData&token=' + token() + '&idproduct=' + idproduct;
		var overlay = $('#productOverlay');
		var modal = $('#productModal');
		modal.innerHTML = '<p style="color:#8f7fc7;">Cargando…</p>';
		overlay.classList.add('open');

		fetchJSON(url)
			.then(function (data) {
				if (data.error) {
					modal.innerHTML = '<p style="color:var(--danger);">' + esc(data.error) + '</p>';
					return;
				}
				current = {
					product: data.product,
					flavors: data.flavors || [],
					toppings: data.toppings || [],
					syrups: data.syrups || [],
					selected: { flavors: [], toppings: [], syrups: [] },
					extras: {},       // idproduct -> qty adicional
					qty: 1
				};
				renderModal();
			})
			.catch(function (err) {
				console.error('getModalData error:', err);
				modal.innerHTML = '<p style="color:var(--danger);">No se pudo cargar el producto. ' + esc(err && err.message ? err.message : '') + '</p>';
			});
	}

	function closeProduct() {
		$('#productOverlay').classList.remove('open');
		current = null;
	}

	function toggleChoice(arr, val, max) {
		var idx = arr.indexOf(val);
		if (idx > -1) { arr.splice(idx, 1); return; }
		if (max === 1) { arr.length = 0; arr.push(val); return; }
		if (max > 0 && arr.length < max) { arr.push(val); }
	}

	function chipHtml(opt, selected, max, groupKey, selectedArr) {
		var sel = selectedArr.indexOf(opt) !== -1;
		var blocked = !sel && max > 0 && selectedArr.length >= max;
		var cls = 'chip' + (sel ? ' selected' : '') + ((max === 0 || blocked) ? ' disabled' : '');
		return '<button type="button" class="' + cls + '" data-group="' + groupKey + '" data-val="' + esc(opt) + '">' + esc(opt) + '</button>';
	}

	function chipsRow(options, selectedArr, max, groupKey) {
		return options.map(function (o) {
			return chipHtml(o.label, o, max, groupKey, selectedArr);
		}).join('');
	}

	function renderModal() {
		var p = current.product;
		var modal = $('#productModal');

		var pricePreview = fmtUsd(currentProductPrice() * current.qty);

		var flavorsSection = p.max_sabores > 0
			? '<div class="section-label">Sabores <span class="count">elige hasta ' + p.max_sabores + '</span></div>' +
				'<div class="chips" id="flavorChips">' + chipsRow(current.flavors, current.selected.flavors, p.max_sabores, 'flavor') + '</div>'
			: '';

		var toppingsSection = (p.max_toppings_incluidos > 0 && current.toppings.length)
			? '<div class="section-label">Toppings <span class="count">elige hasta ' + p.max_toppings_incluidos + '</span></div>' +
				'<div class="chips" id="toppingChips">' + chipsRow(current.toppings, current.selected.toppings, p.max_toppings_incluidos, 'topping') + '</div>'
			: '';

		var syrupsSection = (p.max_sirope > 0 && current.syrups.length)
			? '<div class="section-label">Sirope <span class="count">elige hasta ' + p.max_sirope + '</span></div>' +
				'<div class="chips" id="syrupChips">' + chipsRow(current.syrups, current.selected.syrups, p.max_sirope, 'syrup') + '</div>'
			: '';

		var extrasSection = renderExtrasSection();

		modal.innerHTML =
			'<h2>' + esc(p.label) + '</h2>' +
			'<div class="modal-sub">' + esc(p.description || '') + ' · ' + esc(p.price_ttc_formated || '') + '</div>' +
			flavorsSection +
			toppingsSection +
			syrupsSection +
			extrasSection +
			'<div class="qty-row">' +
				'<button class="qty-btn" id="qtyMinus">-</button>' +
				'<span class="qty-val" id="qtyVal">' + current.qty + '</span>' +
				'<button class="qty-btn" id="qtyPlus">+</button>' +
				'<span style="margin-left:auto;font-weight:800;color:var(--purple);font-size:16px;" id="lineTotalPreview">' + pricePreview + '</span>' +
			'</div>' +
			'<div class="modal-actions">' +
				'<button class="btn-ghost" id="cancelProduct">Cancelar</button>' +
				'<button class="btn-primary" id="addProduct">Agregar a la orden</button>' +
			'</div>';

		$$('.chip', modal).forEach(function (chip) {
			chip.addEventListener('click', function () {
				var group = chip.dataset.group;
				var val = chip.dataset.val;
				if (group === 'flavor') { toggleChoice(current.selected.flavors, val, p.max_sabores); }
				if (group === 'topping') { toggleChoice(current.selected.toppings, val, p.max_toppings_incluidos); }
				if (group === 'syrup') { toggleChoice(current.selected.syrups, val, p.max_sirope); }
				renderModal();
			});
		});

		bindExtras();

		$('#qtyMinus').onclick = function () { current.qty = Math.max(1, current.qty - 1); renderModal(); };
		$('#qtyPlus').onclick = function () { current.qty += 1; renderModal(); };
		$('#cancelProduct').onclick = closeProduct;
		$('#addProduct').onclick = confirmAddProduct;
	}

	function currentProductPrice() {
		var p = current.product;
		var num = parseFloat((p.price == null ? 0 : p.price).toString().replace(/[^0-9.\-]/g, ''));
		return isNaN(num) ? 0 : num;
	}

	/* Sección de adicionales: contador +/- por cada topping/sirope disponible */
	function renderExtrasSection() {
		if (!current) { return ''; }
		var options = current.toppings.concat(current.syrups);
		if (!options.length) { return ''; }

		var html = '<div class="section-label">Adicionales <span class="count">opcional</span></div>' +
			'<div class="extras-list" id="extrasList">';
		options.forEach(function (o) {
			var qty = current.extras[o.id] || 0;
			html += '<div class="extra-row">' +
				'<span class="extra-name">' + esc(o.label) + '</span>' +
				'<div class="extra-stepper">' +
					'<button type="button" class="qty-btn" data-extra-minus="' + o.id + '">−</button>' +
					'<span class="qty-val">' + qty + '</span>' +
					'<button type="button" class="qty-btn" data-extra-plus="' + o.id + '">+</button>' +
				'</div>' +
			'</div>';
		});
		html += '</div>';
		return html;
	}

	/* Adherencia de eventos a los contadores de adicionales (tras renderModal) */
	function bindExtras() {
		var wrap = $('#extrasList');
		if (!wrap) { return; }
		$$('[data-extra-plus]', wrap).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.dataset.extraPlus;
				current.extras[id] = (current.extras[id] || 0) + 1;
				renderModal();
			});
		});
		$$('[data-extra-minus]', wrap).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.dataset.extraMinus;
				current.extras[id] = Math.max(0, (current.extras[id] || 0) - 1);
				renderModal();
			});
		});
	}

	function confirmAddProduct() {
		var p = current.product;
		if (current.selected.flavors.length === 0) {
			alert('Selecciona al menos un sabor.');
			return;
		}

		var labels = function (ids, arr) {
			// arr = [{id,label}]; devuelve labels de ids en el mismo orden
			var map = {};
			arr.forEach(function (o) { map[o.id] = o.label; });
			return ids.map(function (id) { return map[id] || id; });
		};

		var flavors = labels(current.selected.flavors, current.flavors);
		var toppings = labels(current.selected.toppings, current.toppings);
		var syrups = labels(current.selected.syrups, current.syrups);

		// Adicionales: convertir objeto {id: qty} a arreglo {idproduct, qty}
		var extras = [];
		Object.keys(current.extras).forEach(function (id) {
			var qty = current.extras[id];
			if (qty > 0) {
				extras.push({ idproduct: parseInt(id, 10), qty: qty });
			}
		});

		var body = new URLSearchParams();
		body.append('action', 'addItem');
		body.append('token', token());
		body.append('idproduct', p.id);
		body.append('place', place);
		body.append('qty', current.qty);
		body.append('flavors', JSON.stringify(flavors));
		body.append('toppings', JSON.stringify(toppings));
		body.append('syrups', JSON.stringify(syrups));
		body.append('extras', JSON.stringify(extras));

		fetchJSON(AJAX, { method: 'POST', body: body })
			.then(function (data) {
				if (data.error) { alert(data.error); return; }
				closeProduct();
				return refreshCart();
			})
			.catch(function (err) {
				console.error('addItem error:', err);
				alert('No se pudo agregar la línea. ' + (err && err.message ? err.message : ''));
			});
	}

	/* ---------- carrito (factura provisional en Dolibarr) ---------- */
	function addLine(idproduct) {
		var url = BASE + '/takepos/invoice.php?action=addline&token=' + token() +
			'&place=' + place + '&idproduct=' + idproduct +
			'&qty=1&selectedline=0&invoiceid=' + invoiceid;

		fetch(url, { credentials: 'same-origin' })
			.then(function () { return refreshCart(); })
			.catch(function () { alert('No se pudo agregar el producto.'); });
	}

	function deleteLine(idline) {
		var url = BASE + '/takepos/invoice.php?action=deleteline&token=' + token() +
			'&place=' + place + '&idline=' + idline + '&invoiceid=' + invoiceid;
		fetch(url, { credentials: 'same-origin' })
			.then(function () { return refreshCart(); })
			.catch(function () { alert('No se pudo eliminar la línea.'); });
	}

	function clearCart() {
		if (!invoiceid) { cart = []; drawCart(); return; }
		var url = BASE + '/takepos/invoice.php?action=delete&token=' + token() + '&place=' + place;
		fetch(url, { credentials: 'same-origin' })
			.then(function () {
				invoiceid = '';
				cart = [];
				drawCart();
			});
	}

	/* Refresca el carrito leyendo los datos JSON del endpoint del módulo */
	function refreshCart() {
		var params = new URLSearchParams();
		params.append('action', 'getCartData');
		params.append('token', token());
		params.append('place', place);
		params.append('invoiceid', invoiceid);

		return fetchJSON(AJAX + '?' + params.toString())
			.then(function (data) { parseCart(data); })
			.catch(function (err) {
				console.error('getCartData error:', err);
			});
	}

	function parseCart(data) {
		/* el id de factura provisional viene en la respuesta */
		if (data && data.invoiceid) {
			invoiceid = data.invoiceid;
			/* mantener el hidden en el DOM (lo usa pay.php vía parent.$('#invoiceid')) */
			var domHid = document.getElementById('invoiceid');
			if (domHid) { domHid.value = invoiceid; }
		}

		var lines = [];
		if (data && data.lines) {
			lines = data.lines.map(function (l) {
				/* Mostramos label + descripción personalizada (sabor/topping/sirope) */
				var desc = l.desc || l.label || '';
				if (l.label && l.desc && l.desc !== l.label) {
					desc = l.label + ' — ' + l.desc;
				}
				return {
					id: l.id,
					desc: desc,
					label: l.label,
					qty: l.qty,
					totalTtc: l.total_ttc_formated || ''
				};
			});
		}

		var totalTxt = (data && data.total_formated) || '$0.00';

		cart = lines;
		drawCart(totalTxt);
	}

	function drawCart(totalTxt) {
		var wrap = $('#cartLines');
		var totalEl = $('#totalUsd');
		var countEl = $('#itemCount');
		var payBtn = $('#payBtn');

		if (!cart.length) {
			wrap.innerHTML = '<div class="empty-cart"><div class="bubble">¡Lo sé! Es difícil elegir</div><div>Toca un producto para armar la orden.</div></div>';
		} else {
			wrap.innerHTML = cart.map(function (line) {
				return '<div class="line"><div class="line-top">' +
					'<div><strong>' + line.qty + 'x ' + esc(line.desc) + '</strong></div>' +
					'<div style="text-align:right;"><div class="line-total">' + esc(line.totalTtc || '') + '</div>' +
					'<button class="line-remove" data-id="' + esc(line.id) + '">Quitar</button></div>' +
					'</div></div>';
			}).join('');

			$$('.line-remove', wrap).forEach(function (btn) {
				btn.addEventListener('click', function () { deleteLine(btn.dataset.id); });
			});
		}

		var qty = cart.reduce(function (s, l) { return s + l.qty; }, 0);
		if (countEl) { countEl.textContent = qty; }
		if (totalEl) { totalEl.textContent = totalTxt || '$0.00'; }
		if (payBtn) { payBtn.disabled = !cart.length; }

		/* barra resumen móvil */
		var barTotal = $('#cartbarTotal');
		var barCount = $('#cartbarCount');
		if (barTotal) { barTotal.textContent = totalTxt || '$0.00'; }
		if (barCount) { barCount.textContent = qty + (qty === 1 ? ' artículo' : ' artículos'); }
	}

	/* ---------- cobro ---------- */
	function pay() {
		if (!invoiceid) { return; }
		var url = BASE + '/takepos/pay.php?place=' + place + '&invoiceid=' + invoiceid;
		if (typeof $.colorbox === 'function') {
			$.colorbox({ href: url, width: '80%', height: '90%', transition: 'none', iframe: true, title: '' });
		} else {
			window.open(url, '_blank');
		}
	}

	/* Tras cerrar el colorbox de pago, refrescar el carrito:
	   si la venta quedó validada, pay.php limpió #invoiceid y conviene
	   recargar la pantalla para empezar una orden nueva. */
	function initPayCloseHook() {
		// Usamos jQuery real (no el helper $ de querySelector) para el evento colorbox.
		var jq = (typeof window.jQuery === 'function') ? window.jQuery : null;
		if (jq) {
			jq(document).on('cbox_closed', function () {
				var hid = document.getElementById('invoiceid');
				if (hid && hid.value === '') {
					window.location.reload();
				} else {
					refreshCart();
				}
			});
		}
	}

	/* ---------- contexto (terminal / cliente) ---------- */
	function initContext() {
		/* los selects de la topbar ya vienen con opciones desde PHP */
		var termSel = $('#terminalSelect');
		var custSel = $('#clienteSelect');
		if (termSel) {
			termSel.addEventListener('change', function () {
				window.location.href = BASE + '/takepos/index.php?setterminal=' + termSel.value + '&sabrooski=1';
			});
		}
		/* cliente: reutiliza el selector de socios del TakePOS (colorbox) */
		if (custSel) {
			custSel.addEventListener('change', function () {
				var v = custSel.value;
				if (v === 'new') {
					if (typeof Customer === 'function') { Customer(); }
				} else if (v) {
					var url = BASE + '/societe/list.php?action=change&token=' + token() +
						'&type=t&contextpage=poslist&idcustomer=' + v + '&place=' + place;
					fetch(url, { credentials: 'same-origin' });
					window.location.reload();
				}
			});
		}
	}

	/* ---------- mobile: toggle del carrito (bottom sheet) ---------- */
	function initMobileCart() {
		var cartEl = $('.cart');
		var bar = $('.cartbar-mobile');
		if (cartEl && bar) {
			bar.addEventListener('click', function (e) {
				if (e.target.closest('.cartbar-pay')) { return; }
				cartEl.classList.toggle('expanded');
			});
		}
		var payBtnBar = $('#cartbarPay');
		if (payBtnBar) { payBtnBar.addEventListener('click', pay); }
		var payBtn = $('#payBtn');
		if (payBtn) { payBtn.addEventListener('click', pay); }
		var clearBtn = $('#clearCart');
		if (clearBtn) { clearBtn.addEventListener('click', clearCart); }

		/* cerrar modal al tocar el fondo */
		var overlay = $('#productOverlay');
		if (overlay) {
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) { closeProduct(); }
			});
		}
	}

	/* ---------- init ---------- */
	function init() {
		renderCategories();
		renderGrid(CONFIG.rootCategoryId ? String(CONFIG.rootCategoryId) : 'all');
		refreshCart();
		initContext();
		initMobileCart();
		initPayCloseHook();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
