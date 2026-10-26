# AVANCE — Módulo SabrooskiPOS: toma de pedidos sobre TakePOS nativo

> **Fecha:** 2026-08-31 / 2026-09-05
> **Estado:** funcional. La "toma de orden" está completa y validada en local:
> botón en la barra de TakePOS → popup con categorías/productos reales → modal de
> sabor/topping/sirope → resumen de la orden (carrito) → "Finalizar orden" cierra y
> vuelve al ticket para cobrar. El módulo es portable y a prueba de cambios.
>
> Para la instalación en producción ver el **README.md** del módulo.

---

## 1. Objetivo del proyecto

Personalizar el POS de la heladería Sabrooski (Dolibarr **TakePOS**) con una
pantalla de **"Toma de orden"** que permita:
- Ver las **categorías reales** cargadas en la BD (pestañas, SIN el botón "Todos").
- Al tocar un producto, elegir **sabor / topping / sirope** (con límites por producto).
- Agregar el producto personalizado al ticket nativo de TakePOS (el operador luego
  solo toca "Cobrar").

Restricción importante: **NO reescribir TakePOS** — solo se le engancha un botón y
un popup por hooks. El `takepos/index.php` debe quedar **100% intacto** (idéntico al
backup).

---

## 2. Decisión clave de arquitectura (aprendizaje de todo el día)

**Los productos NO se re-obtienen en PHP.** El POS nativo ya los pinta con su endpoint:

```
takepos/ajax/ajax.php?action=getProducts&token=...&thirdpartyid=<socid>&category=<catid>&tosell=1&limit=100&offset=0
```

Ese endpoint devuelve un array de objetos Product con `id`, `label`, `ref`,
`price_ttc_formated`, `price_formated`, `description`, y los extrafields
(`array_options.options_max_sabores`, `options_max_toppings_incluidos`,
`options_max_sirope`).

Por eso `picker/api.php` **NO lista productos base** (eso sería duplicar el POS).
`api.php` SOLO devuelve:
- **`getData`** → categorías hijas de la raíz + listas de sabores/toppings/siropes.
- **`getProduct`** → detalle de un producto para el modal (extrafields + precio).
- **`getInvoice`** → id de la factura provisional por ref, para anotar el detalle.

El JS (`picker.js`) pide los productos al **endpoint nativo** por categoría.

---

## 3. Estructura actual del módulo

```
custom/sabrooskipos/
├── core/modules/modSabrooskiPOS.class.php   ← descriptor v1.3 (hooks takeposfrontend)
├── class/actions_sabrooskipos.class.php     ← hook ActionButtons + addHtmlHeader
├── picker/
│   ├── index.php                            ← popup (contenedor), inyecta window.PICKER
│   └── api.php                              ← getData / getProduct / getInvoice
├── js/picker.js                             ← lógica: categorías, endpoint nativo, modal
├── css/picker.css                           ← estilos del popup (paleta Sabrooski)
├── langs/es_ES/sabrooskipos.lang            ← textos (TomaDeOrden, etc.)
├── admin/setup.php                          ← configuración de categorías (conservado)
└── sabrooskiposindex.php                    ← ya NO es el POS: redirige a /takepos
```

`picker.js` y `picker.css` usan prefijo `pk-` (linhas pk-cat-btn, pk-card, pk-chip,
pk-section-label, etc.) para no chocar con el resto.

---

## 4. Datos reales verificados en la BD (llx_const)

| Constante | Valor local | Significado |
|---|---|---|
| `TAKEPOS_ROOT_CATEGORY_ID` | 1 | Categoría raíz de productos del TakePOS |
| `SABROOSKIPOS_CATEGORY_FLAVORS` | *configurable* | Categoría de sabores (Setup → Sabrooski POS) |
| `SABROOSKIPOS_CATEGORY_TOPPINGS` | *configurable* | Categoría de toppings |
| `SABROOSKIPOS_CATEGORY_SYRUPS` | *configurable* | Categoría de siropes |
| `MAIN_MODULE_SABROOSKIPOS_HOOKS` | `["takeposfrontend"]` | Hook activo (se crea al ACTIVAR el módulo) |
| `MAIN_MODULE_SABROOSKIPOS` | 1 | Módulo activo |

> **Nota de portabilidad:** los IDs de categoría del módulo se crean VACÍOS al activar.
> En esta BD se configuraron en Setup como `5` (sabores), `6` (toppings), `7` (siropes),
> pero cada instalación tiene sus propios IDs. Por eso **no** van fijos en el descriptor.
>
> La constante de hooks `MAIN_MODULE_SABROOSKIPOS_HOOKS` la genera Dolibarr al activar el
> módulo (no al editar archivos). Si el botón no aparece, **desactivar/reactivar** el
> módulo "Sabrooski POS" (o insertarla con el SQL indicado abajo).
>
> ```sql
> INSERT INTO llx_const (name, type, value, note, visible, entity)
> VALUES ('MAIN_MODULE_SABROOSKIPOS_HOOKS', 'chaine', '["takeposfrontend"]', '', '0', 1)
> ON DUPLICATE KEY UPDATE value = VALUES(value);
> ```

### Categorías reales de producto (hijas de la raíz 1 → son las pestañas)
| id | nombre | productos |
|---|---|---|
| 2 | Conos | 4 |
| 3 | Vasos | 1 |
| 4 | Sundaes | 1 |

### Sabores / toppings / siropes (referencia local)
- Sabores (cat 5): 8 productos
- Toppings (cat 6): 5 productos
- Siropes (cat 7): 4 productos

Productos base (tosell=1): Barquifull, Barquillooski, Cestooski, Maxifull,
Sundooski, Tinakids.

---

## 5. Aprendizajes técnicos críticos (para no repetir errores)

1. **`DOL_URL_ROOT` es RELATIVO en esta instalación** (`/sabrooski`, sin `http://`).
   Al pasar `DOL_URL_ROOT.'/custom/...'` a `top_htmlhead`, este lo re-prefija con
   `dol_buildpath` → genera `/sabrooski/sabrooski/custom/...` (doble prefijo → 404).
   - **Solución:** pasar rutas RELATIVAS (`/sabrooskipos/js/picker.js`) a `top_htmlhead`.
     `dol_buildpath` antepone `DOL_URL_ROOT` y el `/custom` si el archivo vive en custom/.
   - Solo el hook `addHtmlHeader` usa `DOL_URL_ROOT.'/custom/...'` porque NO pasa por
     `top_htmlhead` (el navegador resuelve la ruta y queda bien).

2. **Los módulos custom se sirven bajo `/custom/sabrooskipos/...`** (confirmado con curl:
   `http://localhost/sabrooski/custom/sabrooskipos/js/picker.js`=200; sin `/custom` =404).

3. **Hook real de botones de TakePOS:** `ActionButtons` en contexto **`takeposfrontend`**
   (NO `addMoreActionsButtons` como decía el .md original). El botón se agrega devolviendo
   `$this->results[] = array( array('title'=>..., 'action'=>...) )` (array de arrays),
   y devolviendo `0` para conservar los botones nativos.

4. **El JS del popup se carga al FINAL del `<body>`** (no en el `<head>`), porque
   `window.PICKER` se define en el body. `picker.js` lee `window.PICKER` en `init()`
   (que corre cuando ya está definido).

5. **`Categorie::getObjectsInCateg(..., '(o.tosell:=:1)')`** dio problemas en el picker
   (devolvía vacío). Por eso `api.php` usa **SQL directo** (join `categorie_product` +
   `product` + `product_extrafields`) para sabores/toppings y para el modal. Para los
   productos BASE se usa el endpoint nativo.

6. **`invoiceid` puede ser 0** en una venta nueva. El flujo de "agregar" hace `addline`
   → `api.php?action=getInvoice` (resuelve id por ref `(PROV-POS<term>-<place>)`) →
   `getInvoice` del ajax nativo → `addnote`. Así el detalle sabor/topping sienta bien.

---

## 6. Endpoints nativos reutilizados (NO tocar)

- `takepos/ajax/ajax.php?action=getProducts` → productos de una categoría (listado).
- `takepos/ajax/ajax.php?action=getInvoice&id=X` → factura provisional JSON (para el id
  de la última línea).
- `takepos/invoice.php?action=addline&place=..&invoiceid=..&idproduct=..&qty=..` →
  agrega producto al ticket.
- `takepos/invoice.php?action=addnote` → anota detalle en la línea (POST idline, addnote).
- `sabrooskipos/picker/api.php?action=getCart` → resumen de la orden (líneas + totales).
- `sabrooskipos/ajax/ajax.php?action=removeLine` → quitar una línea del resumen.

---

## 7. Estado final y flujo de la toma de orden

El popup "Toma de orden" funciona así:

1. Se abre desde el botón "Toma de orden" en la barra de acciones de TakePOS.
2. **Columna izquierda**: categorías (pestañas) + grid de productos reales (mismo
   endpoint nativo que usa el POS).
3. Al tocar un producto → **modal** de sabores/toppings/siropes (con límites por
   extrafield) + cantidad + adicionales.
4. **"Agregar a la orden"** añade el producto (con su detalle en la descripción) y
   vuelve a la rejilla sin cerrar, para seguir agregando.
5. **Columna derecha → resumen "Orden actual"**: líneas (`cantidad × producto`,
   detalle, total), contador de artículos, total, botón **Vaciar** y **Quitar** por
   línea. Lee la factura provisional del TakePOS (fuente de verdad).
6. **"Finalizar orden"** cierra el popup y recarga el ticket de TakePOS para cobrar
   e imprimir.

La orden entera cae en la misma factura provisional `(PROV-POS<term>-<place>)`, así
que al finalizar el ticket nativo ya tiene todo armado.

---

## 8. Referencias

- Planteamiento original: `takepos/sabrooski-pos-modulo-dolibarr.md`
- Estructura/diseño: `takepos/sabrooski-pos-estructura.md`
- Maqueta visual: `takepos/sabrooski-pos-demo.html`
- Backup del POS nativo: `C:\laragon\www\sabrooski_backup\takepos_20260829\`

---

## 9. Ajuste al menú real (v1.5)

El menú definitivo de Sabrooski queda documentado en `sabrooski_productos.json`
(derivado de `productos sabrooski.xlsx`). El módulo se alineó a él:

- **Categorías de venta:** Servido en camión, Especial domo, Empaquetado y Adicional
  (esta última oculta como pestaña vía `SABROOSKIPOS_HIDDEN_CATEGORIES`).
- **Sabores:** dos listas — `mix` (6, servidos/especiales) y `bandejas` (14, empaquetado) —
  elegidas según el extrafield `grupo_sabores` del producto.
- **Adicional:** un único producto a precio plano ($0.25) con stepper en el modal; cada
  unidad se agrega como una línea de venta propia.
- **Gramos por porción:** extrafield `gramos_porcion`, informativo en la descripción/ticket.
- **Toppings/sirope:** ya no son seleccionables (ingredientes fijos de la receta).

Piezas modificadas:
- `core/modules/modSabrooskiPOS.class.php` — v1.5; extrafields `grupo_sabores` y
  `gramos_porcion`; constantes `SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS` y
  `SABROOSKIPOS_PRODUCT_ADICIONAL`.
- `admin/setup.php` — configuración por terminal de sabores mix/bandejas + Producto Adicional.
- `admin/cargar_catalogo.php` — carga idempotente del catálogo desde el JSON (nueva).
- `picker/api.php` y `js/picker.js` — modal según grupo de sabores + stepper Adicional.
- `lib/sabrooskipos.lib.php` — pestaña "Cargar catálogo" en el Setup.

---

## 10. Continuación: carga del catálogo + correcciones (2026-10-08)

Se ejecutó la carga real del catálogo y se corrigieron bugs encontrados:

- **IVA 16%:** `SABROOSKIPOS_TVA_TX` (nueva constante, default `16`). Los `precio_usd`
  del JSON se guardan como **precio final TTC** con IVA 16% (verificado: `price_ttc`
  = precio del JSON, `tva_tx` = 16). Configurable en **Setup → (pestaña global)**.
- **Carga ejecutada** (idempotente): categorías nuevas `8 Servido en camión`,
  `9 Especial domo`, `10 Empaquetado`, `11 Adicional`, `12 Sabores mix`,
  `13 Sabores bandejas`; **36 productos** (`16 PV-*` + `6 SM-*` + `14 SB-*`).
- **Bug corregido — definiciones de extrafields:** las llamadas a `addExtraField()`
  estaban desalineadas (faltaba el argumento `$entity=''`), por lo que
  `grupo_sabores` y `gramos_porcion` creaban la columna pero **no** la definición en
  `llx_extrafields` (y `insertExtraFields()` los descartaba). Corregido en
  `lib/sabrooskipos.lib.php` y en `core/modules/modSabrooskiPOS.class.php`.
- **`EnsureProduct` ahora actualiza extrafields de productos existentes** al re-ejecutar,
  para que la carga converja (antes solo creaba y saltaba los existentes).
- **Runner CLI nuevo:** `test/cargar_catalogo_cli.php` (usa la MISMA función
  `sabrooskiposLoadCatalogo()` que la página admin). Permite cargar/verificar sin navegador:
  `php custom/sabrooskipos/test/cargar_catalogo_cli.php`.
- **Constantes fijadas:** `SABROOSKIPOS_CATEGORY_FLAVORS=12`,
  `SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS=13`, `SABROOSKIPOS_PRODUCT_ADICIONAL=59`,
  `SABROOSKIPOS_HIDDEN_CATEGORIES=11,2,3,4` (oculta Adicional + legacy Conos/Vasos/Sundaes).

**Pendiente:** prueba funcional en TakePOS (popup) y decisión sobre las categorías legacy
2/3/4, que siguen visibles en el **grid nativo** del TakePOS (el popup ya las oculta).

---

## 11. Adicionales como lista + fixes del popup (2026-10-08)

- **Adicionales = lista seleccionable:** la sección "Adicionales" del modal ahora
  lista **varios** adicionales, cada uno con `+ / −` y su propio precio; se suman como
  líneas aparte.
  - Fuente (sin duplicados): categorías **Topping** (`SABROOSKIPOS_CATEGORY_TOPPINGS`),
    **Sirope** (`SABROOSKIPOS_CATEGORY_SYRUPS`) y **Adicional**
    (`SABROOSKIPOS_CATEGORY_ADICIONAL`). Hoy: 5 toppings + 4 siropes + 1 genérico = 10.
  - Se reincorporaron los campos Toppings/Sirope en el Setup por terminal.
  - La lista se muestra en **dos columnas** (una sola en pantallas angostas ≤520px).
- **Total del modal:** `lineTotalPreview` ahora suma producto × cantidad **+ los
  adicionales seleccionados** (antes solo contaba el producto base).
- **Fix "Unexpected token '<'":** al renombrar `pickerGetAdicional()` quedaron llamadas
  al nombre viejo (fatal PHP → HTML en vez de JSON). Corregido:
  `pickerAdicionalOptions()` devuelve la lista en `adicionals`.
- **Cache-buster por hash de contenido** (md5) en `picker.js`/`picker.css`
  (`picker/index.php`), para no servir versiones intermedias cacheadas.
- **Categorías legacy 2/3/4 eliminadas**; 4 productos viejos usados en documentos
  (BARQ-110, BARQ-160, CEST-120, SUND-170) quedaron sin categoría (no aparecen en el POS).

**Pendiente:** prueba funcional del popup en TakePOS.

---

## 12. Migración de datos a producción (temporal)

Herramientas **temporales** para clonar categorías + productos + constantes entre
instalaciones, por **label/ref** (independiente de IDs). Eliminar tras las pruebas.

- `admin/catalogo_export.php` (local): genera y descarga `catalogo_export_*.json` con
  categorías de producto, productos (categorías, precio TTC, `tosell`, descripción y
  **todos los extrafields**), las **definiciones** de extrafields de producto y las
  constantes del módulo.
- `admin/catalogo_import.php` (producción): sube el JSON (o usa
  `custom/sabrooskipos/catalogo_export.json`); crea categorías (por jerarquía de labels),
  productos (por ref, precio TTC con IVA `SABROOSKIPOS_TVA_TX`), las definiciones de
  extrafields que falten, y fija las constantes resolviendo IDs por label/ref. Idempotente.
- Lógica en la lib: `sabrooskiposExportCatalogo()` / `sabrooskiposImportCatalogo()`.
  Prueba CLI: `test/migracion_cli.php` (export+import en local; 10 categorías, 53
  productos, 5 extrafields; re-import sin cambios).

**Uso:** en local → abrir `.../custom/sabrooskipos/admin/catalogo_export.php` → *Descargar
JSON*. En producción → `.../custom/sabrooskipos/admin/catalogo_import.php` → subir el JSON
→ *Importar*.

**Nota producción:** requiere Dolibarr + TakePOS instalados y el módulo **activado**
(para crear extrafields/permisos). No viajan en el JSON: `TAKEPOS_NUM_TERMINALS`, almacenes,
usuarios y datos de empresa; ajustarlos aparte.

---

## 13. Catálogo según planillas reales + réplica del turno 10/10/2026 (2026-10-18)

Fuente: `Sabrooski_Planillas_10-10-2026.xlsx` (2do turno, Movil01PM, tasa Bs 900).
Respaldo de la BD previo: `C:\laragon\www\sabrooski_backup\sabrooski_db_antes_turno_20261010.sql`.

**`test/adaptar_catalogo_planilla_cli.php`** (idempotente):
- Bandejas 500 g (`PV-020-01..14`) y Sundae congelado (`PV-022-01..07`) = **un producto por
  sabor**, con stock propio; nuevas pestañas "Bandejas 500 g" y "Sundae congelado". Se
  borraron los genéricos `PV-020` / `PV-022`.
- Toppings (9) y siropes (4) reales a $0,25, IVA 16%, inventario en gramos.
- Golosinas: Samba $1,00 (deducido del P/M de Bs 630 = $0,70 con 30% desc.); Flaquito,
  Pingüinito y Coquito quedan **fuera de venta hasta tener precio**.
- Insumos (categoría "Insumos", no se venden): domos, cucharillas, servilletas, mixes, etc.
- Especiales domo y Adicional sin stock propio (`stockable_product = 0`).
- Viejos: borrados SAB-* y siropes sin uso; BARQ/CEST/SUND desactivados (tienen facturas).
- Config: `CASHDESK_ID_WAREHOUSE2` = Movil01PM; forma de pago **EFBS (Efectivo Bs)** →
  cuenta Efectivo Bs (pasada a cuenta corriente: una cuenta caja solo acepta LIQ);
  `TAKEPOS_DELAYED_PAYMENT = 1` (ventas a crédito/delivery).
- Popup: los adicionales solo se ofrecen en productos `grupo_sabores = mix`.

**`test/replicar_turno_20261010_cli.php`** (no repetible; restaurar respaldo para rehacer):
57 tickets `TC2-2610-0001..0057` con fecha/hora del turno: 17 P/M (refs y Bs exactos),
34 transacciones de punto de venta cuadradas por lote y crédito/débito, efectivo $5 y
Bs 4.200, 4 delivery a crédito ($70, 14 bandejas + 40 conos a $0). Cada ticket guarda la
tasa efectiva (Bs ÷ $). Stock de Movil01PM: existencia, reposición, regalía, dañadas y
ajuste al cierre escrito → todos los productos cuadran con la planilla.

---

## 14. Migración completa a producción + ReportesVentas (v1.6, 2026-10-18)

**Paquete de migración (temporal)** — `lib/sabrooskipos_migracion.lib.php`:
- `admin/migracion_export.php` (local) → JSON con catálogo (ahora converge en destino:
  etiqueta, precio, IVA, venta/compra, stock propio, categorías exactas y `retired_refs`),
  configuración del POS y del popup por ref/label (almacenes y cuentas por terminal,
  formas de pago, monedas, categorías del popup, incluida la config por terminal) y los
  turnos del rango (tickets con líneas, pagos, movimientos bancarios y de stock, con hora).
- `admin/migracion_import.php` (pestaña **Migración** del Setup) → **Simular** (todo dentro
  de una transacción que se revierte, con chequeo de que nada quedó guardado) o
  **Importar**. No duplica turnos. Botón **Reactivar módulo** que conserva permisos.
- CLI: `test/migracion_paquete_cli.php export|import [--simular] [--db=bd]`,
  `test/reactivar_modulo_cli.php`.
- Probado contra `sabrooski_prodtest` (clon del respaldo previo): bancos por cuenta/lote,
  horas, tickets, stock de Movil01PM y catálogo idénticos a local.
- Aprendizajes: `addExtraField()` hace ALTER TABLE (commit implícito en MySQL) → ahora
  solo se llama si falta el campo. `llx_facture.date_valid` es DATE (sin hora): la hora
  del ticket está en `datec`. `Categorie::TYPE_PRODUCT` vale `'product'`, en SQL usar `0`.
  `activateModule()` justo después de `unActivateModule()` necesita `$noconfverification=1`.
  Desactivar el módulo borra los permisos asignados (el runner los guarda y restaura).

**ReportesVentas** (menú superior; permiso `sabrooskipos->reportes->read`) —
`reportes/index.php` + `lib/sabrooskipos_reportes.lib.php`. Por fecha + terminal:
Camión congelados (por sabor, con conos "+N conos"), Pago móvil y puntos (lotes y
crédito/débito desde "Lote X · Tipo"), Especificaciones de ventas (+ resumen de cierre y
ventas a crédito), Inventario de ventas. Excel (.xlsx) por reporte o las 4 planillas.
Reglas de stock por etiqueta del movimiento: "Existencia inicial…", "Reposición/entradas",
"Regal…", "Dañad…", "Crédito…", "Ajuste…". Para el 10/10 cuadra con la planilla en papel.

Entregables: `C:\laragon\www\sabrooski_deploy\module_sabrooskipos-1.6.zip` y
`sabrooskipos_paquete_20261010.json`.

---

## 15. Topping y Sirope genéricos + recibo explícito (v1.7, 2026-10-21)

Respaldo previo de la BD: `C:\laragon\www\sabrooski_backup\sabrooski_db_antes_topping_generico.sql`.

- **Popup:** la sección de adicionales muestra solo dos filas con cantidad, **Topping** y
  **Sirope** (el cliente elige cuál en la barra). `pickerAdicionalOptions()` lee solo la
  categoría Adicional, productos en venta, ordenados por ref.
- **Catálogo** (`test/adaptar_catalogo_planilla_cli.php`, re-ejecutado; idempotente):
  `PV-031 Topping` y `PV-032 Sirope` a $0,25, IVA 16%, categoría Adicional, sin stock propio.
  Los 9 toppings y 4 siropes reales pasan a `tosell = 0` (solo inventario en gramos; el
  consumo sigue saliendo de la diferencia del turno). `PV-030 Adicional` fuera de venta
  (conserva su categoría por las ventas del 10/10). Ojo: re-ejecutar `cargar_catalogo` lo
  reactivaría.
- **Detalle de la línea** (lo anota el popup): producto → `(Chocolate, Fresa) 1 Topping
  1 Sirope`; cada línea de topping/sirope → `para Barquilla`. Ya no se anotan los gramos.
- **Recibo:** el `receipt.php` nativo solo imprime el nombre del producto, así que el hook
  `TakeposReceipt` (`class/actions_sabrooskipos.class.php`) le suma en memoria el detalle
  (`sabrooskiposDetalleRecibo()` en la lib; también convierte las líneas viejas
  "Sabor: X · 82 g" a "(X)"). Resultado:
  `Barquilla (Chocolate, Fresa) 1 Topping 1 Sirope` / `Topping para Barquilla` /
  `Sirope para Barquilla`.
- **Prueba:** `test/recibo_detalle_cli.php` (casos de formato, adicionales del popup y una
  factura de prueba revertida pasando por el hookmanager) → Todo OK.

---

## 16. Depuración del catálogo + exportación sin turnos (2026-10-21)

Respaldo previo de la BD: `C:\laragon\www\sabrooski_backup\sabrooski_db_antes_depurar_productos.sql`.
El turno 10/10 se deja como está (el usuario borró a mano los tickets 0046..0057; quedan
15 movimientos de stock de esos tickets, sin factura).

**`test/depurar_productos_cli.php [--simular]`** (idempotente; el turno TC2-2610 no se toca):
- Borra los 5 tickets del prototipo `TC1-2609-*` con líneas, pagos, líneas de banco (CASH-POS),
  vínculos, PDFs y sus movimientos de stock, y el traslado de prueba TS-20260905-0001.
  El stock por almacén se corrige restando el efecto de cada movimiento borrado.
- Borra BARQ-110, BARQ-160, CEST-120 y SUND-170.
- Sabores SM-*/SB-* sin control de stock; toppings/siropes reales a $0 (solo insumos).
- **Ejecutado** en local: 5 tickets, 5 pagos/líneas de banco, 12 movimientos + traslado,
  4 productos borrados, 33 ajustes. Turno 10/10 intacto (45 tickets), sin existencias negativas.
- JSON definitivo: `C:\laragon\www\sabrooski_deploy\sabrooskipos_catalogo_config_20261021.json`
  (84 productos, 22 constantes, 0 turnos; simulado contra `sabrooski_prodtest` sin errores).

**Exportación solo catálogo + configuración:**
- `sabrooskiposExportPaquete(..., $conTurnos = true)`; en `admin/migracion_export.php`
  la casilla **Incluir turnos** viene desmarcada (archivo `sabrooskipos_catalogo_config_AAAAMMDD.json`);
  CLI: `test/migracion_paquete_cli.php export <archivo> --sin-turnos`.
- Los productos de `retired_refs` ya no viajan como producto, solo como "a retirar"; se agregó
  `PV-030 Adicional`. `SABROOSKIPOS_PRODUCT_ADICIONAL` pasa a PV-031 Topping.
- Simulado contra `sabrooski_prodtest`: 2 productos creados (PV-031/032), 13 toppings/siropes
  actualizados, PV-030 desactivado (y sin categorías), 22 constantes, 0 tickets, 0 errores.

**Despliegue:** `C:\laragon\www\sabrooski_deploy\module_sabrooskipos-1.7.zip` (48 archivos, sin
planillas, capturas, zips ni .git). Orden en producción: instalar el zip → importar el JSON de
catálogo + configuración en Setup → Sabrooski POS → Migración (primero **Simular**).


---

## 17. Servidos por receta, carga del camión, cierre y costos (v1.8, 2026-10-26)

Pedido tras las pruebas en caliente: los servidos se controlan por insumos (vasos, conos,
mix por sabor, sirope, topping, cucharillas, servilletas), los congelados por unidad.
Fuente: `Estructura de Costos Sistema.xlsx` → `recetas_sabrooski.json`. Plan aprobado en
`C:\Users\Edgar\.claude\plans\eventual-enchanting-seal.md`. Respaldo previo:
`C:\laragon\www\sabrooski_backup\sabrooski_db_antes_recetas.sql`.

Decisiones del usuario: sirope, topping y pirulín/oreo (el cliente elige en la barra) no se
descuentan al vender, se comparan en total contra el conteo de cierre; sabores combinados
mitad y mitad; adicional Topping 15 g y Sirope 10 g; productos nuevos incluidos; costos con
reporte de margen; carga = traslado desde Principal.

- **Recetas** (`lib/sabrooskipos_recetas.lib.php`, `test/cargar_recetas_cli.php [--simular]`,
  idempotente): 21 productos nuevos (15 insumos, 6 de venta fuera de venta hasta tener
  precio), costos en `cost_price`, extrafields `gramos_sirope`, `gramos_topping`,
  `uds_pirulin_oreo`, `unidad_control` (producto) y `sabrooski_sabores` (línea de factura);
  `gramos_porcion` = gramos de mix. Kits nativos (`incdec = 1`) para los insumos fijos; los
  SM-* son kits de mix (`incdec = 0`, solo reparto). `PRODUIT_SOUSPRODUITS = 1`. Existencia
  propia de Sundae/Barquilla/Barquillón/Tinita puesta en 0 ("pasa a receta").
  Mix y siropes en gramos; Oreo y Pirulín en unidades.
- **Al vender**: Dolibarr descuenta el kit; el trigger
  `core/triggers/interface_99_modSabrooskiPOS_Recetas.class.php` descuenta el mix según los
  sabores que guarda el popup (`setLineSabores`), devuelve en notas de crédito y revierte en
  anular/borrar. Dolibarr no permite existencias negativas: **sin insumos cargados en el
  móvil no se puede vender**.
- **Carga y cierre** (`carga/index.php`, `carga/cierre.php`, permiso `sabrooskipos->carga->write`,
  menú ReportesVentas): Existencia inicial / Reposición = traslado movilstock
  (`movilstockCrearTraslado()`, extraído de `movilstockindex.php`); Entrada a Principal;
  conteo de cierre → "Consumo del turno (conteo)" (siropes/toppings) o "Ajuste cierre (conteo)".
- **Reportes**: "Insumos del móvil" (real = existencia + carga − regalías/dañadas − cierre
  contra teórico; sirope, topping y pirulín/oreo en total) y "Costos y margen" (ventas del
  turno + costo por receta).
- **Pruebas** (todo revertido): `test/recetas_cli.php` (ticket + nota de crédito + costos vs
  Excel) y `test/ciclo_turno_cli.php` (entrada → carga → venta → cierre → reportes) → Todo OK.
- **Migración**: el catálogo lleva `cost_price`, kits y constantes. Corregido: los extrafields
  se crean antes de la transacción (`sabrooskiposImportExtrafields()`); antes un ALTER TABLE
  confirmaba la transacción y "Simular" guardaba todo. `sabrooski_prodtest` se reconstruyó
  desde `sabrooski_db_antes_topping_generico.sql` + paquete 20261021 (réplica de producción) y
  la simulación del paquete nuevo da 0 errores.
- **Bug corregido**: "Quitar"/"Vaciar" del carrito del popup no hacían nada (`removeLine` no
  existía en `picker/api.php`).
- Diferencias con el Excel: cesta $0,30 (tabla de insumos) vs $0,25 (receta); el Especial
  Flaquito dice "3 uds" pero su costo equivale a 2.

**Despliegue:** `C:\laragon\www\sabrooski_deploy\module_sabrooskipos-1.8.zip` y
`sabrooskipos_catalogo_config_20261026.json`. En producción: instalar el zip → reactivar el
módulo (trigger, permiso y menús) → Migración: Simular e Importar → cargar el inventario de
Principal y la existencia inicial del móvil antes de vender.

### 17.1 Inventario de prueba para pruebas en caliente (local, 2026-10-26)

Respaldo previo: `C:\laragon\www\sabrooski_backup\sabrooski_db_antes_inventario_pruebas.sql`.
`test/inventario_pruebas_cli.php [--simular]`: entrada a Principal de dos cargas típicas y
"Existencia inicial" de una carga típica en Movil01AM y Movil01PM (se suma a lo que había):
mix 10 kg por sabor, 200 uds por envase, 2 kg por sirope, 1 kg por topping (Oreo y Pirulín
100 uds), golosinas 30 (brownie 20), cucharillas y servilletas 500, congelados 10 por sabor.
Traslados `_TS-20261026-0001` (AM) y `_TS-20261026-0002` (PM).

Bugs encontrados y corregidos:
- `sabrooskiposRegistrarCarga()` usaba `dol_stringtotime()` sin cargar `date.lib.php`
  (la planilla de carga habría fallado).
- movilstock: la numeración de traslados y producciones no encontraba las refs del día (la
  máscara empieza con "/" y la ref se guarda con "_"), así que todas salían "-0001". Se
  corrigió y se renumeró el traslado duplicado del PM.

### 17.2 Popup más rápido al agregar a la orden (2026-10-26)

Agregar un producto con sus adicionales hacía ~11 peticiones en serie (6 a
`takepos/invoice.php`, que pinta el ticket completo) + 2 para refrescar: Sundae + Topping +
Sirope tardaba varios segundos. Ahora:
- `picker/api.php` **action=addOrder** (POST, una sola petición): crea la factura provisional
  si falta y agrega el producto (con su detalle y sabores en `sabrooski_sabores`) y cada
  adicional ("para <producto>") con la misma lógica de precio, IVA, descuento del cliente y
  costo (`MARGIN_TYPE`) que `takepos/invoice.php` action=addline; devuelve el carrito.
- El popup pinta el carrito con esa respuesta y guarda en memoria los productos de cada
  pestaña: al volver a la rejilla no hay más peticiones. También recuerda la pestaña activa
  desde el inicio (antes, el primer agregado recargaba todo con `loadData()`).
- Probado con un arnés CLI (transacción sin confirmar): 1 petición, ~400 ms con el arranque de
  Dolibarr; líneas, precios, IVA y sabores correctos.

## 18. v1.9: sin MovilStock (2026-10-26)

MovilStock sobraba: solo se usaba `movilstockCrearTraslado()` desde la carga del camión y
la constante `MOVILSTOCK_ID_WAREHOUSE_MAIN`; sus pantallas no se usan en producción (1
traslado, 0 producciones). Ahora:
- `sabrooskiposTraslado()` (`lib/sabrooskipos_recetas.lib.php`): por producto, salida de
  Principal y entrada al móvil con el mismo `inventorycode` `CARGA-AAAAMMDDhhmmss-xxxx` y
  etiqueta "Existencia inicial dd/mm/aaaa · CARGA-…" o "Reposición …" (los reportes siguen
  leyendo la etiqueta). Revisa existencia en Principal antes de mover. Ya no se escribe en
  `llx_movilstock_transfer`.
- `sabrooskiposAlmacenPrincipal()`: `SABROOSKIPOS_ID_WAREHOUSE_MAIN`; si falta, copia el de
  MovilStock. También lo copia `init()` del módulo al activarlo. Se elige en Setup →
  Sabrooski POS → Configuración y viaja en el paquete de migración (por ref del almacén).
- Carga del camión: tabla "Últimas cargas" del almacén (por `inventorycode` `CARGA-`/
  `ENTRADA-`) con enlace a la lista de movimientos de Dolibarr.
- Local: MovilStock desactivado (sin menús ni permisos); sus tablas y movimientos
  anteriores quedan. `test/ciclo_turno_cli.php` (ahora verifica también la salida de
  Principal y los movimientos `CARGA-`) y `test/recetas_cli.php` → Todo OK.
  Respaldo previo: `sabrooski_db_antes_v19.sql`.

**Despliegue:** `C:\laragon\www\sabrooski_deploy\module_sabrooskipos-1.9.zip`. En producción:
instalar el zip → reactivar Sabrooski POS (copia el almacén principal) → revisar en Setup →
Sabrooski POS que el almacén principal sea el correcto → desactivar MovilStock. Si la 1.8
aún no está en producción, importar también `sabrooskipos_catalogo_config_20261026.json`
(Simular e Importar).
## 19. v1.10: regalías y dañadas con PIN (2026-10-26)

Decisiones del usuario: las registra **el encargado al cierre**; necesitan **autorización
con PIN**; deben ser un **proceso completo** (un servido regalado o caído baja su receta).
El crédito queda pendiente (el usuario va a consultar cómo se cobra).

- `carga/bajas.php` (menú SabrooskiVentas → Regalías y dañadas, permiso `carga->write`):
  móvil + fecha del turno; tipo (Regalía/Dañada), producto (venta + insumos, sin sabores ni
  adicionales), sabores si el producto es de mix (respeta `max_sabores`), cantidad,
  motivo/a quién y PIN. Lista del turno con costo, quién autorizó y botón Anular (con PIN).
- PIN: permiso nuevo `bajas->autorizar`; cada autorizador fija el suyo en
  `carga/pin.php` (menú "Mi PIN de autorización"), 4–8 dígitos, `password_hash` en
  `llx_user_param` (SABROOSKIPOS_PIN), único entre autorizadores; para cambiarlo pide el
  actual. 5 PIN errados bloquean 10 min (por sesión).
- `lib/sabrooskipos_bajas.lib.php`: `sabrooskiposBajaRegistrar()` baja con `correct_stock`
  lo que da `sabrooskiposBajaMovimientos()` (servido: kit + mix por sabor vía
  `sabrooskiposConsumoLinea`; sin receta: el producto), etiqueta "Regalía: …" / "Dañadas:
  …" e inventorycode `BAJA-…`, fechado a las 21:00 del turno; guarda la fila en
  `llx_sabrooskipos_baja` con el costo de receta. `sabrooskiposBajaAnular()` devuelve lo
  neto con "Regalía anulada: …" (`BAJA-…-A`) y marca status 9.
- Reportes: regalías/dañadas por etiqueta ahora netas (las anuladas restan) y fuera de
  Reposición; las de servidos se cuentan desde la tabla (Inventario de ventas y Camión);
  Insumos suma al teórico el sirope/topping/pirulín de las bajas (no se mueven: se ven en
  el conteo); Costos y margen tiene la sección "Regalías y dañadas"; Observaciones lista
  cada una con motivo, autorizador y costo.
- Prueba `test/bajas_cli.php` (todo revertido): PIN, validaciones, regalía de 2 Sundae
  Chocolate-Fresa (5 insumos), dañada de una bandeja, los 5 reportes y anulación → Todo OK.
  `ciclo_turno_cli`, `recetas_cli` y `reportes_cli` (10/10 igual) siguen OK.
  Respaldo previo: `sabrooski_db_antes_v110.sql`.
- Notas: las bandejas no tienen `cost_price` (su costo sale 0); el Sundae tiene existencia
  vieja en Movil01PM (59, de antes de los kits).

**Despliegue:** `C:\laragon\www\sabrooski_deploy\module_sabrooskipos-1.10.zip`. En producción:
instalar el zip → reactivar Sabrooski POS (crea la tabla, el permiso y los menús) → dar el
permiso "Autorizar regalías y dañadas (PIN)" a quien autoriza y que cada uno fije su PIN.
