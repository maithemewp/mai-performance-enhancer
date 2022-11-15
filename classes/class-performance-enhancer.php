<?php

/**
 * Manipulates the final output of the dom for performance tweaks.
 *
 * @version 0.1.0
 *
 * @return void
 */
class Mai_Performance_Enhancer {
	protected $scripts;
	protected $settings;
	protected $data;

	/**
	 * Constructs the class.
	 *
	 * @return void
	 */
	function __construct() {
		// Sets props.
		$this->scripts  = '';
		$this->settings = apply_filters( 'mai_performance_enhancer_settings',
			[
				'cache_headers'    => true,
				'ttl_homepage'     => '60', // in seconds.
				'ttl_inner'        => '180', // in seconds.
				'preload_header'   => true,
				'tidy'             => true,
				'lazy_images'      => true,
				'lazy_iframes'     => true,
				'move_scripts'     => true,
			]
		);

		// Get data. TODO: Come from settings.
		$this->data = apply_filters( 'mai_performance_enhancer_data',
			[
				'preconnect_links' => '',
				'prefetch_links'   => '',
				'scripts'          => '',
			]
		);

		// Sanitize settings.
		$this->settings['cache_headers']    = rest_sanitize_boolean( $this->settings['cache_headers'] );
		$this->settings['ttl_homepage']     = absint( $this->settings['ttl_homepage'] );
		$this->settings['ttl_inner']        = absint( $this->settings['ttl_inner'] );
		$this->settings['preload_header']   = rest_sanitize_boolean( $this->settings['preload_header'] );
		$this->settings['tidy']             = rest_sanitize_boolean( $this->settings['tidy'] );
		$this->settings['lazy_images']      = rest_sanitize_boolean( $this->settings['lazy_images'] );
		$this->settings['lazy_iframes']     = rest_sanitize_boolean( $this->settings['lazy_iframes'] );
		$this->settings['move_scripts']     = rest_sanitize_boolean( $this->settings['move_scripts'] );

		// Sanitize data.
		$this->data['preconnect_links'] = wp_kses_post( trim( $this->data['preconnect_links'] ) );
		$this->data['prefetch_links']   = wp_kses_post( trim( $this->data['prefetch_links'] ) );
		$this->data['scripts']          = wp_kses_post( trim( $this->data['scripts'] ) );

		$this->hooks();
	}

	/**
	 * Runs hooks.
	 *
	 * @return void
	 */
	function hooks() {
		add_action( 'after_setup_theme', [ $this, 'start' ], 999998 );
		add_action( 'shutdown',          [ $this, 'end' ], 999999 );
	}

	/**
	 * Starts output buffering.
	 *
	 * @return void
	 */
	function start() {
		ob_start( [ $this, 'callback' ] );
	}

	/**
	 * Appends div entry point for JS code.
	 * Ends output buffering.
	 *
	 * @return void
	 */
	function end() {
		/**
		 * I hope this is right.
		 *
		 * @link https://stackoverflow.com/questions/7355356/whats-the-difference-between-ob-flush-and-ob-end-flush
		 */
		if ( ob_get_length() ) {
			// ob_end_flush();
			ob_flush();
		}
	}

	/**
	 * Buffer callback.
	 *
	 * @param string $buffer The full dom markup.
	 *
	 * @return string
	 */
	function callback( $buffer ) {
		if ( ! $buffer ) {
			return $buffer;
		}

		// Bail if XML.
		if ( false !== strpos( trim( $buffer ), '<?xml' ) ) {
			return $buffer;
		}

		// Sets caching headers.
		$this->do_cache_headers();

		// Adds existing preloads to header.
		$this->do_preload_header( $buffer );

		// Tidy.
		$buffer = $this->do_tidy( $buffer );

		// Gravatar.
		$buffer = $this->do_gravatar( $buffer );

		// Common replacements.
		$buffer = $this->do_common( $buffer );

		// Adds preconnect, and dns-prefetch links.
		$buffer = $this->do_pp( $buffer );

		// Sets up new scripts.
		$this->setup_scripts();

		// Gets DOMDocument.
		$dom = $this->get_dom( $buffer );

		// Bail if no dom.
		if ( ! $dom ) {
			return $buffer;
		}

		// Check for required elements.
		$head = $dom->getElementsByTagName( 'head' );
		$body = $dom->getElementsByTagName( 'body' );
		$head = $head && $head->item(0) ? $head->item(0) : false;
		$body = $body && $body->item(0) ? $body->item(0) : false;

		// Bail if not head and body.
		if ( ! ( $head && $body ) ) {
			return $buffer;
		}

		// Lazy load images.
		if ( $this->settings['lazy_images'] ) {
			$main = $body->getElementsByTagName( 'main' );
			$main = $main && $main->item(0) ? $main->item(0) : false;

			if ( $main ) {
				$images = $main->getElementsByTagName( 'img' );

				if ( $images->length ) {
					foreach ( $images as $node ) {
						static $first = true;

						// Skip the first, likely above the fold.
						if ( $first ) {
							continue;
						}

						$first = false;

						// Skip if loading attribute already exists.
						if ( $node->getAttribute( 'loading' ) ) {
							continue;
						}

						// Skip if no-lazy class.
						if ( in_array( 'no-lazy', explode( ' ', $node->getAttribute( 'class' ) ) ) ) {
							continue;
						}

						$node->setAttribute( 'loading', 'lazy' );
					}
				}
			}
		}

		// Lazy load iframes.
		if ( $this->settings['lazy_iframes'] ) {
			$iframes = $head->getElementsByTagName( 'iframes' );

			if ( $iframes->length ) {
				foreach ( $iframes as $node ) {
					// Skip if loading attribute already exists.
					if ( $node->getAttribute( 'loading' ) ) {
						continue;
					}

					// Skip if no-lazy class.
					if ( in_array( 'no-lazy', explode( ' ', $node->getAttribute( 'class' ) ) ) ) {
						continue;
					}

					$node->setAttribute( 'loading', 'lazy' );
				}
			}
		}

		// Move scripts.
		if ( $this->settings['move_scripts'] ) {
			$head_scripts = $head->getElementsByTagName( 'script' );
			$body_scripts = $body->getElementsByTagName( 'script' );

			if ( $head_scripts->length ) {
				$this->handle_scripts( $head_scripts, $dom, $body );
			}

			if ( $body_scripts->length ) {
				$this->handle_scripts( $body_scripts, $dom, $body );
			}
		}

		// Gets main site-container.
		$container = $dom->getElementById( 'top' );

		if ( $container && $this->scripts ) {
			$fragment = $container->ownerDocument->createDocumentFragment();
			$fragment->appendXML( $this->scripts );
			/**
			 * Add script(s) after this element. There is no insertAfter() in PHP ¯\_(ツ)_/¯.
			 *
			 * @link https://gist.github.com/deathlyfrantic/cd8d7ef8ba91544cdf06
			 */
			$container->parentNode->insertBefore( $fragment, $container->nextSibling );
		}

		// if ( $container && $this->script_objects ) {
		// 	// Reverse, because insertBefore will put them in opposite order.
		// 	$this->script_objects = array_reverse( $this->script_objects );

		// 	foreach ( $this->script_objects as $node ) {
		// 		/**
		// 		 * Add script(s) after this element. There is no insertAfter() in PHP ¯\_(ツ)_/¯.
		// 		 *
		// 		 * @link https://gist.github.com/deathlyfrantic/cd8d7ef8ba91544cdf06
		// 		 */
		// 		$container->parentNode->insertBefore( $node, $container->nextSibling );
		// 	}
		// }

		// Save HTML.
		$buffer = $dom->saveHTML();

		return $buffer;
	}

	/**
	 * Sets cache headers.
	 *
	 * @return void
	 */
	function do_cache_headers() {
		if ( ! $this->settings['cache_headers'] ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		header_remove( 'Cache-Control' );

		$max_age    = is_front_page() ? $this->settings['ttl_homepage'] : $this->settings['ttl_inner'];
		$revalidate = $max_age * 2;

		header( "Cache-Control: public, max-age={$max_age}, must-revalidate, stale-while-revalidate={$revalidate}, stale-if-error=14400" );
	}

	/**
	 * Preload links in HTTP headers.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_preload_header( $buffer ) {
		if ( ! $this->settings['preload_header'] ) {
			return;
		}

		preg_match_all( "#<link rel=\"preload\" href=\"(.*?)\" as=\"(.*?)\" (.*?)>#s", $buffer, $links, PREG_PATTERN_ORDER );

		if ( isset( $links[1] ) && is_array( $links[1] ) && count( $links[1] ) ) {
			foreach ( $links[1] as $key => $link ) {
				if ( ! isset( $links[2][$key] ) ) {
					continue;
				}

				header( "Link: <{$link}>; rel=preload; as={$links[2][$key]}; crossorigin", false );
			}
		}
	}

	/**
	 * Gets DOMDocument.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return DOMDocument
	 */
	function get_dom( $buffer ) {
		// Create the new document.
		$dom = new DOMDocument();

		// Modify state.
		$libxml_previous_state = libxml_use_internal_errors( true );

		// Load the content in the document HTML.
		$dom->loadHTML( mb_convert_encoding( $buffer, 'HTML-ENTITIES', 'UTF-8' ) );

		// Handle errors.
		libxml_clear_errors();

		// Restore.
		libxml_use_internal_errors( $libxml_previous_state );

		return $dom;
	}

	/**
	 * Handles scripts before closing body tag.
	 *
	 * @param array       $scripts Array of script element nodes.
	 * @param DOMDocument $dom     The full dom object.
	 * @param DOMNOde     $body    The body dom node.
	 *
	 * @return void
	 */
	function handle_scripts( $scripts, $dom, $body ) {
		static $sources = [];

		foreach ( $scripts as $node ) {
			$src = $node->getAttribute( 'src' );

			if ( $src ) {
				// Skip if we already have this script.
				// This happens with Twitter embeds and similar.
				if ( in_array( $src, $sources ) ) {
					continue;
				}

				// Skip if a Mai script.
				if ( false !== strpos( $src, 'mai-engine' ) ) {
					continue;
				}

				$sources[] = $src;
			}

			// Add scripts to move later.
			$this->scripts .= trim( $dom->saveHTML( $node ) ) . PHP_EOL;

			// Remove current script.
			$node->parentNode->removeChild( $node );
		}
	}

	/**
	 * Gets tidy HTML.
	 *
	 * @link Tidy (v5.6.0 - https://api.html-tidy.org/tidy/quickref_5.6.0.html)
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_tidy( $buffer ) {
		if ( ! $this->settings['tidy'] ) {
			return $buffer;
		}

		if ( ! class_exists( 'tidy' ) || isset( $_GET['notidy'] ) ) {
			return $buffer;
		}

		// Tidy Configuration Options
		$config = [
			'alt-text'                    => 'Image',
			'break-before-br'             => 1,
			'clean'                       => 1,
			'doctype'                     => 'html5',
			'drop-empty-elements'         => 0,
			'drop-proprietary-attributes' => 0,
			'hide-comments'               => 0,
			'indent-cdata'                => 0,
			'indent-spaces'               => 4,
			'indent'                      => 1,
			'merge-divs'                  => 0,
			'merge-spans'                 => 0,
			'new-blocklevel-tags'         => 'fb:like, fb:send, fb:comments, fb:activity, fb:recommendations, fb:like-box, fb:login-button, fb:facepile, fb:live-stream, fb:fan, fb:pile, article, aside, bdi, command, details, summary, figure, figcaption, footer, header, hgroup, mark, meter, nav, picture, progress, ruby, rt, rp, section, span, time, wbr, audio, video, source, embed, track, canvas, datalist, keygen, output, amp-ad, amp-analytics, ampstyle, amp-img, amp-instagram, amp-twitter, amp-youtube, amp-iframe,ad-img, glomex-player',
			'new-empty-tags'              => 'a,b,li,strong,span,i,div',
			'output-xhtml'                => 1,
			'wrap'                        => 0,
		];

		$tidy = new tidy();
		$tidy->parseString( $buffer, $config, 'utf8' );
		$tidy->cleanRepair();

		$tidy   = $tidy . "\n<!-- HTML Tidy engine enabled -->";
		$buffer = $tidy;

		// CDATA cleanups.
		$find = [
			'/*<![CDATA[*/',
			'/*//]]>*/',
			'//<![CDATA[',
			'//]]>',
			'<![CDATA[',
			']]>',
			'/**/'
		];
		$buffer = str_replace( $find, '', $buffer );

		// HTML5 Mode.
		$buffer = preg_replace(
			[
				"#<!DOCTYPE(.+?)>#s",
				"# xmlns=\"(.+?)\"#s",
				"# (xml|xmlns)\:(.+?)=\"(.+?)\"#s"
			],
			[
			"<!doctype html>",
			"",
			""
			],
			$buffer
		);

		return $buffer;
	}

	/**
	 * Makes sure gravatar is loaded securely.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_gravatar( $buffer ) {
		// For Gravatar
		$buffer = str_ireplace(
			['http://0.gravatar.com', 'http://1.gravatar.com', 'http://2.gravatar.com', 'http://3.gravatar.com', 'http://4.gravatar.com'],
			['https://0.gravatar.com', 'https://1.gravatar.com', 'https://2.gravatar.com', 'https://3.gravatar.com', 'https://4.gravatar.com'],
			$buffer
		);

		return $buffer;
	}

	/**
	 * Handles common replacements
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_common( $buffer ) {
		// Common replacements.
		$find = [
			'<meta http-equiv="content-type" content="text/html; charset=utf-8" />',
			' type=\'text/javascript\'',
			' type="text/javascript"',
			' type=\'text/css\'',
			' type="text/css"',
			' language=\'Javascript\'',
			' language="Javascript"',
			'//<![CDATA[',
			'//]]>',
			'async="true"',
			'async="async"',
			'async=\'true\'',
			'async=\'async\'',
			'http://youtu.be',
			'http://youtube.com',
			'http://www.youtube.com',
			'http://vimeo.com',
			'http://www.vimeo.com',
			'http://dailymotion.com',
			'http://www.dailymotion.com',
			'http://facebook.com',
			'http://www.facebook.com',
			'http://twitter.com',
			'http://www.twitter.com',
		];

		$replace = [
			'<meta charset="utf-8" />',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'async',
			'async',
			'async',
			'async',
			'https://youtu.be',
			'https://www.youtube.com',
			'https://www.youtube.com',
			'https://vimeo.com',
			'https://vimeo.com',
			'https://www.dailymotion.com',
			'https://www.dailymotion.com',
			'https://www.facebook.com',
			'https://www.facebook.com',
			'https://twitter.com',
			'https://twitter.com',
		];

		$buffer = str_ireplace( $find, $replace, $buffer );

		return $buffer;
	}

	/**
	 * Handles preconnect, and dns-prefetch.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_pp( $buffer ) {
		$links = [];

		if ( $this->data['preconnect_links'] ) {
			$preconnect = $this->data['preconnect_links'];
			$preconnect = array_unique( $preconnect );
			$preconnect = array_filter( $preconnect );

			if ( $preconnect ) {
				foreach ( $preconnect as $link ) {
					$links[] = sprintf( '<link rel="preconnect" href="%s" />', esc_url( $link ) );
				}
			}
		}

		if ( $this->data['prefetch_links'] ) {
			$prefetch = $this->data['prefetch_links'];
			$prefetch = array_unique( $prefetch );
			$prefetch = array_filter( $prefetch );

			if ( $prefetch ) {
				foreach ( $prefetch as $link ) {
					$links[] = sprintf( '<link rel="dns-prefetch" href="%s" />', esc_url( $link ) );
				}
			}
		}

		if ( $links ) {
			$links  = implode( PHP_EOL, $links );
			$buffer = str_replace( '<meta charset="UTF-8" />', '<meta charset="UTF-8" />' . PHP_EOL . $links, $buffer );
		}

		return $buffer;
	}

	/**
	 * Adds new scripts to footer.
	 *
	 * @return void
	 */
	function setup_scripts() {
		if ( $this->data['scripts'] ) {
			$this->scripts .= $this->data['scripts'];
		}
	}
}

/**
 * Instantiates the class.
 * Add conditionals as needed.
 *
 * @return void
 */
add_action( 'after_setup_theme', function() {
	if ( is_admin() ) {
		return;
	}

	new Mai_Performance_Enhancer;
});
