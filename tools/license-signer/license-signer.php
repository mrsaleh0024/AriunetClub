<?php
/**
 * AriunetClub license signer CLI (maintainer-only, excluded from the
 * distribution zip).
 *
 * Commands:
 *   keypair [--out DIR]                       Generate an Ed25519 keypair.
 *   issue --key FILE --domain D
 *         [--plan P] [--days N]               Issue a signed license token.
 *   verify --token STRING|FILE --key FILE     Verify a token (public key).
 *   info --token STRING|FILE                  Decode a payload without verifying.
 *
 * Requires PHP 7.2+ with the sodium extension (bundled by default).
 * The private key must never be committed or shipped.
 */

error_reporting( E_ALL );

if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This tool must run on the command line.\n" );
        exit( 1 );
}
if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
        fwrite( STDERR, "The PHP sodium extension (libsodium) is required. Install php-sodium or use PHP 7.2+ where it is bundled.\n" );
        exit( 1 );
}

/**
 * Mirrors AriunetClub\Modules\License\DomainNormalizer::normalize().
 * The two implementations must stay in sync (checked by the phase 5 tests).
 */
function ariunetclub_signer_normalize_domain( string $domain ): string {
        $domain = strtolower( trim( $domain ) );
        if ( '' === $domain ) {
                return '';
        }
        if ( false !== strpos( $domain, '://' ) ) {
                $parts  = parse_url( $domain );
                $domain = is_array( $parts ) && isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : '';
        }
        $split  = preg_split( '~[/?#:]+~', $domain, 2 );
        $domain = is_array( $split ) ? (string) $split[0] : '';
        $domain = trim( $domain, ". \t\n\r" );
        if ( function_exists( 'idn_to_ascii' ) ) {
                $ascii = idn_to_ascii( $domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
                if ( is_string( $ascii ) && '' !== $ascii ) {
                        $domain = $ascii;
                }
        }
        if ( 0 === strpos( $domain, 'www.' ) ) {
                $domain = substr( $domain, 4 );
        }
        if ( 1 !== preg_match( '~^[a-z0-9.-]{1,253}[a-z0-9]$~', $domain ) ) {
                return '';
        }
        return $domain;
}

function ariunetclub_signer_arg_value( array $args, string $name, string $default = '' ): string {
        foreach ( $args as $index => $value ) {
                if ( $value === '--' . $name && isset( $args[ $index + 1 ] ) ) {
                        return (string) $args[ $index + 1 ];
                }
                if ( 0 === strpos( $value, '--' . $name . '=' ) ) {
                        return (string) substr( $value, strlen( '--' . $name . '=' ) );
                }
        }
        return $default;
}

function ariunetclub_signer_b64url_encode( string $bytes ): string {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
}

function ariunetclub_signer_b64url_decode( string $text ): ?string {
        $raw  = strtr( $text, '-_', '+/' );
        $mod  = strlen( $raw ) % 4;
        if ( 2 === $mod ) {
                $raw .= '==';
        } elseif ( 3 === $mod ) {
                $raw .= '=';
        }
        $decoded = base64_decode( $raw, true );
        return false === $decoded ? null : $decoded;
}

/**
 * Builds the canonical signed token. The payload field order and flags must
 * match LicenseVerifier expectations: product, domain, plan, issued_at,
 * duration_days, jti.
 */
function ariunetclub_signer_build_token( string $domain, string $plan, int $days, string $private_key_hex ): string {
        $payload = array(
                'product'       => 'ariunetclub',
                'domain'        => ariunetclub_signer_normalize_domain( $domain ),
                'plan'          => $plan,
                'issued_at'     => time(),
                'duration_days' => $days,
                'jti'           => bin2hex( random_bytes( 16 ) ),
        );
        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
                fwrite( STDERR, "Unable to encode the payload as JSON.\n" );
                exit( 1 );
        }
        $signature = sodium_crypto_sign_detached( $json, sodium_hex2bin( $private_key_hex ) );
        return ariunetclub_signer_b64url_encode( $json ) . '.' . ariunetclub_signer_b64url_encode( $signature );
}

function ariunetclub_signer_read_token( array $args ): ?string {
        $token = ariunetclub_signer_arg_value( $args, 'token' );
        if ( '' === $token ) {
                return null;
        }
        if ( is_file( $token ) ) {
                $contents = file_get_contents( $token );
                return false === $contents ? null : trim( $contents );
        }
        return $token;
}

$args    = array_slice( $argv, 1 );
$command = $args ? array_shift( $args ) : '';

if ( '' === $command ) {
        echo "AriunetClub license signer\n";
        echo "Commands:\n";
        echo "  keypair [--out DIR]                 Generate a new Ed25519 keypair (hex files)\n";
        echo "  issue --key FILE --domain D [--plan P] [--days N]\n";
        echo "                                      Issue a signed license token\n";
        echo "  verify --token STRING|FILE --key FILE\n";
        echo "                                      Verify a token against a public key\n";
        echo "  info --token STRING|FILE            Decode a token payload without verifying\n";
        exit( 0 );
}

if ( 'keypair' === $command ) {
        $out_dir = ariunetclub_signer_arg_value( $args, 'out', (string) getcwd() );
        if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0700, true ) ) {
                fwrite( STDERR, "Unable to create the output directory.\n" );
                exit( 1 );
        }
        $keypair    = sodium_crypto_sign_keypair();
        $secret_hex = sodium_bin2hex( sodium_crypto_sign_secretkey( $keypair ) );
        $public_hex = sodium_bin2hex( sodium_crypto_sign_publickey( $keypair ) );
        $secret_file = rtrim( $out_dir, '/\\' ) . '/ariunetclub-private-key.txt';
        $public_file = rtrim( $out_dir, '/\\' ) . '/ariunetclub-public-key.txt';
        file_put_contents( $secret_file, $secret_hex . "\n" );
        file_put_contents( $public_file, $public_hex . "\n" );
        chmod( $secret_file, 0600 );
        echo "Ed25519 keypair generated.\n";
        echo "  Private key (NEVER share, never commit): $secret_file\n";
        echo "  Public key  (embeds into the plugin):    $public_file\n";
        echo "  Public key hex: $public_hex\n";
        exit( 0 );
}

if ( 'issue' === $command ) {
        $key_file = ariunetclub_signer_arg_value( $args, 'key' );
        $domain   = ariunetclub_signer_arg_value( $args, 'domain' );
        $plan     = ariunetclub_signer_arg_value( $args, 'plan', 'pro' );
        $days     = (int) ariunetclub_signer_arg_value( $args, 'days', '365' );
        if ( '' === $key_file || ! is_file( $key_file ) ) {
                fwrite( STDERR, "A --key private key file is required.\n" );
                exit( 1 );
        }
        $normalized = ariunetclub_signer_normalize_domain( $domain );
        if ( '' === $normalized ) {
                fwrite( STDERR, "A valid --domain is required.\n" );
                exit( 1 );
        }
        if ( $days < 1 || $days > 3650 ) {
                fwrite( STDERR, "--days must be between 1 and 3650.\n" );
                exit( 1 );
        }
        $private_hex = trim( (string) file_get_contents( $key_file ) );
        if ( 1 !== preg_match( '/^[0-9a-f]{128}$/i', $private_hex ) ) {
                fwrite( STDERR, "The private key file does not look like an Ed25519 secret key (64-byte hex).\n" );
                exit( 1 );
        }
        $token = ariunetclub_signer_build_token( $normalized, $plan, $days, $private_hex );
        echo "License token (plan: $plan, domain: $normalized, $days days from activation):\n";
        echo $token . "\n";
        exit( 0 );
}

if ( 'verify' === $command || 'info' === $command ) {
        $token = ariunetclub_signer_read_token( $args );
        if ( null === $token || false === strpos( $token, '.' ) ) {
                fwrite( STDERR, "A --token (string or file) is required.\n" );
                exit( 1 );
        }
        list( $payload_b64, $signature_b64 ) = array_pad( explode( '.', $token, 2 ), 2, '' );
        $json    = ariunetclub_signer_b64url_decode( $payload_b64 );
        $payload = null;
        if ( null !== $json ) {
                $decoded = json_decode( $json, true, 4 );
                if ( is_array( $decoded ) ) {
                        $payload = $decoded;
                }
        }
        if ( null === $payload ) {
                fwrite( STDERR, "The token payload is not valid JSON.\n" );
                exit( 1 );
        }
        echo "Payload:\n";
        foreach ( $payload as $key => $value ) {
                echo '  ' . $key . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode_safe( $value ) ) . "\n";
        }
        if ( 'info' === $command ) {
                echo "(Not verified - use the verify command with the public key.)\n";
                exit( 0 );
        }
        $key_file = ariunetclub_signer_arg_value( $args, 'key' );
        if ( '' === $key_file || ! is_file( $key_file ) ) {
                fwrite( STDERR, "A --key public key file is required.\n" );
                exit( 1 );
        }
        $public_hex = trim( (string) file_get_contents( $key_file ) );
        $signature  = ariunetclub_signer_b64url_decode( $signature_b64 );
        if ( null === $signature ) {
                fwrite( STDERR, "Signature: INVALID (signature is not valid base64url)\n" );
                exit( 1 );
        }
        if ( 1 !== preg_match( '/^[0-9a-f]{64}$/i', $public_hex ) ) {
                fwrite( STDERR, "The public key file does not look like an Ed25519 public key (32-byte hex).\n" );
                exit( 1 );
        }
        if ( sodium_crypto_sign_verify_detached( $signature, (string) $json, sodium_hex2bin( $public_hex ) ) ) {
                echo "Signature: VALID\n";
                exit( 0 );
        }
        fwrite( STDERR, "Signature: INVALID\n" );
        exit( 1 );
}

fwrite( STDERR, "Unknown command: $command\n" );
exit( 1 );

/**
 * @param mixed $value
 */
function wp_json_encode_safe( $value ): string {
        $encoded = json_encode( $value );
        return false === $encoded ? '' : $encoded;
}
