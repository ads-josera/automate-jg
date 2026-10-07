# Flujo seguro para proyectos Drupal con GitHub, VS Code y cPanel

Este documento define el flujo que vamos a usar de aqui en adelante para manejar proyectos Drupal de forma consistente y segura.

## Objetivo

Mantener un proceso repetible para:

- trabajar localmente con Docker o DDEV
- versionar el proyecto con Git
- respaldar el codigo en GitHub
- desplegar de forma controlada a un subdominio o dominio en cPanel
- reducir errores por archivos subidos a mano o cambios no rastreados

## Estandar que vamos a seguir siempre

1. El proyecto vive en local y se desarrolla desde VS Code.
2. Todo cambio pasa por Git antes de salir del equipo.
3. El repositorio remoto principal vive en GitHub.
4. Produccion nunca se modifica a mano si el cambio puede hacerse desde Git.
5. El `document root` del dominio o subdominio debe apuntar al directorio `web/` del proyecto.
6. La base de datos, variables sensibles y archivos subidos no se guardan en Git.
7. Antes de desplegar, validamos Composer, configuracion y version de PHP.

## Estructura recomendada para Drupal

Este proyecto usa la estructura `drupal/recommended-project`, asi que:

- la raiz del proyecto contiene `composer.json`
- el codigo publico vive en `web/`
- el subdominio en cPanel debe apuntar a algo como `public_html/primer-drupal/web`

## Que necesito para conectar un proyecto nuevo

Cuando vayamos a conectar un proyecto a GitHub y dejarlo listo para despliegue, necesitare lo siguiente:

1. Nombre del proyecto.
2. URL del repositorio de GitHub, o confirmacion de que lo crearemos.
3. Dominio o subdominio destino.
4. `Document Root` del dominio en cPanel.
5. Version de PHP disponible en cPanel.
6. Confirmacion de si el hosting tiene `SSH Access` o `Terminal`.
7. Datos de la base de datos:
   - nombre de la base
   - usuario
   - password
   - host, si no es `localhost`

## Flujo de trabajo local

### 1. Preparar el proyecto

Verificar:

- `composer.json`
- `composer.lock`
- `.gitignore`
- configuracion local de Docker o DDEV

### 2. Probar en local

Antes de pensar en despliegue:

- el sitio debe levantar
- Drupal debe cargar correctamente
- modulos y dependencias deben instalar sin error
- cualquier cambio importante debe probarse localmente

### 3. Guardar cambios con Git

Flujo base:

```bash
git status
git add .
git commit -m "Describe el cambio"
```

## Flujo recomendado con GitHub

### 1. Crear el repositorio remoto

Crear un repositorio en GitHub con un nombre claro, por ejemplo:

`primer-drupal`

### 2. Conectar el proyecto local

Comandos base:

```bash
git init
git branch -M main
git remote add origin git@github.com:TU_USUARIO/TU_REPO.git
git add .
git commit -m "Initial project setup"
git push -u origin main
```

Si no se usa SSH con GitHub, puede usarse HTTPS.

### 3. Regla de trabajo

- `main` representa el estado desplegable
- cambios grandes idealmente van en ramas separadas
- antes de desplegar, `main` debe estar limpio y probado

## Flujo seguro de despliegue a cPanel

### Opcion recomendada: cPanel con Terminal o SSH

Esta es la opcion que prefiero para todos los proyectos porque permite repetir el proceso y dejar menos cosas manuales.

### Paso 1. Crear el subdominio

En cPanel:

- crear el subdominio
- confirmar el `Document Root`

Para este tipo de Drupal, el `Document Root` debe terminar en `web`.

Ejemplo:

`public_html/primer-drupal/web`

### Paso 2. Configurar la version de PHP

En `MultiPHP Manager`:

- asignar `PHP 8.4` si esta disponible
- si no, usar `PHP 8.3`

### Paso 3. Crear la base de datos

En `MySQL Database Wizard` o `Manage My Databases`:

- crear base de datos
- crear usuario
- asignar todos los privilegios
- guardar los datos en un lugar seguro

### Paso 4. Subir o clonar el proyecto

Hay dos variantes:

#### Variante A. Git en servidor

Si cPanel permite terminal o SSH, el flujo ideal es:

```bash
cd ~
git clone URL_DEL_REPO public_html/primer-drupal
cd public_html/primer-drupal
composer install --no-dev --optimize-autoloader
```

Como este proyecto expone `web/`, el dominio debe apuntar a:

`public_html/primer-drupal/web`

#### Variante B. Subida manual controlada

Si no hay SSH:

- comprimir el proyecto sin `vendor` si Composer correra en servidor
- o subir una version ya preparada
- extraer archivos en la carpeta del subdominio
- asegurar que el dominio siga apuntando a `web/`

Esta variante funciona, pero es menos segura y menos repetible.

### Paso 5. Configurar `settings.php`

En produccion necesitaremos:

- conexion a la base de datos
- hash salt
- configuraciones especificas del ambiente si aplican

Nunca se deben subir credenciales reales al repositorio.

### Paso 6. Instalar dependencias y ajustar permisos

Si el servidor permite Composer:

```bash
composer install --no-dev --optimize-autoloader
```

Revisar permisos de:

- `web/sites/default/files`
- `web/sites/default/settings.php`

### Paso 7. Instalar el sitio o conectar la base existente

Si el sitio es nuevo:

- usar el instalador web de Drupal
- o importar una base existente

Si el sitio ya existe:

- importar base de datos
- confirmar acceso al panel

### Paso 8. Validar antes de abrir al publico

Checklist:

- el dominio carga
- no hay errores PHP fatales
- la pagina principal responde
- login administrativo funciona
- formularios funcionan
- archivos se pueden subir
- cron y correos basicos se revisan

## Flujo de actualizacion en adelante

Para todos los siguientes cambios:

1. Cambiamos localmente.
2. Probamos localmente.
3. Guardamos en Git.
4. Subimos a GitHub.
5. Desplegamos desde el repositorio o desde un paquete controlado.
6. Validamos en el servidor.

## Reglas de seguridad que vamos a mantener

- no editar archivos criticos directo en produccion salvo emergencia real
- no subir credenciales a GitHub
- no subir `files`, cache o compilados temporales al repo
- no desplegar sin revisar `git status`
- no desplegar cambios no probados
- preferir `SSH` y `git pull` o `git clone` sobre arrastrar archivos manualmente
- mantener copia de la base de datos antes de cambios delicados

## Comandos utiles de referencia

### Local

```bash
ddev start
ddev exec drush status
ddev exec drush cr
```

### Git

```bash
git status
git add .
git commit -m "Mensaje"
git push
```

### Produccion

```bash
composer install --no-dev --optimize-autoloader
```

## Playbook rapido para este proyecto

Datos de referencia actuales:

- Repo GitHub: `git@github.com:ads-josera/primer-drupal.git`
- Dominio de prueba: `https://primer-drupal.josera.com.mx`
- Ruta en servidor: `/home/josera/primer-drupal.josera.com.mx`
- Drupal root: `/home/josera/primer-drupal.josera.com.mx/web`
- PHP correcto en servidor: `/opt/cpanel/ea-php84/root/usr/bin/php`
- Composer en servidor: `/usr/local/bin/composer`
- Drush del proyecto: `vendor/bin/drush.php`

### Aliases recomendados en el servidor

Agregar al `~/.bashrc` o `~/.bash_profile`:

```bash
alias php84='/opt/cpanel/ea-php84/root/usr/bin/php'
alias composer84='php84 /usr/local/bin/composer'
alias drush84='php84 /home/josera/primer-drupal.josera.com.mx/vendor/bin/drush.php --root=/home/josera/primer-drupal.josera.com.mx/web --uri=https://primer-drupal.josera.com.mx'
```

Luego recargar la sesion:

```bash
source ~/.bashrc
```

### Primer despliegue

```bash
cd /home/josera/primer-drupal.josera.com.mx
git clone https://github.com/ads-josera/primer-drupal.git temp-repo
rsync -av temp-repo/ . --exclude .git --exclude web/sites/default/settings.php
rm -rf temp-repo
php84 /usr/local/bin/composer install --no-dev --optimize-autoloader
mkdir -p web/sites/default/files
chmod 755 web/sites/default
chmod 775 web/sites/default/files
```

Despues:

- importar base de datos
- configurar `web/sites/default/settings.php`
- ejecutar `drush84 cr`

Regla importante:

- cuando usemos `rsync` para desplegar, no debemos sobrescribir `web/sites/default/settings.php`
- tampoco debemos pisar `web/sites/default/files/`
- los secretos y configuraciones del servidor se mantienen fuera de Git y fuera del `rsync` agresivo

### Actualizacion normal de codigo

Flujo local:

```bash
git status
git add .
git commit -m "Describe el cambio"
git push origin main
```

Flujo en servidor:

```bash
cd /home/josera/primer-drupal.josera.com.mx
git pull origin main
composer84 install --no-dev --optimize-autoloader
drush84 cr
```

### Si cambias configuracion en local

Exportar configuracion en local:

```bash
ddev exec drush cex -y
git add .
git commit -m "Export Drupal config"
git push origin main
```

Importar configuracion en servidor:

```bash
cd /home/josera/primer-drupal.josera.com.mx
git pull origin main
composer84 install --no-dev --optimize-autoloader
drush84 cim -y
drush84 cr
```

### Si necesitas mover la base local al servidor otra vez

Local:

```bash
ddev export-db --file=.ddev/db.sql.gz
scp -P 22 .ddev/db.sql.gz josera@72.167.47.47:/home/josera/primer-drupal.josera.com.mx/
```

Servidor:

```bash
cd /home/josera/primer-drupal.josera.com.mx
gunzip -c db.sql.gz | mysql -u josera_drupusr -p josera_drupatest
drush84 cr
rm -f db.sql.gz
```

### Checklist corta despues de cada deploy

- abrir `https://primer-drupal.josera.com.mx`
- revisar login admin
- probar un formulario
- correr `drush84 status`
- correr `drush84 cr` si hace falta
- revisar `error_log` solo si aparece fallo

## Siguiente paso para este proyecto

Para `primer-drupal.josera.com.mx`, el siguiente paso operativo es confirmar:

1. `Document Root` exacto del subdominio.
2. Si cPanel tiene `SSH Access` o `Terminal`.
3. La version de PHP disponible para ese subdominio.
4. Los datos de la base de datos de pruebas.

Con eso, seguimos con la conexion a GitHub y luego con el despliegue.
