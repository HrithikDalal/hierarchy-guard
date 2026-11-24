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

	public function test_safe_redirect_manager_uses_the_same_columns_with_full_url_targets(): void {
		$home = static fn(): string => 'http://example.org';
		add_filter( 'pre_option_home', $home );
		$file = Csv::build( 'srm', $this->pairs() );
		remove_filter( 'pre_option_home', $home );

		$this->assertSame( "source,target,regex,code\r\n/about/team/,http://example.org/company/team/,0,301\r\n/about/team/leadership/,http://example.org/company/team/leadership/,0,301\r\n", $file );
	}

	public function test_on_a_site_in_a_subdirectory_each_layout_s_cells_lead_to_the_right_page(): void {
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
			[
				'old' => '/cdn/',
				'new' => '//cdn.example/new/',
			],
		];

		$files = [];
		foreach ( array_keys( Csv::LAYOUTS ) as $layout ) {
			$files[ $layout ] = Csv::build( $layout, $pairs );
		}
		remove_filter( 'pre_option_home', $home );

		$this->assertSame(
			[
				'redirection' => "source,target,regex,code\r\n/blog/about/team/,/blog/company/team/,0,301\r\n/blog/old/,https://other.example/new/,0,301\r\n/blog/cdn/,http://cdn.example/new/,0,301\r\n",
				'srm'         => "source,target,regex,code\r\n/about/team/,http://example.org/blog/company/team/,0,301\r\n/old/,https://other.example/new/,0,301\r\n/cdn/,http://cdn.example/new/,0,301\r\n",
				'yoast'       => "Origin,Target,Type,Format\n/about/team/,/company/team/,301,plain\n/old/,https://other.example/new/,301,plain\n/cdn/,http://cdn.example/new/,301,plain\n",
				'rank-math'   => "source,destination,type\r\n/about/team/,/company/team/,301\r\n/old/,https://other.example/new/,301\r\n/cdn/,http://cdn.example/new/,301\r\n",
				'simple-301'  => "request,destination\r\n/about/team/,/company/team/\r\n/old/,https://other.example/new/\r\n/cdn/,http://cdn.example/new/\r\n",
				'generic'     => "source,target,code\r\n/about/team/,/company/team/,301\r\n/old/,https://other.example/new/,301\r\n/cdn/,http://cdn.example/new/,301\r\n",
			],
			$files,
			'Redirection matches the whole request path and sends a target as it is; Safe Redirect Manager strips the home path from the request only and sends a path target from the domain root; the others strip the home path from both, and Yoast SEO Premium and Simple 301 Redirects read a protocol-relative target as a path.'
		);
	}

	public function test_full_url_targets_take_the_home_url_s_scheme_whatever_the_request_s(): void {
		$home  = static fn(): string => 'http://example.org';
		$https = $_SERVER['HTTPS'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved as it was, to put back.
		add_filter( 'pre_option_home', $home );
		$_SERVER['HTTPS'] = 'on';
		$pairs            = [
			[
				'old' => '/about/team/',
				'new' => '/company/team/',
			],
			[
				'old' => '/cdn/',
				'new' => '//cdn.example/new/',
			],
		];

		$this->assertTrue( is_ssl(), 'Precondition: a request over HTTPS, as an admin forced to SSL.' );
		$srm = Csv::build( 'srm', $pairs );
		if ( null === $https ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $https;
		}
		remove_filter( 'pre_option_home', $home );

		$this->assertSame( "source,target,regex,code\r\n/about/team/,http://example.org/company/team/,0,301\r\n/cdn/,http://cdn.example/new/,0,301\r\n", $srm );
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
		$home = static fn(): string => 'http://example.org';
		add_filter( 'pre_option_home', $home );
		$srm  = Csv::build( 'srm', $pairs );
		remove_filter( 'pre_option_home', $home );
		$this->assertSame( "source,target,regex,code\r\n/about/team/,http://example.org/company/team/,0,301\r\n/about/team/leadership/,http://example.org/company/team/leadership/,0,302\r\n", $srm );
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
