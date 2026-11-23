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

	public function test_on_a_site_in_a_subdirectory_only_redirection_s_paths_carry_the_home_path(): void {
		$home  = static fn(): string => 'http://example.org/blog';
		add_filter( 'pre_option_home', $home );
		$pairs = [
			[
				'old' => '/about/team/',
				'new' => '/company/team/',
			],
			[
				'old' => '/old/',
				'new' => 'https://other.example/new/',
			],
		];

		$redirection = Csv::build( 'redirection', $pairs );
		$srm         = Csv::build( 'srm', $pairs );
		$yoast       = Csv::build( 'yoast', $pairs );
		remove_filter( 'pre_option_home', $home );

		$this->assertSame( "source,target,regex,code\r\n/blog/about/team/,/blog/company/team/,0,301\r\n/blog/old/,https://other.example/new/,0,301\r\n", $redirection );
		$this->assertSame( "source,target,regex,code\r\n/about/team/,/company/team/,0,301\r\n/old/,https://other.example/new/,0,301\r\n", $srm, 'Safe Redirect Manager strips the home path itself.' );
		$this->assertStringContainsString( "\n/about/team/,/company/team/,301,plain\n", $yoast );
	}

	public function test_the_generic_layout_has_source_target_and_code(): void {
		$this->assertSame( "source,target,code\r\n/about/team/,/company/team/,301\r\n", Csv::build( 'generic', [ $this->pairs()[0] ] ) );
	}

	public function test_a_redirect_a_restore_could_undo_is_a_302_in_every_layout(): void {
		$pairs                 = $this->pairs();
		$pairs[0]['temporary'] = false;
		$pairs[1]['temporary'] = true;

		$this->assertSame(
			"source,target,regex,code\r\n/about/team/,/company/team/,0,301\r\n/about/team/leadership/,/company/team/leadership/,0,302\r\n",
			Csv::build( 'redirection', $pairs )
		);
		$this->assertSame( Csv::build( 'redirection', $pairs ), Csv::build( 'srm', $pairs ) );
		$this->assertSame(
			"source,target,code\r\n/about/team/,/company/team/,301\r\n/about/team/leadership/,/company/team/leadership/,302\r\n",
			Csv::build( 'generic', $pairs )
		);
	}

	public function test_yoast_reads_origin_target_type_and_a_plain_format_on_unix_lines(): void {
		$pairs                 = $this->pairs();
		$pairs[1]['temporary'] = true;

		$this->assertSame(
			"Origin,Target,Type,Format\n/about/team/,/company/team/,301,plain\n/about/team/leadership/,/company/team/leadership/,302,plain\n",
			Csv::build( 'yoast', $pairs )
		);
	}

	public function test_rank_math_reads_source_destination_and_type(): void {
		$this->assertSame( "source,destination,type\r\n/about/team/,/company/team/,301\r\n", Csv::build( 'rank-math', [ $this->pairs()[0] ] ) );
	}

	public function test_simple_301_reads_request_and_destination_only(): void {
		$pairs                 = $this->pairs();
		$pairs[1]['temporary'] = true;

		$this->assertSame(
			"request,destination\r\n/about/team/,/company/team/\r\n/about/team/leadership/,/company/team/leadership/\r\n",
			Csv::build( 'simple-301', $pairs )
		);
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
