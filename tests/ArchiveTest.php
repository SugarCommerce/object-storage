<?php
/**
 * Archive tests.
 *
 * @package SugarCommerce\ObjectStorage\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\ObjectStorage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\HttpClient\{Fake, Response};
use SugarCommerce\ObjectStorage\{Archive, Bucket};
use SugarCommerce\S3Signer\Provider;

/**
 * Class ArchiveTest
 *
 * Against a fake bucket that keeps what is PUT, gives it back on a GET and
 * forgets it on a DELETE — and can be told to refuse.
 */
final class ArchiveTest extends TestCase {

	/**
	 * What the fake bucket holds, by key.
	 *
	 * @var array<string, string>
	 */
	private array $objects = array();

	/**
	 * Requests the fake bucket refuses, by verb.
	 *
	 * @var array<string, true>
	 */
	private array $refuse = array();

	private string $dir;

	private Fake $http;

	protected function setUp(): void {
		$this->dir  = sys_get_temp_dir() . '/object-storage-archive-' . bin2hex( random_bytes( 4 ) );
		$this->http = ( new Fake() )->on( '*', '*', $this->answer( ... ) );

		mkdir( $this->dir . '/local', 0700, true );
	}

	protected function tearDown(): void {
		$this->remove( $this->dir );
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * The fake bucket.
	 *
	 * @param string      $method HTTP verb.
	 * @param string      $url    URL.
	 * @param string|null $body   Request body.
	 *
	 * @return Response
	 */
	private function answer( string $method, string $url, ?string $body ): Response {
		$key = rawurldecode( (string) preg_replace( '#^https?://[^/]+/files/|\?.*$#', '', $url ) );

		if ( isset( $this->refuse[ $method ] ) ) {
			return new Response( false, 500 );
		}

		switch ( $method ) {
			case 'PUT':
				$this->objects[ $key ] = (string) $body;

				return new Response( true, 200, headers: array( 'etag' => '"' . md5( (string) $body ) . '"' ) );

			case 'GET':
				return isset( $this->objects[ $key ] )
					? new Response( true, 200, $this->objects[ $key ], headers: array( 'content-length' => (string) strlen( $this->objects[ $key ] ) ) )
					: new Response( false, 404, '<Error><Code>NoSuchKey</Code></Error>' );

			case 'DELETE':
				unset( $this->objects[ $key ] );

				return new Response( true, 204 );
		}

		return new Response( false, 405 );
	}

	/**
	 * An archive listing into the test directory.
	 *
	 * @param bool $sealed With the test's sealing hooks.
	 * @param int  $keep   Copies kept.
	 *
	 * @return Archive
	 */
	private function archive( bool $sealed = false, int $keep = 14 ): Archive {
		$bucket = new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'files', $this->http );

		return $sealed
			? new Archive( $bucket, $this->list(), 'backups/', $keep, self::sealer( ... ), self::opener( ... ) )
			: new Archive( $bucket, $this->list(), 'backups/', $keep );
	}

	/**
	 * A stand-in seal: a marker and the bytes reversed.
	 *
	 * @param string $from Plain file.
	 * @param string $to   Sealed file.
	 *
	 * @return bool
	 */
	private static function sealer( string $from, string $to ): bool {
		return false !== file_put_contents( $to, 'SEALED:' . strrev( (string) file_get_contents( $from ) ) );
	}

	/**
	 * Opens what sealer() sealed, and nothing else.
	 *
	 * @param string $from Sealed file.
	 * @param string $to   Plain file.
	 *
	 * @return bool
	 */
	private static function opener( string $from, string $to ): bool {
		$sealed = (string) file_get_contents( $from );

		return str_starts_with( $sealed, 'SEALED:' ) && false !== file_put_contents( $to, strrev( substr( $sealed, 7 ) ) );
	}

	private function list(): string {
		return $this->dir . '/local/remote.json';
	}

	/**
	 * A local file, as a backup would be.
	 *
	 * @param string $name     File name.
	 * @param string $contents What is in it.
	 *
	 * @return string Its path.
	 */
	private function local( string $name, string $contents = 'SQLite format 3 and the rows' ): string {
		file_put_contents( $this->dir . '/local/' . $name, $contents );

		return $this->dir . '/local/' . $name;
	}

	/**
	 * Every file left in a directory, hidden ones included.
	 *
	 * @param string $dir Directory.
	 *
	 * @return list<string> Names, sorted.
	 */
	private static function files( string $dir ): array {
		$names = array_values( array_diff( scandir( $dir ) ?: array(), array( '.', '..' ) ) );

		sort( $names );

		return $names;
	}

	private function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( self::files( $path ) as $name ) {
				$this->remove( $path . '/' . $name );
			}

			rmdir( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/* ─── Sending ───────────────────────────────────────────────────── */

	public function test_a_copy_is_sent_under_the_prefix_and_listed(): void {
		$key = $this->archive()->send( $this->local( 'app-20260101-000000.db' ) );

		$this->assertSame( 'backups/app-20260101-000000.db', $key );
		$this->assertSame( 'SQLite format 3 and the rows', $this->objects['backups/app-20260101-000000.db'] );
		$this->assertSame( array( 'backups/app-20260101-000000.db' ), $this->archive()->keys() );
	}

	/**
	 * What leaves the server is what the seal hook made, under a key that
	 * says so; the sealed file it went up from is gone.
	 */
	public function test_a_sealed_copy_goes_up_sealed_and_leaves_nothing_behind(): void {
		$key = $this->archive( sealed: true )->send( $this->local( 'app.db' ) );

		$this->assertSame( 'backups/app.db.sealed', $key );
		$this->assertStringStartsWith( 'SEALED:', $this->objects['backups/app.db.sealed'] );
		$this->assertStringNotContainsString( 'SQLite format', $this->objects['backups/app.db.sealed'] );
		$this->assertSame( array( 'app.db', 'remote.json' ), self::files( $this->dir . '/local' ) );
	}

	public function test_a_copy_can_be_sent_under_another_name(): void {
		$this->assertSame( 'backups/nightly.db', $this->archive()->send( $this->local( 'app.db' ), 'nightly.db' ) );
	}

	/**
	 * The list is only written once storage has the copy, so a refusal
	 * leaves it exactly as it was.
	 */
	public function test_the_list_survives_a_failed_upload(): void {
		$archive = $this->archive( sealed: true );

		(void) $archive->send( $this->local( 'a.db' ) );
		$before = (string) file_get_contents( $this->list() );

		$this->refuse['PUT'] = true;

		$this->assertNull( $archive->send( $this->local( 'b.db' ) ) );
		$this->assertSame( $before, file_get_contents( $this->list() ) );
		$this->assertSame( array( 'backups/a.db.sealed' ), $archive->keys() );
		$this->assertSame( array( 'a.db', 'b.db', 'remote.json' ), self::files( $this->dir . '/local' ), 'The sealed file is gone.' );
	}

	public function test_a_seal_that_failed_sends_nothing(): void {
		$archive = new Archive(
			new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'files', $this->http ),
			$this->list(),
			seal: static fn ( string $from, string $to ): bool => false,
			open: self::opener( ... ),
		);

		$this->assertNull( $archive->send( $this->local( 'a.db' ) ) );
		$this->assertSame( array(), $this->http->methods() );
		$this->assertSame( array( 'a.db' ), self::files( $this->dir . '/local' ) );
	}

	/**
	 * A seal that throws is the caller's to see, and still leaves nothing.
	 */
	public function test_a_seal_that_threw_is_passed_on_after_cleaning_up(): void {
		$archive = new Archive(
			new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'files', $this->http ),
			$this->list(),
			seal: static function ( string $from, string $to ): never {
				file_put_contents( $to, 'half' );

				throw new \RuntimeException( 'disk full' );
			},
			open: self::opener( ... ),
		);

		try {
			(void) $archive->send( $this->local( 'a.db' ) );
			$this->fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'disk full', $e->getMessage() );
		}

		$this->assertSame( array( 'a.db' ), self::files( $this->dir . '/local' ) );
	}

	public function test_a_file_that_is_not_there_sends_nothing(): void {
		$this->assertNull( $this->archive()->send( $this->dir . '/local/missing.db' ) );
		$this->assertSame( array(), $this->http->methods() );
	}

	/**
	 * Sent again — a retry — it is listed once, as the newest.
	 */
	public function test_a_name_sent_again_is_listed_once_as_the_newest(): void {
		$archive = $this->archive();

		(void) $archive->send( $this->local( 'a.db' ) );
		(void) $archive->send( $this->local( 'b.db' ) );
		(void) $archive->send( $this->dir . '/local/a.db' );

		$this->assertSame( array( 'backups/a.db', 'backups/b.db' ), $archive->keys() );
	}

	/**
	 * The list holds what is in storage, which is nobody else's business.
	 */
	public function test_the_list_and_its_directory_are_owner_only(): void {
		$archive = new Archive( new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'files', $this->http ), $this->dir . '/new/remote.json' );

		(void) $archive->send( $this->local( 'a.db' ) );

		$this->assertSame( 0700, fileperms( $this->dir . '/new' ) & 0777 );
		$this->assertSame( 0600, fileperms( $this->dir . '/new/remote.json' ) & 0777 );
	}

	/* ─── Writing a copy to send ────────────────────────────────────── */

	/**
	 * The writer gets a path that does not exist yet — what
	 * Database::snapshot() insists on — and the copy is gone afterwards.
	 */
	public function test_a_captured_copy_is_written_sent_and_removed(): void {
		$given = '';
		$key   = $this->archive( sealed: true )->capture(
			static function ( string $path ) use ( &$given ): void {
				$given = $path;

				if ( file_exists( $path ) ) {
					throw new \RuntimeException( 'A snapshot will not overwrite ' . $path );
				}

				file_put_contents( $path, 'fresh copy' );
			},
			'app-20260101-000000.db'
		);

		$this->assertSame( 'backups/app-20260101-000000.db.sealed', $key );
		$this->assertSame( 'SEALED:' . strrev( 'fresh copy' ), $this->objects[ (string) $key ] );
		$this->assertSame( $this->dir . '/local', dirname( $given ) );
		$this->assertFileDoesNotExist( $given );
		$this->assertSame( array( 'remote.json' ), self::files( $this->dir . '/local' ) );
	}

	public function test_a_capture_whose_writer_failed_sends_nothing(): void {
		$this->assertNull( $this->archive()->capture( static fn ( string $path ): bool => false, 'a.db' ) );
		$this->assertSame( array(), $this->http->methods() );
		$this->assertSame( array(), self::files( $this->dir . '/local' ) );
	}

	/* ─── Pruning ───────────────────────────────────────────────────── */

	public function test_pruning_keeps_the_newest(): void {
		$archive = $this->archive( keep: 2 );

		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $name ) {
			(void) $archive->send( $this->local( $name . '.db' ) );
		}

		$this->assertSame( 3, $archive->prune() );
		$this->assertSame( array( 'backups/e.db', 'backups/d.db' ), $archive->keys() );
		$this->assertSame( array( 'backups/d.db', 'backups/e.db' ), array_keys( $this->objects ) );
		$this->assertCount( 3, $this->http->sent( 'DELETE' ) );
	}

	/**
	 * Newest by when it was sent, not by what it is called.
	 */
	public function test_pruning_goes_by_the_order_sent_not_by_name(): void {
		$archive = $this->archive( keep: 1 );

		(void) $archive->send( $this->local( 'z.db' ) );
		(void) $archive->send( $this->local( 'a.db' ) );
		(void) $archive->prune();

		$this->assertSame( array( 'backups/a.db' ), $archive->keys() );
	}

	public function test_nothing_to_prune_asks_storage_nothing(): void {
		$archive = $this->archive( keep: 2 );

		(void) $archive->send( $this->local( 'a.db' ) );

		$this->assertSame( 0, $archive->prune() );
		$this->assertSame( array(), $this->http->sent( 'DELETE' ) );
	}

	/**
	 * A copy whose delete fails is not forgotten: still listed, and tried
	 * again next time.
	 */
	public function test_a_copy_that_would_not_delete_is_tried_again(): void {
		$archive = $this->archive( keep: 1 );

		(void) $archive->send( $this->local( 'a.db' ) );
		(void) $archive->send( $this->local( 'b.db' ) );

		$this->refuse['DELETE'] = true;

		$this->assertSame( 0, $archive->prune() );
		$this->assertSame( array( 'backups/b.db', 'backups/a.db' ), $archive->keys() );

		unset( $this->refuse['DELETE'] );

		$this->assertSame( 1, $archive->prune() );
		$this->assertSame( array( 'backups/b.db' ), $archive->keys() );
		$this->assertArrayNotHasKey( 'backups/a.db', $this->objects );
	}

	/* ─── The list ──────────────────────────────────────────────────── */

	public function test_with_no_list_nothing_is_in_storage(): void {
		$this->assertSame( array(), $this->archive()->keys() );
	}

	/**
	 * Written oldest first, so a list kept by hand the same way reads.
	 */
	public function test_the_list_is_read_oldest_first(): void {
		file_put_contents( $this->list(), '["backups/a.db","backups/b.db"]' );

		$this->assertSame( array( 'backups/b.db', 'backups/a.db' ), $this->archive()->keys() );
	}

	/**
	 * A fresh list over a corrupt one forgets every copy in storage, which
	 * then stays there for good. So it is refused, before anything is sent
	 * or deleted, and the file is left for someone to look at.
	 */
	public function test_a_corrupt_list_is_refused_and_left_alone(): void {
		$archive = $this->archive( keep: 1 );

		file_put_contents( $this->list(), '["backups/a.db", "backups/b.d' );

		foreach ( array( 'keys' => fn () => $archive->keys(), 'send' => fn () => $archive->send( $this->local( 'c.db' ) ), 'prune' => fn () => $archive->prune() ) as $call => $run ) {
			try {
				$run();
				$this->fail( $call . '() used a corrupt list.' );
			} catch ( \UnexpectedValueException $e ) {
				$this->assertStringContainsString( $this->list(), $e->getMessage() );
			}
		}

		$this->assertSame( array(), $this->http->methods(), 'Nothing sent, nothing deleted.' );
		$this->assertSame( '["backups/a.db", "backups/b.d', file_get_contents( $this->list() ) );
	}

	/**
	 * prune() deletes what the list names, so a list naming anything else
	 * — a product file, say — must not be believed.
	 */
	public function test_a_list_naming_keys_outside_the_prefix_is_refused(): void {
		$archive = $this->archive( keep: 1 );

		foreach ( array( '["products/master.wav","backups/a.db"]', '["backups/../products/master.wav"]', '{"a":"backups/a.db"}', '[1]', '"backups/a.db"', '' ) as $list ) {
			file_put_contents( $this->list(), $list );

			try {
				$archive->prune();
				$this->fail( 'Believed ' . $list );
			} catch ( \UnexpectedValueException ) {
				$this->assertSame( array(), $this->http->sent( 'DELETE' ) );
			}
		}
	}

	/* ─── Fetching and restoring ────────────────────────────────────── */

	public function test_a_sealed_copy_comes_back_opened(): void {
		$archive = $this->archive( sealed: true );
		$key     = (string) $archive->send( $this->local( 'app.db' ) );

		$this->assertTrue( $archive->fetch( $key, $this->dir . '/restored.db' ) );
		$this->assertSame( 'SQLite format 3 and the rows', file_get_contents( $this->dir . '/restored.db' ) );
		$this->assertSame( 0600, fileperms( $this->dir . '/restored.db' ) & 0777 );
		$this->assertSame( array( 'local', 'restored.db' ), self::files( $this->dir ) );
		$this->assertSame( array( 'app.db', 'remote.json' ), self::files( $this->dir . '/local' ) );
	}

	/**
	 * The server whose list it was may be what was lost.
	 */
	public function test_a_key_need_not_be_listed_to_be_fetched(): void {
		$this->objects['backups/old.db'] = 'from another server';

		$this->assertTrue( $this->archive()->fetch( 'backups/old.db', $this->dir . '/restored.db' ) );
		$this->assertSame( 'from another server', file_get_contents( $this->dir . '/restored.db' ) );
	}

	public function test_a_restore_refuses_to_overwrite(): void {
		$archive = $this->archive( sealed: true );
		$key     = (string) $archive->send( $this->local( 'app.db' ) );

		file_put_contents( $this->dir . '/live.db', 'the live database' );

		try {
			(void) $archive->fetch( $key, $this->dir . '/live.db' );
			$this->fail( 'Overwrote an existing file.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'will not overwrite', $e->getMessage() );
		}

		$this->assertSame( 'the live database', file_get_contents( $this->dir . '/live.db' ) );
		$this->assertSame( array(), $this->http->sent( 'GET' ), 'Nothing downloaded.' );
		$this->assertSame( array( 'live.db', 'local' ), self::files( $this->dir ) );
	}

	public function test_a_restore_overwrites_when_told_to(): void {
		$archive = $this->archive( sealed: true );
		$key     = (string) $archive->send( $this->local( 'app.db' ) );

		file_put_contents( $this->dir . '/live.db', 'the live database' );

		$this->assertTrue( $archive->fetch( $key, $this->dir . '/live.db', overwrite: true ) );
		$this->assertSame( 'SQLite format 3 and the rows', file_get_contents( $this->dir . '/live.db' ) );
	}

	/**
	 * The wrong key, or damaged: nothing at the target, nothing left over.
	 */
	public function test_a_copy_that_will_not_open_restores_nothing(): void {
		$this->objects['backups/app.db.sealed'] = 'not sealed by us';

		$this->assertFalse( $this->archive( sealed: true )->fetch( 'backups/app.db.sealed', $this->dir . '/restored.db' ) );
		$this->assertSame( array( 'local' ), self::files( $this->dir ) );
		$this->assertSame( array(), self::files( $this->dir . '/local' ) );
	}

	public function test_a_copy_storage_does_not_have_restores_nothing(): void {
		$this->assertFalse( $this->archive()->fetch( 'backups/missing.db', $this->dir . '/restored.db' ) );
		$this->assertSame( array( 'local' ), self::files( $this->dir ) );
		$this->assertSame( array(), self::files( $this->dir . '/local' ) );
	}

	public function test_a_key_outside_the_prefix_is_not_fetched(): void {
		$this->expectException( \InvalidArgumentException::class );

		(void) $this->archive()->fetch( 'products/master.wav', $this->dir . '/restored.db' );
	}

	/**
	 * A copy downloaded some other way opens the same.
	 */
	public function test_a_local_copy_is_restored_with_the_open_hook(): void {
		$sealed = $this->local( 'app.db.sealed', 'SEALED:' . strrev( 'the rows' ) );

		$this->assertTrue( Archive::restore( $sealed, $this->dir . '/restored.db', self::opener( ... ) ) );
		$this->assertSame( 'the rows', file_get_contents( $this->dir . '/restored.db' ) );

		$this->expectException( \RuntimeException::class );

		(void) Archive::restore( $sealed, $this->dir . '/restored.db', self::opener( ... ) );
	}

	public function test_an_unsealed_local_copy_is_copied(): void {
		$this->assertTrue( Archive::restore( $this->local( 'app.db', 'plain' ), $this->dir . '/restored.db' ) );
		$this->assertSame( 'plain', file_get_contents( $this->dir . '/restored.db' ) );
	}

	public function test_an_open_hook_that_threw_leaves_nothing(): void {
		$sealed = $this->local( 'app.db.sealed' );

		try {
			(void) Archive::restore( $sealed, $this->dir . '/restored.db', static fn ( string $from, string $to ): never => throw new \RuntimeException( 'bad key' ) );
			$this->fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad key', $e->getMessage() );
		}

		$this->assertSame( array( 'local' ), self::files( $this->dir ) );
	}

	/* ─── Refusals ──────────────────────────────────────────────────── */

	public function test_a_name_with_a_path_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		(void) $this->archive()->send( $this->local( 'a.db' ), '../a.db' );
	}

	public function test_a_prefix_that_is_not_one_is_refused(): void {
		foreach ( array( '', 'backups', '/backups/', '../backups/', 'backups//' ) as $prefix ) {
			try {
				new Archive( new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'files', $this->http ), $this->list(), $prefix );
				$this->fail( 'Took the prefix ' . $prefix );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_keeping_none_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->archive( keep: 0 );
	}

	/**
	 * A seal hook without its open hook makes copies nothing here can open.
	 */
	public function test_one_sealing_hook_without_the_other_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Archive( new Bucket( Provider::R2->signer( 'key', 'secret', 'auto', 'acct', '' ), 'files', $this->http ), $this->list(), seal: self::sealer( ... ) );
	}
}
