# CHANGELOG MODULE SABROOSKIPOS FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

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
