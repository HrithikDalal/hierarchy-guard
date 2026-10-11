<?php
/**
 * CSV files in redirect plugins' import layouts.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Export\Csv;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Export\Csv
 */
final class CsvTest extends WP_UnitTestCase {

	/**
	 * Two pairs.
	 *
	 * @return array<int,array{old:string,new:string}>
	 */
	private function pairs(): array {
		return [
			[
				'old' => '/about/team/',
				'new' => '/company/team/',
			],
			[
				'old' => '/about/team/leadership/',
				'new' => '/company/team/leadership/',
			],
		];
	}

	public function test_redirection_reads_source_target_regex_and_code(): void {
		$this->assertSame(
			"source,target,regex,code\r\n/about/team/,/company/team/,0,301\r\n/about/team/leadership/,/company/team/leadership/,0,301\r\n",
			Csv::build( 'redirection', $this->pairs() )
		);
	}

	public function test_safe_redirect_manager_uses_the_same_columns(): void {
		$this->assertSame( Csv::build( 'redirection', $this->pairs() ), Csv::build( 'srm', $this->pairs() ) );
	}

	public function test_the_generic_layout_has_source_target_and_code(): void {
		$this->assertSame( "source,target,code\r\n/about/team/,/company/team/,301\r\n", Csv::build( 'generic', [ $this->pairs()[0] ] ) );
	}

	public function test_an_unknown_layout_falls_back_to_generic(): void {
		$this->assertSame( 'generic', Csv::layout( 'nope' ) );
		$this->assertSame( Csv::build( 'generic', $this->pairs() ), Csv::build( 'nope', $this->pairs() ) );
	}

	public function test_cells_are_quoted_only_when_they_need_it(): void {
		$this->assertSame( '/a/,"/b,c/","say ""hi""",301', Csv::line( [ '/a/', '/b,c/', 'say "hi"', '301' ] ) );
	}

	public function test_a_cell_a_spreadsheet_would_read_as_a_formula_is_defused(): void {
		$this->assertSame( "'=1+1,'@x,'-2", Csv::line( [ '=1+1', '@x', '-2' ] ) );
	}

	public function test_the_file_name_names_the_layout_and_the_day(): void {
		$this->assertSame( 'hierarchy-guard-redirects-redirection-' . gmdate( 'Y-m-d' ) . '.csv', Csv::filename( 'redirection' ) );
	}
}
