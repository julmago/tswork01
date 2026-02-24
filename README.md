# Entrada de Stock Simple (PHP + MySQL)

## Qué incluye (MVP sin CSS)
- Login por usuario/contraseña desde base de datos
- Dashboard con:
  - Botones: Nuevo Listado, Nuevo Producto, Listado de Productos
  - Buscador de productos (mismo resultado que listado)
  - Tabla de listados: id, fecha, nombre, creador, sync, estado
- Listado (detalle):
  - Datos: id, fecha, nombre, creador, sync, estado
  - Descargar Excel (CSV)
  - Switch (botón) abrir/cerrar
  - Botón sincronizar (solo marca como 'prestashop' y bloquea re-sincronizar)
  - Escaneo de código (cualquier código)
  - Si el código no existe: pantalla inmediata para Asociar o Crear producto nuevo
  - Tabla de items: sku, nombre, cantidad (último escaneado primero)
- Productos:
  - Crear: sku, nombre, marca + agregar códigos
  - Listar: sku, nombre, marca + entrar/editar
  - Editar: cambiar datos + agregar/eliminar códigos

> Nota: este MVP guarda contraseñas en texto plano porque lo pediste.
> Recomendación: luego migrar a password_hash/password_verify.

## Instalación rápida
1) Copiá la carpeta `public/` en tu hosting (o en un subdirectorio).
2) Creá una base MySQL y ejecutá `database.sql`.
3) Editá `public/config.php` con tus credenciales.
4) Creá tu primer usuario en la tabla `users`.

### Crear usuario ejemplo
```sql
INSERT INTO users(role, first_name, last_name, email, password_plain)
VALUES ('superadmin','Admin','Principal','admin@local','1234');
```

### Migrar roles dinámicos (instalaciones existentes)
Si ya tenés la base creada con `users.role` como `ENUM`, corré esta migración una sola vez:
```sql
ALTER TABLE users
  MODIFY role VARCHAR(32) NOT NULL;
```
> Recomendación: hacé backup antes de ejecutar la migración.

## Requisitos
- PHP 8.0+ recomendado (funciona desde 7.4 con pequeños cambios)
- MySQL 5.7+ / 8.0+
- PDO MySQL habilitado


## Sincronización real a PrestaShop
- En el menú tenés **Config PrestaShop** para cargar:
  - URL base (sin / final)
  - API Key del Webservice
  - Modo: Reemplazar o Sumar
- En el detalle del listado, el botón **Sincronizar a PrestaShop** llama a `ps_sync.php`
  y muestra un log por SKU.
- Si TODOS los ítems sincronizan OK, el listado queda marcado como `prestashop` y se bloquea.
  Si hay algún error, NO se marca sincronizado para que puedas corregir y reintentar.
