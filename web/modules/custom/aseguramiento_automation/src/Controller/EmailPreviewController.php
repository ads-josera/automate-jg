<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Controller;

use Drupal\aseguramiento_automation\Service\MailService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the editable emails with sample data for the settings preview.
 *
 * Receives what is typed in the settings form (saved or not) and renders it
 * with the same MailService methods used to send, so the preview is what the
 * recipient gets. CSRF is enforced by the route (X-CSRF-Token header).
 */
final class EmailPreviewController extends ControllerBase {

  /**
   * Settings keys each preview may override with unsaved form values.
   */
  private const EDITABLE = [
    'client' => ['email_reply_subject', 'email_reply_body', 'email_reply_is_html'],
    'team' => ['notification_subject', 'notification_body'],
    'batch' => ['batch_subject_ok', 'batch_subject_partial', 'batch_subject_errors', 'batch_body'],
  ];

  public function __construct(private readonly MailService $mailService) {
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aseguramiento_automation.mail'));
  }

  public function preview(Request $request): JsonResponse {
    $input = json_decode($request->getContent(), TRUE);
    $template = is_array($input) ? (string) ($input['template'] ?? '') : '';
    if (!isset(self::EDITABLE[$template])) {
      return new JsonResponse(['error' => 'Plantilla desconocida.'], 400);
    }
    $settings = $this->config('aseguramiento_automation.settings')->getRawData();
    foreach (self::EDITABLE[$template] as $key) {
      if (array_key_exists($key, (array) ($input['values'] ?? []))) {
        $settings[$key] = $input['values'][$key];
      }
    }
    $settings['email_reply_is_html'] = !empty($settings['email_reply_is_html']);

    $rendered = match ($template) {
      'client' => $this->mailService->renderClientReply($this->sampleConstancia(), $settings),
      'team' => $this->mailService->renderInboundNotification(['from' => 'cliente@ejemplo.com', 'subject' => 'Solicitud de aseguramiento'], 2, $settings),
      'batch' => $this->mailService->renderBatchReply($this->sampleOk(), $this->sampleFailed(), $settings),
    };
    $is_html = $template !== 'client' || $settings['email_reply_is_html'];
    return new JsonResponse([
      'subject' => $rendered['subject'],
      'html' => $this->mailService->previewDocument($rendered['body'], $is_html),
      'note' => $template === 'batch' ? (string) $this->t('Ejemplo: 3 archivos, 2 constancias generadas y 1 que requiere corrección.') : '',
    ]);
  }

  private function sampleConstancia(): array {
    return [
      'folio' => 'AA-20260925-ejemplo',
      'nombre' => 'Cliente de ejemplo S.A. de C.V.',
      'solicitante' => 'Cliente de ejemplo S.A. de C.V.',
      'email' => 'cliente@ejemplo.com',
      'aseguradora' => 'Seguros Atlas',
      'tipo_documento' => 'Constancia',
      'beneficiario_nombre' => 'Beneficiario de ejemplo',
      'mercancia_asegurada' => 'Refacciones automotrices',
      'medio_transporte' => 'Terrestre',
      'origen_ciudad' => 'Monterrey',
      'destino_ciudad' => 'Ciudad de México',
      'fecha_inicio_seguro' => '2026-09-25',
      'moneda' => 'pesos',
      'suma_asegurada_total' => '150000.00',
    ];
  }

  private function sampleOk(): array {
    return [
      ['folio' => 'AA-20260925-ejemplo1', 'nombre' => 'Cliente de ejemplo S.A. de C.V.', 'pdf_uri' => ''],
      ['folio' => 'AA-20260925-ejemplo2', 'nombre' => 'Cliente de ejemplo S.A. de C.V.', 'pdf_uri' => ''],
    ];
  }

  private function sampleFailed(): array {
    return [[
      'file' => 'solicitud_3.xlsx',
      'folio' => 'AA-20260925-ejemplo3',
      'nombre' => 'Cliente de ejemplo S.A. de C.V.',
      'fields' => ['Medio de transporte: falta llenarlo', 'Fecha de inicio del seguro: la fecha no es válida; escríbela como 24/09/2026'],
      'internal' => [],
    ]];
  }

}
