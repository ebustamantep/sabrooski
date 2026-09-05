# Implementación como módulo custom en Dolibarr — Sabrooski POS

> **Nota de implementación (v1.1):** este documento describe una estrategia con
> **hooks** sobre la pantalla nativa de TakePOS. La versión finalmente implementada
> usa en su lugar una **pantalla custom** (`sabrooskiposindex.php`) que reutiliza los
> endpoints de TakePOS (`ajax.php`, `invoice.php`, `pay.php`), complementada con un
> endpoint propio (`ajax/ajax.php`) para el modal de personalización y el carrito JSON.
> Se conserva la intención de no tocar el core ni el módulo TakePOS.

Esta guía describe cómo empaquetar la personalización de sabores/toppings/siropes (descrita en `sabrooski-pos-estructura.md`) como un **módulo externo** de Dolibarr, sin tocar el core ni el módulo TakePOS original. Así, una actualización de Dolibarr no borra el trabajo hecho.

## 1. Enfoque general

No se reescribe TakePOS: se le **engancha** un módulo nuevo que:
1. Se activa/desactiva como cualquier módulo de Dolibarr (Inicio → Configuración → Módulos).
2. Depende de que los módulos `Product`, `Categorie` y `TakePOS` estén activos.
3. Usa el sistema de hooks de Dolibarr para inyectar el modal de selección (sabor/topping/sirope) cuando se toca un producto de la categoría "helados", en vez de agregar la línea directo.
4. Guarda la selección en la propia línea de venta, sin tablas nuevas obligatorias.

## 2. Estructura de carpetas

```
htdocs/custom/sabrooskipos/
├── core/
│   └── modules/
│       └── modSabrooskiPos.class.php     ← descriptor del módulo
├── class/
│   └── actions_sabrooskipos.class.php    ← clase de hooks
├── js/
│   └── sabrooskipos.js                    ← lógica del modal (adaptada de la demo HTML)
├── css/
│   └── sabrooskipos.css.php
├── langs/
│   └── es_ES/
│       └── sabrooskipos.lang
└── README.md
```

Esta es la estructura estándar que usa el "Module Builder" de Dolibarr (Herramientas → Module Builder puede generar el esqueleto automáticamente y luego se completa a mano).

## 3. Descriptor del módulo

`core/modules/modSabrooskiPos.class.php` — extiende `DolibarrModules`, igual que cualquier módulo de Dolibarr. Los puntos importantes para este caso:

```php
class modSabrooskiPos extends DolibarrModules
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->numero = 500001; // elegir un número libre (ver Inicio > Información del sistema)
        $this->rights_class = 'sabrooskipos';
        $this->family = 'other';

        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'Selección de sabores, toppings y sirope para TakePOS (Sabrooski)';

        $this->version = '1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'cash-register';

        // Aquí se declara el contexto de hook al que este módulo se engancha.
        // 'takepos' es el contexto que usa el módulo TakePOS; conviene confirmarlo
        // haciendo un grep de "initHooks(" dentro de /htdocs/takepos en la versión
        // instalada, porque el nombre exacto puede variar entre versiones.
        $this->module_parts = array(
            'hooks' => array('takepos'),
        );

        // Dependencias: no debe poder activarse sin estos módulos
        $this->depends = array('modProduct', 'modCategorie', 'modTakePos');

        $this->langfiles = array('sabrooskipos');
    }

    public function init($options = '')
    {
        // Aquí se crean los extrafields de producto la primera vez que se activa
        // el módulo (ver sección 5), en vez de pedirle al usuario que los cree a mano.
        return $this->_init(array(), $options);
    }
}
```

## 4. Clase de hooks

`class/actions_sabrooskipos.class.php` es donde vive la lógica que "escucha" lo que hace TakePOS. El nombre de cada método debe coincidir exactamente con el nombre del hook que Dolibarr ejecuta en ese punto de la pantalla (por ejemplo, algo como `doActions`, `printPOSProductButton` o similar, según la versión). Estructura genérica:

```php
class ActionsSabrooskiPos
{
    public $results = array();
    public $resprints;

    /**
     * Se ejecuta en el punto donde TakePOS dibuja cada botón de producto.
     * Aquí se detecta si el producto pertenece a la categoría "helados"
     * y, si es así, se cambia el comportamiento del botón para que abra
     * el modal en vez de agregar la línea directo.
     */
    public function completeTabsHead($parameters, &$object, &$action, $hookmanager)
    {
        // leer $parameters['product'] o equivalente según el hook real
        // comprobar si el producto está en la categoría de helados
        // si sí: agregar atributo data-* al botón y encolar el JS/CSS del modal
        return 0;
    }

    /**
     * Se ejecuta cuando se agrega una línea a la venta.
     * Aquí se toma lo que el modal dejó guardado (por ejemplo en un campo
     * oculto o vía llamada AJAX) y se escribe en la línea:
     * - descripción legible ("Sabor: Chocolate, Vainilla · Sirope: Fresa"), y/o
     * - extrafield JSON de la línea, para reportes.
     */
    public function createLine($parameters, &$object, &$action, $hookmanager)
    {
        return 0;
    }
}
```

Notas importantes:
- El nombre de la clase debe ser `Actions` + nombre del módulo en PascalCase (`ActionsSabrooskiPos`), es la convención que Dolibarr usa para autodetectar la clase de hooks.
- Los nombres reales de los métodos (`completeTabsHead`, `createLine` arriba son ilustrativos) dependen de qué hooks expone realmente el archivo de TakePOS que se quiere interceptar. Antes de escribir la clase final, hay que revisar el código fuente de `/htdocs/takepos/` de la versión de Dolibarr instalada y localizar las llamadas `$hookmanager->executeHooks('nombreDelHook', ...)` para usar los nombres exactos.

## 5. Extrafields de producto (reglas de sabores/toppings)

En vez de pedirle al usuario que los cree a mano desde la interfaz, el módulo los crea automáticamente al activarse, usando la clase `ExtraFields` de Dolibarr dentro del método `init()` del descriptor:

```php
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
$extrafields = new ExtraFields($this->db);

$extrafields->addExtraField('max_sabores', 'Máx. sabores', 'int', 100, 3, 'product', 0, 0, '', '', 1, '', 0, '', '', 'sabrooskipos@sabrooskipos');
$extrafields->addExtraField('max_toppings_incluidos', 'Máx. toppings incluidos', 'int', 101, 3, 'product', 0, 0, '', '', 1, '', 0, '', '', 'sabrooskipos@sabrooskipos');
$extrafields->addExtraField('max_sirope', 'Máx. sirope', 'int', 102, 3, 'product', 0, 0, '', '', 1, '', 0, '', '', 'sabrooskipos@sabrooskipos');
```

Esto hace que los tres campos (`max_sabores`, `max_toppings_incluidos`, `max_sirope`) aparezcan automáticamente en la ficha de cada producto, sin tocar nada por SQL a mano.

## 6. JS/CSS del modal

El HTML/JS de la demo (`sabrooski-pos-demo.html`) es prácticamente reutilizable tal cual como punto de partida para `js/sabrooskipos.js` y `css/sabrooskipos.css.php`:
- Se cambia de leer un arreglo `PRODUCTS` fijo en el JS, a pedir esos datos vía AJAX a un endpoint del propio módulo (que consulta productos, categorías y extrafields reales de Dolibarr).
- El "Agregar a la orden" del modal, en vez de solo actualizar un carrito en memoria (como en la demo), debe llamar a la función/endpoint que ya usa TakePOS internamente para agregar una línea, pasándole la descripción/extrafield con la selección.

## 7. Pasos para instalar y probar

1. Copiar la carpeta `sabrooskipos/` dentro de `htdocs/custom/` del servidor Dolibarr.
2. Entrar como administrador → Configuración → Módulos → buscar "Sabrooski POS" → Activar.
3. Confirmar que aparecen los tres extrafields nuevos en la ficha de un producto de prueba.
4. Crear/editar un producto de helado, asignarle `max_sabores = 2`, `max_toppings_incluidos = 2`, `max_sirope = 1`, y ponerlo en la categoría de helados.
5. Abrir TakePOS, tocar ese producto, y confirmar que abre el modal de selección en vez de agregar la línea directo.
6. Revisar que la línea generada en la venta muestre la descripción con sabor/topping/sirope, y que un topping "extra" (fuera del límite) aparezca como línea de venta aparte con su propio precio.
7. Probar en un entorno de prueba/staging antes de instalarlo en el Dolibarr de producción de la heladería, porque el nombre exacto de los hooks de TakePOS puede variar según la versión instalada (ver nota de la sección 4).

## 8. Siguientes pasos

- Confirmar la versión de Dolibarr instalada y localizar en su código fuente los nombres exactos de los hooks de TakePOS a usar en `class/actions_sabrooskipos.class.php`.
- Definir el endpoint AJAX real que el modal usará para leer productos/extrafields y para agregar la línea a la venta.
- Decidir si el JSON de la selección se guarda como extrafield de línea (mejor para reportes) además del texto en la descripción (mejor para el ticket impreso), o solo uno de los dos.
