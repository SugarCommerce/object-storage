<?php
/**
 * A storage call that did not produce an answer.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

/**
 * Class StorageError
 *
 * Thrown where an empty answer and no answer would look the same afterwards —
 * reading bytes back, where '' is also what an empty file returns — and where
 * why storage said no is what someone has to know next, starting an upload.
 *
 * @since 1.0.0
 */
final class StorageError extends \RuntimeException {

	/**
	 * @since 1.0.0
	 *
	 * @param string $operation  What was being done, e.g. "read".
	 * @param int    $status     HTTP status, 0 when nothing answered.
	 * @param string $detail     Start of the response, for the log.
	 * @param string $error_code Storage's own name for the refusal, e.g.
	 *                           "SignatureDoesNotMatch", or '' when it gave none.
	 */
	public function __construct(
		public readonly string $operation,
		public readonly int $status,
		string $detail = '',
		public readonly string $error_code = '',
	) {
		parent::__construct( sprintf( 'Storage %s failed (%d)%s', $operation, $status, '' === $detail ? '' : ': ' . substr( $detail, 0, 200 ) ) );
	}

	/**
	 * A refusal, read from storage's error document.
	 *
	 * S3 and everything compatible with it answer `<Error><Code>…</Code>
	 * <Message>…</Message></Error>`: the code says which fix, the message says
	 * it in words. Anything else is kept as it came, for the log.
	 *
	 * @since 2.0.0
	 *
	 * @param string $operation What was being done.
	 * @param int    $status    HTTP status, 0 when nothing answered.
	 * @param string $body      The answer, or the transport's error.
	 *
	 * @return self
	 */
	public static function refused( string $operation, int $status, string $body ): self {
		$code    = 1 === preg_match( '#<Code>([^<]*)</Code>#', $body, $found ) ? $found[1] : '';
		$message = 1 === preg_match( '#<Message>([^<]*)</Message>#', $body, $found ) ? $found[1] : '';
		$detail  = '' !== $code ? trim( $code . ': ' . $message, ': ' ) : trim( strip_tags( $body ) );

		return new self( $operation, $status, $detail, $code );
	}
}
