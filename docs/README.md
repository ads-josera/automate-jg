# Documentos del sistema de aseguramiento

## para-entregar/

Lo que se manda a los clientes y al equipo. **Adjúntalos directo, sin abrirlos ni guardarlos.** Excel y la Vista Previa de Mac reescriben el archivo al guardar y le quitan la protección o los scripts.

| Archivo | Para quién |
|---|---|
| `solicitud_aseguramiento_formato.xlsx` | Clientes: formato de solicitud en Excel (recomendado). |
| `solicitud_aseguramiento_rellenable.pdf` | Clientes: formato de solicitud en PDF rellenable (Adobe Acrobat Reader). |
| `guia_usuario_aseguramiento.pdf` | Clientes: cómo llenar, guardar y enviar la solicitud. |
| `guia_gestor_aseguramiento.pdf` | Equipo JG Mylard: panel, estados y qué hacer con cada error. |

Antes de repartir el Excel, compruébalo con:

```
ddev drush php:script scripts/template_integrity_check.php
```

## diseno/

Archivos de diseño (Illustrator) y las bases en PDF que usan los generadores. No se reparten.

| Archivo | Se usa en |
|---|---|
| `Solicitud_aseguramiento.ai` / `.pdf` | Diseño del formato PDF. `scripts/build_pdf_form.php` le agrega los campos y genera el rellenable. |
| `Guia-para-el-usuario.ai` / `.pdf` | Membrete de la guía del usuario (`scripts/build_guides.php`). |
| `Guia-para-el-gestor.ai` / `.pdf` | Membrete de la guía del gestor (`scripts/build_guides.php`). |

Los `.ai` no se guardan en git: son el original de diseño y viven solo aquí.

## desarrollo/

Documentación técnica: despliegue en cPanel y notas de desarrollo.

## Cómo se regeneran

| Qué | Comando | Cuándo |
|---|---|---|
| Formato Excel | `ddev drush php:script scripts/build_excel_form.php` | Cambian los montos permitidos. |
| PDF rellenable | `ddev drush php:script scripts/build_pdf_form.php` | Cambia el diseño o los montos. |
| Guías | `ddev drush php:script scripts/build_guides.php` | Cambian los montos, los mensajes de error o los asuntos. |
