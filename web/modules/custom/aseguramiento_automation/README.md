# Aseguramiento Automation

Sistema enterprise de automatización documental para Drupal 10/11.

## Capacidades

- Lectura de correos por capa desacoplada de providers.
- Microsoft Graph como proveedor principal para Microsoft 365.
- IMAP como fallback para proveedores legacy.
- Procesamiento asíncrono con Queue API.
- Parsing de Excel con PhpSpreadsheet.
- Validación de datos de negocio.
- Entidad auditable `aseguramiento_constancia`.
- Plantillas PDF corporativas administrables.
- Overlay dinámico sobre PDFs base con FPDI/TCPDF.
- Builder visual de coordenadas.
- Envío automático de respuestas con PDF generado.
- Dashboard, exportaciones CSV/Excel-ready y trazabilidad.
- Preparación multiempresa, multiaseguradora y multibuzón.

## Dependencias Composer

El proyecto raíz debe incluir:

```bash
composer require phpoffice/phpspreadsheet setasign/fpdi tecnickcom/tcpdf firebase/php-jwt
```

En este proyecto ya fueron instaladas.

## Instalación

```bash
ddev exec drush pm:enable aseguramiento_automation -y
ddev exec drush cr
```

Rutas principales:

- `/admin/aseguramiento`
- `/admin/config/aseguramiento/settings`
- `/admin/config/aseguramiento/mail-accounts`
- `/admin/config/aseguramiento/pdf-templates`
- `/admin/aseguramiento/export`

## Arquitectura

El flujo está separado por responsabilidades:

1. Cron consulta cuentas activas.
2. `MailProviderManagerService` resuelve el provider.
3. `EmailProcessingQueueWorker` descarga y valida adjuntos.
4. `ExcelParsingQueueWorker` parsea Excel, valida y crea constancias.
5. `PdfGenerationQueueWorker` resuelve plantilla y genera PDF.
6. `MailSendingQueueWorker` responde al destinatario.

Servicios principales:

- `MailProviderManagerService`
- `MicrosoftGraphService`
- `ImapService`
- `EmailParserService`
- `ExcelParserService`
- `ValidationService`
- `PdfTemplateService`
- `CoordinateMappingService`
- `PdfOverlayService`
- `MailService`
- `ExportService`
- `AuditService`
- `QueueManagerService`

## Providers de correo

Los providers implementan:

```php
Drupal\aseguramiento_automation\Mail\MailProviderInterface
```

Implementaciones incluidas:

- `MicrosoftGraphMailProvider`
- `ImapMailProvider`

Para agregar Gmail API, Exchange custom, Amazon SES inbound, Mailgun routes u otro provider, crea una implementación nueva del contrato y regístrala en el manager o migra el manager a plugin discovery si el número de providers crece.

## Microsoft Graph

El módulo usa Graph API con client credentials para buzones corporativos.

Permisos sugeridos en Microsoft Entra ID:

- `Mail.Read`
- `Mail.ReadWrite`
- `Mail.Send`

Configura tenant, client ID, client secret, mailbox y carpetas desde:

`/admin/config/aseguramiento/mail-accounts`

Nota: para entornos con MFA/delegated auth y refresh tokens por usuario, extiende `MicrosoftGraphService::accessToken()` con un storage cifrado de tokens por cuenta. La arquitectura ya separa esa responsabilidad.

## IMAP fallback

Soporta host, puerto, SSL/TLS/none, usuario, contraseña y carpeta. Requiere la extensión PHP IMAP habilitada en el contenedor.

## Plantillas PDF

Cada plantilla permite:

- PDF base corporativo.
- Versionado.
- Activación/desactivación.
- Asociación por empresa, aseguradora y tipo de documento.
- Mapeos por página con coordenadas, fuente, tamaño, color, ancho, alineación, multilínea y formato.

El builder visual está en:

`/admin/config/aseguramiento/pdf-templates/{template}/builder`

Variables principales disponibles para colocar sobre el PDF:

- `{{ solicitante }}`
- `{{ solicitante_telefono }}`
- `{{ solicitud_fecha }}`
- `{{ beneficiario_nombre }}`
- `{{ beneficiario_domicilio }}`
- `{{ beneficiario_contacto }}`
- `{{ mercancia_estado }}`
- `{{ mercancia_referencia }}`
- `{{ fecha_inicio_seguro }}`
- `{{ origen_ciudad }}`
- `{{ origen_pais }}`
- `{{ destino_ciudad }}`
- `{{ destino_pais }}`
- `{{ medio_transporte }}`
- `{{ moneda }}`
- `{{ valor_factura }}`
- `{{ gastos_fletes }}`
- `{{ gastos_incrementales }}`
- `{{ seguro_contenedor }}`
- `{{ suma_asegurada_total }}`
- `{{ consignatario_nombre }}`
- `{{ consignatario_domicilio }}`
- `{{ consignatario_contacto }}`
- `{{ proveedor_nombre }}`
- `{{ proveedor_domicilio }}`
- `{{ proveedor_contacto }}`
- `{{ ref_terrestre }}`
- `{{ ref_talon_embarque }}`
- `{{ ref_contenedor_caja }}`
- `{{ ref_maritimo_bl }}`
- `{{ ref_maritimo_contenedor }}`
- `{{ ref_aereo_guia }}`
- `{{ ref_aereo_linea }}`
- `{{ acepta_informacion_veridica }}`

## Seguridad

- Permisos dedicados por administración, visualización, edición, exportación y reproceso.
- Archivos entrantes y generados en `private://` por defecto.
- Validación de extensión y MIME para Excel.
- Sanitización de nombres de archivo.
- CSRF en endpoint de guardado del builder.
- Filtros de dominio remitente y keywords de asunto.
- Preparado para cifrado de tokens sensibles mediante key configurable.

## Colas

Colas registradas:

- `aseguramiento_email_processing`
- `aseguramiento_excel_parsing`
- `aseguramiento_pdf_generation`
- `aseguramiento_mail_sending`

Drupal cron ejecuta cada worker según su ventana configurada. Para alto volumen, usa un runner dedicado de Queue API o un backend de colas distribuido compatible con Drupal.

## Auditoría

La entidad `ConstanciaEntity` conserva:

- Correo origen.
- Excel original.
- Provider usado.
- Plantilla usada.
- PDF generado.
- Metadata del correo.
- Logs.
- Errores.
- Timestamps.

Además, el módulo usa el canal logger `aseguramiento_automation`.

## Entidad principal

`aseguramiento_constancia` contiene:

- folio
- nombre
- poliza
- suma_asegurada
- vigencia
- rfc
- email
- telefono
- aseguradora
- tipo_documento
- company_id
- status
- plantilla_usada
- pdf_generado
- correo_origen
- excel_original
- provider_correo
- metadata
- logs
- errores
- timestamps

La entidad está integrada con Views y listados administrativos.

## Consideraciones enterprise pendientes por entorno

- Configurar SMTP real o un mail plugin con soporte de adjuntos.
- Definir private file system en `settings.php`.
- Crear app registration en Microsoft Entra ID.
- Habilitar PHP IMAP solo si se usará fallback.
- Definir estrategia de token encryption con Key module o KMS externo.
- Ejecutar colas con workers dedicados para cargas masivas.
- Revisar `composer audit` y políticas internas de seguridad antes de producción.
