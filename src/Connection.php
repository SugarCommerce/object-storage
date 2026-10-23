<?php
/**
 * The keys to a bucket somebody else chose.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

use SugarCommerce\HttpClient\Client as Http;
use SugarCommerce\S3Signer\{AddressingStyle, Provider, Signer};
use SugarCommerce\Sanitize\Input;

/**
 * Class Connection
 *
 * Everything needed to reach one bucket at a known provider, checked once on
 * the way in so that nothing past this point has to wonder whether a field is
 * usable. For an application that lets its users bring their own storage: a
 * marketplace whose sellers keep their files in their own R2, a backup tool
 * pointed at a customer's S3.
 *
 * The host is the part that needs most care. Every request made to the bucket
 * — a connection test, starting and finishing uploads, reading a file back to
 * scan it, a download proxy — goes there. A host the user could type would be
 * one they control: pointed at your own network, or a server that shows your
 * scanner one file and everybody else another, by address. So there is no
 * endpoint field at all. The host is built from the provider, region and
 * account id, and has to be the provider's own (Provider::is_own_endpoint())
 * or the connection is refused. MinIO and "Other" need a typed host, so by
 * default they can never be a Connection.
 *
 * The exception is a host the operator typed: an install's own MinIO, or an
 * S3-compatible service named in its configuration. from_array() takes one
 * for MinIO and Other only when the caller says the input is the operator's
 * (its third argument), so a form a user fills in cannot open that door by
 * listing those providers.
 *
 * Shown only through masked(), so a page that saved the keys never gets them
 * back, and kept out of var_dump(), print_r() and json_encode() the same way.
 * to_json() is the one way to the secret, for storing it sealed.
 *
 * @since 1.3.0
 */
final readonly class Connection implements \JsonSerializable {

	/**
	 * Providers whose endpoint is built rather than typed: every one a
	 * Connection can be for. Narrow it per application with from_array()'s
	 * second argument.
	 */
	public const array PROVIDERS = array( 'r2', 'aws', 'backblaze', 'digitalocean', 'linode', 'wasabi', 'scaleway' );

	/**
	 * Providers whose endpoint is typed: the host is whoever typed it's to
	 * choose. Accepted only when from_array() is told the input is the
	 * operator's.
	 *
	 * @since 2.2.0
	 */
	public const array TYPED = array( 'minio', 'other' );

	/**
	 * @since 1.3.0
	 *
	 * @param Provider $provider   Who runs the bucket.
	 * @param string   $bucket     Bucket name.
	 * @param string   $access_key Access key id.
	 * @param string   $secret_key Secret access key.
	 * @param string   $region     Region, or '' for the provider's default.
	 * @param string   $account_id Cloudflare account id, for R2 only.
	 * @param string   $endpoint   Host, with a port if it needs one, for
	 *                             MinIO and Other only. @since 2.2.0.
	 */
	public function __construct(
		public Provider $provider,
		public string $bucket,
		#[\SensitiveParameter] public string $access_key,
		#[\SensitiveParameter] public string $secret_key,
		public string $region = '',
		public string $account_id = '',
		public string $endpoint = '',
	) {}

	/**
	 * A connection from what somebody typed, or why it will not do.
	 *
	 * Every field is trimmed; the bucket, region and account id are lower-
	 * cased, and the account id is dropped for a provider that has none.
	 * The endpoint is kept only for MinIO and Other, lowercased, with any
	 * scheme, path or trailing slash pasted from a dashboard taken off.
	 *
	 * @since 1.3.0
	 * @since 2.2.0 The endpoint field, and $operator.
	 *
	 * @param array<string, mixed> $input     Fields: provider, bucket,
	 *                                        access_key, secret_key, region,
	 *                                        account_id, endpoint. Anything
	 *                                        not a string counts as empty.
	 * @param array<int, string>   $providers Provider values that may be
	 *                                        chosen. Only those in PROVIDERS
	 *                                        can be, and those in TYPED too
	 *                                        when $operator is true.
	 * @param bool                 $operator  Whether the input is the
	 *                                        operator's own configuration,
	 *                                        not something a user typed. Only
	 *                                        then may the host be typed.
	 *
	 * @return self|string The connection, or a sentence saying what to fix.
	 */
	public static function from_array( array $input, array $providers = self::PROVIDERS, bool $operator = false ): self|string {
		$field    = static fn ( string $name ): string => trim( (string) ( is_scalar( $input[ $name ] ?? null ) ? $input[ $name ] : '' ) );
		$chosen   = $field( 'provider' );
		$possible = $operator ? array( ...self::PROVIDERS, ...self::TYPED ) : self::PROVIDERS;
		$provider = in_array( $chosen, $providers, true ) && in_array( $chosen, $possible, true ) ? Provider::from( $chosen ) : null;

		if ( null === $provider ) {
			return 'Choose who runs your bucket.';
		}

		$connection = new self(
			$provider,
			strtolower( $field( 'bucket' ) ),
			$field( 'access_key' ),
			$field( 'secret_key' ),
			strtolower( $field( 'region' ) ),
			$provider->needs_account_id() ? strtolower( $field( 'account_id' ) ) : '',
			$provider->needs_endpoint() ? self::bare_host( $field( 'endpoint' ) ) : '',
		);

		return $connection->problem() ?: $connection;
	}

	/**
	 * A connection as to_json() wrote it, or null when the document is not
	 * one — or no longer passes, because the rules got stricter since.
	 *
	 * @since 1.3.0
	 * @since 2.2.0 $operator.
	 *
	 * @param string             $json      What to_json() wrote, already
	 *                                      decrypted if it was stored sealed.
	 * @param array<int, string> $providers As for from_array().
	 * @param bool               $operator  As for from_array(): a stored
	 *                                      MinIO or Other connection reads
	 *                                      back only with it.
	 *
	 * @return self|null
	 */
	public static function from_json( string $json, array $providers = self::PROVIDERS, bool $operator = false ): ?self {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		// A JSON list, or an object with numeric names, decodes to integer
		// keys, and none of those is a field.
		$connection = self::from_array( array_filter( $data, 'is_string', ARRAY_FILTER_USE_KEY ), $providers, $operator );

		return $connection instanceof self ? $connection : null;
	}

	/**
	 * The document to store. It holds the secret: seal it before it is
	 * written anywhere.
	 *
	 * The endpoint is written only when there is one, so a connection
	 * stored before it existed and the same one stored now are the same
	 * document.
	 *
	 * @since 1.3.0
	 *
	 * @return string JSON.
	 */
	public function to_json(): string {
		$document = array(
			'provider'   => $this->provider->value,
			'bucket'     => $this->bucket,
			'access_key' => $this->access_key,
			'secret_key' => $this->secret_key,
			'region'     => $this->region,
			'account_id' => $this->account_id,
		);

		if ( '' !== $this->endpoint ) {
			$document['endpoint'] = $this->endpoint;
		}

		return (string) json_encode( $document );
	}

	/**
	 * Why this connection cannot be used, or ''.
	 *
	 * A typed endpoint is checked for shape only — a host name and an
	 * optional port, nothing the signer would have to cut off — since there
	 * is no official host to hold it to. Who may type one is from_array()'s
	 * question, not this one's.
	 *
	 * @since 1.3.0
	 * @since 2.2.0 Checks the endpoint.
	 *
	 * @return string A sentence for the person who typed it.
	 */
	public function problem(): string {
		$printable = static fn ( string $value ): bool => 1 === preg_match( '/^[\x21-\x7E]{1,256}$/', $value );
		$typed     = $this->provider->needs_endpoint();

		return match ( true ) {
			$typed && '' === $this->endpoint => $this->provider->label() . ' cannot be connected without an address: it has to be typed, as the endpoint.',
			$typed && ! self::is_host( $this->endpoint ) => 'An endpoint is a host name, with a port if it needs one, like minio.example.com:9000.',
			! $typed && '' !== $this->endpoint => $this->provider->label() . ' builds its own address: leave the endpoint empty.',
			! AddressingStyle::is_valid_bucket( $this->bucket ) => 'A bucket name is 3 to 63 lowercase letters, numbers, dots and hyphens.',
			AddressingStyle::VirtualHosted === $this->provider->addressing() && ! AddressingStyle::is_dns_compatible( $this->bucket ) => $this->provider->label() . ' needs a bucket name without dots.',
			! $printable( $this->access_key ) => 'Paste the access key id, without spaces.',
			! $printable( $this->secret_key ) => 'Paste the secret access key, without spaces.',
			1 !== preg_match( '/^[a-z0-9\-]{0,40}$/', $this->region ) => 'A region is letters, numbers and hyphens, like eu-west-2.',
			$this->provider->needs_account_id() && 1 !== preg_match( '/^[a-z0-9]{8,64}$/', $this->account_id ) => 'Paste your Cloudflare account id: the 32 characters in your R2 endpoint address.',
			! $typed && ! $this->provider->is_own_endpoint( $this->host() ) => 'That region does not give an address at ' . $this->provider->label() . '. Check the region.',
			default => '',
		};
	}

	/**
	 * A signer for this bucket's provider.
	 *
	 * @since 1.3.0
	 *
	 * @return Signer
	 */
	public function signer(): Signer {
		return $this->provider->signer( $this->access_key, $this->secret_key, $this->region, $this->account_id, $this->endpoint );
	}

	/**
	 * The bucket, ready to call.
	 *
	 * @since 1.3.0
	 *
	 * @param Http $http HTTP client. Keep its SSRF guard: the host is the
	 *                   provider's, but what it resolves to is DNS's say.
	 *
	 * @return Bucket
	 */
	public function bucket( Http $http = new Http() ): Bucket {
		return new Bucket( $this->signer(), $this->bucket, $http );
	}

	/**
	 * The endpoint host, built from the provider, region and account id, or
	 * the endpoint as typed for MinIO and Other.
	 *
	 * @since 1.3.0
	 *
	 * @return string Host, or '' when the provider cannot build one.
	 */
	public function host(): string {
		try {
			return $this->provider->endpoint( $this->region, $this->account_id, $this->endpoint );
		} catch ( \InvalidArgumentException ) {
			return '';
		}
	}

	/**
	 * Where a browser sends upload parts and fetches previews from.
	 *
	 * @since 1.3.0
	 *
	 * @return string e.g. `https://acct.r2.cloudflarestorage.com`.
	 */
	public function origin(): string {
		return $this->signer()->origin( $this->bucket );
	}

	/**
	 * Whether two connections reach the same bucket.
	 *
	 * New keys for a bucket already connected should replace the old ones
	 * rather than start a second connection: whatever is in it is reached
	 * with whichever keys are stored, and the old ones may have been revoked.
	 *
	 * @since 1.3.0
	 *
	 * @param self $other The other connection.
	 *
	 * @return bool
	 */
	public function same_bucket( self $other ): bool {
		return $this->provider === $other->provider && $this->bucket === $other->bucket && $this->origin() === $other->origin();
	}

	/**
	 * What may be shown about this connection, to anybody.
	 *
	 * The secret is never shown at all, not even masked: there is nothing
	 * anybody does with half a secret. An account id in the host is masked;
	 * a host built from a region says nothing the region does not, and a
	 * typed endpoint is the operator's own, shown so they can recognise it.
	 *
	 * @since 1.3.0
	 *
	 * @return array{provider: string, label: string, bucket: string, host: string, region: string, access_key: string}
	 */
	public function masked(): array {
		$host = $this->host();

		return array(
			'provider'   => $this->provider->value,
			'label'      => $this->provider->label(),
			'bucket'     => self::mask( $this->bucket ),
			'host'       => $this->provider->needs_account_id() ? self::mask_host( $host ) : $host,
			'region'     => '' !== $this->region ? $this->region : $this->provider->default_region(),
			'access_key' => self::mask( $this->access_key ),
		);
	}

	/**
	 * Enough of a value to recognise it, and no more.
	 *
	 * The rule is sugarcommerce/sanitize's `Input::mask()`, so a key shown
	 * here and a key shown anywhere else in an app are cut the same way.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value Value.
	 *
	 * @return string e.g. `med…ia`; four and four of a value of 16 or more.
	 */
	public static function mask( #[\SensitiveParameter] string $value ): string {
		return Input::mask( $value );
	}

	/**
	 * Kept out of var_dump() and print_r(), which an error page or a log
	 * line can reach. SensitiveParameter covers stack traces only.
	 *
	 * @since 1.3.0
	 *
	 * @return array{provider: string, label: string, bucket: string, host: string, region: string, access_key: string}
	 */
	public function __debugInfo(): array {
		return $this->masked();
	}

	/**
	 * What json_encode() writes: the masked view, never the keys.
	 *
	 * The properties are public, and json_encode() of an object writes its
	 * public properties — the secret among them — into whatever API answer or
	 * log line the connection was handed to.
	 *
	 * @since 2.1.0
	 *
	 * @return array{provider: string, label: string, bucket: string, host: string, region: string, access_key: string}
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return $this->masked();
	}

	/**
	 * An endpoint as pasted, cut down to the host and port.
	 *
	 * The same trimming the signer does to an endpoint, done on the way in
	 * so that what is stored, compared and shown is what is signed for.
	 *
	 * @since 2.2.0
	 *
	 * @param string $endpoint What was typed, e.g. `https://MinIO.local:9000/`.
	 *
	 * @return string e.g. `minio.local:9000`.
	 */
	private static function bare_host( string $endpoint ): string {
		$host = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', trim( $endpoint ) );

		return strtolower( rtrim( explode( '/', $host, 2 )[0], '.' ) );
	}

	/**
	 * Whether an endpoint is a host name, with an optional port and nothing
	 * else: no user, path, query or bracketed address, which the signer
	 * cannot sign for.
	 *
	 * @since 2.2.0
	 *
	 * @param string $endpoint Endpoint, as bare_host() leaves it.
	 *
	 * @return bool
	 */
	private static function is_host( string $endpoint ): bool {
		$label = '[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?';

		if ( strlen( $endpoint ) > 259 || 1 !== preg_match( '/^' . $label . '(?:\.' . $label . ')*(?::(\d{1,5}))?$/', $endpoint, $match ) ) {
			return false;
		}

		return ! isset( $match[1] ) || ( (int) $match[1] >= 1 && (int) $match[1] <= 65535 );
	}

	/**
	 * A host with its first label masked: the account id.
	 *
	 * @since 1.3.0
	 *
	 * @param string $host Host.
	 *
	 * @return string
	 */
	private static function mask_host( string $host ): string {
		$labels = explode( '.', $host, 2 );

		return self::mask( $labels[0] ) . ( isset( $labels[1] ) ? '.' . $labels[1] : '' );
	}
}
