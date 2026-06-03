<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines a versioned PDF overlay template.
 *
 * @ConfigEntityType(
 *   id = "aseguramiento_pdf_template",
 *   label = @Translation("Plantilla PDF"),
 *   label_collection = @Translation("Plantillas PDF"),
 *   handlers = {
 *     "list_builder" = "Drupal\aseguramiento_automation\Entity\PdfTemplateListBuilder",
 *     "form" = {
 *       "add" = "Drupal\aseguramiento_automation\Form\PdfTemplateForm",
 *       "edit" = "Drupal\aseguramiento_automation\Form\PdfTemplateForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   config_prefix = "pdf_template",
 *   admin_permission = "administer aseguramiento automation",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   links = {
 *     "add-form" = "/admin/config/aseguramiento/pdf-templates/add",
 *     "edit-form" = "/admin/config/aseguramiento/pdf-templates/{aseguramiento_pdf_template}",
 *     "delete-form" = "/admin/config/aseguramiento/pdf-templates/{aseguramiento_pdf_template}/delete",
 *     "collection" = "/admin/config/aseguramiento/pdf-templates"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "insurer",
 *     "document_type",
 *     "version",
 *     "file_uri",
 *     "pages",
 *     "mappings",
 *     "company_id",
 *     "weight"
 *   }
 * )
 */
final class PdfTemplate extends ConfigEntityBase {

  protected string $id;

  protected string $label;

  protected string $insurer = '';

  protected string $document_type = '';

  protected string $version = '1.0.0';

  protected string $file_uri = '';

  protected int $pages = 1;

  protected array $mappings = [];

  protected string $company_id = '';

  protected int $weight = 0;

  public function getFileUri(): string {
    return $this->file_uri;
  }

  public function getMappings(): array {
    return $this->mappings;
  }

  public function setMappings(array $mappings): self {
    $this->set('mappings', $mappings);
    return $this;
  }

  public function matches(array $context): bool {
    foreach (['insurer' => 'aseguradora', 'document_type' => 'tipo_documento', 'company_id' => 'company_id'] as $property => $key) {
      $expected = trim((string) $this->{$property});
      if ($expected !== '' && strcasecmp($expected, (string) ($context[$key] ?? '')) !== 0) {
        return FALSE;
      }
    }
    return $this->status();
  }

}
