<?php
/**
 * SEO and GEO (generative engine optimisation) audit tools. Read-only.
 *
 * audit-seo: site-wide switches plus per-post checks (title, meta description, headings, image alt text, links,
 * noindex), reading the fields of Yoast SEO, Rank Math, SEOPress and All in One SEO when one is active.
 * audit-geo: how ready the site is for AI search and answer engines: llms.txt, robots.txt rules for AI crawlers,
 * structured data (JSON-LD), and per-post signals (author, updated date, question headings, direct-answer opening).
 *
 * The tools report problems; fixing them is a separate step the AI can do with the content tools.
 *
 * Optional add-on (v0.15.0). To drop it: remove the require in wp-mcp-tools.php and the entry in class-plugin.php.
 *
 * @package WPMCPTools
 */

defined( 'ABSPATH' ) || exit;

final class WPMCP_Seo {

	/** AI crawlers worth knowing about (robots.txt user-agent names). */
	const AI_BOTS = array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Meta-ExternalAgent' );

	public static function register(): void {
		$h = 'WPMCP_Helpers';
		WPMCP_Registry::add_group(
			'seo',
			array(
				'label'       => 'SEO and GEO audit',
				'description' => 'Read-only SEO and AI-search (GEO) checks for the site and its posts',
			)
		);
		WPMCP_Registry::add(
			'seo',
			'audit-seo',
			array(
				'label'       => 'Audit SEO',
				'description' => 'SEO audit. Site-wide: search-engine visibility, permalinks, HTTPS, sitemap, active SEO plugin. Per post: title and meta description length, headings, images without alt text, internal links, noindex, featured image. Reads Yoast, Rank Math, SEOPress and AIOSEO fields. Pass post_id for one post, or post_type/limit/offset to scan a batch (default: published pages and posts, 20 at a time). Report only; it changes nothing.',
				'properties'  => array(
					'post_id'   => $h::s_int( 'Audit one post or page.' ),
					'post_type' => $h::s_str( 'Post type for a batch (default: post and page).' ),
					'limit'     => $h::s_int( 'Posts per batch (default 20, max 50).' ),
					'offset'    => $h::s_int( 'Skip this many posts (paging).' ),
				),
				'capability'  => 'edit_others_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'audit_seo' ),
			)
		);
		WPMCP_Registry::add(
			'seo',
			'audit-geo',
			array(
				'label'       => 'Audit GEO',
				'description' => 'AI-search readiness: llms.txt, robots.txt rules for AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended...), JSON-LD structured data on the home page, and per-post signals (author, last-updated date, question headings, short direct answer at the top). Pass post_id for one post, or limit for a sample (default 10). Report only.',
				'properties'  => array(
					'post_id' => $h::s_int( 'Check one post or page.' ),
					'limit'   => $h::s_int( 'Posts in the sample (default 10, max 30).' ),
				),
				'capability'  => 'edit_others_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'audit_geo' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function issue( string $level, string $code, string $message ): array {
		return array( 'level' => $level, 'code' => $code, 'message' => $message );
	}

	/** Which SEO plugin is active (first match). */
	public static function seo_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rank-math';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return '';
	}

	/**
	 * Custom title, meta description and noindex flag for a post, from whichever SEO plugin stored them.
	 *
	 * @return array{title:string,description:string,noindex:bool,source:string}
	 */
	public static function seo_fields( int $post_id ): array {
		$m   = static function ( string $key ) use ( $post_id ) {
			$v = get_post_meta( $post_id, $key, true );
			return is_scalar( $v ) ? trim( (string) $v ) : '';
		};
		$out = array( 'title' => '', 'description' => '', 'noindex' => false, 'source' => '' );
		$pairs = array(
			'yoast'     => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
			'rank-math' => array( 'rank_math_title', 'rank_math_description' ),
			'seopress'  => array( '_seopress_titles_title', '_seopress_titles_desc' ),
			'aioseo'    => array( '_aioseo_title', '_aioseo_description' ),
		);
		foreach ( $pairs as $name => $keys ) {
			$title = $m( $keys[0] );
			$desc  = $m( $keys[1] );
			if ( '' !== $title || '' !== $desc ) {
				$out['title']       = $title;
				$out['description'] = $desc;
				$out['source']      = $name;
				break;
			}
		}
		$robots_rm = get_post_meta( $post_id, 'rank_math_robots', true );
		$out['noindex'] = '1' === $m( '_yoast_wpseo_meta-robots-noindex' )
			|| 'yes' === $m( '_seopress_robots_index' )
			|| ( is_array( $robots_rm ) && in_array( 'noindex', $robots_rm, true ) );
		return $out;
	}

	/** Strip shortcodes and tags to get readable text. */
	private static function text( string $html ): string {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) );
	}

	private static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
	}

	/** @return array<string,mixed> */
	private static function site_checks(): array {
		$issues = array();
		if ( ! get_option( 'blog_public' ) ) {
			$issues[] = self::issue( 'error', 'search_hidden', 'Settings → Reading has "Discourage search engines" on. Search engines are told not to index this site.' );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$issues[] = self::issue( 'warning', 'plain_permalinks', 'Permalinks are "Plain" (?p=123). Pretty permalinks are better for SEO.' );
		}
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			$issues[] = self::issue( 'warning', 'not_https', 'The site address is not HTTPS.' );
		}
		if ( '' === trim( (string) get_bloginfo( 'description' ) ) ) {
			$issues[] = self::issue( 'info', 'no_tagline', 'The site tagline is empty.' );
		}
		$plugin = self::seo_plugin();
		if ( '' === $plugin ) {
			$issues[] = self::issue( 'info', 'no_seo_plugin', 'No supported SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO) detected; per-post titles and descriptions come from the theme defaults.' );
		}
		$sitemap = function_exists( 'wp_sitemaps_get_server' ) && get_option( 'blog_public' );
		return array(
			'seo_plugin'    => '' === $plugin ? 'none' : $plugin,
			'core_sitemap'  => (bool) $sitemap,
			'sitemap_url'   => $sitemap ? home_url( '/wp-sitemap.xml' ) : '',
			'issues'        => $issues,
		);
	}

	/* ------------------------------------------------------------------ */
	/* audit-seo                                                           */
	/* ------------------------------------------------------------------ */

	/** @return array<string,mixed> One post's SEO report. */
	public static function seo_post( WP_Post $post ): array {
		$f      = self::seo_fields( $post->ID );
		$issues = array();
		$title  = '' !== $f['title'] ? $f['title'] : $post->post_title;
		$tlen   = self::len( wp_strip_all_tags( $title ) );
		if ( '' === trim( $title ) ) {
			$issues[] = self::issue( 'error', 'no_title', 'No title.' );
		} elseif ( $tlen > 65 ) {
			$issues[] = self::issue( 'warning', 'title_long', "Title is $tlen characters; search results cut it off after about 60." );
		} elseif ( $tlen < 20 ) {
			$issues[] = self::issue( 'info', 'title_short', "Title is only $tlen characters." );
		}
		$desc = '' !== $f['description'] ? $f['description'] : self::text( (string) $post->post_excerpt );
		$dlen = self::len( $desc );
		if ( '' === $desc ) {
			$issues[] = self::issue( 'warning', 'no_meta_description', 'No meta description (or excerpt). Search engines will pick a snippet by themselves.' );
		} elseif ( $dlen > 165 ) {
			$issues[] = self::issue( 'info', 'meta_description_long', "Meta description is $dlen characters; about 155 is shown." );
		} elseif ( $dlen < 70 ) {
			$issues[] = self::issue( 'info', 'meta_description_short', "Meta description is only $dlen characters." );
		}
		if ( $f['noindex'] && 'publish' === $post->post_status ) {
			$issues[] = self::issue( 'warning', 'noindex', 'This published post is set to noindex.' );
		}
		$content = (string) $post->post_content;
		$h1      = preg_match_all( '/<h1[\s>]/i', $content ) + preg_match_all( '/<!-- wp:heading \{"level":1/', $content );
		if ( $h1 > 0 ) {
			$issues[] = self::issue( 'warning', 'h1_in_content', 'The content has its own H1; the post title is normally the only H1.' );
		}
		$words = str_word_count( self::text( $content ) );
		if ( $words < 300 && in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			$issues[] = self::issue( 'info', 'thin_content', "About $words words; short pages rank less often." );
		}
		$no_alt = 0;
		$imgs   = 0;
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $m ) ) {
			foreach ( $m[0] as $tag ) {
				++$imgs;
				if ( ! preg_match( '/\balt\s*=\s*(["\'])(.+?)\1/i', $tag ) ) {
					++$no_alt;
				}
			}
		}
		if ( $no_alt ) {
			$issues[] = self::issue( 'warning', 'images_without_alt', "$no_alt of $imgs images have no alt text." );
		}
		$home    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$links   = 0;
		if ( preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])(.+?)\1/i', $content, $lm ) ) {
			foreach ( $lm[2] as $href ) {
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || $host === $home || 0 === strpos( $href, '/' ) ) {
					++$links;
				}
			}
		}
		if ( 0 === $links && $words >= 300 ) {
			$issues[] = self::issue( 'info', 'no_internal_links', 'No links to other pages of this site.' );
		}
		if ( 'post' === $post->post_type && ! has_post_thumbnail( $post ) ) {
			$issues[] = self::issue( 'info', 'no_featured_image', 'No featured image (used for social sharing previews).' );
		}
		$score = 100;
		foreach ( $issues as $i ) {
			$score -= 'error' === $i['level'] ? 30 : ( 'warning' === $i['level'] ? 12 : 4 );
		}
		return array(
			'id'     => $post->ID,
			'type'   => $post->post_type,
			'title'  => $post->post_title,
			'url'    => get_permalink( $post ),
			'seo_source' => $f['source'] ?: 'none',
			'score'  => max( 0, $score ),
			'issues' => $issues,
		);
	}

	public static function audit_seo( array $input ) {
		$out = array();
		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( absint( $input['post_id'] ) );
			if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
				return WPMCP_Helpers::error( 'not_found', 'Post not found.', 404 );
			}
			return array( 'post' => self::seo_post( $post ) );
		}
		$type  = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$limit = min( 50, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$q     = new WP_Query(
			array(
				'post_type'      => '' !== $type ? $type : array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'offset'         => max( 0, (int) ( $input['offset'] ?? 0 ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);
		$posts    = array();
		$by_code  = array();
		foreach ( $q->posts as $p ) {
			if ( ! current_user_can( 'read_post', $p->ID ) ) {
				continue;
			}
			$r       = self::seo_post( $p );
			$posts[] = $r;
			foreach ( $r['issues'] as $i ) {
				$by_code[ $i['code'] ] = ( $by_code[ $i['code'] ] ?? 0 ) + 1;
			}
		}
		arsort( $by_code );
		usort(
			$posts,
			static function ( $a, $b ) {
				return $a['score'] <=> $b['score'];
			}
		);
		$out['site']          = self::site_checks();
		$out['checked']       = count( $posts );
		$out['total_matching'] = (int) $q->found_posts;
		$out['issue_counts']  = $by_code;
		$out['posts']         = $posts; // Worst first.
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* audit-geo                                                           */
	/* ------------------------------------------------------------------ */

	/** Which AI crawlers a robots.txt body blocks entirely. @return string[] */
	public static function blocked_bots( string $robots ): array {
		$blocked = array();
		$agents  = array();
		$lines   = preg_split( '/\r?\n/', $robots );
		$groups  = array(); // Each: agents[], disallow_all bool.
		$cur     = array( 'agents' => array(), 'all' => false, 'open' => false );
		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$k = strtolower( $k );
			if ( 'user-agent' === $k ) {
				if ( $cur['open'] ) {
					$groups[] = $cur;
					$cur      = array( 'agents' => array(), 'all' => false, 'open' => false );
				}
				$cur['agents'][] = strtolower( $v );
			} elseif ( in_array( $k, array( 'disallow', 'allow' ), true ) ) {
				$cur['open'] = true;
				if ( 'disallow' === $k && '/' === $v ) {
					$cur['all'] = true;
				}
				if ( 'allow' === $k && '/' === $v ) {
					$cur['all'] = false;
				}
			}
		}
		$groups[] = $cur;
		foreach ( self::AI_BOTS as $bot ) {
			foreach ( $groups as $g ) {
				if ( $g['all'] && in_array( strtolower( $bot ), $g['agents'], true ) ) {
					$blocked[] = $bot;
					break;
				}
			}
		}
		return $blocked;
	}

	/** Does a page's HTML carry JSON-LD, and which @type values? @return string[] */
	public static function jsonld_types( string $html ): array {
		$types = array();
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				$stack = is_array( $data ) ? array( $data ) : array();
				while ( $stack ) {
					$n = array_pop( $stack );
					if ( isset( $n['@type'] ) ) {
						foreach ( (array) $n['@type'] as $t ) {
							$types[] = (string) $t;
						}
					}
					foreach ( $n as $v ) {
						if ( is_array( $v ) ) {
							$stack[] = $v;
						}
					}
				}
			}
		}
		return array_values( array_unique( $types ) );
	}

	/** @return array<string,mixed> */
	public static function geo_post( WP_Post $post ): array {
		$issues  = array();
		$content = (string) $post->post_content;
		$text    = self::text( $content );
		if ( ! get_the_author_meta( 'display_name', (int) $post->post_author ) ) {
			$issues[] = self::issue( 'info', 'no_author', 'No visible author.' );
		}
		if ( strtotime( $post->post_modified_gmt ) < time() - 2 * YEAR_IN_SECONDS ) {
			$issues[] = self::issue( 'info', 'stale', 'Not updated for over two years; AI answers favour fresh pages.' );
		}
		$has_q = (bool) preg_match( '/<h[2-4][^>]*>[^<]*\?\s*<\/h[2-4]>/i', $content );
		if ( ! $has_q && str_word_count( $text ) > 400 ) {
			$issues[] = self::issue( 'info', 'no_question_headings', 'No headings phrased as questions (FAQ style), which answer engines quote well.' );
		}
		$first = '';
		if ( preg_match( '#<p[^>]*>(.*?)</p>#is', $content, $pm ) ) {
			$first = self::text( $pm[1] );
		}
		$fw = str_word_count( $first );
		if ( $fw > 70 || ( $first && $fw < 10 ) ) {
			$issues[] = self::issue( 'info', 'opening_not_direct', "The first paragraph is $fw words; a 20 to 60 word direct answer at the top is easiest to quote." );
		}
		if ( ! preg_match( '/<(table|ul|ol)[\s>]/i', $content ) && str_word_count( $text ) > 600 ) {
			$issues[] = self::issue( 'info', 'no_structure', 'Long text without lists or tables; structured content is easier for AI to extract.' );
		}
		return array( 'id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink( $post ), 'issues' => $issues );
	}

	public static function audit_geo( array $input ) {
		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( absint( $input['post_id'] ) );
			if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
				return WPMCP_Helpers::error( 'not_found', 'Post not found.', 404 );
			}
			return array( 'post' => self::geo_post( $post ) );
		}
		$site = array();
		$probe = array( 'WPMCP_Diagnose', 'probe' );

		// llms.txt.
		$llms_file = file_exists( ABSPATH . 'llms.txt' );
		$res       = call_user_func( $probe, home_url( '/llms.txt' ) );
		$llms_http = 200 === $res['code'] && '' !== trim( $res['body'] ) && false === stripos( $res['headers']['content-type'] ?? '', 'html' );
		$site['llms_txt'] = array(
			'present' => $llms_file || $llms_http,
			'note'    => ( $llms_file || $llms_http ) ? 'llms.txt is served.' : 'No llms.txt. It is an optional, plain-text map of your best pages for AI tools; cheap to add.',
		);
		// robots.txt.
		$res    = call_user_func( $probe, home_url( '/robots.txt' ) );
		$robots = 200 === $res['code'] ? $res['body'] : '';
		$blocked = self::blocked_bots( $robots );
		$site['robots_txt'] = array(
			'reachable'    => 200 === $res['code'],
			'blocked_ai_bots' => $blocked,
			'note'         => $blocked ? 'These AI crawlers are blocked entirely: ' . implode( ', ', $blocked ) . '. That keeps the site out of those AI answers and training sets; keep it only if that is your intention.' : 'No AI crawler is blocked entirely.',
		);
		if ( ! get_option( 'blog_public' ) ) {
			$site['robots_txt']['note'] .= ' WordPress "Discourage search engines" is on, which also hurts AI visibility.';
		}
		// Structured data on the home page.
		$res   = call_user_func( $probe, home_url( '/' ) );
		$types = 200 === $res['code'] ? self::jsonld_types( $res['body'] ) : array();
		$site['structured_data'] = array(
			'home_page_types' => $types,
			'note'            => $types ? 'JSON-LD found on the home page.' : ( 200 === $res['code'] ? 'No JSON-LD on the home page. Add Organization / WebSite schema (an SEO plugin can do it).' : 'The home page could not be fetched from the server itself, so structured data was not checked.' ),
		);
		$site['seo_plugin'] = self::seo_plugin() ?: 'none';

		$limit = min( 30, max( 1, (int) ( $input['limit'] ?? 10 ) ) );
		$q     = new WP_Query( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true ) );
		$posts = array();
		$count = array();
		foreach ( $q->posts as $p ) {
			if ( ! current_user_can( 'read_post', $p->ID ) ) {
				continue;
			}
			$r       = self::geo_post( $p );
			$posts[] = $r;
			foreach ( $r['issues'] as $i ) {
				$count[ $i['code'] ] = ( $count[ $i['code'] ] ?? 0 ) + 1;
			}
		}
		arsort( $count );
		return array( 'site' => $site, 'sampled' => count( $posts ), 'issue_counts' => $count, 'posts' => $posts );
	}
}
