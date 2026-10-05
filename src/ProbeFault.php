<?php
/**
 * What went wrong with one step of the connection test.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

/**
 * Enum ProbeFault
 *
 * A code rather than a sentence, so the application says it in its own words
 * and language. Each names what the person who typed the keys should check;
 * none says anything about the network the test ran from — see Unreachable.
 *
 * @since 1.3.0
 */
enum ProbeFault: string {

	/** Not tried, because an earlier step failed. */
	case Skipped = 'skipped';

	/**
	 * No answer at all: the network failed, or the HTTP client refused an
	 * address that resolves somewhere private. Deliberately one case for
	 * both, so the test cannot be used to ask what is on your network; the
	 * step's detail carries the reason, for your log only.
	 */
	case Unreachable = 'unreachable';

	/** 403: the keys were refused, or may not do this. */
	case Refused = 'refused';

	/** 404: no bucket by that name at that endpoint. */
	case Missing = 'missing';

	/** 301, 307 or 400: the bucket answered from another region. */
	case Elsewhere = 'elsewhere';

	/** Any other status; the step's status says which. */
	case Failed = 'failed';

	/** Read back something other than what was written. */
	case Altered = 'altered';

	/** The bucket's CORS policy will not let the uploader work; the detail says why. */
	case CorsPolicy = 'cors_policy';

	/** A browser preflight from an origin was refused; the detail is the origin. */
	case CorsOrigin = 'cors_origin';

	/** Uploads are allowed but ETag is not exposed, so every one fails at assembly. */
	case EtagHidden = 'etag_hidden';

	/**
	 * The fault a failed response stands for.
	 *
	 * @since 1.3.0
	 *
	 * @param int $status HTTP status, 0 for no answer.
	 *
	 * @return self
	 */
	public static function of_status( int $status ): self {
		return match ( true ) {
			0 === $status                                => self::Unreachable,
			403 === $status                              => self::Refused,
			404 === $status                              => self::Missing,
			in_array( $status, array( 301, 307, 400 ), true ) => self::Elsewhere,
			default                                      => self::Failed,
		};
	}
}
