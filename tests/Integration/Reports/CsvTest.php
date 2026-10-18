<?php

namespace Levy\EuVat\Tests\Integration\Reports;

use Levy\EuVat\Reports\Csv;
use WP_UnitTestCase;

class CsvTest extends WP_UnitTestCase {

	/**
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function cells(): array {
		return array(
			'equals'     => array( '=SUM(A1:A9)', "'=SUM(A1:A9)" ),
			'plus'       => array( '+1+1', "'+1+1" ),
			'minus'      => array( '-2+3', "'-2+3" ),
			'at'         => array( '@cmd', "'@cmd" ),
			'tab'        => array( "\tx", "'\tx" ),
			'plain'      => array( 'DE123456789', 'DE123456789' ),
			'negative'   => array( '-12.50', '-12.50' ),
			'float'      => array( -7.6, '-7.6' ),
			'positive'   => array( 19, '19' ),
			'empty'      => array( '', '' ),
			'inner sign' => array( 'a=b', 'a=b' ),
		);
	}

	/**
	 * @dataProvider cells
	 * @param mixed $input
	 */
	public function test_csv_escapes_formula_cells( $input, string $expected ): void {
		$this->assertSame( $expected, Csv::cell( $input ) );
	}

	public function test_render_quotes_and_guards_every_row(): void {
		$csv = Csv::render( array( 'Name', 'Net' ), array( array( '=HYPERLINK("x")', '-5.00' ), array( 'Smith, J', 3 ) ) );

		$this->assertSame( "Name,Net\n\"'=HYPERLINK(\"\"x\"\")\",-5.00\n\"Smith, J\",3\n", $csv );
	}
}
