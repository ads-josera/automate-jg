<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Stores an auditable insurance document automation record.
 *
 * @ContentEntityType(
 *   id = "aseguramiento_constancia",
 *   label = @Translation("Constancia"),
 *   label_collection = @Translation("Constancias"),
 *   label_singular = @Translation("constancia"),
 *   label_plural = @Translation("constancias"),
 *   label_count = @PluralTranslation(
 *     singular = "@count constancia",
 *     plural = "@count constancias"
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\aseguramiento_automation\Entity\ConstanciaListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "add" = "Drupal\aseguramiento_automation\Form\ConstanciaForm",
 *       "edit" = "Drupal\aseguramiento_automation\Form\ConstanciaForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "aseguramiento_constancia",
 *   data_table = "aseguramiento_constancia_field_data",
 *   translatable = FALSE,
 *   admin_permission = "administer aseguramiento automation",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "folio",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "canonical" = "/admin/aseguramiento/constancia/{aseguramiento_constancia}",
 *     "add-form" = "/admin/aseguramiento/constancia/add",
 *     "edit-form" = "/admin/aseguramiento/constancia/{aseguramiento_constancia}/edit",
 *     "delete-form" = "/admin/aseguramiento/constancia/{aseguramiento_constancia}/delete",
 *     "collection" = "/admin/aseguramiento/constancias"
 *   },
 *   field_ui_base_route = "entity.aseguramiento_constancia.collection"
 * )
 */
final class ConstanciaEntity extends ContentEntityBase implements ConstanciaEntityInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if (!$this->getOwnerId()) {
      $this->setOwnerId(0);
    }
    if ($this->get('folio')->isEmpty()) {
      $this->set('folio', sprintf('AA-%s-%06d', date('Ymd'), random_int(1, 999999)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['folio'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Folio'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 80)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -20])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => -20])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['nombre'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Nombre'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['poliza'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Póliza'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['suma_asegurada'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Suma asegurada'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 2)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => -8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['vigencia'] = BaseFieldDefinition::create('daterange')
      ->setLabel(t('Vigencia'))
      ->setDisplayOptions('form', ['type' => 'daterange_default', 'weight' => -7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['rfc'] = BaseFieldDefinition::create('string')
      ->setLabel(t('RFC'))
      ->setSetting('max_length', 13)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Correo electrónico'))
      ->setDisplayOptions('form', ['type' => 'email_default', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['telefono'] = BaseFieldDefinition::create('telephone')
      ->setLabel(t('Teléfono'))
      ->setDisplayOptions('form', ['type' => 'telephone_default', 'weight' => -4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['aseguradora'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Aseguradora'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['tipo_documento'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tipo documento'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['company_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Empresa / cliente'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    foreach (static::solicitudStringFields() as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('string')
        ->setLabel($label)
        ->setSetting('max_length', 255)
        ->setDisplayOptions('form', ['type' => 'string_textfield'])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    foreach (static::solicitudLongTextFields() as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('string_long')
        ->setLabel($label)
        ->setDisplayOptions('form', ['type' => 'string_textarea'])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    foreach (static::solicitudDateFields() as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('datetime')
        ->setLabel($label)
        ->setSetting('datetime_type', 'date')
        ->setDisplayOptions('form', ['type' => 'datetime_default'])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    foreach (static::solicitudAmountFields() as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('decimal')
        ->setLabel($label)
        ->setSetting('precision', 14)
        ->setSetting('scale', 2)
        ->setDisplayOptions('form', ['type' => 'number'])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    $fields['mercancia_estado'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Mercancía'))
      ->setSettings([
        'allowed_values' => [
          'nueva' => 'Nueva',
          'usada' => 'Usada',
        ],
      ])
      ->setDisplayOptions('form', ['type' => 'options_buttons'])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['moneda'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Conceptos por asegurar en'))
      ->setSettings([
        'allowed_values' => [
          'usd' => 'USD',
          'pesos' => 'Pesos',
        ],
      ])
      ->setDisplayOptions('form', ['type' => 'options_buttons'])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['acepta_informacion_veridica'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Acepta que la información declarada es verídica'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox'])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Estado'))
      ->setDefaultValue('pending')
      ->setSettings([
        'allowed_values' => [
          'pending' => 'Pendiente',
          'queued' => 'En cola',
          'validating' => 'Validando',
          'validated' => 'Validado',
          'pdf_generated' => 'PDF generado',
          'sent' => 'Enviado',
          'error' => 'Error',
        ],
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    foreach ([
      'plantilla_usada' => t('Plantilla usada'),
      'pdf_generado' => t('PDF generado'),
      'correo_origen' => t('Correo origen'),
      'excel_original' => t('Excel original'),
      'provider_correo' => t('Proveedor de correo'),
    ] as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('string')
        ->setLabel($label)
        ->setSetting('max_length', 512)
        ->setDisplayOptions('form', ['type' => 'string_textfield'])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    $fields['metadata'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Metadatos'));

    $fields['logs'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Bitácora'))
      ->setDisplayOptions('form', ['type' => 'string_textarea'])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['errores'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Errores'))
      ->setDisplayOptions('form', ['type' => 'string_textarea'])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['fecha_procesamiento'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Fecha procesamiento'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Creado'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Actualizado'));

    return $fields;
  }

  /**
   * Returns single-line fields for the insurance request form.
   */
  public static function solicitudStringFields(): array {
    return [
      'solicitante' => t('Solicitante'),
      'solicitante_telefono' => t('Teléfono del solicitante'),
      'beneficiario_nombre' => t('Beneficiario - Nombre'),
      'beneficiario_contacto' => t('Beneficiario - Contacto'),
      'mercancia_referencia' => t('Mercancía - Referencia'),
      'origen_ciudad' => t('Ciudad de origen'),
      'origen_pais' => t('País de origen'),
      'destino_ciudad' => t('Ciudad destino'),
      'destino_pais' => t('País destino'),
      'medio_transporte' => t('Medio de transporte'),
      'consignatario_nombre' => t('Consignatario - Nombre'),
      'consignatario_contacto' => t('Consignatario - Contacto'),
      'proveedor_nombre' => t('Proveedor - Nombre'),
      'proveedor_contacto' => t('Proveedor - Contacto'),
      'ref_terrestre' => t('Referencia terrestre'),
      'ref_talon_embarque' => t('Talón de embarque'),
      'ref_contenedor_caja' => t('N° de contenedor o caja'),
      'ref_maritimo_bl' => t('B/L marítimo'),
      'ref_maritimo_contenedor' => t('Contenedor marítimo'),
      'ref_aereo_guia' => t('N° de guía aérea'),
      'ref_aereo_linea' => t('Línea aérea'),
    ];
  }

  /**
   * Returns long text fields for addresses.
   */
  public static function solicitudLongTextFields(): array {
    return [
      'mercancia_asegurada' => t('Mercancía asegurada'),
      'beneficiario_domicilio' => t('Beneficiario - Domicilio'),
      'consignatario_domicilio' => t('Consignatario - Domicilio'),
      'proveedor_domicilio' => t('Proveedor - Domicilio'),
    ];
  }

  /**
   * Returns date fields for the request.
   */
  public static function solicitudDateFields(): array {
    return [
      'solicitud_fecha' => t('Fecha de solicitud'),
      'fecha_inicio_seguro' => t('Fecha de inicio del seguro'),
    ];
  }

  /**
   * Returns amount fields for insured concepts.
   */
  public static function solicitudAmountFields(): array {
    return [
      'valor_factura' => t('Valor factura'),
      'gastos_fletes' => t('Gastos de fletes'),
      'gastos_incrementales' => t('Gastos incrementales'),
      'seguro_contenedor' => t('Seguro del contenedor'),
      'suma_asegurada_total' => t('Suma asegurada total'),
    ];
  }

  /**
   * Returns all request fields that can be mapped into PDFs.
   */
  public static function solicitudPdfFields(): array {
    return array_merge(array_keys(static::solicitudStringFields() + static::solicitudLongTextFields() + static::solicitudDateFields() + static::solicitudAmountFields()), [
      'mercancia_estado',
      'moneda',
      'acepta_informacion_veridica',
    ]);
  }

}
