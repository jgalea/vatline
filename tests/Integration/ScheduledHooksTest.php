<?php

namespace Levy\EuVat\Tests\Integration;

use Levy\EuVat\Tests\Support\IntegrationTestCase;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Every Action Scheduler call in src/ must name a hook the booted plugin
 * listens on, with a callback that takes the arguments the call passes.
 */
class ScheduledHooksTest extends IntegrationTestCase {

	const HOOK_POSITION = array(
		'as_enqueue_async_action'      => 0,
		'as_schedule_single_action'    => 1,
		'as_schedule_recurring_action' => 2,
	);

	/**
	 * @return array<string,array{0:string,1:string,2:int}> call site => [file, hook, arg count]
	 */
	public static function calls_in( string $dir ): array {
		$calls = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			preg_match( '/^namespace\s+([^;]+);/m', $source, $namespace );
			preg_match( '/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class );
			$fqcn = ( $namespace[1] ?? '' ) . '\\' . ( $class[1] ?? '' );
			preg_match_all( '/^use\s+([\w\\\\]+);/m', $source, $uses );
			$imports = array();
			foreach ( $uses[1] as $use ) {
				$imports[ substr( (string) strrchr( '\\' . $use, '\\' ), 1 ) ] = $use;
			}

			preg_match_all( '/\b(' . implode( '|', array_keys( self::HOOK_POSITION ) ) . ')\(/', $source, $matches, PREG_OFFSET_CAPTURE );
			foreach ( $matches[1] as $match ) {
				$args  = self::split_args( $source, $match[1] + strlen( $match[0] ) + 1 );
				$hook  = self::resolve( $args[ self::HOOK_POSITION[ $match[0] ] ], $fqcn, $imports );
				$count = self::array_length( $args[ self::HOOK_POSITION[ $match[0] ] + 1 ] ?? 'array()' );
				$line  = substr_count( substr( $source, 0, $match[1] ), "\n" ) + 1;

				$calls[ basename( $file->getPathname() ) . ':' . $line ] = array( $file->getPathname(), $hook, $count );
			}
		}
		ksort( $calls );
		return $calls;
	}

	/**
	 * @return string[] the top-level arguments of the call whose list starts at $start
	 */
	private static function split_args( string $source, int $start ): array {
		$args    = array();
		$depth   = 0;
		$current = '';
		$length  = strlen( $source );
		for ( $i = $start; $i < $length; $i++ ) {
			$char = $source[ $i ];
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$args[]  = trim( $current );
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$args[] = trim( $current );
		return $args;
	}

	/**
	 * @param array<string,string> $imports short class name => imported class
	 */
	private static function resolve( string $expression, string $fqcn, array $imports ): string {
		if ( preg_match( "/^'([^']+)'$/", $expression, $literal ) ) {
			return $literal[1];
		}
		if ( preg_match( '/^(?:self|static)::(\w+)$/', $expression, $constant ) ) {
			return (string) constant( $fqcn . '::' . $constant[1] );
		}
		if ( preg_match( '/^\\\\?([\w\\\\]+)::(\w+)$/', $expression, $constant ) ) {
			$class = $imports[ $constant[1] ] ?? ( false !== strpos( $constant[1], '\\' ) ? $constant[1] : substr( $fqcn, 0, (int) strrpos( $fqcn, '\\' ) ) . '\\' . $constant[1] );
			return (string) constant( $class . '::' . $constant[2] );
		}
		self::fail( "Cannot resolve hook expression {$expression} in {$fqcn}" );
	}

	private static function array_length( string $expression ): int {
		if ( ! preg_match( '/^array\s*\((.*)\)$/s', $expression, $inner ) ) {
			self::fail( "Arguments must be an array literal, got {$expression}" );
		}
		return '' === trim( $inner[1] ) ? 0 : count( self::split_args( $inner[1] . ')', 0 ) );
	}

	public function test_every_scheduled_hook_has_a_callback_that_takes_its_arguments(): void {
		global $wp_filter;

		$calls = self::calls_in( dirname( __DIR__, 2 ) . '/src' );
		$this->assertGreaterThanOrEqual( 8, count( $calls ), implode( ', ', array_keys( $calls ) ) );

		foreach ( $calls as $site => list( , $hook, $count ) ) {
			$this->assertTrue( has_action( $hook ), "{$site}: nothing listens on {$hook}" );
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = is_array( $callback['function'] )
						? new ReflectionMethod( $callback['function'][0], $callback['function'][1] )
						: new ReflectionFunction( $callback['function'] );
					$passed   = min( $count, (int) $callback['accepted_args'] );
					$this->assertSame( $count, $passed, "{$site}: {$hook} is scheduled with {$count} args but the callback accepts {$callback['accepted_args']}" );
					$this->assertGreaterThanOrEqual( $function->getNumberOfRequiredParameters(), $passed, "{$site}: {$hook} passes {$passed} args, {$function->getName()} requires {$function->getNumberOfRequiredParameters()}" );
					$this->assertLessThanOrEqual( $function->getNumberOfParameters(), $passed, "{$site}: {$hook} passes more args than {$function->getName()} takes" );
				}
			}
		}
	}

	public function test_the_scanner_catches_a_mismatched_hook(): void {
		$dir = get_temp_dir() . 'levy-scan-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/Bad.php', "<?php\nnamespace Levy\\EuVat\\Ledger;\nclass Bad {\n\tpublic function go() {\n\t\tas_enqueue_async_action( 'levy_eu_vat_nobody_listens', array( 1, 2 ), 'g' );\n\t\tas_schedule_single_action( time() + 60, Backfill::HOOK, array(), 'g' );\n\t}\n}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$calls = self::calls_in( $dir );
		array_map( 'unlink', (array) glob( $dir . '/*' ) );
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		$this->assertSame( array( 'levy_eu_vat_nobody_listens', 2 ), array_slice( $calls['Bad.php:5'], 1 ) );
		$this->assertSame( array( 'levy_eu_vat_backfill_batch', 0 ), array_slice( $calls['Bad.php:6'], 1 ) );
		$this->assertFalse( has_action( 'levy_eu_vat_nobody_listens' ) );
	}
}
