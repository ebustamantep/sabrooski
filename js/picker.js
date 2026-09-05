/* =====================================================================
   SabrooskiPOS — picker "Toma de orden"
   Se abre como popup (iframe) dentro de TakePOS.

   Flujo:
   1. Carga categorías reales de la BD (picker/api.php?action=getData).
   2. Cada pestaña = una categoría real; al tocarla lista sus productos.
   3. Al tocar un producto abre el modal de sabores/toppings/siropes.
   4. Al confirmar, agrega la línea al ticket nativo de TakePOS
      (invoice.php addline / addnote) y cierra el popup.

   Usa el MISMO origen de datos que el grid nativo (método getObjectsInCateg),
   con las constantes reales de categorías (sabores=5, toppings=6, siropes=7).
   ===================================================================== */
(function () {
	'use strict';

	var BASE = '', API = '', PLACE = '0', invoiceid = '', TOKEN = '', P_socid = '';
	var products = [];        // productos de la categoría activa
	var current = null;       // estado del modal (producto + selección)
	var flavors = [], toppings = [], syrups = [];
	var catsCache = [];       // categorías ya cargadas (para volver a la rejilla sin re-fetch)
	var activeCatId = null;   // categoría activa al volver del modal

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	function fetchJSON(url, opts) {
		var timeout = new Promise(function (_, reject) {
			setTimeout(function () { reject(new Error('Timeout esperando al servidor')); }, 15000);
		});
		return Promise.race([
			fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}))
				.then(function (r) {
					if (!r.ok) { throw new Error('HTTP ' + r.status); }
					var ct = (r.headers.get('content-type') || '');
					return r.text().then(function (t) {
						if (ct.indexOf('json') === -1) {
							throw new Error('Respuesta no-JSON: ' + t.slice(0, 160));
						}
						return JSON.parse(t);
					});
				}),
			timeout
		]);
	}

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	function fmtUsd(n) { return '$' + Number(n || 0).toFixed(2); }

	/* ---------- cargar datos ----------
	   Las categorías y las listas de sabores/toppings/siropes vienen de picker/api.php.
	   Los PRODUCTOS se piden al endpoint NATIVO del TakePOS (takepos/ajax/ajax.php
	   ?action=getProducts), el MISMO que pinta la rejilla nativa. Así mostramos
	   exactamente lo que ya se ve en el POS nativo. */
	function getNativeProducts(catid) {
		var url = BASE + '/takepos/ajax/ajax.php?action=getProducts&token=' + encodeURIComponent(TOKEN) +
			'&thirdpartyid=' + encodeURIComponent(P_socid || '') +
			'&category=' + encodeURIComponent(catid) + '&tosell=1&limit=100&offset=0';
		return fetchJSON(url);
	}

	function loadData() {
		var root = $('#picker-root');
		root.innerHTML = '<div class="pk-loading">Cargando…</div>';

		fetchJSON(API + '?action=getData&token=' + encodeURIComponent(TOKEN) + '&place=' + encodeURIComponent(PLACE))
			.then(function (data) {
				flavors = data.flavors || [];
				toppings = data.toppings || [];
				syrups = data.syrups || [];

				var cats = data.categories || [];
				if (!cats.length) {
					root.innerHTML = '<div class="pk-error">No hay categorías. Revisa la categoría raíz de TakePOS.</div>';
					return;
				}
				renderCats(cats);
				loadCategoryProducts(cats[0].id);
			})
			.catch(function (err) {
				root.innerHTML = '<div class="pk-error">No se pudo cargar. ' + esc(err && err.message ? err.message : '') + '</div>';
			});
	}

	function renderCats(cats) {
		catsCache = cats || [];
		var root = $('#picker-root');
		var html = '<div class="pk-panel">' +
			'<div class="pk-panel-head"><h2>Toma de orden</h2>' +
			'<button type="button" class="pk-finish" data-finish>Finalizar orden</button>' +
			'<button type="button" class="pk-close" data-close>Cerrar</button></div>' +
			'<div class="pk-layout">' +
			'<div class="pk-main"><div class="pk-cats" id="pkCats">';

		cats.forEach(function (c, i) {
			html += '<button type="button" class="pk-cat-btn' + (i === 0 ? ' active' : '') + '" data-cat="' + c.id + '">' + esc(c.label) + '</button>';
		});

		html += '</div><div class="pk-grid" id="pkGrid"></div></div>' +
			'<div class="pk-cart" id="pkCart"></div>' +
			'</div></div>';
		root.innerHTML = html;

		$$('.pk-cat-btn', root).forEach(function (btn) {
			btn.addEventListener('click', function () {
				$$('.pk-cat-btn', root).forEach(function (b) { b.classList.remove('active'); });
				btn.classList.add('active');
				activeCatId = btn.dataset.cat;
				loadCategoryProducts(btn.dataset.cat);
			});
		});

		bindClose(root);
		bindFinish(root);
		renderCart();
	}

	/* ---------- resumen de la orden (carrito) ---------- */
	// Lee la factura provisional del TakePOS (getCart) y pinta el panel derecho,
	// igual que la columna "Orden actual" de index.html. El botón principal es
	// "Finalizar orden" (cierra y vuelve a TakePOS para cobrar e imprimir).
	function renderCart() {
		var cart = $('#pkCart');
		if (!cart) { return; }

		cart.innerHTML = '<div class="pk-cart-inner">' +
			'<div class="pk-cart-head"><h3>Orden actual</h3>' +
			'<button type="button" class="pk-cart-clear" data-clear>Vaciar</button></div>' +
			'<div class="pk-cart-lines" id="pkCartLines"><div class="pk-empty">Cargando…</div></div>' +
			'<div class="pk-cart-foot">' +
				'<div class="pk-totals-row"><span>Artículos</span><span id="pkItemCount">0</span></div>' +
				'<div class="pk-totals-row pk-grand"><span>Total</span><span id="pkTotal">$0.00</span></div>' +
				'<button type="button" class="pk-btn-finish pk-cart-pay" data-finish>Finalizar orden</button>' +
			'</div>' +
			'</div>';

		fetchJSON(API + '?action=getCart&token=' + encodeURIComponent(TOKEN) + '&place=' + encodeURIComponent(PLACE))
			.then(function (d) {
				var lines = d.lines || [];
				var itemCount = d.itemcount || 0;
				var total = d.total_formated || '$0.00';
				var linesEl = $('#pkCartLines', cart);

				if (!lines.length) {
					linesEl.innerHTML = '<div class="pk-empty">El pedido está vacío.<br>Toca un producto para armar la orden.</div>';
				} else {
					linesEl.innerHTML = lines.map(function (line) {
						var detail = line.desc ? '<div class="pk-line-detail">' + esc(line.desc) + '</div>' : '';
						var label = line.label || line.ref || '';
						return '<div class="pk-line">' +
							'<div class="pk-line-top">' +
								'<div><strong>' + esc(line.qty) + 'x ' + esc(label) + '</strong>' + detail + '</div>' +
								'<div class="pk-line-right">' +
									'<div class="pk-line-total">' + esc(line.total_ttc_formated || '') + '</div>' +
									'<button type="button" class="pk-line-remove" data-remove="' + line.id + '">Quitar</button>' +
								'</div>' +
							'</div>' +
						'</div>';
					}).join('');
				}

				var itemCountEl = $('#pkItemCount', cart), totalEl = $('#pkTotal', cart);
				if (itemCountEl) { itemCountEl.textContent = itemCount; }
				if (totalEl) { totalEl.textContent = total; }
				// Guardamos el total formateado para no re-fetch al finalizar.
				if (cart) { cart.dataset.total = total; }

				bindCartRemove(cart, lines);
			})
			.catch(function () {
				var linesEl = $('#pkCartLines', cart);
				if (linesEl) { linesEl.innerHTML = '<div class="pk-empty">No se pudo cargar la orden.</div>'; }
			});

		// Botones "Vaciar" y "Finalizar orden" del carrito.
		var clear = $('[data-clear]', cart);
		if (clear) { clear.onclick = clearCart; }
		bindFinish(cart);
	}

	function bindCartRemove(cart, lines) {
		$$('[data-remove]', cart).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var lineid = btn.dataset.remove;
				if (!lineid) { return; }
				fetch(API + '?action=removeLine&token=' + encodeURIComponent(TOKEN) +
					'&place=' + encodeURIComponent(PLACE) + '&lineid=' + encodeURIComponent(lineid),
					{ credentials: 'same-origin' })
					.then(function () { renderCart(); })
					.catch(function () { /* carrito no cambió */ });
			});
		});
	}

	function clearCart() {
		// Vaciar el carrito = borrar todas las líneas de la factura provisional.
		var linesEl = $('#pkCartLines');
		var removeBtns = $$('[data-remove]', linesEl);
		if (!removeBtns.length) { renderCart(); return; }
		var chain = Promise.resolve();
		removeBtns.forEach(function (btn) {
			var lineid = btn.dataset.remove;
			if (lineid) {
				chain = chain.then(function () {
					return fetch(API + '?action=removeLine&token=' + encodeURIComponent(TOKEN) +
						'&place=' + encodeURIComponent(PLACE) + '&lineid=' + encodeURIComponent(lineid),
						{ credentials: 'same-origin' });
				});
			}
		});
		return chain.then(function () { renderCart(); });
	}

	function loadCategoryProducts(catid) {
		var grid = $('#pkGrid');
		if (grid) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#8f7fc7;padding:20px;">Cargando…</div>'; }

		getNativeProducts(catid)
			.then(function (data) { renderProducts(data || []); })
			.catch(function (err) {
				if (grid) { grid.innerHTML = '<div style="grid-column:1/-1;color:#e0455a;">No se pudieron cargar los productos. ' + esc(err && err.message ? err.message : '') + '</div>'; }
			});
	}

	function renderProducts(list) {
		var grid = $('#pkGrid');
		if (!grid) { return; }
		products = list || [];

		if (!products.length) {
			grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#8f7fc7;padding:20px;">Sin productos en esta categoría.</div>';
			return;
		}

		/* El endpoint nativo devuelve objetos con id, label, price_ttc_formated,
		   array_options.options_max_*, description. Usamos esas claves. */
		grid.innerHTML = products.map(function (p) {
			var label = p.label || p.ref || '';
			var price = p.price_ttc_formated || p.price_formated || '';
			return '<button type="button" class="pk-card" data-id="' + p.id + '">' +
				'<div class="pk-swatch">' + esc((label)[0] || '') + '</div>' +
				'<h3>' + esc(label) + '</h3>' +
				(price ? '<div class="pk-price">' + esc(price) + '</div>' : '') +
				'</button>';
		}).join('');

		$$('.pk-card', grid).forEach(function (btn) {
			btn.addEventListener('click', function () { openProduct(btn.dataset.id); });
		});
	}

	/* ---------- modal de personalización ---------- */
	function openProduct(idproduct) {
		var root = $('#picker-root');
		root.innerHTML = '<div class="pk-loading">Cargando producto…</div>';

		fetchJSON(API + '?action=getProduct&token=' + encodeURIComponent(TOKEN) + '&idproduct=' + idproduct)
			.then(function (data) {
				if (data.error) { root.innerHTML = '<div class="pk-error">' + esc(data.error) + '</div>'; return; }
				current = {
					product: data.product,
					flavors: data.flavors || [],
					toppings: data.toppings || [],
					syrups: data.syrups || [],
					selected: { flavors: [], toppings: [], syrups: [] },
					extras: {},   // idproduct -> qty adicional
					qty: 1
				};
				// Defaults defensivos (si el producto no trae extrafields de límites):
				// al menos se admite elegir 1 sabor, y si no hay categorías de
				// toppings/siropes, no se muestra la sección.
				var p = current.product;
				if (typeof p.max_sabores === 'undefined' || p.max_sabores === null) { p.max_sabores = 1; }
				if (typeof p.max_toppings_incluidos === 'undefined' || p.max_toppings_incluidos === null) { p.max_toppings_incluidos = 0; }
				if (typeof p.max_sirope === 'undefined' || p.max_sirope === null) { p.max_sirope = 0; }
				renderModal();
			})
			.catch(function (err) {
				root.innerHTML = '<div class="pk-error">No se pudo cargar el producto. ' + esc(err && err.message ? err.message : '') + '</div>';
			});
	}

	function renderModal() {
		var p = current.product;
		var root = $('#picker-root');

		function chipRow(options, selectedArr, max, groupKey) {
			return options.map(function (o) {
				var sel = selectedArr.indexOf(o.label) !== -1;
				var blocked = !sel && max > 0 && selectedArr.length >= max;
				var cls = 'pk-chip' + (sel ? ' selected' : '') + ((max === 0 || blocked) ? ' disabled' : '');
				return '<button type="button" class="' + cls + '" data-group="' + groupKey + '" data-val="' + esc(o.label) + '">' + esc(o.label) + '</button>';
			}).join('');
		}

		var flavorsSection = p.max_sabores > 0
			? '<div class="pk-section-label">Sabores <span class="pk-count">elije hasta ' + p.max_sabores + '</span></div>' +
				'<div class="pk-chips" id="flavorChips">' + chipRow(current.flavors, current.selected.flavors, p.max_sabores, 'flavor') + '</div>'
			: '';

		var toppingsSection = (p.max_toppings_incluidos > 0 && current.toppings.length)
			? '<div class="pk-section-label">Toppings incluidos <span class="pk-count">elije hasta ' + p.max_toppings_incluidos + '</span></div>' +
				'<div class="pk-chips" id="toppingChips">' + chipRow(current.toppings, current.selected.toppings, p.max_toppings_incluidos, 'topping') + '</div>'
			: '';

		var syrupsSection = (p.max_sirope > 0 && current.syrups.length)
			? '<div class="pk-section-label">Sirope incluido <span class="pk-count">elije hasta ' + p.max_sirope + '</span></div>' +
				'<div class="pk-chips" id="syrupChips">' + chipRow(current.syrups, current.selected.syrups, p.max_sirope, 'syrup') + '</div>'
			: '';

		var extrasSection = renderExtras();

		root.innerHTML =
			'<div class="pk-panel pk-modal">' +
			'<div class="pk-panel-head"><button type="button" class="pk-back" data-back>←</button>' +
			'<h2>' + esc(p.label) + '</h2>' +
			'<button type="button" class="pk-close" data-close>Cerrar</button></div>' +
			'<div class="pk-sub">' + (p.description ? esc(p.description) + ' · ' : '') + esc(p.price_formated || '') + '</div>' +
			flavorsSection + toppingsSection + syrupsSection + extrasSection +
			'<div class="pk-qty-row">' +
				'<button class="pk-btn-square" id="qtyMinus">−</button>' +
				'<span class="pk-qty-val" id="qtyVal">' + current.qty + '</span>' +
				'<button class="pk-btn-square" id="qtyPlus">+</button>' +
				'<span class="pk-line-total" id="lineTotalPreview">' + fmtUsd((p.price_ttc || 0) * current.qty) + '</span>' +
			'</div>' +
			'<div class="pk-actions">' +
				'<button type="button" class="pk-btn-finish" data-finish>Finalizar orden</button>' +
				'<button type="button" class="pk-btn-ghost" id="cancelProduct">Cancelar</button>' +
				'<button type="button" class="pk-btn-primary" id="addProduct">Agregar a la orden</button>' +
			'</div>' +
			'</div>';

		$$('.pk-chip', root).forEach(function (chip) {
			chip.addEventListener('click', function () {
				var group = chip.dataset.group, val = chip.dataset.val;
				if (group === 'flavor') { toggleChoice(current.selected.flavors, val, p.max_sabores); }
				if (group === 'topping') { toggleChoice(current.selected.toppings, val, p.max_toppings_incluidos); }
				if (group === 'syrup') { toggleChoice(current.selected.syrups, val, p.max_sirope); }
				renderModal();
			});
		});

		bindExtras();
		bindClose(root);
		bindFinish(root);

		var minus = $('#qtyMinus', root), plus = $('#qtyPlus', root);
		if (minus) { minus.onclick = function () { current.qty = Math.max(1, current.qty - 1); renderModal(); }; }
		if (plus) { plus.onclick = function () { current.qty += 1; renderModal(); }; }

		var back = $('[data-back]', root);
		if (back) { back.onclick = function () { backToGrid(); }; }

		var cancel = $('#cancelProduct', root);
		if (cancel) { cancel.onclick = function () { backToGrid(); }; }

		var add = $('#addProduct', root);
		if (add) { add.onclick = function () { confirmAddProduct(false); }; }
	}

	function toggleChoice(arr, val, max) {
		var idx = arr.indexOf(val);
		if (idx > -1) { arr.splice(idx, 1); return; }
		if (max === 1) { arr.length = 0; arr.push(val); return; }
		if (max > 0 && arr.length < max) { arr.push(val); }
	}

	function renderExtras() {
		if (!current) { return ''; }
		var options = current.toppings.concat(current.syrups);
		if (!options.length) { return ''; }

		var html = '<div class="pk-section-label">Adicionales <span class="pk-count">se cobran aparte, sin límite</span></div>' +
			'<div class="pk-extras" id="extrasList">';
		options.forEach(function (o) {
			var qty = current.extras[o.id] || 0;
			html += '<div class="pk-extra-row">' +
				'<span class="pk-extra-name">' + esc(o.label) + '</span>' +
				'<div class="pk-extra-stepper">' +
					'<button type="button" class="pk-btn-square pk-btn-sm" data-extra-minus="' + o.id + '">−</button>' +
					'<span class="pk-extra-qty">' + qty + '</span>' +
					'<button type="button" class="pk-btn-square pk-btn-sm" data-extra-plus="' + o.id + '">+</button>' +
				'</div>' +
			'</div>';
		});
		html += '</div>';
		return html;
	}

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

	function construirDescripcion() {
		var partes = [];
		if (current.selected.flavors.length) partes.push('Sabor: ' + current.selected.flavors.join(', '));
		if (current.selected.toppings.length) partes.push('Topping: ' + current.selected.toppings.join(', '));
		if (current.selected.syrups.length) partes.push('Sirope: ' + current.selected.syrups.join(', '));
		return partes.join(' · ');
	}

	/* ---------- agregar a la venta de TakePOS ---------- */
	function agregarProducto(idProducto, cantidad, usarInvoiceId) {
		var invid = (usarInvoiceId !== undefined ? usarInvoiceId : invoiceid);
		var url = BASE + '/takepos/invoice.php?action=addline&token=' + encodeURIComponent(TOKEN) +
			'&place=' + encodeURIComponent(PLACE) + '&invoiceid=' + encodeURIComponent(invid) +
			'&idproduct=' + idProducto + '&qty=' + cantidad;
		return fetch(url, { credentials: 'same-origin' });
	}

	/* Resuelve el id real de la factura provisional por la ref (PROV-POS<term>-<place>). */
	function resolveInvoiceId() {
		return fetchJSON(API + '?action=getInvoice&token=' + encodeURIComponent(TOKEN) + '&place=' + encodeURIComponent(PLACE))
			.then(function (d) { return d.invoiceid || 0; })
			.catch(function () { return 0; });
	}

	function idDeUltimaLinea(invid) {
		var id = (invid || invoiceid);
		if (!id) { return Promise.resolve(0); }
		return fetchJSON(BASE + '/takepos/ajax/ajax.php?action=getInvoice&token=' + encodeURIComponent(TOKEN) + '&id=' + encodeURIComponent(id))
			.then(function (d) {
				var lines = d.lines || [];
				return lines.length ? lines[lines.length - 1].id : 0;
			})
			.catch(function () { return 0; });
	}

	function anotarDetalle(idLinea, texto, invid) {
		var body = new URLSearchParams();
		body.append('action', 'addnote');
		body.append('token', TOKEN);
		body.append('invoiceid', (invid || invoiceid));
		body.append('idline', idLinea);
		body.append('addnote', texto);
		return fetch(BASE + '/takepos/invoice.php', { method: 'POST', body: body, credentials: 'same-origin' });
	}

	function confirmAddProduct(shouldClose) {
		var p = current.product;
		if (current.selected.flavors.length === 0) {
			alert('Selecciona al menos un sabor.');
			return;
		}

		var root = $('#picker-root');
		root.innerHTML = '<div class="pk-loading">Agregando a la orden…</div>';

		var desc = construirDescripcion();
		var extras = [];
		Object.keys(current.extras).forEach(function (id) {
			var qty = current.extras[id];
			if (qty > 0) { extras.push({ id: parseInt(id, 10), qty: qty }); }
		});

		agregarProducto(p.id, current.qty, invoiceid)
			.then(function () {
				return resolveInvoiceId().then(function (realId) { return { realId: realId }; });
			})
			.then(function (ctx) {
				invoiceid = ctx.realId || invoiceid;
				if (desc && invoiceid) {
					return idDeUltimaLinea(invoiceid).then(function (idLinea) {
						if (idLinea > 0) { return anotarDetalle(idLinea, desc, invoiceid); }
					});
				}
			})
			.then(function () {
				var chain = Promise.resolve();
				extras.forEach(function (ex) {
					chain = chain.then(function () { return agregarProducto(ex.id, ex.qty, invoiceid); });
				});
				return chain;
			})
			.then(function () {
				// Si se pidió finalizar (debeClose), agregamos y cerramos para cobrar.
				// Si no, NOS quedamos en el popup para seguir agregando productos.
				if (shouldClose) {
					closeAndReload();
				} else {
					showToast('Producto agregado a la orden.');
					backToGrid(); // renderCats() ya refresca el carrito (renderCart).
				}
			})
			.catch(function () {
				root.innerHTML = '<div class="pk-error">No se pudo agregar a la orden. Inténtalo de nuevo.</div>';
			});
	}

	// Vuelve a la rejilla al terminar de agregar un producto, conservando la
	// categoría activa cuando es posible.
	function backToGrid() {
		current = null; // Ya no hay producto en el modal: "Finalizar orden" solo cerrará.
		if (catsCache.length && activeCatId) {
			renderCats(catsCache);
			// Reactivar la pestaña que estaba activa
			var btns = $$('.pk-cat-btn');
			btns.forEach(function (b) { b.classList.remove('active'); });
			var active = btns.filter(function (b) { return b.dataset.cat === activeCatId; })[0];
			if (active) { active.classList.add('active'); loadCategoryProducts(activeCatId); }
			else { loadCategoryProducts(catsCache[0].id); }
		} else {
			loadData();
		}
	}

	// Finalizar orden: cierra el popup y recarga el Ticket de TakePOS para cobrar.
	function closeAndReload() {
		try {
			if (parent && parent.$ && parent.$.colorbox) { parent.$.colorbox.close(); }
			if (parent && parent.location) { parent.location.reload(); }
		} catch (e) {
			var root = $('#picker-root');
			if (root) { root.innerHTML = '<div class="pk-done">Orden agregada. Cierra esta ventana para ver el ticket.</div>'; }
		}
	}

	function bindFinish(root) {
		$$('[data-finish]', root || document).forEach(function (btn) {
			btn.addEventListener('click', function () {
				// Si estamos en el modal (hay un producto configurado), agregamos el
				// producto pendiente y luego cerramos. Si no, cerramos directamente.
				if (current && current.product) {
					confirmAddProduct(true);
				} else {
					closeAndReload();
				}
			});
		});
	}

	// Aviso breve superpuesto (para confirmar que el producto se agregó sin cerrar).
	function showToast(msg) {
		var el = document.createElement('div');
		el.className = 'pk-toast';
		el.textContent = msg;
		document.body.appendChild(el);
		setTimeout(function () { el.classList.add('show'); }, 20);
		setTimeout(function () {
			el.classList.remove('show');
			setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 400);
		}, 1600);
	}

	function bindClose(root) {
		$$('[data-close]', root || document).forEach(function (btn) {
			btn.addEventListener('click', function () { closePopup(); });
		});
	}

	function closePopup() {
		try { if (parent && parent.$ && parent.$.colorbox) { parent.$.colorbox.close(); } }
		catch (e) { /* sin colorbox en el padre */ }
	}

	function init() {
		var cfg = (window.PICKER || {});
		BASE = cfg.base || '';
		API = cfg.api || (BASE + '/custom/sabrooskipos/picker/api.php');
		PLACE = cfg.place || '0';
		invoiceid = cfg.invoiceid || '';
		TOKEN = cfg.token || '';
		P_socid = cfg.socid || '';
		loadData();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
