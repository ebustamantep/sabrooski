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
