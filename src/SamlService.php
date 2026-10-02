<?php

namespace petertornstrand;

use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Response as SamlResponse;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;

/**
 * SP-initiated SAML 2.0 login against Google Workspace.
 *
 * Signature and condition checks are done by onelogin/php-saml.
 */
class SamlService {

  private const NAMEID_EMAIL = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';

  /**
   * @param string $baseUrl
   *   The public base URL; also the SP entity ID.
   * @param array $idp
   *   Keys: entity_id, sso_url, cert.
   */
  public function __construct(private string $baseUrl, private array $idp) {}

  private function settings(): Settings {
    return new Settings([
      'strict' => true,
      'debug' => false,
      'baseurl' => $this->baseUrl,
      'sp' => [
        'entityId' => $this->baseUrl,
        'assertionConsumerService' => [
          'url' => $this->baseUrl . '/saml/acs',
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'NameIDFormat' => self::NAMEID_EMAIL,
      ],
      'idp' => [
        'entityId' => $this->idp['entity_id'],
        'singleSignOnService' => [
          'url' => $this->idp['sso_url'],
          'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'x509cert' => $this->idp['cert'],
      ],
      'security' => [
        'authnRequestsSigned' => FALSE,
        'wantMessagesSigned' => TRUE,
        'wantNameId' => TRUE,
        'wantAssertionsEncrypted' => FALSE,
        'rejectDeprecatedAlgorithm' => TRUE,
        'requestedAuthnContext' => FALSE,
      ],
    ], FALSE);
  }

  /**
   * Builds the redirect URL that starts a login at the IdP.
   *
   * @return array{0: string, 1: string}
   *   The URL and the AuthnRequest ID the response must answer.
   */
  public function loginUrl(string $relayState): array {
    $settings = $this->settings();
    $request = new AuthnRequest($settings);
    $url = $this->idp['sso_url'];
    $url .= (str_contains($url, '?') ? '&' : '?')
      . http_build_query(['SAMLRequest' => $request->getRequest(), 'RelayState' => $relayState], '', '&', PHP_QUERY_RFC3986);
    return [$url, $request->getId()];
  }

  /**
   * Validates a SAMLResponse and returns the authenticated email address.
   *
   * @throws \RuntimeException If the response is not valid for this request.
   */
  public function validate(string $samlResponse, string $requestId): string {
    $parts = parse_url($this->baseUrl);
    Utils::setSelfProtocol($parts['scheme']);
    Utils::setSelfHost($parts['host']);
    Utils::setSelfPort($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));

    try {
      $response = new SamlResponse($this->settings(), $samlResponse);
      if (!$response->isValid($requestId)) {
        throw new \RuntimeException($response->getError() ?: 'Invalid SAML response.');
      }
      $email = strtolower(trim($response->getNameId()));
    }
    catch (\RuntimeException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \RuntimeException('NameID is not an email address.');
    }
    return $email;
  }

  /**
   * The SP metadata XML, to configure the SAML app in Google Admin.
   */
  public function metadata(): string {
    return $this->settings()->getSPMetadata();
  }

}
