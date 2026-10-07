<?php

/**
 * @file
 * Builds the two user guides on JG Mylard's letterhead.
 *
 * - docs/guia_usuario_aseguramiento.pdf: for clients (how to fill in and send
 *   a request, what they receive, how to correct).
 * - docs/guia_gestor_aseguramiento.pdf: for the team (panel, statuses, what
 *   to do with each error, the emails they receive).
 *
 * The letterhead (docs/Guia-para-el-*.pdf, from the Illustrator files) is the
 * background of every page. The amount limits are read from the settings,
 * so run this again whenever they change:
 * @code
 * ddev drush php:script scripts/build_guides.php
 * @endcode
 */

declare(strict_types=1);

use setasign\Fpdi\Tcpdf\Fpdi;

$docs = DRUPAL_ROOT . '/../docs/';
$limits = \Drupal::service('aseguramiento_automation.amount_limits');
$usd = $limits->forCurrency('USD');
$mxn = $limits->forCurrency('MXN');
if (!$usd || !$mxn) {
  throw new \RuntimeException('Configura los montos permitidos (USD y MXN) antes de generar las guías.');
}
$money = static fn(float $value): string => '$' . number_format($value, 2);
$range_usd = $money($usd['min']) . ' y ' . $money($usd['max']) . ' USD';
$range_mxn = $money($mxn['min']) . ' y ' . $money($mxn['max']) . ' MXN';

/**
 * Letterhead on every page; content between the header art and the footer.
 */
final class LetterheadPdf extends Fpdi {

  public string $letterhead = '';

  private ?string $template = NULL;

  /**
   * No "Powered by TCPDF" link (the constructor turns it back on).
   */
  public function hideTcpdfLink(): void {
    $this->tcpdflink = FALSE;
  }

  /**
   * Page setup shared by the guide and the copies used to measure.
   */
  public function setUp(string $letterhead, string $title): void {
    $this->hideTcpdfLink();
    $this->letterhead = $letterhead;
    $this->SetCreator('JG Mylard');
    $this->SetAuthor('JG Mylard');
    $this->SetTitle($title);
    $this->SetMargins(58, 112, 58);
    $this->SetAutoPageBreak(TRUE, 82);
    $this->setCellHeightRatio(1.35);
    $this->SetFont('helvetica', '', 10);
  }

  /**
   * Writes one section. A section that does not fit in what is left of the
   * page, but fits on a page of its own, starts on the next page instead of
   * leaving its heading alone at the bottom.
   */
  public function writeSection(string $html): void {
    $height = $this->measure($html);
    $left = $this->getPageHeight() - $this->getBreakMargin() - $this->GetY();
    if ($height !== NULL && $height > $left) {
      $this->AddPage();
    }
    $this->writeHTML($html, TRUE, FALSE, TRUE, FALSE, '');
  }

  /**
   * Height of $html on an empty page, or NULL if it needs more than a page.
   */
  private function measure(string $html): ?float {
    $probe = new self('P', 'pt', 'LETTER', TRUE, 'UTF-8', FALSE);
    $probe->setUp($this->letterhead, '');
    $probe->AddPage();
    $top = $probe->GetY();
    $probe->writeHTML($html, TRUE, FALSE, TRUE, FALSE, '');
    return $probe->getPage() === 1 ? $probe->GetY() - $top : NULL;
  }

  public function Header(): void {
    if ($this->template === NULL) {
      $this->setSourceFile($this->letterhead);
      $this->template = $this->importPage(1);
    }
    $this->useTemplate($this->template, 0, 0, 612, 792);
  }

  public function Footer(): void {
    // Page number above the letterhead's footer line.
    $this->SetY(-62);
    $this->SetFont('helvetica', '', 8);
    $this->SetTextColor(120, 126, 140);
    $this->Cell(0, 10, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
  }

}

// Design tokens: navy and pink from the letterhead.
$css = <<<'CSS'
<style>
  h1 { color: #26266f; font-size: 21pt; font-weight: bold; }
  h2 { color: #26266f; font-size: 13pt; font-weight: bold; }
  h3 { color: #26266f; font-size: 10.5pt; font-weight: bold; }
  p, li, td { color: #2b2f3a; font-size: 10pt; }
  .kicker { color: #e6007e; font-size: 8.5pt; font-weight: bold; }
  .lead { color: #4a5263; font-size: 11pt; }
  .num { color: #e6007e; }
  .muted { color: #5b6475; font-size: 9pt; }
  .th { color: #ffffff; font-weight: bold; font-size: 9pt; }
  .warn { color: #8a1238; }
</style>
CSS;

/**
 * Marks where a section starts; $render() keeps each one together.
 */
$section = static fn(string $html): string => "\x1E" . $html;

/**
 * Highlighted box: light background, used for "Lo esencial" and warnings.
 */
$box = static function (string $html, string $bg = '#f1f2fa'): string {
  return '<table cellpadding="10" cellspacing="0"><tr><td bgcolor="' . $bg . '">' . $html . '</td></tr></table>';
};

/**
 * Two- or three-column table with a navy header row.
 */
$table = static function (array $head, array $rows, array $widths): string {
  // <thead>: the header row repeats if a long table continues on next page.
  $out = '<table cellpadding="6" cellspacing="0" border="0"><thead><tr>';
  foreach ($head as $i => $h) {
    $out .= '<td width="' . $widths[$i] . '%" bgcolor="#26266f"><span class="th">' . $h . '</span></td>';
  }
  $out .= '</tr></thead>';
  foreach ($rows as $n => $row) {
    $bg = $n % 2 ? '#ffffff' : '#f6f7fb';
    $out .= '<tr nobr="true">';
    foreach ($row as $i => $cell) {
      $out .= '<td width="' . $widths[$i] . '%" bgcolor="' . $bg . '">' . $cell . '</td>';
    }
    $out .= '</tr>';
  }
  return $out . '</table>';
};

$render = static function (string $letterhead, string $title, string $html, string $out) use ($css): void {
  $pdf = new LetterheadPdf('P', 'pt', 'LETTER', TRUE, 'UTF-8', FALSE);
  $pdf->setUp($letterhead, $title);
  $pdf->AddPage();
  foreach (explode("\x1E", $html) as $n => $part) {
    if ($n > 0) {
      $pdf->Ln(8);
    }
    // Styles apply per writeHTML() call.
    $pdf->writeSection(str_starts_with($part, $css) ? $part : $css . $part);
  }
  $pdf->Output($out, 'F');
};

// ---------------------------------------------------------------------------
// Guía para el usuario (cliente).
// ---------------------------------------------------------------------------
$required = [
  ['Solicitante', 'Nombre de quien solicita el seguro.'],
  ['Beneficiario: Nombre', 'A favor de quién se emite la constancia.'],
  ['Mercancía asegurada', 'Descripción de lo que se transporta.'],
  ['Fecha inicio seguro', 'Día/mes/año, por ejemplo 24/09/2026.'],
  ['Ciudad de origen', 'De dónde sale la mercancía.'],
  ['Ciudad de destino', 'A dónde llega la mercancía.'],
  ['Medio transporte', 'Terrestre, marítimo o aéreo.'],
  ['Moneda', 'USD o PESOS.'],
  ['Valor factura', 'Solo números, por ejemplo 150000.00.'],
];
$required_rows = array_map(static fn(array $r): array => ['<b>' . $r[0] . '</b>', $r[1]], $required);

$messages = [
  ['«falta llenarlo»', 'Llene ese campo; es obligatorio.'],
  ['«la fecha no es válida; escríbela como 24/09/2026»', 'Escriba la fecha como día/mes/año.'],
  ['«debe ser una cantidad, por ejemplo 150000.00»', 'Escriba solo números, sin letras ni otros símbolos.'],
  ['«elige USD o PESOS»', 'Seleccione la moneda en la lista del formato.'],
  ['«no puede ser negativo»', 'Los montos deben ser 0 o mayores.'],
  ['«… supera el máximo …» o «… es menor que el mínimo …»', 'Revise los montos: la suma asegurada total debe quedar dentro del rango permitido.'],
  ['«No pudimos leer el archivo»', 'El archivo está dañado o no es el formato. Vuelva a llenar el formato original y guárdelo como .xlsx.'],
  ['«El PDF está protegido con contraseña»', 'Guarde el PDF sin contraseña y envíelo de nuevo.'],
  ['«El PDF no tiene campos rellenables»', 'Si llenó el Excel y lo guardó como PDF, envíe el archivo .xlsx. Si usó el formato PDF, llénelo con Adobe Acrobat Reader.'],
];

$user = $css
  . '<span class="kicker">GUÍA PARA EL USUARIO</span>'
  . '<h1>Cómo enviar su solicitud de aseguramiento</h1>'
  . '<p class="lead">Envíe su solicitud por correo y reciba su constancia de aseguramiento en PDF, normalmente en pocos minutos. Siga estos pasos para que se procese sin contratiempos.</p>'
  . $box(
    '<h3>Lo esencial</h3>'
    . '<b>Envíe a:</b> solicitud@jgmylard.work<br>'
    . '<b>Asunto:</b> Solicitud de aseguramiento<br>'
    . '<b>Adjunte:</b> el formato de Excel lleno (.xlsx) o el formato PDF llenado con Adobe Acrobat Reader.<br>'
    . '<b>Respuesta:</b> llega al mismo correo desde el que envió la solicitud.'
  )

  . $section('<h2><span class="num">1.</span> Use el formato oficial</h2>'
  . '<p>Utilice siempre el formato que le proporcionó JG Mylard:</p>'
  . '<ul><li><b>Formato Excel</b> (recomendado): solicitud_aseguramiento_formato.xlsx</li>'
  . '<li><b>Formato PDF rellenable:</b> solicitud_aseguramiento_rellenable.pdf</li></ul>'
  . '<p>No copie los datos a un archivo nuevo ni use una versión anterior del formato: el sistema solo puede leer el formato oficial.</p>')

  . $section('<h2><span class="num">2.</span> Llene los datos</h2>'
  . '<p>Los campos marcados en <b>rojo</b> son obligatorios. El color se quita cuando el campo queda lleno.</p>'
  . $table(['Campo obligatorio', 'Qué escribir'], $required_rows, [38, 62])
  . '<h3>Montos y suma asegurada</h3>'
  . '<ul>'
  . '<li>Escriba los montos solo con números, iguales o mayores a 0 (por ejemplo 150000.00).</li>'
  . '<li>La <b>suma asegurada total</b> se calcula sola: Valor factura + Gastos de fletes + Gastos incrementales + Seguro contenedor. No necesita escribirla.</li>'
  . '<li>La suma asegurada total debe quedar entre <b>' . $range_usd . '</b> o entre <b>' . $range_mxn . '</b>, según la moneda. Los límites se aceptan. Si queda fuera, el formato la marca en rojo e indica el rango.</li>'
  . '</ul>'
  . '<p>Complete también los demás datos que apliquen a su embarque (beneficiario, consignatario, proveedor y referencias de transporte) y confirme la declaración de veracidad al final del formato.</p>')

  . $section('<h2><span class="num">3.</span> Guarde el archivo correctamente</h2>'
  . '<ul>'
  . '<li><b>Excel:</b> guárdelo como libro de Excel (<b>.xlsx</b>). Puede ponerle el nombre que quiera, por ejemplo «Embarque Monterrey 24-09.xlsx».</li>'
  . '<li><b>No</b> lo guarde como PDF, CSV ni en otro formato, y no cambie la extensión del archivo: el sistema no podría leer sus datos.</li>'
  . '<li><b>PDF rellenable:</b> llénelo y guárdelo con <b>Adobe Acrobat Reader</b> (gratuito). Con otros programas, como Vista Previa de Mac o el navegador, pueden dejar de funcionar los avisos de campos obligatorios y la suma automática. No le ponga contraseña.</li>'
  . '</ul>')

  . $section('<h2><span class="num">4.</span> Envíe su solicitud</h2>'
  . '<ul>'
  . '<li><b>Para:</b> solicitud@jgmylard.work</li>'
  . '<li><b>Asunto:</b> Solicitud de aseguramiento</li>'
  . '<li>Adjunte un archivo por cada solicitud. Puede enviar <b>varios archivos en un mismo correo</b>: recibirá una sola respuesta con todo.</li>'
  . '<li>No envíe dos veces la misma solicitud: se generaría otra constancia.</li>'
  . '</ul>'
  . $box('<span class="warn"><b>Importante:</b> el asunto debe decir «Solicitud de aseguramiento». Si el correo llega con otro asunto, el sistema no lo procesa y no recibirá respuesta.</span>', '#fdf1f5'))

  . $section('<h2><span class="num">5.</span> Qué recibirá</h2>'
  . '<p>La respuesta suele llegar en pocos minutos. Algunos proveedores de correo tardan más en entregar los mensajes, a veces hasta 20 o 30 minutos.</p>'
  . $table(['Resultado', 'Qué recibe'], [
    ['<b>Todo correcto</b>', 'Un correo con su constancia (o sus constancias) en PDF.'],
    ['<b>Algunas solicitudes con errores</b>', 'Un correo con las constancias que sí se generaron y, por cada archivo con error, qué debe corregir.'],
    ['<b>Todas con errores</b>', 'Un correo que indica que su solicitud requiere correcciones y qué corregir en cada archivo.'],
  ], [34, 66]))

  . $section('<h2><span class="num">6.</span> Si le pedimos correcciones</h2>'
  . '<ol>'
  . '<li>Corrija el archivo indicado en el correo.</li>'
  . '<li>Use <b>Responder</b> en ese mismo correo, sin cambiar el asunto.</li>'
  . '<li>Adjunte <b>solo el archivo corregido</b>. No vuelva a enviar las solicitudes que ya recibieron constancia.</li>'
  . '</ol>'
  . '<p>Recibirá su constancia y nuestro equipo verá que esa solicitud quedó corregida.</p>')

  . $section('<h2><span class="num">7.</span> Mensajes que puede recibir</h2>'
  . $table(['Mensaje', 'Qué hacer'], $messages, [48, 52]))

  . $section('<h2><span class="num">8.</span> Recomendaciones</h2>'
  . '<ul>'
  . '<li>Agregue <b>solicitud@jgmylard.work</b> a sus contactos para que nuestras respuestas no lleguen a la carpeta de spam.</li>'
  . '<li>Si no recibe respuesta en 30 minutos, revise su carpeta de spam y confirme el destinatario, el asunto y el archivo adjunto.</li>'
  . '<li>Si tiene dudas, comuníquese con su ejecutivo de JG Mylard o a los teléfonos que aparecen al pie de esta guía.</li>'
  . '</ul>')

  . $section('<h2>Antes de enviar, revise</h2>'
  . $box(
    '<span style="font-family:dejavusans;">&#9744;</span>&nbsp; Usé el formato oficial y llené todos los campos en rojo.<br>'
    . '<span style="font-family:dejavusans;">&#9744;</span>&nbsp; La fecha está como día/mes/año y la moneda es USD o PESOS.<br>'
    . '<span style="font-family:dejavusans;">&#9744;</span>&nbsp; La suma asegurada total está dentro del rango permitido.<br>'
    . '<span style="font-family:dejavusans;">&#9744;</span>&nbsp; Guardé el Excel como .xlsx (o el PDF con Adobe Acrobat Reader).<br>'
    . '<span style="font-family:dejavusans;">&#9744;</span>&nbsp; El correo va a solicitud@jgmylard.work con el asunto «Solicitud de aseguramiento».'
  ));

$render($docs . 'Guia-para-el-usuario.pdf', 'Guía para el usuario: solicitud de aseguramiento', $user, $docs . 'guia_usuario_aseguramiento.pdf');

// ---------------------------------------------------------------------------
// Guía para el gestor (equipo de JG Mylard).
// ---------------------------------------------------------------------------
$gestor = $css
  . '<span class="kicker">GUÍA PARA EL GESTOR</span>'
  . '<h1>Panel de constancias de aseguramiento</h1>'
  . '<p class="lead">Todo lo que necesitas para dar seguimiento a las solicitudes de los clientes: cómo funciona el sistema, qué significa cada estado y qué hacer cuando algo requiere atención.</p>'

  . $section('<h2><span class="num">1.</span> Cómo funciona</h2>'
  . $table(['Paso', 'Qué pasa'], [
    ['<b>1</b>', 'El cliente envía su formato a <b>solicitud@jgmylard.work</b> con el asunto «Solicitud de aseguramiento».'],
    ['<b>2</b>', 'El sistema revisa el buzón cada minuto.'],
    ['<b>3</b>', 'Lee cada archivo y revisa los datos obligatorios, las fechas y los montos.'],
    ['<b>4</b>', 'Genera una constancia en PDF por cada solicitud correcta.'],
    ['<b>5</b>', 'Responde al cliente con un solo correo: sus constancias y, si hay errores, qué corregir.'],
    ['<b>6</b>', 'Te envía un resumen del resultado, con los formatos originales del cliente adjuntos.'],
  ], [12, 88])
  . '<p>El sistema procesa cada correo en segundos. El tiempo total depende del proveedor de correo del cliente: con Gmail suele ser de 1 a 2 minutos; con Yahoo hemos visto entregas de hasta 20 o 30 minutos.</p>')

  . $section('<h2><span class="num">2.</span> Entrar al panel</h2>'
  . '<ul>'
  . '<li>Entra en <b>https://jgmylard.work/user/login</b> con tu usuario y contraseña.</li>'
  . '<li>Arriba tienes tres secciones: <b>Panel</b>, <b>Constancias</b> y <b>Exportar</b>, y el botón <b>Cerrar sesión</b>.</li>'
  . '<li>No compartas tu cuenta. Cada acción (reprocesar, marcar como resuelta) queda registrada con tu usuario.</li>'
  . '</ul>')

  . $section('<h2><span class="num">3.</span> Panel</h2>'
  . '<p>Muestra los totales y las constancias más recientes:</p>'
  . $table(['Contador', 'Significado'], [
    ['<b>Pendientes</b>', 'Solicitudes en espera de procesarse.'],
    ['<b>Validadas</b>', 'Datos correctos; el PDF está por generarse.'],
    ['<b>PDF generado</b>', 'Constancias con su PDF disponible.'],
    ['<b>Enviadas</b>', 'Constancias que el cliente ya recibió.'],
    ['<b>Con error</b>', 'Requieren revisión. Es el número que hay que vigilar.'],
  ], [30, 70]))

  . $section('<h2><span class="num">4.</span> Constancias</h2>'
  . '<p>Lista todas las constancias. Puedes <b>buscar</b> por folio, cliente, correo o póliza, y elegir cuántas <b>mostrar</b> por página. Cada fila tiene su estado, el enlace para abrir el PDF y, cuando aplica, el botón <b>Reprocesar</b>. Haz clic en el folio para ver el detalle.</p>'
  . $table(['Estado', 'Qué significa'], [
    ['<b>Pendiente, En cola, Validando, Validado</b>', 'El sistema la está procesando; normalmente toma segundos.'],
    ['<b>PDF generado</b>', 'La constancia está lista y por enviarse al cliente.'],
    ['<b>Enviado</b>', 'El cliente ya recibió su constancia.'],
    ['<b>Error</b>', 'Requiere revisión. Pasa el puntero (o toca) sobre «Error» para ver el motivo sin abrirla.'],
    ['<b>Corregida</b>', 'Tenía error y ya se resolvió: el cliente envió el archivo corregido, o alguien la marcó como resuelta.'],
  ], [36, 64]))

  . $section('<h2><span class="num">5.</span> Qué hacer con una constancia en error</h2>'
  . '<p>Abre el detalle: el sistema te dice de qué tipo de error se trata.</p>'
  . $table(['Tipo de error', 'Qué hacer'], [
    ['<b>El cliente debe corregir la solicitud</b><br><span class="muted">Datos faltantes o inválidos, montos fuera de rango.</span>', 'El cliente ya recibió qué debe corregir. Cuando <b>responde a ese mismo correo</b> con el archivo corregido, se genera la nueva constancia y la anterior pasa sola a <b>Corregida</b>, enlazada con el folio nuevo. Si tarda en responder, contáctalo.'],
    ['<b>Error interno (no es del cliente)</b><br><span class="muted">Falló algo de nuestro lado al generar o enviar.</span>', 'Usa <b>Reprocesar y enviar</b>. El PDF se genera de nuevo y se envía al cliente en 1 o 2 minutos. Si el error se repite, avisa al administrador del sistema.'],
    ['<b>Se resolvió por otra vía</b><br><span class="muted">El cliente mandó el corregido en un correo nuevo, lo resolvió por teléfono o canceló.</span>', 'Usa <b>Marcar como resuelta</b> en el detalle y escribe una nota breve. No se envía nada al cliente; queda registrado quién la marcó, cuándo y la nota.'],
  ], [38, 62])
  . $box('<b>¿Por qué a veces no pasa sola a «Corregida»?</b> Solo se enlaza automáticamente cuando el cliente <b>responde</b> al correo que recibió. Si envía el corregido en un correo nuevo, o el sistema no puede saber con seguridad qué solicitud corrige, la anterior se queda en «Error» para que la marques tú como resuelta. Así nunca se oculta una solicitud pendiente por error.'))

  . $section('<h2><span class="num">6.</span> Detalle de una constancia</h2>'
  . '<ul>'
  . '<li>Los datos capturados, el estado y las fechas.</li>'
  . '<li><b>Abrir PDF generado</b> y la vista previa del documento.</li>'
  . '<li>Los errores, explicados como los leyó el cliente.</li>'
  . '<li>Si fue corregida, el enlace al folio nuevo (y en la nueva, el enlace a la que corrige).</li>'
  . '</ul>')

  . $section('<h2><span class="num">7.</span> Correos que recibes</h2>'
  . '<p><b>Resumen por cada correo de cliente.</b> Llega después de responderle, con el asunto «Solicitud de [cliente]: [resultado]». Incluye:</p>'
  . '<ul>'
  . '<li>Una tabla por archivo: <b>Constancia</b> con su folio (y «corrige AA-…» si corrige una anterior),&nbsp;<b>Por corregir</b> con el motivo, o <b>Error interno</b>.</li>'
  . '<li>Si el cliente ya recibió su respuesta.</li>'
  . '<li>Los formatos originales del cliente adjuntos.</li>'
  . '</ul>'
  . $table(['Si el resumen dice…', 'Qué hacer'], [
    ['«No se pudo enviar la respuesta al cliente»', 'Envíale la respuesta manualmente con los PDF del panel.'],
    ['«No hay un correo válido del cliente»', 'Busca su correo y envíale la respuesta manualmente.'],
    ['«Sin archivos de solicitud»', 'El cliente no adjuntó un Excel o PDF. Pídele que reenvíe con el formato.'],
  ], [42, 58])
  . '<p><b>Copia oculta de la respuesta al cliente.</b> Siempre que hay correcciones, y también en las respuestas exitosas si está activada la opción. Los destinatarios (hasta 3 correos) los configura el administrador.</p>')

  . $section('<h2><span class="num">8.</span> Exportar</h2>'
  . '<p>Descarga la información de las constancias en <b>CSV</b> o <b>Excel</b>. Puedes filtrar por aseguradora, estado, póliza, cliente y rango de fechas.</p>')

  . $section('<h2><span class="num">9.</span> Cuando un cliente dice «envié y no me llegó nada»</h2>'
  . '<ol>'
  . '<li>Que lo haya enviado a <b>solicitud@jgmylard.work</b>.</li>'
  . '<li>Que el asunto diga <b>«Solicitud de aseguramiento»</b>. Si no, el sistema descarta el correo sin avisar a nadie: ni el cliente ni tú reciben nada.</li>'
  . '<li>Que haya adjuntado el formato en <b>.xlsx</b> o el PDF rellenable (no un Excel guardado como PDF).</li>'
  . '<li>Que revise su carpeta de spam y espere unos minutos si su correo es de Yahoo u otro proveedor lento.</li>'
  . '<li>Si todo está bien y no aparece en el panel, pídele que lo reenvíe o avisa al administrador para revisar el buzón.</li>'
  . '</ol>')

  . $section('<h2><span class="num">10.</span> Reglas del formato, para resolver dudas</h2>'
  . '<ul>'
  . '<li>Obligatorios (en rojo): Solicitante, Beneficiario (nombre), Mercancía asegurada, Fecha inicio seguro, Ciudad de origen, Ciudad de destino, Medio de transporte, Moneda y Valor factura.</li>'
  . '<li>Fecha como día/mes/año (24/09/2026). Moneda: USD o PESOS. Montos: números de 0 o más.</li>'
  . '<li>La suma asegurada total se calcula sola y debe quedar entre <b>' . $range_usd . '</b> o entre <b>' . $range_mxn . '</b>. Si un embarque legítimo necesita otro rango, el administrador puede ajustar los límites.</li>'
  . '<li>El Excel puede llevar cualquier nombre, pero debe enviarse como .xlsx. El PDF rellenable se llena con Adobe Acrobat Reader y sin contraseña.</li>'
  . '<li>Si un cliente envía dos veces la misma solicitud, se generan dos constancias: avisa al administrador.</li>'
  . '</ul>'
  . '<p class="muted">Para los clientes existe la «Guía para el usuario», con los pasos para llenar y enviar su solicitud.</p>');

$render($docs . 'Guia-para-el-gestor.pdf', 'Guía para el gestor: panel de constancias de aseguramiento', $gestor, $docs . 'guia_gestor_aseguramiento.pdf');

echo 'Guías generadas: docs/guia_usuario_aseguramiento.pdf y docs/guia_gestor_aseguramiento.pdf' . PHP_EOL;
