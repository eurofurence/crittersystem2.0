<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\SigningCertificate;
use App\Repository\SigningCertificateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the self-signed certificate used to sign audit exports. The certificate
 * is generated on demand (and during installation), then persisted encrypted in
 * the database so it survives in a containerised deployment without a mounted
 * keystore. Signing proves the export's authenticity and non-repudiation.
 */
final class CertificateAuthority
{
    public function __construct(
        private readonly SigningCertificateRepository $certificates,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function hasCertificate(): bool
    {
        return $this->certificates->findActive() !== null;
    }

    /** Return the active signing certificate, generating one if none exists. */
    public function ensureCertificate(): SigningCertificate
    {
        return $this->certificates->findActive() ?? $this->generate();
    }

    /** Sign raw bytes; returns a base64-encoded RSA-SHA256 signature. */
    public function sign(string $data): string
    {
        $certificate = $this->ensureCertificate();
        $key = openssl_pkey_get_private($certificate->getPrivateKeyPem());
        if ($key === false) {
            throw new \RuntimeException('Unable to load the signing private key.');
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign the export.');
        }

        return base64_encode($signature);
    }

    public function publicCertificatePem(): string
    {
        return $this->ensureCertificate()->getCertificatePem();
    }

    private function generate(): SigningCertificate
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];

        $pkey = openssl_pkey_new($config);
        if ($pkey === false) {
            throw new \RuntimeException('Unable to generate a signing key: '.openssl_error_string());
        }

        $dn = [
            'organizationName' => 'Critter',
            'organizationalUnitName' => 'Audit',
            'commonName' => 'Critter Audit Signing',
        ];

        $csr = openssl_csr_new($dn, $pkey, $config);
        if ($csr === false) {
            throw new \RuntimeException('Unable to create a certificate request: '.openssl_error_string());
        }

        $x509 = openssl_csr_sign($csr, null, $pkey, 3650, $config);
        if ($x509 === false) {
            throw new \RuntimeException('Unable to self-sign the certificate: '.openssl_error_string());
        }

        $certPem = '';
        openssl_x509_export($x509, $certPem);
        $privPem = '';
        openssl_pkey_export($pkey, $privPem, null, $config);

        $fingerprint = openssl_x509_fingerprint($certPem, 'sha256') ?: '';

        $certificate = new SigningCertificate($certPem, $privPem, $fingerprint);
        $this->em->persist($certificate);
        $this->em->flush();

        return $certificate;
    }
}
