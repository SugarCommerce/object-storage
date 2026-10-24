<?php
/**
 * Copies of local files kept in a bucket: sealed, listed, pruned and fetched.
 *
 * @package   SugarCommerce\ObjectStorage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     2.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage;

/**
 * Class Archive
 *
 * The off-site half of a backup: a copy sent to the bucket so it outlives
 * the server it was made on, the newest few kept, and a way to get one back.
 *
 * Four things a hand-rolled version gets wrong:
 *
 * - **Listing the bucket.** Keys that can write and read a bucket often
 *   cannot list it, and should not need to. What was sent is written down in
 *   a small JSON file on the server once storage has it, oldest first, and
 *   pruning goes by that. A delete that fails stays on the list and is tried
 *   again next time.
 * - **Starting the list again.** A list that does not parse, or names a key
 *   this archive would not have sent, throws and is left as it is. Writing a
 *   fresh one over it forgets every copy already in the bucket, which then
 *   stays there for good — and a list that names any key it likes would
 *   have prune() delete it.
 * - **Leaving copies behind.** The sealed copy, a copy written to be sent,
 *   a download and a half-restored file all pass through temporary files,
 *   removed on every way out, failures and exceptions included.
 * - **Restoring over the database.** A restore is written beside its target
 *   and moved into place whole, and a target that exists is refused unless
 *   the caller says to overwrite it.
 *
 * Sealing is two hooks, `seal` (file A into file B) and `open` (file B back
 * into file A), so this neither knows the key nor decides whether sealing
 * streams or reads a file whole. Each hook is called with `( $from, $to )`,
 * where `$to` is an empty owner-only file to write over. False means it
 * failed; anything else, void included, that it worked. An exception from a
 * hook is passed on, after the temporary files are removed. Without hooks a
 * copy goes up as it is.
 *
 *   $archive = new Archive( $bucket, '/var/backups/app/remote.json', 'backups/', keep: 14,
 *       seal: $sealer->seal_file( ... ), open: $sealer->open_file( ... ) );
 *
 *   $key     = $archive->send( $backups->take() );  // null: not in storage
 *   $deleted = $archive->prune();
 *   $ok      = $archive->fetch( $archive->keys()[0], '/var/backups/app/restored.db' );
 *
 * One writer at a time: two processes sending at once can each write the
 * list without the other's key.
 *
 * @since 2.3.0
 */
final readonly class Archive {

	/**
	 * What a copy's name may be: no path, no leading dot.
	 */
	private const string NAME = '/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/D';

	/**
	 * Point at the bucket and the list of what is in it.
	 *
	 * @since 2.3.0
	 *
	 * @param Bucket                               $bucket Where copies go.
	 * @param string                               $list   The JSON file that lists them,
	 *                                                     e.g. `remote.json` beside the
	 *                                                     local copies. Its directory is
	 *                                                     created owner-only when missing,
	 *                                                     and holds the temporary files.
	 * @param string                               $prefix Start of every key, ending in
	 *                                                     `/`. Only keys under it are ever
	 *                                                     listed, deleted or fetched.
	 * @param int                                  $keep   Copies kept by prune(), newest
	 *                                                     first.
	 * @param (\Closure(string, string): mixed)|null $seal   Seals file A into file B.
	 * @param (\Closure(string, string): mixed)|null $open   Opens file B back into file A.
	 * @param string                               $suffix Added to the key of a sealed
	 *                                                     copy.
	 *
	 * @throws \InvalidArgumentException On a prefix that is not one or more
	 *                                   path segments ending in `/`, keeping
	 *                                   none, one sealing hook without the
	 *                                   other, or a suffix that is not a dot
	 *                                   and a name.
	 */
	public function __construct(
		private Bucket $bucket,
		private string $list,
		private string $prefix = 'backups/',
		public int $keep = 14,
		private ?\Closure $seal = null,
		private ?\Closure $open = null,
		private string $suffix = '.sealed',
	) {
		if ( 1 !== preg_match( '#^(?:[A-Za-z0-9_-]+/)+$#D', $prefix ) ) {
			throw new \InvalidArgumentException( 'An archive prefix is path segments of letters, digits, _ and -, ending in /: ' . $prefix );
		}

		if ( $keep < 1 ) {
			throw new \InvalidArgumentException( 'Keep at least one copy.' );
		}

		if ( ( null === $seal ) !== ( null === $open ) ) {
			throw new \InvalidArgumentException( 'Sealing needs both hooks: one to seal, one to open what it sealed.' );
		}

		if ( 1 !== preg_match( '/^\.[A-Za-z0-9_-]+$/D', $suffix ) ) {
			throw new \InvalidArgumentException( 'A sealed suffix is a dot and letters, digits, _ or -: ' . $suffix );
		}
	}

	/**
	 * Send a copy of a file to storage, sealed when there are hooks, and
	 * list it once storage has it.
	 *
	 * Sending a name that is already listed lists it once, as the newest.
	 * Storage refusing it leaves the list as it was.
	 *
	 * @since 2.3.0
	 *
	 * @param string $path Local file, e.g. what Backups::take() returned.
	 * @param string $name Name to send it as, or '' for the file's own.
	 *
	 * @return string|null The key it is stored under, or null when it is not
	 *                     in storage: no such file, a seal hook that said
	 *                     false, or storage refused it.
	 *
	 * @throws \InvalidArgumentException On a name with a path or a leading dot.
	 * @throws \UnexpectedValueException When the list cannot be trusted; it
	 *                                   is checked before anything is sent.
	 * @throws \RuntimeException         When the list cannot be written.
	 */
	#[\NoDiscard( 'Null means the copy is not in storage.' )]
	public function send( string $path, string $name = '' ): ?string {
		$name = '' === $name ? basename( $path ) : $name;

		self::check( $name );
		$this->listed();

		if ( ! is_file( $path ) ) {
			return null;
		}

		if ( null === $this->seal ) {
			return $this->upload( $this->prefix . $name, $path );
		}

		$sealed = $this->scratch( true );

		try {
			return self::made( $this->seal, $path, $sealed ) ? $this->upload( $this->prefix . $name . $this->suffix, $sealed ) : null;
		} finally {
			@unlink( $sealed );
		}
	}

	/**
	 * Write a fresh copy, send it, and remove it again.
	 *
	 * For a copy that is only wanted off the server. The writer is called
	 * with a path that does not exist yet, in the list's owner-only
	 * directory — what Database::snapshot() wants:
	 *
	 *   $archive->capture( $db->snapshot( ... ), 'app-' . gmdate( 'Ymd-His' ) . '.db' );
	 *
	 * @since 2.3.0
	 *
	 * @param \Closure(string): mixed $write Writes the copy. False means it
	 *                                       failed; anything else that it
	 *                                       worked, once the file is there.
	 * @param string                  $name  Name to send it as.
	 *
	 * @return string|null The key it is stored under, or null when it is not
	 *                     in storage.
	 *
	 * @throws \InvalidArgumentException On a name with a path or a leading dot.
	 * @throws \UnexpectedValueException When the list cannot be trusted.
	 * @throws \RuntimeException         When the list cannot be written.
	 */
	#[\NoDiscard( 'Null means the copy is not in storage.' )]
	public function capture( \Closure $write, string $name ): ?string {
		self::check( $name );
		$this->listed();

		$copy = $this->scratch( false );

		try {
			return false !== $write( $copy ) && is_file( $copy ) ? $this->send( $copy, $name ) : null;
		} finally {
			@unlink( $copy );
		}
	}

	/**
	 * Delete all but the newest copies from storage.
	 *
	 * Newest by when they were sent, which is the list's order. A copy
	 * whose delete fails stays on the list, where it was, and is tried
	 * again next time.
	 *
	 * @since 2.3.0
	 *
	 * @return int Copies deleted.
	 *
	 * @throws \UnexpectedValueException When the list cannot be trusted;
	 *                                   nothing is deleted.
	 * @throws \RuntimeException         When the list cannot be written.
	 */
	public function prune(): int {
		$listed = $this->listed();
		$excess = count( $listed ) - $this->keep;

		if ( $excess < 1 ) {
			return 0;
		}

		$failed  = array();
		$deleted = 0;

		foreach ( array_slice( $listed, 0, $excess ) as $key ) {
			if ( $this->bucket->delete( $key ) ) {
				++$deleted;
			} else {
				$failed[] = $key;
			}
		}

		$this->write( array( ...$failed, ...array_slice( $listed, $excess ) ) );

		return $deleted;
	}

	/**
	 * The copies in storage, newest first.
	 *
	 * @since 2.3.0
	 *
	 * @return list<string> Object keys.
	 *
	 * @throws \UnexpectedValueException When the list cannot be trusted.
	 */
	public function keys(): array {
		return array_reverse( $this->listed() );
	}

	/**
	 * Download a copy and restore it to a file.
	 *
	 * Any key under the prefix, listed or not: the server whose list it was
	 * may be the thing that was lost. Opened with the open hook when there
	 * are hooks.
	 *
	 * @since 2.3.0
	 *
	 * @param string $key       Object key.
	 * @param string $to        Where to write the restored file.
	 * @param bool   $overwrite Replace $to when it exists.
	 *
	 * @return bool False when nothing was restored: storage would not give
	 *              it, or it would not open — the wrong key, or damaged.
	 *
	 * @throws \InvalidArgumentException On a key outside the prefix.
	 * @throws \RuntimeException         When $to exists and $overwrite is
	 *                                   false; nothing is downloaded.
	 */
	#[\NoDiscard( 'False means nothing was restored.' )]
	public function fetch( string $key, string $to, bool $overwrite = false ): bool {
		if ( ! $this->ours( $key ) ) {
			throw new \InvalidArgumentException( 'Not a key under ' . $this->prefix . ': ' . $key );
		}

		self::vacant( $to, $overwrite );

		$copy = $this->scratch( false );

		try {
			return $this->bucket->download_file( $key, $copy ) && self::restore( $copy, $to, $this->open, $overwrite );
		} finally {
			@unlink( $copy );
		}
	}

	/**
	 * Restore a copy already on disk to a file.
	 *
	 * For a copy downloaded some other way. Opened into a file beside $to,
	 * made owner-only, and moved into place only once it has opened, so a
	 * copy that will not open leaves nothing behind. Restoring over a
	 * database something has open is the caller's business.
	 *
	 * @since 2.3.0
	 *
	 * @param string                                 $from      The copy.
	 * @param string                                 $to        Where to write the restored file.
	 * @param (\Closure(string, string): mixed)|null $open      The open hook it was sealed for,
	 *                                                          or null for a copy that is not
	 *                                                          sealed.
	 * @param bool                                   $overwrite Replace $to when it exists.
	 *
	 * @return bool False when nothing was restored: no such copy, or it
	 *              would not open.
	 *
	 * @throws \RuntimeException When $to exists and $overwrite is false.
	 */
	#[\NoDiscard( 'False means nothing was restored.' )]
	public static function restore( string $from, string $to, ?\Closure $open = null, bool $overwrite = false ): bool {
		self::vacant( $to, $overwrite );

		if ( ! is_file( $from ) ) {
			return false;
		}

		$part = dirname( $to ) . '/.' . basename( $to ) . '.' . bin2hex( random_bytes( 8 ) ) . '.part';

		try {
			if ( ! self::owner_only( $part ) ) {
				return false;
			}

			$made = null === $open ? @copy( $from, $part ) : self::made( $open, $from, $part );

			return $made && self::place( $part, $to, $overwrite );
		} finally {
			@unlink( $part );
		}
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * Upload a file, and list it once storage has it.
	 *
	 * @param string $key  Object key.
	 * @param string $path Local file.
	 *
	 * @return string|null The key, or null when storage refused it.
	 */
	private function upload( string $key, string $path ): ?string {
		if ( ! $this->bucket->upload_file( $key, $path ) ) {
			return null;
		}

		$this->write( array( ...array_diff( $this->listed(), array( $key ) ), $key ) );

		return $key;
	}

	/**
	 * The listed keys, oldest first.
	 *
	 * No file is an empty list. Anything else that is not a JSON list of
	 * keys this archive could have sent throws, and is left as it is.
	 *
	 * @return list<string>
	 *
	 * @throws \UnexpectedValueException When the list cannot be trusted.
	 */
	private function listed(): array {
		if ( ! file_exists( $this->list ) && ! is_link( $this->list ) ) {
			return array();
		}

		$json = @file_get_contents( $this->list );

		try {
			$keys = is_string( $json ) ? json_decode( $json, true, 4, JSON_THROW_ON_ERROR ) : null;
		} catch ( \JsonException ) {
			$keys = null;
		}

		if ( ! is_array( $keys ) || ! array_is_list( $keys ) ) {
			throw $this->untrusted( 'it is not a JSON list' );
		}

		$found = array();

		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! $this->ours( $key ) ) {
				throw $this->untrusted( 'it names something other than a key under ' . $this->prefix );
			}

			$found[ $key ] = $key;
		}

		return array_values( $found );
	}

	/**
	 * Why the list is being left alone.
	 *
	 * @param string $reason What is wrong with it.
	 *
	 * @return \UnexpectedValueException
	 */
	private function untrusted( string $reason ): \UnexpectedValueException {
		return new \UnexpectedValueException( 'The list of copies in storage at ' . $this->list . ' cannot be trusted: ' . $reason . '. It is left as it is, because a new one would forget every copy already sent.' );
	}

	/**
	 * Replace the list, whole.
	 *
	 * Written to a temporary file and renamed over the old one, so a crash
	 * part way leaves the old list rather than half a new one.
	 *
	 * @param list<string> $keys Object keys, oldest first.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When it cannot be written.
	 */
	private function write( array $keys ): void {
		$temp = $this->scratch( true );

		try {
			$json = json_encode( array_values( array_unique( $keys ) ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );

			if ( false === file_put_contents( $temp, $json ) || ! @rename( $temp, $this->list ) ) {
				throw new \RuntimeException( 'Cannot write the list of copies in storage at ' . $this->list );
			}
		} finally {
			@unlink( $temp );
		}
	}

	/**
	 * A temporary path in the list's directory, which is made owner-only
	 * when it is missing.
	 *
	 * @param bool $create Create it as an empty owner-only file, rather than
	 *                     leave it for a writer that wants a path that does
	 *                     not exist.
	 *
	 * @return string
	 */
	private function scratch( bool $create ): string {
		$dir = dirname( $this->list );

		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0700, true );
		}

		$path = $dir . '/.archive-' . bin2hex( random_bytes( 8 ) ) . '.tmp';

		if ( $create ) {
			self::owner_only( $path );
		}

		return $path;
	}

	/**
	 * Create an empty owner-only file, where nothing is yet.
	 *
	 * @param string $path File.
	 *
	 * @return bool
	 */
	private static function owner_only( string $path ): bool {
		$handle = @fopen( $path, 'xb' );

		if ( false === $handle ) {
			return false;
		}

		fclose( $handle );

		return @chmod( $path, 0600 );
	}

	/**
	 * Run a sealing hook, and whether it made its file.
	 *
	 * @param \Closure(string, string): mixed $hook Seal or open.
	 * @param string                          $from File it reads.
	 * @param string                          $to   File it writes.
	 *
	 * @return bool
	 */
	private static function made( \Closure $hook, string $from, string $to ): bool {
		return false !== $hook( $from, $to ) && is_file( $to );
	}

	/**
	 * Move a restored file into place.
	 *
	 * Without overwriting, by a hard link, which fails rather than replace
	 * a file that appeared since the check. A filesystem without hard
	 * links gets a check and a rename.
	 *
	 * @param string $part      The restored file.
	 * @param string $to        Where it goes.
	 * @param bool   $overwrite Replace $to when it exists.
	 *
	 * @return bool
	 *
	 * @throws \RuntimeException When $to appeared and $overwrite is false.
	 */
	private static function place( string $part, string $to, bool $overwrite ): bool {
		if ( $overwrite || @link( $part, $to ) ) {
			return $overwrite ? @rename( $part, $to ) : true;
		}

		self::vacant( $to, false );

		return @rename( $part, $to );
	}

	/**
	 * Refuse a target that exists, unless it is to be replaced.
	 *
	 * @param string $to        Target.
	 * @param bool   $overwrite Replace it when it exists.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When it exists and $overwrite is false.
	 */
	private static function vacant( string $to, bool $overwrite ): void {
		if ( ! $overwrite && ( file_exists( $to ) || is_link( $to ) ) ) {
			throw new \RuntimeException( 'A restore will not overwrite ' . $to );
		}
	}

	/**
	 * Whether a key is one this archive could have sent.
	 *
	 * @param string $key Object key.
	 *
	 * @return bool
	 */
	private function ours( string $key ): bool {
		return str_starts_with( $key, $this->prefix ) && 1 === preg_match( self::NAME, substr( $key, strlen( $this->prefix ) ) );
	}

	/**
	 * Refuse a name that is not a plain file name.
	 *
	 * @param string $name Name to send a copy as.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When it has a path or a leading dot.
	 */
	private static function check( string $name ): void {
		if ( 1 !== preg_match( self::NAME, $name ) ) {
			throw new \InvalidArgumentException( 'A copy is sent under a plain name — letters, digits, ., _ and -, not starting with a dot: ' . $name );
		}
	}
}
