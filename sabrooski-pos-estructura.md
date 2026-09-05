# Estructura del POS táctil — Sabrooski (mobile-first)

## 1. Punto de partida

El POS de Sabrooski se piensa **mobile-first**: el diseño se resuelve primero para una tablet/teléfono en la mano del vendedor detrás del mostrador, y *después* se adapta hacia pantallas más grandes (tablet horizontal, punto de venta fijo). No al revés.

Razones concretas para este caso:
- Una heladería vende de pie, muchas veces con una sola mano libre (con la otra se sirve el helado).
- El dispositivo típico es una tablet Android de gama media o un teléfono, no un monitor con mouse.
- La conexión puede ser inestable — el flujo debe funcionar bien aunque la pantalla sea chica y la red falle un momento.

## 2. Jerarquía de pantallas

```
┌─────────────────────────────┐
│ 1. Barra superior             │  ← marca + tasa BCV
├─────────────────────────────┤
│ 2. Categorías (scroll horiz.) │  ← Barquillooski, Barquifull...
├─────────────────────────────┤
│ 3. Grid de productos          │  ← tarjetas grandes, tocables
│                                │
├─────────────────────────────┤
│ 4. Carrito / orden actual      │  ← en móvil: bandeja inferior
└─────────────────────────────┘

   (modal) 5. Selección de sabor / topping / sirope
```

### 2.1 Barra superior
- Logo + nombre, siempre visible, altura fija pequeña (no debe robar espacio vertical en móvil).
- Tres selectores de contexto de la venta: **Terminal**, **Cliente** y **Almacén activo** — son datos que Dolibarr ya maneja por venta (caja/terminal de TakePOS, tercero/cliente, almacén de descuento de stock), así que van arriba porque aplican a toda la orden, no a un producto puntual.
  - En móvil, estos tres selectores pueden apilarse o colapsar en un solo botón "Terminal 1 · Público general · Almacén principal" que abre una hoja para cambiarlos, para no ocupar demasiado ancho en pantallas angostas.
- La **tasa BCV no va en esta pantalla**: es un dato de uso interno que fija el administrador en otra parte del sistema (configuración de Dolibarr), el vendedor no necesita verla ni tocarla. El total en Bs que ve el cliente se sigue mostrando en el carrito, pero calculado con esa tasa interna, sin exponer el campo en la barra superior.

### 2.2 Categorías
- Fila con scroll horizontal (`overflow-x:auto`), **no** un menú desplegable — en pantalla táctil, deslizar con el dedo es más rápido que abrir un select.
- Cada botón de categoría debe medir al menos 44×44px de zona táctil (mínimo recomendado para dedo, no para mouse).

### 2.3 Grid de productos
- En móvil: **1 o 2 columnas**, tarjetas grandes con nombre, descripción corta y precio. Nada de grids de 4-6 columnas apretadas como en un POS de escritorio clásico.
- En tablet/desktop: el grid escala a más columnas automáticamente (`auto-fit, minmax(...)`), sin que el vendedor tenga que cambiar de pantalla.
- Cada tarjeta es un solo tap → abre el modal de personalización (no hay "agregar directo sin elegir sabor", porque en una heladería el sabor **es** el producto).

### 2.4 Carrito / orden actual
- **Este es el punto donde mobile-first cambia más la estructura respecto a un POS de escritorio:**
  - En desktop: el carrito vive fijo a la derecha, siempre visible mientras se sigue tocando productos.
  - En móvil: no hay espacio para dos columnas a la vez. El carrito se convierte en una **bandeja inferior (bottom sheet)** con:
    - Una barra resumen siempre visible y fija abajo: "3 artículos · $9.20 · Cobrar ▲"
    - Al tocarla, se expande hacia arriba mostrando el detalle de cada línea, sin salir de la pantalla de productos (no es una pantalla nueva).
  - Esto evita que el vendedor pierda de vista el grid de productos mientras arma una orden con varios ítems.

### 2.5 Modal de selección (sabor / topping / sirope)
- En móvil, este modal debe ocupar el **alto completo de la pantalla** (no un cuadro flotante chico), porque el vendedor necesita ver bien los chips de sabor/topping con el dedo sin hacer zoom.
- Los límites de selección (ej. "máximo 2 sabores") se marcan visualmente apagando los chips no elegibles — el vendedor no debe depender de leer un texto de aviso para entender que ya llegó al límite.
- Botón de "Agregar a la orden" siempre anclado abajo del modal, alcanzable con el pulgar (zona cómoda de una mano sosteniendo el dispositivo).

## 3. Principios mobile-first aplicados

| Principio | Cómo se aplica aquí |
|---|---|
| Zona táctil mínima | Botones y chips ≥ 44px de alto, con separación entre ellos para evitar toques accidentales |
| Una mano | Acciones frecuentes (agregar, cobrar) ancladas abajo, donde llega el pulgar |
| Contenido antes que chrome | El grid de helados ocupa la mayoría de la pantalla; barra superior y navegación se reducen al mínimo |
| Progresivo hacia desktop | El layout no se "rediseña" en pantallas grandes, se **expande**: el carrito pasa de bandeja inferior a panel lateral fijo, el grid gana columnas |
| Resiliencia de red | La orden se arma en memoria local (JS) y solo se sincroniza con Dolibarr al confirmar el cobro — si la conexión falla a mitad de una venta, no se pierde lo ya seleccionado |

## 4. Integración con Dolibarr TakePOS

La capa visual (lo descrito arriba) vive como una vista personalizada que reemplaza/extiende la pantalla de productos de TakePOS. Por debajo:

1. **Hook de enganche**: un módulo en `htdocs/custom/` se conecta a los hooks que ya expone TakePOS (por ejemplo en `pay.php`) para interceptar el toque sobre un producto de la categoría "helados" y abrir el modal en vez de agregar la línea directo.
2. **Guardado de la selección**: al confirmar el modal, la línea se agrega a la venta con la personalización guardada como:
   - texto en la descripción de línea (simple, se imprime tal cual en el ticket), y/o
   - un extrafield JSON en la línea de factura (para poder filtrar/reportar por sabor o topping más adelante).
3. **Reglas de límite** (máx. sabores, máx. toppings) se guardan como extrafield del producto en Dolibarr, para que el módulo las lea dinámicamente en vez de tenerlas escritas a mano en el JS.
4. **Cobro**: al tocar "Cobrar", se sigue el flujo normal de pago de TakePOS (efectivo, Bs a tasa BCV, pago móvil, tarjeta) — no se reemplaza esa parte, solo se le agrega la personalización previa a la línea.

## 5. Sabores, toppings e inventario en Dolibarr

Para que el control de stock y los reportes salgan gratis (sin tablas ni lógica personalizada), sabores, toppings y siropes se modelan como **productos reales** de Dolibarr, no como una lista suelta dentro del POS.

### 5.1 Productos por categoría

| Categoría Dolibarr | Ejemplo de producto | Qué resuelve |
|---|---|---|
| `Sabores helado` | `SAB-CHOC` (Chocolate), `SAB-FRES` (Fresa) | Stock por sabor: si se acaba un sabor, queda sin existencias y el POS lo puede ocultar/avisar |
| `Topping` | `TOP-OREO`, `TOP-GRAG` (grageas) | Precio propio por topping, y stock si aplica (ej. galleta triturada) |
| `Sirope` | `SIR-CHOC`, `SIR-FRES` | Igual que topping, cada uno con su propio costo si se necesita |

Los productos "base" del menú (Barquillooski, Sundooski, etc.) siguen siendo el producto que se vende; sabor/topping/sirope son solo la referencia que queda anotada en la línea de venta, salvo el caso de toppings extra (ver 5.3).

### 5.2 Reglas de cada producto base como extrafields

En la ficha de cada producto base (Configuración → Extrafields → Productos):

| Extrafield | Tipo | Ejemplo |
|---|---|---|
| `max_sabores` | entero | 2 |
| `max_toppings_incluidos` | entero | 2 |
| `max_sirope` | entero | 1 |

El módulo/POS lee estos valores para saber cuántos puede elegir el cliente en cada producto, en vez de tenerlo fijo a mano en el JS del modal (como está hoy en la demo).

### 5.3 Toppings que se cobran aparte

- Si el topping elegido entra dentro de `max_toppings_incluidos`, no se cobra aparte (su precio en Dolibarr puede ser 0, o ya prorrateado en el precio del producto base).
- Si el cliente pide más toppings de los incluidos, cada topping extra se agrega como **línea de venta adicional real**, con el precio propio de ese producto topping (ej. `TOP-OREO` a $0.30). Así el total y el descuento de stock quedan correctos automáticamente — es una línea normal de Dolibarr, no un dato oculto en un JSON.

### 5.4 Cómo queda el ticket

- La línea principal (ej. "1x Barquifull") lleva en su descripción el resumen legible: `Sabor: Chocolate, Vainilla · Sirope: Fresa`.
- Cada topping que exceda el límite entra como su propia línea de venta (producto real `TOP-...`), vinculada a la misma venta.
- Esto da reportes nativos de Dolibarr por producto: cuánto se vendió de cada sabor/topping, sin reportes personalizados.

### 5.5 Adicionales (extra maní, extra sirope, etc.)

Esto es distinto al caso de "toppings extra por pasarse del límite incluido" (5.3): aquí el cliente **pide explícitamente** una unidad de más de algo que ya eligió o de algo nuevo — "ponle doble maní" o "quiero extra de sirope de fresa" — sin que eso implique que se pasó de los toppings/sirope incluidos.

**Cómo se modela:**

- Un adicional **no es un producto nuevo**: es una unidad más del mismo producto topping/sirope ya definido en 5.1 (`TOP-MANI`, `SIR-FRES`, etc.).
- En el modal de personalización, después de la sección de toppings/sirope incluidos, va una sección aparte **"Adicionales"** con un contador (+/-) por cada topping y sirope disponible, sin el límite `max_toppings_incluidos` — el cliente puede pedir tantos adicionales como quiera.
- Cada unidad de adicional agrega **una línea de venta más** de ese producto (mismo código, mismo precio de lista), igual que en el caso 5.3. No hace falta crear un código `EXTRA-MANI` separado: el precio normal del producto (ej. `TOP-MANI` a $0.30) ya sirve tanto para "topping incluido con costo 0" como para "adicional con costo real", según si cae dentro o fuera de lo incluido.
- El total del pedido se recalcula al tiro sumando `precio_adicional × cantidad` por cada uno, visible para el cliente antes de cobrar.
- El stock se descuenta por unidad automáticamente, porque cada adicional es una línea de venta normal — no hay que llevar un contador aparte.

**Alternativa más simple (si no quieres ligar el adicional a un producto específico):**

Si en algún momento se prefiere un cobro plano por "un extra" sin importar cuál ingrediente (ej. siempre +$0.50 sin diferenciar si es maní o sirope), se puede crear en su lugar un producto genérico `EXTRA-TOPPING` / `EXTRA-SIROPE` con precio fijo. Es más rápido de montar, pero se pierde el detalle de qué ingrediente específico se usó de más y el descuento de stock deja de ser exacto por sabor/topping — por eso el enfoque recomendado es el de arriba (adicional = unidad extra del producto real).

- Cargar en Dolibarr los productos reales de sabores, toppings y siropes (con sus categorías y precios) cuando se tenga la lista definitiva del inventario.
- Definir, producto base por producto base, los valores de `max_sabores` / `max_toppings_incluidos` / `max_sirope`.
- Decidir qué toppings, si alguno, deben tener precio distinto de 0 desde el primer momento (los que normalmente se piden "extra").
- Confirmar si los adicionales se cobran al precio de lista de cada topping/sirope real, o se prefiere un precio plano genérico por "un extra" (ver 5.5).
