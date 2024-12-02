<?php

namespace Drupal\rest_oai_pmh\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines an interface for OAI Metadata Map plugins.
 */
interface OaiMetadataMapInterface extends PluginInspectionInterface {

  /**
   * Fetch info for ListMetadataFormats call.
   *
   * @return string[]
   *   An associative array mapping containing:
   *   - metadataPrefix: The metadata prefix for this format.
   *   - schema: The XSD of this format.
   *   - metadataNamespace: The namespace URI for the prefix.
   */
  public function getMetadataFormat();

  /**
   * Get metadata wrapper element and related info.
   *
   * @return string[][]
   *   An associative array mapping the name of the wrapping element to
   *   data as accepted by Symfony's XmlEncoder::encode()
   *   (and XmlEncoder::buildXml()) method.
   *
   *   NOTE: There's presently two specially-handled values:
   *   - xml-metadata: Is populated/overwritten via
   *     \Drupal\rest_oai_pmh\Plugin\rest\resource\OaiPmh::getRecordMetadata().
   *   - oai-dc-string: Is used to pass response info internally.
   *
   * @see \Symfony\Component\Serializer\Encoder\XmlEncoder::encode()
   * @see \Symfony\Component\Serializer\Encoder\XmlEncoder::buildXml()
   */
  public function getMetadataWrapper();

  /**
   * Transforms an entity into a metadata record.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being rendered.
   *
   * @return string
   *   The metadata record markup to be rendered.
   */
  public function transformRecord(ContentEntityInterface $entity);

}
