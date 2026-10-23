<?php

namespace Vatright\Tests\Integration\Admin;

use Vatright\Admin\RateMappingEditor;
use Vatright\Rates\RateMapping;
use Vatright\Rates\RateSet;
use Vatright\Rates\RateSync;
use Vatright\Tests\Support\IntegrationTestCase;

class RateMappingEditorTest extends IntegrationTestCase {

	/** @var RateMappingEditor */
	private $editor;

	/** @var RateSet */
	private $set;

	public function set_up() {
		parent::set_up();
		delete_option( RateMapping::OPTION );
		$this->set = new RateSet(
			array(
				'DE' => array(
					'standard' => 19.0,
					'reduced'  => array(
						array(
							'rate'       => 7.0,
							'type'       => 'reduced',
							'categories' => array( 'FOODSTUFFS', 'PERIODICALS' ),
						),
					),
				),
				'FR' => array(
					'standard' => 20.0,
					'reduced'  => array(
						array(
							'rate'       => 5.5,
							'type'       => 'reduced',
							'categories' => array( 'FOODSTUFFS' ),
						),
						array(
							'rate'       => 2.1,
							'type'       => 'super_reduced',
							'categories' => array( 'NEWSPAPERS' ),
						),
					),
				),
			),
			'tedb',
			'2026-10-17'
		);
		$sync      = $this->createMock( RateSync::class );
		$sync->method( 'latest' )->willReturn( $this->set );
		$this->editor = new RateMappingEditor( $sync );
	}

	public function tear_down() {
		unset( $_POST[ RateMappingEditor::FIELD ] );
		parent::tear_down();
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function save( array $rows ): void {
		$_POST[ RateMappingEditor::FIELD ] = wp_slash( $rows );
		$this->editor->save();
	}

	private function html(): string {
		ob_start();
		$this->editor->render();
		return (string) ob_get_clean();
	}

	public function test_renders_a_row_per_tax_class(): void {
		$html = $this->html();
		foreach ( wc_get_product_tax_class_options() as $label ) {
			$this->assertStringContainsString( esc_html( $label ), $html );
		}
		$this->assertStringContainsString( 'value="reduced"', $html );
	}

	public function test_country_rates_are_rendered_for_every_class_but_visible_only_for_reduced(): void {
		update_option(
			RateMapping::OPTION,
			array(
				'reduced-rate' => array(
					'type'        => 'reduced',
					'per_country' => array(),
				),
			)
		);
		$html    = $this->html();
		$classes = count( wc_get_product_tax_class_options() );
		$this->assertSame( $classes, substr_count( $html, 'vatright-countries' ) );
		$this->assertSame( $classes - 1, substr_count( $html, 'vatright-countries" hidden' ) );
		$this->assertStringContainsString( 'vatright_mapping[0][rates][DE]', $html );
		$this->assertStringContainsString( '5.5% - Foodstuffs', $html );
		$this->assertStringContainsString( '2.1% (super reduced)', $html );
	}

	public function test_save_persists_type_and_picked_rates(): void {
		$this->save(
			array(
				array(
					'class' => '',
					'type'  => 'standard',
				),
				array(
					'class' => 'reduced-rate',
					'type'  => 'reduced',
					'rates' => array(
						'DE' => '7',
						'FR' => '5.5',
					),
				),
			)
		);
		$map = RateMapping::load()->to_array();
		$this->assertSame( 'standard', $map['']['type'] );
		$this->assertSame(
			array(
				'DE' => 7.0,
				'FR' => 5.5,
			),
			$map['reduced-rate']['per_country']
		);

		$targets = RateMapping::load()->targets( $this->set );
		$this->assertContains(
			array(
				'country' => 'FR',
				'class'   => 'reduced-rate',
				'type'    => 'reduced',
				'rate'    => 5.5,
			),
			$targets
		);
	}

	public function test_rate_not_in_the_eu_list_is_dropped(): void {
		$this->save(
			array(
				array(
					'class' => 'reduced-rate',
					'type'  => 'reduced',
					'rates' => array(
						'DE' => '3',
						'XX' => '7',
						'FR' => '',
					),
				),
			)
		);
		$this->assertSame( array(), RateMapping::load()->to_array()['reduced-rate']['per_country'] );
	}

	public function test_unknown_class_and_unknown_type_are_ignored(): void {
		$this->save(
			array(
				array(
					'class' => 'made-up',
					'type'  => 'standard',
				),
				array(
					'class' => '',
					'type'  => 'bogus',
				),
			)
		);
		$this->assertSame( array(), RateMapping::load()->to_array() );
	}

	public function test_not_mapped_removes_the_class(): void {
		$this->save(
			array(
				array(
					'class' => '',
					'type'  => 'standard',
				),
			)
		);
		$this->save(
			array(
				array(
					'class' => '',
					'type'  => '',
				),
			)
		);
		$this->assertArrayNotHasKey( '', RateMapping::load()->to_array() );
	}

	public function test_nothing_posted_leaves_mapping_alone(): void {
		update_option( RateMapping::OPTION, RateMapping::defaults() );
		$this->editor->save();
		$this->assertSame( RateMapping::defaults(), RateMapping::load()->to_array() );
	}
}
