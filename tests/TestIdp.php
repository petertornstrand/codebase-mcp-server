<?php

namespace petertornstrand\Tests;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * A fake SAML identity provider that signs responses with a throwaway key.
 */
class TestIdp {

  public string $entityId = 'https://idp.test/entity';

  public string $ssoUrl = 'https://idp.test/sso';

  public string $cert;

  private string $privateKey;

  public function __construct() {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'test-idp'], $key);
    $x509 = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($x509, $cert);
    openssl_pkey_export($key, $privateKey);
    $this->cert = $cert;
    $this->privateKey = $privateKey;
  }

  /**
   * Builds a base64 SAMLResponse for an AuthnRequest.
   *
   * @param string|null $signWith
   *   A private key PEM to sign with instead of this IdP's (to forge).
   */
  public function response(string $email, string $inResponseTo, string $baseUrl, ?string $signWith = null): string {
    $acs = $baseUrl . '/saml/acs';
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $later = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
    $earlier = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
    $id = '_' . bin2hex(random_bytes(16));
    $assertionId = '_' . bin2hex(random_bytes(16));
    $xml = <<<XML
      <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="$id" Version="2.0" IssueInstant="$now" Destination="$acs" InResponseTo="$inResponseTo"><saml:Issuer>{$this->entityId}</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status><saml:Assertion ID="$assertionId" Version="2.0" IssueInstant="$now"><saml:Issuer>{$this->entityId}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">$email</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="$later" Recipient="$acs" InResponseTo="$inResponseTo"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="$earlier" NotOnOrAfter="$later"><saml:AudienceRestriction><saml:Audience>$baseUrl</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="$now" SessionIndex="_s"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement></saml:Assertion></samlp:Response>
      XML;

    $doc = new \DOMDocument();
    $doc->loadXML($xml);
    $root = $doc->documentElement;

    $dsig = new XMLSecurityDSig();
    $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
    $dsig->addReference($root, XMLSecurityDSig::SHA256, [
      'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
      XMLSecurityDSig::EXC_C14N,
    ], ['id_name' => 'ID', 'overwrite' => false]);
    $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
    $key->loadKey($signWith ?? $this->privateKey, false);
    $dsig->sign($key);
    $dsig->add509Cert($this->cert);
    $dsig->insertSignature($root, $root->firstChild->nextSibling);

    return base64_encode($doc->saveXML($root));
  }

  /**
   * A private key PEM that is not trusted by the SP.
   */
  public static function forgedKey(): string {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    return $pem;
  }

}
