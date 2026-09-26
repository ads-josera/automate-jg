# Aseguramiento Automation

Sistema enterprise de automatización documental para Drupal 10/11.

## Capacidades

- Lectura de correos por capa desacoplada de providers.
- Microsoft Graph como proveedor principal para Microsoft 365.
- IMAP (cliente PHP puro, sin extensión) para buzones cPanel/Dovecot y otros proveedores.
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
composer require phpoffice/phpspreadsheet setasign/fpdi tecnickcom/tcpdf firebase/php-jwt directorytree/imapengine
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
6. `MailSendingQueueWorker` responde una sola vez por correo recibido (lote) con todos los PDF y lo que haya que corregir.

### Respuesta al cliente: un correo por solicitud (lote)

Cada correo recibido forma un **lote** (`SolicitudBatchService`, almacén key-value con caducidad de 30 días; cada constancia guarda su lote en el campo `lote`). El cliente recibe **una sola respuesta por correo enviado**, aunque adjunte varios archivos:

- Un solo Excel válido: el correo configurado de siempre (asunto y plantilla de ajustes) con su PDF.
- Varios archivos o alguno con errores: un correo con todos los PDF generados y, por archivo, qué corregir con las etiquetas del formato (`Util\SolicitudErrorFormatter`). El encargado (`notification_emails`) recibe copia oculta.
- Archivo ilegible o sin datos: se informa en la misma respuesta en lugar de perderse.
- Problemas internos (sin plantilla, fallo al generar el PDF): se avisa que no hay nada que corregir y que el equipo dará seguimiento.
- La respuesta espera a que todo el lote termine (reintento diferido de la cola); tras 30 minutos responde con lo que haya. Nunca responde dos veces el mismo lote. Si el envío falla, reintenta hasta 5 veces.
- Prueba de punta a punta: `scripts/batch_reply_check.php` (GreenMail + Mailpit).

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
- `SolicitudBatchService`

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

## IMAP

Soporta host, puerto, cifrado, usuario, contraseña y carpeta. Usa la librería `directorytree/imapengine` (PHP puro): **no requiere la extensión PHP IMAP**, que salió del núcleo en PHP 8.4.

- Cifrado: `ssl` = TLS implícito (puerto 993); `tls` = STARTTLS (puerto 143); `none` = sin cifrado y sin validar certificado (solo redes de confianza).
- Los correos se identifican por **UID**, nunca por número de secuencia: los números de secuencia se recorren al mover/expurgar otro correo y hacían que un elemento en cola apuntara al correo de otro cliente.
- **Qué correos se leen:** los no leídos, y también los leídos que llegaron después de la primera lectura de la carpeta (alguien pudo abrir la solicitud en el webmail antes que el sistema). La marca "leído" ya no decide: cada correo se registra en `ProcessedMailRegistry` (por Message-ID) antes de encolarse, así que se procesa una sola vez aunque no se pueda mover de la bandeja.
- **Primera lectura:** la primera vez que se lee una carpeta se guarda su siguiente UID (state `aseguramiento_automation.imap_uid_marks`); los correos que ya estaban (pruebas viejas, solicitudes ya contestadas) no se tocan. Si el servidor renumera la carpeta (cambia UIDVALIDITY) se vuelve a tomar la marca.
- **Carpetas de spam** (campo "Carpetas de spam a revisar" de la cuenta; por defecto `spam, Junk`): cada correo nuevo en ellas se revisa una vez, sin descargarlo. Si el asunto tiene una de las palabras clave de solicitud (sin palabras clave configuradas no se rescata nada) y trae un Excel o PDF adjunto, se mueve a la bandeja de entrada y se procesa como cualquier otro; se registra en el log como "Solicitud rescatada". El resto del spam no se toca.
- **Fallos:** si procesar un correo falla, se reintenta cada 15 minutos hasta 3 intentos; después se mueve a la carpeta de errores y se registra. El correo se marca como leído al descargar los adjuntos.
- Las carpetas (`Processed`, `Errors`, spam) se buscan por nombre, también como `INBOX.<nombre>` (espacio de nombres de Dovecot en cPanel) y sin distinguir mayúsculas. Si la de destino no existe, el correo queda en la bandeja y se registra una advertencia; el registro evita que se procese otra vez.
- Pruebas contra un servidor IMAP real (GreenMail): `scripts/imap_integration_check.php` y `scripts/mailbox_reading_check.php` (primera lectura, correo abierto en webmail, rescate de spam, sin duplicados, reintentos).

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
- `{{ ref_maritimo }}`
- `{{ ref_maritimo_bl }}`
- `{{ ref_maritimo_contenedor }}`
- `{{ ref_aereo }}`
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
- Crear en el buzón las carpetas `Processed` y `Errors` (o las configuradas en la cuenta).
- Definir estrategia de token encryption con Key module o KMS externo.
- Ejecutar colas con workers dedicados para cargas masivas.
- Revisar `composer audit` y políticas internas de seguridad antes de producción.
