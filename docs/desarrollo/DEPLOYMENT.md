# Despliegue a jgmylard.work

## Document root

El document root del dominio debe apuntar a:

```text
jgmylard.work/web
```

Esto es correcto para Drupal instalado con Composer: solo `web/` queda público y el resto del proyecto queda fuera del navegador.

## Regla de despliegue

- Código y estructura: sí se actualizan.
- Configuración sensible del servidor: no se pisa.
- Archivos subidos por usuarios: no se pisan.

## Nunca versionar ni pisar

```text
web/sites/default/settings.php
web/sites/default/settings.local.php
web/sites/default/settings.*.php
web/sites/default/services.yml
web/sites/default/services.local.yml
web/sites/default/files/
private-files/
vendor/
web/core/
web/modules/contrib/
web/themes/contrib/
web/profiles/contrib/
web/libraries/
```

`composer.json`, `composer.lock`, módulos custom y configuración exportada sí deben viajar por Git.

## Primera conexión del repositorio local

En local:

```bash
git remote set-url origin git@github.com:ads-josera/automate-jg.git
git remote -v
git status
```

Antes del primer commit al repo nuevo, sacar de Git el `settings.php` local si aparece trackeado:

```bash
git rm --cached web/sites/default/settings.php
```

Ese comando no borra el archivo local; solo deja de versionarlo.

## Primer despliegue en servidor

En servidor, dentro de la carpeta raíz del proyecto del dominio:

```bash
git clone git@github.com:ads-josera/automate-jg.git .
composer install --no-dev --optimize-autoloader
```

Crear o conservar estos archivos del servidor:

```text
web/sites/default/settings.php
web/sites/default/services.yml
```

En `web/sites/default/settings.php` de producción configurar:

```php
$databases['default']['default'] = [
  'database' => 'BASE_DE_DATOS_CPANEL',
  'username' => 'USUARIO_CPANEL',
  'password' => 'PASSWORD_CPANEL',
  'prefix' => '',
  'host' => 'localhost',
  'port' => '3306',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'driver' => 'mysql',
];

$settings['file_private_path'] = '../private-files';
```

Crear carpetas escribibles:

```bash
mkdir -p web/sites/default/files
mkdir -p private-files
chmod -R ug+rwX web/sites/default/files private-files
```

### Primera migración de datos

En local:

```bash
ddev export-db --file=database.sql.gz
tar -czf public-files.tar.gz -C web/sites/default files
tar -czf private-files.tar.gz private-files
```

Subir al servidor:

```text
database.sql.gz
public-files.tar.gz
private-files.tar.gz
```

En servidor:

```bash
gunzip < database.sql.gz | mysql -u USUARIO_CPANEL -p BASE_DE_DATOS_CPANEL
tar -xzf public-files.tar.gz -C web/sites/default
tar -xzf private-files.tar.gz
```

Después de importar datos:

```bash
php vendor/bin/drush updb -y
php vendor/bin/drush cim -y
php vendor/bin/drush cr
```

## Deploy futuro

En local:

```bash
git status
git add composer.json composer.lock web/modules/custom docs .gitignore
git commit -m "Actualizar automatizacion de aseguramiento"
git push origin main
```

En servidor:

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php vendor/bin/drush updb -y
php vendor/bin/drush cim -y
php vendor/bin/drush cr
```

## Alternativa con rsync

Si no se usa `git pull` en servidor, usar exclusiones:

```bash
rsync -avz --delete \
  --exclude='.git/' \
  --exclude='.ddev/' \
  --exclude='vendor/' \
  --exclude='web/core/' \
  --exclude='web/modules/contrib/' \
  --exclude='web/themes/contrib/' \
  --exclude='web/profiles/contrib/' \
  --exclude='web/libraries/' \
  --exclude='web/sites/default/settings.php' \
  --exclude='web/sites/default/settings.local.php' \
  --exclude='web/sites/default/settings.*.php' \
  --exclude='web/sites/default/services.yml' \
  --exclude='web/sites/default/services.local.yml' \
  --exclude='web/sites/default/files/' \
  --exclude='private-files/' \
  ./ usuario@servidor:/ruta/al/proyecto/
```

## Cron recomendado

Configurar cron en cPanel cada 5 minutos:

```bash
cd /ruta/al/proyecto && php vendor/bin/drush cron >/dev/null 2>&1
```

Para procesar el ciclo completo manualmente:

```bash
cd /ruta/al/proyecto && php vendor/bin/drush aseguramiento:procesar-correo
```
