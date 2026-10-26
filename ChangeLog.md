# CHANGELOG MODULE SABROOSKIPOS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.10

- Regalías y dañadas (SabrooskiVentas → Regalías y dañadas): las registra el encargado al
  cierre con el PIN de quien autoriza (permiso nuevo "Autorizar regalías y dañadas (PIN)",
  cada autorizador fija su PIN en "Mi PIN de autorización"). Proceso completo: un servido
  baja kit + mix de los sabores; una bandeja o golosina baja ella misma. Se pueden anular
  (también con PIN) y devuelven al móvil.
- Tabla `llx_sabrooskipos_baja` (producto, sabores, motivo, quién registró y autorizó, costo).
- Reportes: regalías/dañadas de servidos en Inventario de ventas; sirope/topping de las
  bajas en el teórico de Insumos; sección "Regalías y dañadas" con su costo en Costos y
  margen; detalle con motivo y autorizador en Observaciones.

## 1.9

- Sin MovilStock: la carga del camión hace el traslado Principal → móvil con movimientos
  de stock propios (código `CARGA-…`), revisando existencia en Principal.
- Almacén principal en Setup → Sabrooski POS → Configuración
  (`SABROOSKIPOS_ID_WAREHOUSE_MAIN`; al activar el módulo se copia el de MovilStock) y en
  el paquete de migración.
- Carga del camión: lista de las últimas cargas con enlace a sus movimientos.
- Popup: agregar a la orden en una sola petición (`action=addOrder`).

## 1.8

- Servidos por receta (`recetas_sabrooski.json`, de "Estructura de Costos Sistema.xlsx"):
  insumos fijos como kits nativos (se descuentan al validar el ticket) y mix por sabor
  descontado por el trigger `interface_99_modSabrooskiPOS_Recetas`. Sirope, topping y
  pirulín/oreo: consumo teórico contra conteo de cierre.
- Insumos con costo y unidad de control; productos nuevos (especiales Susy, Cocosette y
  Maní cubierto, Brownie con helado, Helado de coco, Barquillón congelado) fuera de venta
  hasta tener precio.
- Carga del camión (traslado Principal → móvil) y cierre por conteo.
- ReportesVentas: "Insumos del móvil" y "Costos y margen".
- Migración: el catálogo lleva kits, costos y constantes; extrafields antes de la
  transacción (Simular ya no guarda nada).
- Popup: "Quitar"/"Vaciar" del carrito vuelven a funcionar (`removeLine` en `picker/api.php`).

## 1.7

- Popup: los adicionales son solo **Topping** y **Sirope** genéricos con su cantidad
  ($0,25 c/u); el cliente elige el topping/sirope concreto en la barra.
- Catálogo: genéricos `PV-031 Topping` y `PV-032 Sirope` (categoría Adicional, sin stock
  propio). Los toppings/siropes reales y `PV-030 Adicional` quedan fuera de venta.
- Recibo (hook `TakeposReceipt`): cada línea muestra su detalle junto al producto, p. ej.
  "Barquilla (Chocolate, Fresa) 1 Topping 1 Sirope" y "Topping para Barquilla".

## 1.4

- Reconstruida la toma de pedidos sobre el TakePOS **nativo**.
- Los productos base se obtienen del endpoint **nativo** de TakePOS
  (`takepos/ajax/ajax.php?action=getProducts`), el mismo que pinta la rejilla del POS:
  se muestran las MISMAS categorías y MISMOS productos que en el POS nativo.
- `picker/api.php` ya NO lista productos base (solo categorías + sabores/toppings/siropes).
- Pestañas de categorías reales SIN el botón "Todos": Conos (2), Vasos (3), Sundaes (4).
- Modal de personalización (sabor/topping/sirope) con límites de los extrafields
  (`max_sabores`, `max_toppings_incluidos`, `max_sirope`).
- Al confirmar agrega la línea al ticket nativo (`takepos/invoice.php` addline/addnote).
- Se fijan las categorías reales: sabores=5, toppings=6, siropes=7.

## 1.3

- Se retira el botón "Toma de orden" y su popup (carpeta `picker/`, `js/picker.js`,
  `css/picker.css`) por no cumplir los requisitos en la versión instalada.
- El POS queda **100% nativo**: `/takepos/index.php` sin capa visual extra.
- Se elimina la declaración de hooks (`takeposfrontend`) del descriptor.
- Se fijan las **categorías reales** de la instalación: sabores=5, toppings=6, siropes=7.

## 1.2

- Se abandona la pantalla custom (`sabrooskiposindex.php`) como POS.
- El POS vuelve a ser el TakePOS nativo (`/takepos/index.php`), sin capa visual extra.
- Nuevo botón **"Toma de orden"** en la barra de acciones de TakePOS (hook `ActionButtons`,
  contexto `takeposfrontend`), que abre un popup de selección sabor/topping/sirope.
- Nueva carpeta `picker/`: `index.php` (popup), `api.php` (catálogo JSON real),
  y `js/picker.js` + `css/picker.css` (modal mobile-first).
- Al confirmar, la línea se agrega a la venta con los endpoints nativos de TakePOS
  (`invoice.php addline / addnote`) y la personalización queda como nota de línea.
- Se conservan los extrafields (`max_sabores`, `max_toppings_incluidos`, `max_sirope`)
  y la configuración de categorías (`admin/setup.php`).

## 1.1

- Añadido el modal de personalización de producto (sabor / topping / sirope) con
  límites leídos de los extrafields `max_sabores`, `max_toppings_incluidos` y `max_sirope`.
- Añadida la sección de **Adicionales** con contador +/- (cada unidad adicional se
  agrega como línea de venta propia de un producto real de Dolibarr).
- Nuevo endpoint `ajax/ajax.php` del módulo con `getModalData`, `getCartData` y `addItem`.
- El carrito ahora se lee desde `getCartData` (JSON) para mostrar la descripción
  personalizada y los adicionales como líneas separadas.
- Alta automática de los tres extrafields de producto al activar el módulo.
- Configuración en `admin/setup.php` para elegir las categorías de sabores, toppings y siropes.

## 1.0

Initial version
